<?php
/**
 * Redireciona para o Checkout Pro com o valor devido HOJE.
 * Se o link do MP expirou (mudança de fase: desconto/cheio/multa), gera outro.
 * Acesso via token da cobrança (links de email e consulta pública).
 */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/cobranca.php';

$id = (int)($_GET['c'] ?? 0);
$token = (string)($_GET['t'] ?? '');

$charge = $id ? charge_get($id) : null;
if (!$charge || !hash_equals($charge['token'], $token)) {
    http_response_code(404);
    exit('Cobrança não encontrada.');
}
if ($charge['status'] === 'pago') {
    redirect('comprovante.php?c=' . $id . '&t=' . $token);
}
if ($charge['status'] === 'cancelada') {
    exit('Esta cobrança foi cancelada. Em caso de dúvida, fale com a diretoria.');
}

$member = member_get((int)$charge['member_id']);
try {
    $charge = mp_garantir_preferencia($charge, $member);
} catch (MercadoPagoException $ex) {
    http_response_code(503);
    exit('Não foi possível gerar o link de pagamento agora. Tente novamente em alguns minutos.');
}

redirect($charge['mp_init_point']);
