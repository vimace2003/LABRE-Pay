<?php
/**
 * Webhook Pix do Sicoob. Valida o token próprio (?token=, cadastrado nas
 * Configurações) e SEMPRE confirma consultando a cob pelo txid — nunca confia
 * apenas no corpo da notificação. É uma melhoria sobre o polling (página de
 * pagamento + cron), que é o caminho garantido de confirmação.
 */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/cobranca.php';

http_response_code(200); // responder rápido; evita reenvios do PSP por timeout
header('Content-Type: text/plain; charset=UTF-8');

$tokenEsperado = setting('sicoob_webhook_token');
// O Sicoob concatena "/pix" ao final da URL cadastrada; como ela termina em
// "?token=...", o sufixo vira parte do valor do token — removido aqui.
$tokenRecebido = preg_replace('#/pix$#', '', (string)($_GET['token'] ?? ''));
if ($tokenEsperado === '' || !hash_equals($tokenEsperado, $tokenRecebido)) {
    audit('webhook_sicoob_token_invalido');
    echo 'token invalido';
    exit;
}

$corpo = file_get_contents('php://input');
$json = json_decode($corpo, true) ?: [];
$itens = $json['pix'] ?? [];
if (!is_array($itens) || !$itens) {
    echo 'ignorado';
    exit;
}

$processados = 0;
foreach ($itens as $item) {
    $txid = (string)($item['txid'] ?? '');
    if ($txid === '') continue;
    try {
        $st = db()->prepare('SELECT * FROM charges WHERE sicoob_txid = ? LIMIT 1');
        $st->execute([$txid]);
        $charge = $st->fetch();
        if (!$charge) continue;

        $cob = sicoob_consultar_pix($txid);
        if (($cob['status'] ?? '') !== 'CONCLUIDA') continue;

        $pix = $cob['pix'][0] ?? $item;
        $valorPago = (float)($pix['valor'] ?? $cob['valor']['original'] ?? 0);
        charge_processar_pagamento_sicoob($charge, 'pix_sicoob', $valorPago, (string)($pix['endToEndId'] ?? $txid));
        $processados++;
    } catch (Throwable $ex) {
        // Não expõe detalhes; o cron de reconciliação cobre eventuais falhas.
        audit('webhook_sicoob_erro', 'charge', $charge['id'] ?? null, mb_substr($ex->getMessage(), 0, 500));
    }
}

echo 'ok: ' . $processados . ' processado(s)';
