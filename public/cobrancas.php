<?php
/** Cobranças: geração anual em lote, reenvio, cancelamento e baixa manual. */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/layout.php';
require APP_DIR . '/cobranca.php';

$user = require_login();

/* ---------- Geração em lote (chamadas AJAX em blocos) ---------- */
if (($_GET['acao'] ?? '') === 'gerar_lote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    header('Content-Type: application/json; charset=UTF-8');
    $ano = (int)($_POST['ano'] ?? 0);
    $valor = (float)str_replace(',', '.', (string)($_POST['valor'] ?? '0'));
    $venc = $_POST['vencimento'] ?: vencimento_para_emissao($ano);
    if ($valor <= 0) {
        echo json_encode(['erro' => 'Valor inválido.']);
        exit;
    }
    // Cobrança em lote é sempre do ano vigente — nunca passado, nunca futuro.
    if ($ano !== (int)date('Y')) {
        echo json_encode(['erro' => 'A cobrança em lote só pode ser gerada para o ano vigente (' . date('Y') . ').'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $vencAno = vencimento_do_ano($ano);
    $prazoMeses = max(1, (int)setting('prazo_venc_meses', '3'));

    // Contribuintes ativos sem cobrança do ano vigente:
    //  - adesão dentro do ciclo normal → anuidade cheia do ano;
    //  - adesão DEPOIS do vencimento do ano (meio do ciclo) → proporcional do
    //    restante do ciclo, registrada no MESMO ano (no ano seguinte, lote cheio).
    $condPendentes = "m.status = 'ativo' AND m.classe = 'contribuinte'
        AND NOT EXISTS (SELECT 1 FROM charges c WHERE c.member_id = m.id AND c.ano = ? AND c.status <> 'cancelada')";
    $paramsPendentes = [$ano];

    $st = db()->prepare("SELECT m.* FROM members m WHERE {$condPendentes} ORDER BY m.nome LIMIT 4");
    $st->execute($paramsPendentes);
    $membros = $st->fetchAll();

    $mensagens = [];
    $processados = 0;
    foreach ($membros as $m) {
        $meioCiclo = $m['data_adesao'] !== null && $m['data_adesao'] > $vencAno;
        if ($meioCiclo) {
            $pr = calcular_prorata($m['data_adesao']);
            $valorPr = round($valor / 12 * $pr['meses'], 2);
            $vencPr = min(date('Y-m-d', strtotime('+' . $prazoMeses . ' months')), $pr['vencimento']);
            $mesAno = date('m/Y', strtotime($m['data_adesao']));
            $charge = charge_criar((int)$m['id'], $ano,
                "Anuidade {$ano} (adesão em {$mesAno} — proporcional de {$pr['meses']} meses)", $valorPr, $vencPr, true);
            $mensagens[] = $m['nome'] . ': entrou em ' . fmt_data($m['data_adesao']) .
                ' — cobrado proporcional de ' . fmt_moeda($valorPr) . " ({$pr['meses']} meses, vence " . fmt_data($vencPr) . ').';
        } else {
            $charge = charge_criar((int)$m['id'], $ano, "Anuidade {$ano}", $valor, $venc, false);
        }
        [$okEnv, $msg] = charge_enviar($charge, $m);
        if (!$okEnv) $mensagens[] = $msg;
        $processados++;
    }

    $st = db()->prepare("SELECT COUNT(*) FROM members m WHERE {$condPendentes}");
    $st->execute($paramsPendentes);
    $restantes = (int)$st->fetchColumn();

    echo json_encode([
        'processados' => $processados,
        'restantes' => $restantes,
        'terminou' => $restantes === 0,
        'mensagens' => $mensagens,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Ações individuais ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['acao'] ?? '') === '') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    $charge = charge_get((int)($_POST['id'] ?? 0));
    if (!$charge) {
        flash_set('erro', 'Cobrança não encontrada.');
        redirect('cobrancas.php');
    }

    if ($acao === 'reenviar' && in_array($charge['status'], ['pendente', 'vencida'], true)) {
        [$ok, $msg] = charge_enviar($charge, null, $charge['status'] === 'vencida' ? 'lembrete' : 'cobranca');
        flash_set($ok ? 'ok' : 'erro', $msg);
    }
    if ($acao === 'cancelar' && in_array($charge['status'], ['pendente', 'vencida'], true)) {
        db()->prepare("UPDATE charges SET status = 'cancelada' WHERE id = ?")->execute([$charge['id']]);
        audit('cobranca_cancelada', 'charge', (int)$charge['id']);
        flash_set('ok', 'Cobrança cancelada.');
    }
    if ($acao === 'pagar_manual' && in_array($charge['status'], ['pendente', 'vencida'], true)) {
        $valorPago = (float)str_replace(',', '.', (string)($_POST['valor_pago'] ?? '0'));
        if ($valorPago <= 0) $valorPago = valor_devido($charge)['valor'];
        charge_marcar_paga($charge, $valorPago, null, trim($_POST['meio'] ?? 'manual') ?: 'manual', true);
        flash_set('ok', 'Pagamento registrado manualmente e comprovante enviado.');
    }
    redirect('cobrancas.php?ano=' . (int)$charge['ano']);
}

/* ---------- Listagem ---------- */
$anoAtual = (int)date('Y');
$ano = (int)($_GET['ano'] ?? $anoAtual);
$filtro = $_GET['f'] ?? 'todas';

$modoRelatorio = isset($_GET['csv']) || isset($_GET['imprimir']);
$sql = 'SELECT c.*, m.nome, m.indicativo, m.email FROM charges c JOIN members m ON m.id = c.member_id WHERE c.ano = ?';
$params = [$ano];
if (in_array($filtro, ['pendente', 'pago', 'vencida', 'cancelada'], true)) {
    $sql .= ' AND c.status = ?';
    $params[] = $filtro;
}
$sql .= ' ORDER BY m.nome LIMIT ' . ($modoRelatorio ? 10000 : 1000);
$st = db()->prepare($sql);
$st->execute($params);
$lista = $st->fetchAll();

$anos = db()->query('SELECT DISTINCT ano FROM charges ORDER BY ano DESC')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($ano, $anos)) { $anos[] = $ano; rsort($anos); }

$rotulosStatus = ['pendente' => 'Pendente', 'pago' => 'Pago', 'vencida' => 'Vencida', 'cancelada' => 'Cancelada'];
$rotuloFiltro = 'Ano ' . $ano . ' · ' . ($filtro === 'todas' || !isset($rotulosStatus[$filtro]) ? 'Todas' : $rotulosStatus[$filtro]);

/* ---------- Exportação CSV ---------- */
if (isset($_GET['csv'])) {
    audit('cobrancas_exportadas', null, null, $rotuloFiltro);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cobrancas-' . $ano . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Associado', 'Indicativo', 'Descrição', 'Valor', 'Vencimento', 'Status', 'Pago em', 'Valor pago', 'Meio'], ';');
    foreach ($lista as $c) {
        fputcsv($out, [
            $c['nome'], $c['indicativo'], $c['descricao'],
            number_format((float)$c['valor'], 2, ',', ''),
            date('d/m/Y', strtotime($c['vencimento'])),
            $rotulosStatus[$c['status']],
            $c['pago_em'] ? date('d/m/Y H:i', strtotime($c['pago_em'])) : '',
            $c['valor_pago'] !== null ? number_format((float)$c['valor_pago'], 2, ',', '') : '',
            $c['status'] === 'pago' ? ($c['pago_manual'] ? 'Manual (tesouraria)' : fmt_meio_pagamento($c['meio_pagamento'])) : '',
        ], ';');
    }
    exit;
}

