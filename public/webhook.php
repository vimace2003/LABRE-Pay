<?php
/**
 * Webhook do MercadoPago. Valida a assinatura (se configurada) e SEMPRE
 * confirma o pagamento consultando a API com nosso token — nunca confia
 * apenas no corpo da notificação.
 */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/cobranca.php';

http_response_code(200); // responder rápido; o MP reenvia em caso de falha
header('Content-Type: text/plain; charset=UTF-8');

$corpo = file_get_contents('php://input');
$json = json_decode($corpo, true) ?: [];

// O MP notifica de duas formas: query string (?topic=payment&id=...) ou JSON (type/data.id)
$tipo = $_GET['type'] ?? $_GET['topic'] ?? ($json['type'] ?? '');
$dataId = $_GET['data.id'] ?? $_GET['id'] ?? ($json['data']['id'] ?? '');

if (!str_contains((string)$tipo, 'payment') || $dataId === '') {
    echo 'ignorado';
    exit;
}

$assinaturaOk = mp_validar_webhook(
    (string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''),
    (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''),
    (string)$dataId
);
if (!$assinaturaOk) {
    audit('webhook_assinatura_invalida', 'charge', null, 'data.id=' . $dataId);
    echo 'assinatura invalida';
    exit;
}

try {
    $payment = mp_get_payment((string)$dataId);
    charge_processar_pagamento_mp($payment);
    echo 'ok';
} catch (Throwable $ex) {
    // Não expor detalhes; o cron de reconciliação cobre eventuais falhas
    audit('webhook_erro', null, null, mb_substr($ex->getMessage(), 0, 500));
    echo 'erro registrado';
}
