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
<?php $codigo = 'COMP-' . (int)$charge['id'] . '-' . strtoupper(substr($charge['token'], 0, 8)); ?>
<div class="cupom">
  <div class="c-centro">
    <div class="c-sigla"><?= e(setting('entidade_sigla')) ?></div>
    <div><?= e(setting('entidade_nome')) ?></div>
    <div class="c-mini"><?= e(setting('entidade_site')) ?><?= setting('entidade_email_contato') ? ' · ' . e(setting('entidade_email_contato')) : '' ?></div>
  </div>
  <hr class="c-trace">
  <div class="c-centro">
    <strong>COMPROVANTE DE PAGAMENTO</strong><br>
    <span class="c-mini">Documento sem valor fiscal</span>
  </div>
  <hr class="c-trace">
  <div class="c-linha"><span>Associado</span><span><?= e($member['nome']) ?></span></div>
  <?php if ($member['indicativo']): ?>
    <div class="c-linha"><span>Indicativo</span><span><?= e($member['indicativo']) ?></span></div>
  <?php endif; ?>
  <div class="c-linha"><span>Referente a</span><span><?= e($charge['descricao']) ?></span></div>
  <div class="c-linha"><span>Vencimento</span><span><?= e(fmt_data($charge['vencimento'])) ?></span></div>
  <hr class="c-trace">
  <div class="c-linha"><span>Valor original</span><span><?= e(fmt_moeda((float)$charge['valor'])) ?></span></div>
  <?php if ((float)$charge['desconto_aplicado'] > 0): ?>
    <div class="c-linha"><span>Desconto</span><span>− <?= e(fmt_moeda((float)$charge['desconto_aplicado'])) ?></span></div>
  <?php endif; ?>
  <?php if ((float)$charge['acrescimos_aplicados'] > 0): ?>
    <div class="c-linha"><span>Multa e juros</span><span>+ <?= e(fmt_moeda((float)$charge['acrescimos_aplicados'])) ?></span></div>
  <?php endif; ?>
  <div class="c-linha c-total"><span>TOTAL PAGO</span><span><?= e(fmt_moeda((float)$charge['valor_pago'])) ?></span></div>
  <div class="c-centro c-pago">*** PAGO ***</div>
  <hr class="c-trace">
  <div class="c-linha"><span>Data/hora</span><span><?= e(fmt_data_hora($charge['pago_em'])) ?></span></div>
  <div class="c-linha"><span>Forma</span><span><?= $charge['pago_manual'] ? 'Tesouraria' : 'MercadoPago (' . e($charge['meio_pagamento'] ?: 'online') . ')' ?></span></div>
  <?php if ($charge['mp_payment_id']): ?>
    <div class="c-linha"><span>Transação MP</span><span><?= e($charge['mp_payment_id']) ?></span></div>
  <?php endif; ?>
  <hr class="c-trace">
  <div class="c-barcode" aria-hidden="true"></div>
  <div class="c-centro c-mini"><?= e($codigo) ?></div>
  <hr class="c-trace">
  <div class="c-centro c-mini">Emitido em <?= e(date('d/m/Y H:i')) ?><br>Obrigado por manter sua associação em dia. 73!</div>
  <div class="c-centro c-morse" aria-label="TKS 73 em código Morse" title="TKS 73">
    &#8722; &nbsp; &#8722;&#183;&#8722; &nbsp; &#183;&#183;&#183; &nbsp;&nbsp;&nbsp; &#8722;&#8722;&#183;&#183;&#183; &nbsp; &#183;&#183;&#183;&#8722;&#8722;
  </div>
</div>
<p class="c-centro nao-imprimir" style="text-align:center">
  <button type="button" class="botao botao-primario js-imprimir">Imprimir / salvar PDF</button>
</p>
<?php public_footer(); ?>
