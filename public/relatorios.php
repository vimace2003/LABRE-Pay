<?php
/** Relatórios: gráficos SVG de arrecadação/adimplência e relatório mensal p/ Comissão Fiscal. */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/layout.php';
require APP_DIR . '/cobranca.php';

$user = require_login();

/* ---------- Dados: arrecadação por ano ---------- */
$porAno = db()->query(
    "SELECT ano, SUM(CASE WHEN status = 'pago' THEN valor_pago ELSE 0 END) AS arrecadado,
            SUM(status = 'pago') AS pagas,
            SUM(status IN ('pendente','vencida')) AS abertas
     FROM charges GROUP BY ano ORDER BY ano DESC LIMIT 8"
)->fetchAll();
$porAno = array_reverse($porAno);

/* ---------- Relatório mensal (Comissão Fiscal, Art. 15) ---------- */
$mes = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
$ini = $mes . '-01';
$fim = date('Y-m-t', strtotime($ini));

$st = db()->prepare("SELECT c.*, m.nome, m.indicativo FROM charges c JOIN members m ON m.id = c.member_id
    WHERE c.status = 'pago' AND c.pago_em BETWEEN ? AND ? ORDER BY c.pago_em");
$st->execute([$ini . ' 00:00:00', $fim . ' 23:59:59']);
$pagamentosMes = $st->fetchAll();
$totalMes = array_sum(array_map(fn($p) => (float)$p['valor_pago'], $pagamentosMes));

/* Export CSV do relatório mensal */
if (isset($_GET['csv'])) {
    audit('relatorio_mensal_exportado', null, null, "mes={$mes}");
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio-' . $mes . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Relatório de recebimentos — ' . setting('entidade_sigla'), $mes], ';');
    fputcsv($out, ['Data', 'Associado', 'Indicativo', 'Referência', 'Meio', 'Valor'], ';');
    foreach ($pagamentosMes as $p) {
        fputcsv($out, [
            date('d/m/Y H:i', strtotime($p['pago_em'])), $p['nome'], $p['indicativo'],
            $p['descricao'], $p['pago_manual'] ? 'Manual' : ($p['meio_pagamento'] ?: 'MP'),
            number_format((float)$p['valor_pago'], 2, ',', ''),
        ], ';');
    }
    fputcsv($out, ['', '', '', '', 'TOTAL', number_format($totalMes, 2, ',', '')], ';');
    exit;
}

/* ---------- Gráfico SVG de barras (sem bibliotecas externas) ---------- */
function grafico_barras(array $dados, string $campoRotulo, string $campoValor, string $cor, bool $moeda = false): string
{
    if (!$dados) return '<p class="vazio"><strong>Sem dados ainda</strong> Gere cobranças para ver o gráfico.</p>';
    $max = max(array_map(fn($d) => (float)$d[$campoValor], $dados)) ?: 1;
    $n = count($dados);
    $larguraBarra = 64;
    $gap = 28;
    $altura = 220;
    $areaTexto = 46;
    $largura = $n * ($larguraBarra + $gap) + $gap;
    $svg = '<svg role="img" width="' . $largura . '" height="' . ($altura + $areaTexto) . '" xmlns="http://www.w3.org/2000/svg" style="font-family:inherit">';
    foreach ($dados as $i => $d) {
        $v = (float)$d[$campoValor];
        $h = (int)round(($altura - 30) * $v / $max);
        $x = $gap + $i * ($larguraBarra + $gap);
        $y = $altura - $h;
        $rotuloValor = $moeda ? 'R$ ' . number_format($v, 0, ',', '.') : (string)(int)$v;
        $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $larguraBarra . '" height="' . $h . '" rx="6" fill="' . $cor . '"/>';
        $svg .= '<text x="' . ($x + $larguraBarra / 2) . '" y="' . ($y - 8) . '" text-anchor="middle" font-size="13" font-weight="700" fill="#1a2733">' . $rotuloValor . '</text>';
        $svg .= '<text x="' . ($x + $larguraBarra / 2) . '" y="' . ($altura + 24) . '" text-anchor="middle" font-size="14" fill="#51606e">' . e((string)$d[$campoRotulo]) . '</text>';
    }
    $svg .= '</svg>';
    return $svg;
}

page_header('Relatórios', 'relatorios.php', $user);
?>

<div class="cartao">
  <h2 style="margin-top:0">Arrecadação por ano</h2>
  <div class="grafico-caixa"><?= grafico_barras($porAno, 'ano', 'arrecadado', '#0a7d33', true) ?></div>
</div>

<div class="cartao">
  <h2 style="margin-top:0">Anuidades pagas × em aberto</h2>
  <div style="display:flex;gap:2rem;flex-wrap:wrap">
    <div class="grafico-caixa"><p class="texto-suave" style="margin:0">Pagas</p><?= grafico_barras($porAno, 'ano', 'pagas', '#0a7d33') ?></div>
    <div class="grafico-caixa"><p class="texto-suave" style="margin:0">Em aberto/vencidas</p><?= grafico_barras($porAno, 'ano', 'abertas', '#b3261e') ?></div>
  </div>
</div>

<div class="cartao">
  <h2 style="margin-top:0">Relatório mensal — Comissão Fiscal (Art. 15 do Estatuto)</h2>
  <div class="toolbar">
    <form method="get">
      <label>Mês <input type="month" name="mes" value="<?= e($mes) ?>"></label>
      <button type="submit" class="botao">Ver</button>
    </form>
    <a class="botao" href="relatorios.php?mes=<?= e($mes) ?>&csv=1">Exportar CSV</a>
    <button class="botao nao-imprimir" onclick="window.print()">Imprimir</button>
  </div>

  <?php if (!$pagamentosMes): ?>
    <p class="vazio"><strong>Nenhum recebimento em <?= e(date('m/Y', strtotime($ini))) ?></strong></p>
  <?php else: ?>
    <div class="tabela-envolve tabela-cards">
      <table class="tabela">
        <thead><tr><th>Data</th><th>Associado</th><th>Referência</th><th>Meio</th><th>Valor</th></tr></thead>
        <tbody>
        <?php foreach ($pagamentosMes as $p): ?>
          <tr>
            <td data-rotulo="Data"><?= e(fmt_data_hora($p['pago_em'])) ?></td>
            <td data-rotulo="Associado"><?= e($p['nome']) ?> <span class="texto-suave"><?= e($p['indicativo'] ?: '') ?></span></td>
            <td data-rotulo="Referência"><?= e($p['descricao']) ?></td>
            <td data-rotulo="Meio"><?= $p['pago_manual'] ? 'Manual (tesouraria)' : e($p['meio_pagamento'] ?: 'MercadoPago') ?></td>
            <td data-rotulo="Valor"><?= e(fmt_moeda((float)$p['valor_pago'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr><td colspan="4" style="text-align:right"><strong>Total do mês</strong></td><td><strong><?= e(fmt_moeda($totalMes)) ?></strong></td></tr>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php page_footer(); ?>
