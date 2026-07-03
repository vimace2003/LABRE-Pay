<?php
/** Painel inicial: resumo do ano e alertas (incl. exclusão automática Art. 40). */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/layout.php';
require APP_DIR . '/cobranca.php';

$user = require_login();

$ano = (int)($_GET['ano'] ?? date('Y', strtotime(proximo_vencimento(date('Y-m-d')))));

$tot = function (string $sql, array $p = []): int {
    $st = db()->prepare($sql);
    $st->execute($p);
    return (int)$st->fetchColumn();
};

$ativos = $tot("SELECT COUNT(*) FROM members WHERE status = 'ativo'");
$contribuintes = $tot("SELECT COUNT(*) FROM members WHERE status = 'ativo' AND classe = 'contribuinte'");
$isentos = $ativos - $contribuintes;
$pagas = $tot("SELECT COUNT(*) FROM charges WHERE tipo = 'anuidade' AND ano = ? AND status = 'pago'", [$ano]);
$abertas = $tot("SELECT COUNT(*) FROM charges WHERE tipo = 'anuidade' AND ano = ? AND status IN ('pendente','vencida')", [$ano]);
$semCobranca = $tot("SELECT COUNT(*) FROM members m WHERE m.status='ativo' AND m.classe='contribuinte'
    AND NOT EXISTS (SELECT 1 FROM charges c WHERE c.member_id = m.id AND c.tipo = 'anuidade' AND c.ano = ? AND c.status <> 'cancelada')", [$ano]);

$st = db()->prepare("SELECT COALESCE(SUM(valor_pago),0) FROM charges WHERE tipo = 'anuidade' AND ano = ? AND status = 'pago'");
$st->execute([$ano]);
$arrecadado = (float)$st->fetchColumn();

$st = db()->prepare("SELECT COALESCE(SUM(valor_pago),0) FROM charges WHERE tipo = 'avulsa' AND ano = ? AND status = 'pago'");
$st->execute([$ano]);
$arrecadadoAvulsas = (float)$st->fetchColumn();

/* Alerta Art. 40 IV: inadimplentes há mais de N meses */
$mesesExclusao = max(1, (int)setting('meses_exclusao_auto', '3'));
$st = db()->prepare("SELECT m.id, m.nome, m.indicativo, c.vencimento FROM members m
    JOIN charges c ON c.member_id = m.id
    WHERE m.status = 'ativo' AND c.status = 'vencida' AND c.tipo = 'anuidade'
      AND c.vencimento < DATE_SUB(CURDATE(), INTERVAL ? MONTH)
    GROUP BY m.id, m.nome, m.indicativo, c.vencimento ORDER BY c.vencimento LIMIT 50");
$st->execute([$mesesExclusao]);
$sujeitosExclusao = $st->fetchAll();

/* Últimos pagamentos */
$st = db()->prepare("SELECT c.*, m.nome, m.indicativo FROM charges c JOIN members m ON m.id = c.member_id
    WHERE c.status = 'pago' ORDER BY c.pago_em DESC LIMIT 8");
$st->execute();
$ultimosPagamentos = $st->fetchAll();

page_header('Início', 'dashboard.php', $user);
?>

<div class="toolbar">
  <form method="get">
    <label>Ano de referência <input type="number" name="ano" value="<?= $ano ?>" min="2000" max="2100"></label>
    <button type="submit" class="botao">Ver</button>
  </form>
</div>

<div class="resumo-grid">
  <div class="resumo-card"><span class="num"><?= $ativos ?></span><span class="rotulo">Associados ativos</span>
    <span class="texto-suave"><?= $contribuintes ?> contribuintes · <?= $isentos ?> isentos/remidos</span></div>
  <div class="resumo-card verde"><span class="num"><?= $pagas ?></span><span class="rotulo">Anuidades <?= $ano ?> pagas</span></div>
  <div class="resumo-card vermelho"><span class="num"><?= $abertas ?></span><span class="rotulo">Em aberto / vencidas</span></div>
  <div class="resumo-card amarelo"><span class="num"><?= $semCobranca ?></span><span class="rotulo">Ainda sem cobrança <?= $ano ?></span></div>
  <div class="resumo-card verde"><span class="num" style="font-size:1.4rem"><?= e(fmt_moeda($arrecadado)) ?></span><span class="rotulo">Anuidades arrecadadas em <?= $ano ?></span>
    <?php if ($arrecadadoAvulsas > 0): ?><span class="texto-suave">+ <?= e(fmt_moeda($arrecadadoAvulsas)) ?> em avulsas</span><?php endif; ?></div>
</div>

<?php if ($semCobranca > 0): ?>
  <div class="cartao">
    <strong><?= $semCobranca ?> contribuinte(s) ainda sem cobrança de <?= $ano ?>.</strong>
    <a class="botao botao-verde botao-mini" href="cobrancas.php">Gerar cobranças em lote</a>
  </div>
<?php endif; ?>

<?php if ($sujeitosExclusao): ?>
  <div class="cartao" style="border-color:var(--vermelho)">
    <h2 style="margin-top:0;color:var(--vermelho)">Sujeitos à exclusão automática (Art. 40 do Estatuto)</h2>
    <p>Associados com anuidade vencida há mais de <strong><?= $mesesExclusao ?> meses</strong>.
       A decisão de desligar é sempre da diretoria — abra o cadastro para agir.</p>
    <div class="tabela-envolve tabela-cards">
      <table class="tabela">
        <thead><tr><th>Associado</th><th>Indicativo</th><th>Vencida desde</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($sujeitosExclusao as $s): ?>
          <tr>
            <td data-rotulo="Associado"><?= e($s['nome']) ?></td>
            <td data-rotulo="Indicativo"><?= e($s['indicativo'] ?: '—') ?></td>
            <td data-rotulo="Vencida desde"><?= e(fmt_data($s['vencimento'])) ?></td>
            <td class="acoes"><a class="botao botao-mini" href="associados.php?editar=<?= (int)$s['id'] ?>">Abrir cadastro</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="cartao">
  <h2 style="margin-top:0">Últimos pagamentos</h2>
  <?php if (!$ultimosPagamentos): ?>
    <p class="vazio"><strong>Nenhum pagamento ainda</strong> Assim que os associados pagarem, eles aparecem aqui.</p>
  <?php else: ?>
    <div class="tabela-envolve tabela-cards">
      <table class="tabela">
        <thead><tr><th>Associado</th><th>Referência</th><th>Valor</th><th>Quando</th></tr></thead>
        <tbody>
        <?php foreach ($ultimosPagamentos as $p): ?>
          <tr>
            <td data-rotulo="Associado"><?= e($p['nome']) ?> <span class="texto-suave"><?= e($p['indicativo'] ?: '') ?></span></td>
            <td data-rotulo="Referência"><?= e($p['descricao']) ?></td>
            <td data-rotulo="Valor"><?= e(fmt_moeda((float)$p['valor_pago'])) ?></td>
            <td data-rotulo="Quando"><?= e(fmt_data_hora($p['pago_em'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php page_footer(); ?>
