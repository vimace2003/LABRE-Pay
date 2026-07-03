<?php
/** Situação anual: adimplentes, inadimplentes, isentos e sem cobrança + export CSV. */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/layout.php';
require APP_DIR . '/cobranca.php';

$user = require_login();

$ano = (int)($_GET['ano'] ?? date('Y', strtotime(proximo_vencimento(date('Y-m-d')))));
$filtro = $_GET['f'] ?? 'todos';
$busca = trim($_GET['busca'] ?? '');

/* Associados ativos + cobrança do ano (a mais relevante: não-cancelada) */
$sql = "SELECT m.*, c.id AS charge_id, c.status AS charge_status, c.valor AS charge_valor,
               c.valor_pago, c.pago_em, c.vencimento
        FROM members m
        LEFT JOIN charges c ON c.member_id = m.id AND c.ano = ? AND c.status <> 'cancelada'
        WHERE m.status = 'ativo'";
$params = [$ano];
if ($busca !== '') {
    $sql .= ' AND (m.nome LIKE ? OR m.indicativo LIKE ?)';
    array_push($params, "%{$busca}%", "%{$busca}%");
}
$sql .= ' ORDER BY m.nome';
$st = db()->prepare($sql);
$st->execute($params);
$linhas = $st->fetchAll();

$rotulos = [
    'adimplente' => 'Adimplente',
    'inadimplente' => 'Inadimplente',
    'isento' => 'Isento/Remido',
    'sem_cobranca' => 'Sem cobrança',
];

$dados = [];
$totais = array_fill_keys(array_keys($rotulos), 0);
foreach ($linhas as $l) {
    $chargeDoAno = $l['charge_id'] ? ['status' => $l['charge_status']] : null;
    $sit = situacao_do_membro($l, $chargeDoAno);
    $totais[$sit]++;
    if ($filtro !== 'todos' && $sit !== $filtro) continue;
    $l['situacao'] = $sit;
    $dados[] = $l;
}

/* Export CSV */
if (isset($_GET['csv'])) {
    audit('situacao_exportada', null, null, "ano={$ano} filtro={$filtro}");
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="situacao-' . $ano . '-' . $filtro . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Nome', 'Indicativo', 'Email', 'Cidade/UF', 'Situação ' . $ano, 'Valor', 'Pago em'], ';');
    foreach ($dados as $d) {
        fputcsv($out, [
            $d['nome'], $d['indicativo'], $d['email'],
            trim(($d['cidade'] ?? '') . '/' . ($d['uf'] ?? ''), '/'),
            $rotulos[$d['situacao']],
            $d['charge_valor'] !== null ? number_format((float)($d['valor_pago'] ?? $d['charge_valor']), 2, ',', '') : '',
            $d['pago_em'] ? date('d/m/Y', strtotime($d['pago_em'])) : '',
        ], ';');
    }
    exit;
}

/* ---------- Relatório imprimível ---------- */
if (isset($_GET['imprimir'])) {
    $filtroRotulo = $filtro === 'todos' ? 'Todos' : ($rotulos[$filtro] ?? $filtro);
    $sub = 'Ano ' . $ano . ' · Filtro: ' . $filtroRotulo . ($busca !== '' ? ' · busca: "' . $busca . '"' : '')
        . ' — Adimplentes: ' . $totais['adimplente'] . ' · Inadimplentes: ' . $totais['inadimplente']
        . ' · Isentos/Remidos: ' . $totais['isento'] . ' · Sem cobrança: ' . $totais['sem_cobranca'];
    $voltar = 'situacao.php?ano=' . $ano . '&f=' . urlencode($filtro) . '&busca=' . urlencode($busca);
    report_header('Situação anual — ' . $ano, $sub, $voltar);
    echo '<table class="rel-tabela"><thead><tr><th>#</th><th>Associado</th><th>Indicativo</th><th>Cidade/UF</th><th>Situação ' . $ano . '</th><th>Pago em</th><th class="num">Valor pago (R$)</th></tr></thead><tbody>';
    foreach ($dados as $i => $d) {
        echo '<tr><td class="num">' . ($i + 1) . '</td>';
        echo '<td>' . e($d['nome']) . '</td>';
        echo '<td>' . e($d['indicativo'] ?: '—') . '</td>';
        echo '<td>' . e(trim(($d['cidade'] ?? '') . '/' . ($d['uf'] ?? ''), '/') ?: '—') . '</td>';
        echo '<td>' . e($rotulos[$d['situacao']]) . '</td>';
        echo '<td>' . e($d['pago_em'] ? fmt_data($d['pago_em']) : '—') . '</td>';
        echo '<td class="num">' . ($d['pago_em'] ? e(number_format((float)$d['valor_pago'], 2, ',', '.')) : '—') . '</td></tr>';
    }
    $somaPago = array_sum(array_map(fn($d) => (float)($d['valor_pago'] ?? 0), $dados));
    echo '</tbody><tfoot><tr><td colspan="6">Total: ' . count($dados) . ' associado(s)</td>';
    echo '<td class="num">' . e(number_format($somaPago, 2, ',', '.')) . '</td></tr></tfoot></table>';
    report_footer();
    exit;
}

