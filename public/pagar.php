<?php
/**
 * Página de pagamento da cobrança (acesso via token — links de email e consulta pública).
 *
 * Gateway MercadoPago: redireciona para o Checkout Pro (Pix, boleto e cartão).
 * Gateway Sicoob: mostra QR Pix + copia-e-cola e boleto (quando o associado tem
 * CPF e endereço completos) nesta própria página; cartão sempre via Mercado Pago.
 */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/layout.php';
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

// Baixa do PDF do boleto (link da própria página de pagamento)
if (($_GET['boleto'] ?? '') === '1') {
    if (empty($charge['sicoob_nosso_numero'])) {
        http_response_code(404);
        exit('Boleto não disponível para esta cobrança.');
    }
    try {
        $pdf = sicoob_pdf_boleto($charge['sicoob_nosso_numero']);
    } catch (SicoobException $ex) {
        http_response_code(503);
        exit('Não foi possível obter o boleto agora. Tente novamente em alguns minutos.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="boleto-' . $id . '.pdf"');
    echo $pdf;
    exit;
}

// Botão "Pagar com cartão" na página Sicoob sempre usa o Checkout Pro do MP
if (($_GET['via'] ?? '') === 'mp') {
    try {
        $charge = mp_garantir_preferencia($charge, $member);
    } catch (MercadoPagoException $ex) {
        http_response_code(503);
        exit('Não foi possível gerar o link de cartão agora. Tente novamente em alguns minutos.');
    }
    redirect($charge['mp_init_point']);
}

try {
    $charge = charge_garantir_pagamento($charge, $member);
} catch (MercadoPagoException|SicoobException $ex) {
    http_response_code(503);
    exit('Não foi possível gerar o link de pagamento agora. Tente novamente em alguns minutos.');
}

if (charge_gateway() !== 'sicoob') {
    redirect($charge['mp_init_point']);
}

$devido = valor_devido($charge);
$temPix = !empty($charge['sicoob_pix_copia_cola']);
$temBoleto = !empty($charge['sicoob_linha_digitavel']);
$temCartao = trim(setting('mp_access_token')) !== '';

public_header('Pagamento — ' . $charge['descricao']);
?>
<div class="cartao">
  <h2 style="margin-top:0">Pagar: <?= e($charge['descricao']) ?></h2>
  <p>Valor atual: <strong><?= e(fmt_moeda($devido['valor'])) ?></strong> — vencimento <?= e(fmt_data($charge['vencimento'])) ?></p>

  <div id="pagar-status" data-charge="<?= (int)$charge['id'] ?>" data-token="<?= e($charge['token']) ?>">
    <?php if ($temPix): ?>
      <h3>Pague com Pix</h3>
      <div id="pix-qrcode" class="pix-qr" data-copia-cola="<?= e($charge['sicoob_pix_copia_cola']) ?>" aria-label="QR Code Pix"></div>
      <p class="texto-suave">Abra o app do seu banco, escolha Pix → Pagar com QR Code, ou copie o código abaixo.</p>
      <div class="linha-campos">
        <input type="text" id="pix-copia-cola-campo" readonly aria-label="Código Pix copia e cola" value="<?= e($charge['sicoob_pix_copia_cola']) ?>">
        <button type="button" class="botao botao-primario js-copiar-pix" data-alvo="pix-copia-cola-campo">Copiar código</button>
      </div>
      <p id="pagar-status-msg" class="texto-suave">Assim que o pagamento for identificado, esta página atualiza automaticamente.</p>
    <?php else: ?>
      <div class="flash flash-erro">Não foi possível gerar o Pix agora. Tente novamente em alguns minutos ou fale com a diretoria.</div>
    <?php endif; ?>
  </div>

  <?php if ($temBoleto): ?>
    <h3>Ou pague com boleto</h3>
    <p>Linha digitável: <code><?= e($charge['sicoob_linha_digitavel']) ?></code></p>
    <p><a class="botao" href="pagar.php?c=<?= (int)$charge['id'] ?>&t=<?= e($charge['token']) ?>&boleto=1" target="_blank" rel="noopener">Baixar PDF do boleto</a></p>
  <?php elseif (!member_pode_boleto($member)): ?>
    <p class="texto-suave">Para pagar por boleto, atualize seu endereço e CPF/CNPJ com a secretaria.</p>
  <?php endif; ?>

  <?php if ($temCartao): ?>
    <h3>Ou pague com cartão</h3>
    <p><a class="botao" href="pagar.php?c=<?= (int)$charge['id'] ?>&t=<?= e($charge['token']) ?>&via=mp">Pagar com cartão (Mercado Pago)</a></p>
  <?php endif; ?>
</div>
<script src="<?= e(asset_url('qrcode.js')) ?>"></script>
<?php public_footer(); ?>
