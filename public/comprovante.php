<?php
/** Comprovante de pagamento (público via token, formatado para impressão). */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/layout.php';
require APP_DIR . '/cobranca.php';

$id = (int)($_GET['c'] ?? 0);
$token = (string)($_GET['t'] ?? '');

$charge = $id ? charge_get($id) : null;
if (!$charge || !hash_equals($charge['token'], $token) || $charge['status'] !== 'pago') {
    http_response_code(404);
    exit('Comprovante não encontrado.');
}
$member = member_get((int)$charge['member_id']);

public_header('Comprovante de pagamento');
?>
<?php
$codigo = 'COMP-' . (int)$charge['id'] . '-' . strtoupper(substr($charge['token'], 0, 8));
$forma = $charge['pago_manual'] ? 'Tesouraria (manual)' : fmt_meio_pagamento($charge['meio_pagamento']) . ' — MercadoPago';
$cnpj = setting('entidade_cnpj');
?>
<div class="cupom">
  <div class="c-centro">
    <div class="c-sigla"><?= e(setting('entidade_nome')) ?></div>
    <div class="c-mini">
      <?= e(setting('entidade_sigla')) ?><?= $cnpj ? ' — CNPJ: ' . e($cnpj) : '' ?><br>
      <?= e(preg_replace('#^https?://#', '', setting('entidade_site'))) ?>
    </div>
  </div>
  <hr class="c-trace">
  <div class="c-linha c-mini"><span><?= e(fmt_data_hora($charge['pago_em'])) ?></span><span>DOC:<?= str_pad((string)(int)$charge['id'], 6, '0', STR_PAD_LEFT) ?></span></div>
  <hr class="c-trace">
  <div class="c-centro c-titulo">COMPROVANTE</div>
  <div class="c-centro c-mini">de pagamento — sem valor fiscal</div>
  <hr class="c-trace">
  <div class="c-linha c-mini"><span>ITEM</span><span>VALOR (R$)</span></div>
  <div class="c-item">
    001 <?= e($charge['descricao']) ?><br>
    <div class="c-linha"><span>&nbsp;&nbsp;&nbsp;&nbsp;Venc. <?= e(fmt_data($charge['vencimento'])) ?></span><span><?= e(number_format((float)$charge['valor'], 2, ',', '.')) ?></span></div>
  </div>
  <?php if ((float)$charge['desconto_aplicado'] > 0): ?>
    <div class="c-linha"><span>Desconto antecipação</span><span>-<?= e(number_format((float)$charge['desconto_aplicado'], 2, ',', '.')) ?></span></div>
  <?php endif; ?>
  <?php if ((float)$charge['acrescimos_aplicados'] > 0): ?>
    <div class="c-linha"><span>Multa e juros</span><span>+<?= e(number_format((float)$charge['acrescimos_aplicados'], 2, ',', '.')) ?></span></div>
  <?php endif; ?>
  <hr class="c-trace">
  <div class="c-linha c-total"><span>TOTAL R$</span><span><?= e(number_format((float)$charge['valor_pago'], 2, ',', '.')) ?></span></div>
  <div class="c-linha"><span><?= e($forma) ?></span><span><?= e(number_format((float)$charge['valor_pago'], 2, ',', '.')) ?></span></div>
  <div class="c-centro c-pago">*** PAGO ***</div>
  <hr class="c-trace">
  <div>Associado: <?= e($member['nome']) ?><?= $member['indicativo'] ? ' (' . e($member['indicativo']) . ')' : '' ?></div>
  <?php if ($charge['mp_payment_id']): ?>
    <div>Transação MP: <?= e($charge['mp_payment_id']) ?></div>
  <?php endif; ?>
  <hr class="c-trace">
  <div class="c-hash">SHA: <?= e($charge['token']) ?></div>
  <div class="c-barcode" aria-hidden="true"></div>
  <div class="c-centro c-mini"><?= e($codigo) ?></div>
  <hr class="c-trace">
  <div class="c-centro c-mini">
    Emitido em <?= e(date('d/m/Y H:i')) ?> — LABRE-Pay v<?= e(APP_VERSION) ?><br>
    Obrigado por manter sua associação em dia. 73!
  </div>
  <div class="c-centro c-morse" aria-label="TKS 73 em código Morse" title="TKS 73">
    &#8722; &nbsp; &#8722;&#183;&#8722; &nbsp; &#183;&#183;&#183; &nbsp;&nbsp;&nbsp; &#8722;&#8722;&#183;&#183;&#183; &nbsp; &#183;&#183;&#183;&#8722;&#8722;
  </div>
</div>
<p class="nao-imprimir" style="text-align:center">
  <button type="button" class="botao botao-primario js-imprimir">Imprimir / salvar PDF</button>
</p>
<?php public_footer(); ?>