/* ---------- Relatório imprimível ---------- */
if (isset($_GET['imprimir'])) {
    $totalValor = array_sum(array_map(fn($c) => $c['status'] !== 'cancelada' ? (float)$c['valor'] : 0, $lista));
    $totalPago = array_sum(array_map(fn($c) => (float)($c['valor_pago'] ?? 0), $lista));
    $voltar = 'cobrancas.php?ano=' . $ano . '&f=' . urlencode($filtro);
    report_header('Cobranças — ' . $ano, 'Filtro: ' . $rotuloFiltro . ' · ' . count($lista) . ' cobrança(s)', $voltar);
    echo '<table class="rel-tabela"><thead><tr><th>#</th><th>Associado</th><th>Indicativo</th><th>Descrição</th><th class="num">Valor (R$)</th><th>Vencimento</th><th>Status</th><th>Pago em</th><th class="num">Pago (R$)</th></tr></thead><tbody>';
    foreach ($lista as $i => $c) {
        echo '<tr><td class="num">' . ($i + 1) . '</td>';
        echo '<td>' . e($c['nome']) . '</td>';
        echo '<td>' . e($c['indicativo'] ?: '—') . '</td>';
        echo '<td>' . e($c['descricao']) . '</td>';
        echo '<td class="num">' . e(number_format((float)$c['valor'], 2, ',', '.')) . '</td>';
        echo '<td>' . e(fmt_data($c['vencimento'])) . '</td>';
        echo '<td>' . e($rotulosStatus[$c['status']]) . ($c['status'] === 'pago' && $c['pago_manual'] ? ' (manual)' : '') . '</td>';
        echo '<td>' . e($c['pago_em'] ? fmt_data($c['pago_em']) : '—') . '</td>';
        echo '<td class="num">' . ($c['valor_pago'] !== null ? e(number_format((float)$c['valor_pago'], 2, ',', '.')) : '—') . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><td colspan="4">Totais (' . count($lista) . ' cobrança(s), canceladas fora da soma)</td>';
    echo '<td class="num">' . e(number_format($totalValor, 2, ',', '.')) . '</td><td colspan="3"></td>';
    echo '<td class="num">' . e(number_format($totalPago, 2, ',', '.')) . '</td></tr></tfoot></table>';
    report_footer();
    exit;
}

page_header('Cobranças', 'cobrancas.php', $user);
?>

<div class="cartao">
  <h2 style="margin-top:0">Gerar cobrança anual em lote</h2>
  <p>Cria a cobrança da anuidade para <strong>todos os contribuintes ativos</strong> que ainda não
     têm cobrança do ano escolhido, gera o link de pagamento e envia o email de cada um.
     Quem já tem cobrança no ano não é cobrado de novo.</p>
  <?php $anoVigente = (int)date('Y'); ?>
  <form id="form-lote" method="post" action="cobrancas.php?acao=gerar_lote">
    <?= csrf_field() ?>
    <input type="hidden" name="ano" value="<?= $anoVigente ?>">
    <div class="linha-campos">
      <label>Ano de referência
        <span class="dica">Sempre o ano vigente — nunca passado nem futuro</span>
        <input type="text" value="<?= $anoVigente ?>" disabled>
      </label>
      <label>Valor da anuidade <input type="text" name="valor" value="<?= e(number_format((float)setting('anuidade_valor'), 2, ',', '')) ?>" inputmode="decimal" required></label>
      <label>Vencimento
        <span class="dica">Ciclo já em andamento? O sistema sugere <?= max(1, (int)setting('prazo_venc_meses', '3')) ?> meses após a emissão</span>
        <input id="lote-venc" type="date" name="vencimento" value="<?= e(vencimento_para_emissao($anoVigente)) ?>" required>
      </label>
    </div>
    <p class="texto-suave">Quem entrou <strong>depois do vencimento do ano</strong> (adesão no meio do ciclo)
       recebe automaticamente a cobrança <strong>proporcional</strong> até o próximo vencimento, sem multa.</p>
    <br>
    <button type="submit" class="botao botao-verde">Gerar e enviar cobranças</button>
    <div style="display:none;margin-top:1rem" class="progresso-envolve">
      <div class="progresso"><div id="lote-barra"></div></div>
      <p id="lote-status" class="texto-suave"></p>
    </div>
    <div style="display:none" class="cartao"><strong>Avisos:</strong><ul id="lote-mensagens"></ul></div>
  </form>
</div>

<div class="toolbar">
  <form method="get">
    <label>Ano
      <select name="ano"><?php foreach ($anos as $a): ?><option value="<?= (int)$a ?>" <?= (int)$a === $ano ? 'selected' : '' ?>><?= (int)$a ?></option><?php endforeach; ?></select>
    </label>
    <label>Status
      <select name="f">
        <option value="todas">Todas</option>
        <?php foreach ($rotulosStatus as $v => $r): ?><option value="<?= $v ?>" <?= $filtro === $v ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="botao">Filtrar</button>
  </form>
  <a class="botao" href="cobrancas.php?ano=<?= $ano ?>&f=<?= e(urlencode($filtro)) ?>&csv=1">Exportar CSV</a>
  <a class="botao" href="cobrancas.php?ano=<?= $ano ?>&f=<?= e(urlencode($filtro)) ?>&imprimir=1">Imprimir lista</a>
</div>

<?php if (!$lista): ?>
  <div class="cartao vazio">
    <strong>Nenhuma cobrança em <?= $ano ?></strong>
    Use "Gerar cobrança anual em lote" acima para criar as cobranças do ano.
  </div>
<?php else: ?>
  <div class="tabela-envolve tabela-cards">
    <table class="tabela">
      <thead><tr><th>Associado</th><th>Descrição</th><th>Valor</th><th>Vencimento</th><th>Status</th><th>Ações</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $c): $devido = valor_devido($c); ?>
        <tr>
          <td data-rotulo="Associado"><?= e($c['nome']) ?> <span class="texto-suave"><?= e($c['indicativo'] ?: '') ?></span></td>
          <td data-rotulo="Descrição"><?= e($c['descricao']) ?></td>
          <td data-rotulo="Valor">
            <?= e(fmt_moeda((float)$c['valor'])) ?>
            <?php if ($c['status'] !== 'pago' && $c['status'] !== 'cancelada' && abs($devido['valor'] - (float)$c['valor']) > 0.005): ?>
              <br><span class="texto-suave">hoje: <?= e(fmt_moeda($devido['valor'])) ?><?= $devido['fase'] === 'atraso' ? ' (c/ multa e juros)' : ' (c/ desconto)' ?></span>
            <?php endif; ?>
            <?php if ($c['status'] === 'pago'): ?><br><span class="texto-suave">pago: <?= e(fmt_moeda((float)$c['valor_pago'])) ?></span><?php endif; ?>
          </td>
          <td data-rotulo="Vencimento"><?= e(fmt_data($c['vencimento'])) ?></td>
          <td data-rotulo="Status"><span class="selo selo-<?= e($c['status']) ?>"><?= e($rotulosStatus[$c['status']]) ?></span>
            <?php if ($c['status'] === 'pago' && $c['pago_manual']): ?><span class="texto-suave">(manual)</span><?php endif; ?>
          </td>
          <td class="acoes">
            <?php if (in_array($c['status'], ['pendente', 'vencida'], true)): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="reenviar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="botao botao-mini" <?= $c['email'] ? '' : 'disabled title="Associado sem email"' ?>>Reenviar</button></form>
              <form method="post" data-confirmar="Registrar pagamento manual desta cobrança (valor devido hoje: <?= e(fmt_moeda($devido['valor'])) ?>)?">
                <?= csrf_field() ?><input type="hidden" name="acao" value="pagar_manual"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="botao botao-mini botao-verde">Baixa manual</button></form>
              <form method="post" data-confirmar="Cancelar esta cobrança?">
                <?= csrf_field() ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="botao botao-mini botao-perigo">Cancelar</button></form>
            <?php elseif ($c['status'] === 'pago'): ?>
              <a class="botao botao-mini" href="comprovante.php?c=<?= (int)$c['id'] ?>&t=<?= e($c['token']) ?>" target="_blank" rel="noopener">Comprovante</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php page_footer(); ?>
