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
<div class="cartao comprovante">
  <h2>Comprovante de pagamento</h2>
  <dl>
    <dt>Entidade</dt><dd><?= e(setting('entidade_nome')) ?> (<?= e(setting('entidade_sigla')) ?>)</dd>
    <dt>Associado</dt><dd><?= e($member['nome']) ?><?= $member['indicativo'] ? ' — ' . e($member['indicativo']) : '' ?></dd>
    <dt>Referente a</dt><dd><?= e($charge['descricao']) ?></dd>
    <dt>Valor original</dt><dd><?= e(fmt_moeda((float)$charge['valor'])) ?></dd>
    <?php if ((float)$charge['desconto_aplicado'] > 0): ?>
      <dt>Desconto</dt><dd>− <?= e(fmt_moeda((float)$charge['desconto_aplicado'])) ?></dd>
    <?php endif; ?>
    <?php if ((float)$charge['acrescimos_aplicados'] > 0): ?>
      <dt>Multa e juros</dt><dd>+ <?= e(fmt_moeda((float)$charge['acrescimos_aplicados'])) ?></dd>
    <?php endif; ?>
    <dt>Valor pago</dt><dd><strong><?= e(fmt_moeda((float)$charge['valor_pago'])) ?></strong></dd>
    <dt>Pago em</dt><dd><?= e(fmt_data_hora($charge['pago_em'])) ?></dd>
    <dt>Forma</dt><dd><?= $charge['pago_manual'] ? 'Registrado pela tesouraria' : 'MercadoPago (' . e($charge['meio_pagamento'] ?: 'online') . ')' ?></dd>
    <?php if ($charge['mp_payment_id']): ?>
      <dt>Transação MP</dt><dd><?= e($charge['mp_payment_id']) ?></dd>
    <?php endif; ?>
    <dt>Código</dt><dd>COMP-<?= (int)$charge['id'] ?>-<?= e(strtoupper(substr($charge['token'], 0, 8))) ?></dd>
  </dl>
  <p class="texto-suave">Documento sem valor fiscal, emitido pelo sistema de anuidades em <?= e(date('d/m/Y H:i')) ?>.</p>
  <p class="nao-imprimir"><button type="button" class="botao botao-primario js-imprimir">Imprimir / salvar PDF</button></p>
</div>
<?php public_footer(); ?>