page_header('Situação anual', 'situacao.php', $user);
?>

<div class="resumo-grid">
  <div class="resumo-card verde"><span class="num"><?= $totais['adimplente'] ?></span><span class="rotulo">Adimplentes</span></div>
  <div class="resumo-card vermelho"><span class="num"><?= $totais['inadimplente'] ?></span><span class="rotulo">Inadimplentes</span></div>
  <div class="resumo-card"><span class="num"><?= $totais['isento'] ?></span><span class="rotulo">Isentos/Remidos</span></div>
  <div class="resumo-card amarelo"><span class="num"><?= $totais['sem_cobranca'] ?></span><span class="rotulo">Sem cobrança</span></div>
</div>

<div class="toolbar">
  <form method="get">
    <label>Ano <input type="number" name="ano" value="<?= $ano ?>" min="2000" max="2100"></label>
    <label>Mostrar
      <select name="f">
        <option value="todos">Todos</option>
        <?php foreach ($rotulos as $v => $r): ?><option value="<?= $v ?>" <?= $filtro === $v ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Buscar <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Nome ou indicativo"></label>
    <button type="submit" class="botao">Filtrar</button>
  </form>
  <a class="botao" href="situacao.php?ano=<?= $ano ?>&f=<?= e($filtro) ?>&busca=<?= e(urlencode($busca)) ?>&csv=1">Exportar CSV</a>
  <a class="botao" href="situacao.php?ano=<?= $ano ?>&f=<?= e($filtro) ?>&busca=<?= e(urlencode($busca)) ?>&imprimir=1">Imprimir lista</a>
</div>

<?php if (!$dados): ?>
  <div class="cartao vazio"><strong>Nada a mostrar com esses filtros</strong> Ajuste o ano ou o filtro acima.</div>
<?php else: ?>
  <div class="tabela-envolve tabela-cards">
    <table class="tabela">
      <thead><tr><th>Associado</th><th>Indicativo</th><th>Cidade</th><th>Situação <?= $ano ?></th><th>Pagamento</th></tr></thead>
      <tbody>
      <?php foreach ($dados as $d): ?>
        <tr>
          <td data-rotulo="Associado"><a href="associados.php?editar=<?= (int)$d['id'] ?>"><?= e($d['nome']) ?></a></td>
          <td data-rotulo="Indicativo"><?= e($d['indicativo'] ?: '—') ?></td>
          <td data-rotulo="Cidade"><?= e(trim(($d['cidade'] ?? '') . '/' . ($d['uf'] ?? ''), '/') ?: '—') ?></td>
          <td data-rotulo="Situação"><span class="selo selo-<?= e($d['situacao']) ?>"><?= e($rotulos[$d['situacao']]) ?></span></td>
          <td data-rotulo="Pagamento">
            <?= $d['pago_em'] ? e(fmt_moeda((float)$d['valor_pago']) . ' em ' . fmt_data($d['pago_em'])) : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="texto-suave"><?= count($dados) ?> associado(s) listado(s).</p>
<?php endif; ?>

<?php page_footer(); ?>
