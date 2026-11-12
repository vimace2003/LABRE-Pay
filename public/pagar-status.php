<?php
/**
 * Status de pagamento em JSON, para o polling da página de pagamento (pagar.php).
 * Com cob Pix pendente, reconsulta o Sicoob antes de responder — o cron cobre
 * o caso de o associado fechar a página antes da confirmação.
 */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/cobranca.php';

header('Content-Type: application/json; charset=UTF-8');

$id = (int)($_GET['c'] ?? 0);
$token = (string)($_GET['t'] ?? '');
$charge = $id ? charge_get($id) : null;
if (!$charge || !hash_equals($charge['token'], $token)) {
    http_response_code(404);
    echo json_encode(['status' => 'erro']);
    exit;
}

if (in_array($charge['status'], ['pendente', 'vencida'], true) && !empty($charge['sicoob_txid']) && sicoob_configurado()) {
    try {
        $cob = sicoob_consultar_pix($charge['sicoob_txid']);
        if (($cob['status'] ?? '') === 'CONCLUIDA') {
            $pix = $cob['pix'][0] ?? [];
            $valorPago = (float)($pix['valor'] ?? $cob['valor']['original'] ?? 0);
            charge_processar_pagamento_sicoob($charge, 'pix_sicoob', $valorPago, (string)($pix['endToEndId'] ?? $charge['sicoob_txid']));
            $charge = charge_get($id);
        }
    } catch (SicoobException $ex) {
        // Silencioso: a página continua "pendente" e tenta de novo no próximo ciclo; o cron cobre depois.
    }
}

echo json_encode([
    'status' => $charge['status'] === 'pago' ? 'pago' : 'pendente',
    'comprovante_url' => $charge['status'] === 'pago' ? ('comprovante.php?c=' . $id . '&t=' . $token) : null,
], JSON_UNESCAPED_UNICODE);
