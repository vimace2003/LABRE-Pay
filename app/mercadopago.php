<?php
/**
 * Cliente REST do MercadoPago via cURL (sem SDK/Composer).
 * Docs: https://www.mercadopago.com.br/developers/pt/reference
 */

require_once APP_DIR . '/valores.php';

class MercadoPagoException extends RuntimeException {}

function mp_token(): string
{
    $token = trim(setting('mp_access_token'));
    if ($token === '') {
        throw new MercadoPagoException('Access token do MercadoPago não configurado (Configurações → MercadoPago).');
    }
    // Trava de ambiente: produção exige APP_USR-. Fora de produção aceitamos
    // TEST-… e também APP_USR-… porque o fluxo atual recomendado pelo MP usa
    // as credenciais de uma CONTA DE VENDEDOR DE TESTE, que começam com APP_USR-.
    if (is_production() && str_starts_with($token, 'TEST-')) {
        throw new MercadoPagoException('Token de TESTE configurado em ambiente de PRODUÇÃO. Corrija nas Configurações.');
    }
    return $token;
}

function mp_request(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init('https://api.mercadopago.com' . $path);
    $headers = [
        'Authorization: Bearer ' . mp_token(),
        'Content-Type: application/json',
    ];
    if ($method === 'POST') {
        $headers[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(16));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno !== 0 || $resp === false) {
        throw new MercadoPagoException('Falha de comunicação com o MercadoPago (cURL ' . $errno . ').');
    }
    $data = json_decode($resp, true);
    if ($status >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $resp) : $resp;
        throw new MercadoPagoException('MercadoPago respondeu HTTP ' . $status . ': ' . mb_substr((string)$msg, 0, 300));
    }
    return is_array($data) ? $data : [];
}

/**
 * Garante que a cobrança tem um link de pagamento válido para o valor devido HOJE.
 * Regenera a preferência quando não existe ou quando a fase de valor mudou.
 * Retorna a charge atualizada (com mp_init_point).
 */
function mp_garantir_preferencia(array $charge, array $member): array
{
    $devido = valor_devido($charge);
    $hoje = date('Y-m-d');
    $precisaNova = empty($charge['mp_preference_id'])
        || empty($charge['mp_init_point'])
        || ($charge['mp_pref_expira'] !== null && substr($charge['mp_pref_expira'], 0, 10) < $hoje);

    if (!$precisaNova) {
        return $charge;
    }

    // Expiração do link: fim do dia da próxima mudança de fase (ou +180 dias se estável)
    $expiraData = $devido['fase_expira'] ?? date('Y-m-d', strtotime('+180 days'));
    $expiraIso = $expiraData . 'T23:59:59.000-03:00';

    $sigla = setting('entidade_sigla', 'LABRE');
    $pref = mp_request('POST', '/checkout/preferences', [
        'items' => [[
            'id' => 'charge-' . $charge['id'],
            'title' => $charge['descricao'] . ' — ' . $sigla,
            'quantity' => 1,
            'currency_id' => 'BRL',
            'unit_price' => (float)$devido['valor'],
        ]],
        'payer' => array_filter([
            'name' => $member['nome'],
            'email' => $member['email'] ?: null,
        ]),
        'external_reference' => 'labrepay-' . $charge['id'],
        'notification_url' => BASE_URL . '/webhook.php',
        'back_urls' => [
            'success' => BASE_URL . '/consulta.php?pago=1',
            'pending' => BASE_URL . '/consulta.php?pendente=1',
            'failure' => BASE_URL . '/consulta.php?falha=1',
        ],
        'auto_return' => 'approved',
        'date_of_expiration' => $expiraIso,
        'statement_descriptor' => mb_substr($sigla, 0, 22),
    ]);

    $st = db()->prepare('UPDATE charges SET mp_preference_id = ?, mp_init_point = ?, mp_pref_expira = ? WHERE id = ?');
    $st->execute([$pref['id'], $pref['init_point'], $expiraData . ' 23:59:59', $charge['id']]);

    $charge['mp_preference_id'] = $pref['id'];
    $charge['mp_init_point'] = $pref['init_point'];
    $charge['mp_pref_expira'] = $expiraData . ' 23:59:59';
    return $charge;
}

function mp_get_payment(string $paymentId): array
{
    return mp_request('GET', '/v1/payments/' . rawurlencode($paymentId));
}

/** Busca pagamentos aprovados pela referência externa (reconciliação via cron). */
function mp_buscar_pagamento_por_referencia(int $chargeId): ?array
{
    $res = mp_request('GET', '/v1/payments/search?external_reference=' . rawurlencode('labrepay-' . $chargeId) . '&sort=date_created&criteria=desc');
    foreach ($res['results'] ?? [] as $p) {
        if (($p['status'] ?? '') === 'approved') return $p;
    }
    return null;
}

/**
 * Valida a assinatura x-signature das notificações de webhook.
 * Sem secret configurado, retorna true (a confirmação real é sempre feita
 * consultando a API com nosso token — a assinatura é defesa extra).
 */
function mp_validar_webhook(string $xSignature, string $xRequestId, string $dataId): bool
{
    $secret = trim(setting('mp_webhook_secret'));
    if ($secret === '') return true;
    $parts = [];
    foreach (explode(',', $xSignature) as $p) {
        $kv = explode('=', trim($p), 2);
        if (count($kv) === 2) $parts[$kv[0]] = $kv[1];
    }
    if (empty($parts['ts']) || empty($parts['v1'])) return false;
    $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $xRequestId . ';ts:' . $parts['ts'] . ';';
    return hash_equals(hash_hmac('sha256', $manifest, $secret), $parts['v1']);
}
