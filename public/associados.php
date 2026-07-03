<?php
/** Associados: listagem, cadastro, edição, desligamento, readmissão e LGPD. */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/layout.php';
require APP_DIR . '/cobranca.php';

$user = require_login();

const MOTIVOS_DESLIGAMENTO = [
    'a_pedido' => 'A pedido do associado',
    'falecimento' => 'Falecimento',
    'exclusao_administrativa' => 'Exclusão administrativa',
    'inadimplencia' => 'Inadimplência (Art. 40)',
];
const CATEGORIAS = [
    'efetivo' => 'Efetivo', 'juvenil' => 'Juvenil', 'benemerito' => 'Benemérito',
    'correspondente' => 'Correspondente', 'agremiacao' => 'Agremiação associada',
];
const CLASSES = ['contribuinte' => 'Contribuinte', 'isento' => 'Isento', 'remido' => 'Remido'];

/* ---------- Ações POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($acao === 'salvar') {
        $dados = [
            'nome' => trim($_POST['nome'] ?? ''),
            'indicativo' => strtoupper(trim($_POST['indicativo'] ?? '')) ?: null,
            'email' => trim($_POST['email'] ?? '') ?: null,
            'cpf_cnpj' => so_digitos($_POST['cpf_cnpj'] ?? '') ?: null,
            'telefone' => trim($_POST['telefone'] ?? '') ?: null,
            'cidade' => trim($_POST['cidade'] ?? '') ?: null,
            'uf' => strtoupper(trim($_POST['uf'] ?? '')) ?: null,
            'categoria' => array_key_exists($_POST['categoria'] ?? '', CATEGORIAS) ? $_POST['categoria'] : 'efetivo',
            'classe' => array_key_exists($_POST['classe'] ?? '', CLASSES) ? $_POST['classe'] : 'contribuinte',
            'data_adesao' => $_POST['data_adesao'] ?: date('Y-m-d'),
            'obs' => trim($_POST['obs'] ?? '') ?: null,
        ];
        if ($dados['nome'] === '') {
            flash_set('erro', 'O nome é obrigatório.');
            redirect($id ? "associados.php?editar={$id}" : 'associados.php?novo=1');
        }
        if ($dados['email'] !== null && !filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            flash_set('erro', 'Email inválido.');
            redirect($id ? "associados.php?editar={$id}" : 'associados.php?novo=1');
        }

        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($dados)));
            $st = db()->prepare("UPDATE members SET {$sets} WHERE id = ?");
            $st->execute([...array_values($dados), $id]);
            audit('associado_editado', 'member', $id);
            flash_set('ok', 'Associado atualizado.');
        } else {
            $cols = implode(', ', array_keys($dados));
            $marks = implode(', ', array_fill(0, count($dados), '?'));
            $st = db()->prepare("INSERT INTO members ({$cols}) VALUES ({$marks})");
            $st->execute(array_values($dados));
            $id = (int)db()->lastInsertId();
            audit('associado_criado', 'member', $id);
            flash_set('ok', 'Associado cadastrado.');

            // Primeira cobrança pro-rata (opcional, só para contribuintes)
            if (!empty($_POST['gerar_cobranca']) && $dados['classe'] === 'contribuinte') {
                $pr = calcular_prorata($dados['data_adesao']);
                $valor = $_POST['valor_primeira'] !== '' ? (float)str_replace(',', '.', $_POST['valor_primeira']) : $pr['valor'];
                if (setting('taxa_admissao_ativa') === '1') {
                    $valor += (float)setting('taxa_admissao_valor', '0');
                }
                $vencCobranca = min(date('Y-m-d', strtotime('+30 days')), $pr['vencimento']);
                $charge = charge_criar($id, $pr['ano'], "Anuidade {$pr['ano']} (adesão proporcional — {$pr['meses']} meses)", $valor, $vencCobranca, true);
                $member = member_get($id);
                [$okEnv, $msg] = charge_enviar($charge, $member);
                flash_set($okEnv ? 'ok' : 'erro', 'Cobrança de adesão de ' . fmt_moeda($valor) . ' gerada. ' . $msg);
            }
        }
        redirect('associados.php');
    }

    if ($acao === 'desligar' && $id) {
        $motivo = array_key_exists($_POST['motivo'] ?? '', MOTIVOS_DESLIGAMENTO) ? $_POST['motivo'] : 'a_pedido';
        db()->prepare("UPDATE members SET status = 'desligado', motivo_desligamento = ?, desligado_em = CURDATE() WHERE id = ?")
            ->execute([$motivo, $id]);
        db()->prepare("UPDATE charges SET status = 'cancelada' WHERE member_id = ? AND status IN ('pendente','vencida')")
            ->execute([$id]);
        audit('associado_desligado', 'member', $id, 'motivo=' . $motivo);
        flash_set('ok', 'Associado desligado. As cobranças em aberto foram canceladas e o histórico foi preservado.');
        redirect('associados.php');
    }

    if ($acao === 'readmitir' && $id) {
        $member = member_get($id);
        if ($member && $member['status'] === 'desligado' && !$member['anonimizado']) {
            db()->prepare("UPDATE members SET status = 'ativo', motivo_desligamento = NULL, desligado_em = NULL, readmitido_em = CURDATE() WHERE id = ?")
                ->execute([$id]);
            audit('associado_readmitido', 'member', $id);
            $msgExtra = '';
            if ($member['classe'] === 'contribuinte') {
                $pr = calcular_prorata(date('Y-m-d'));
                $valor = $pr['valor'];
                if (setting('taxa_retorno_ativa') === '1') {
                    $valor += (float)setting('taxa_retorno_valor', '0');
                    $msgExtra = ' (incluída taxa de retorno de ' . fmt_moeda((float)setting('taxa_retorno_valor')) . ')';
                }
                $vencCobranca = min(date('Y-m-d', strtotime('+30 days')), $pr['vencimento']);
                $charge = charge_criar($id, $pr['ano'], "Anuidade {$pr['ano']} (readmissão proporcional — {$pr['meses']} meses)", $valor, $vencCobranca, true);
                [$okEnv, $msg] = charge_enviar($charge);
                flash_set($okEnv ? 'ok' : 'erro', 'Associado readmitido. Cobrança de ' . fmt_moeda($valor) . $msgExtra . ' gerada. ' . $msg);
            } else {
                flash_set('ok', 'Associado readmitido (classe ' . CLASSES[$member['classe']] . ', sem cobrança).');
            }
        }
        redirect('associados.php');
    }

    if ($acao === 'anonimizar' && $id) {
        $member = member_get($id);
        if ($member && $member['status'] === 'desligado') {
            db()->prepare("UPDATE members SET nome = ?, indicativo = NULL, email = NULL, cpf_cnpj = NULL, telefone = NULL, cidade = NULL, uf = NULL, obs = NULL, anonimizado = 1 WHERE id = ?")
                ->execute(['Associado anonimizado nº ' . $id, $id]);
            audit('associado_anonimizado', 'member', $id, 'LGPD: dados pessoais removidos, histórico financeiro preservado');
            flash_set('ok', 'Dados pessoais removidos (LGPD). O histórico financeiro foi mantido de forma anônima.');
        } else {
            flash_set('erro', 'Só é possível anonimizar associados já desligados.');
        }
        redirect('associados.php');
    }
}

/* ---------- Exportação LGPD (portabilidade) ---------- */
if (isset($_GET['exportar'])) {
    $m = member_get((int)$_GET['exportar']);
    if ($m) {
        audit('associado_exportado', 'member', (int)$m['id'], 'LGPD: portabilidade');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="associado-' . $m['id'] . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM p/ Excel
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Campo', 'Valor'], ';');
        foreach (['nome','indicativo','email','cpf_cnpj','telefone','cidade','uf','categoria','classe','status','data_adesao','desligado_em','readmitido_em','obs'] as $campo) {
            fputcsv($out, [$campo, (string)$m[$campo]], ';');
        }
        fputcsv($out, [], ';');
        fputcsv($out, ['Ano', 'Descrição', 'Valor', 'Vencimento', 'Status', 'Pago em', 'Valor pago'], ';');
        $st = db()->prepare('SELECT * FROM charges WHERE member_id = ? ORDER BY ano');
        $st->execute([$m['id']]);
        foreach ($st as $c) {
            fputcsv($out, [$c['ano'], $c['descricao'], $c['valor'], $c['vencimento'], $c['status'], $c['pago_em'], $c['valor_pago']], ';');
        }
        exit;
    }
}

/* ---------- Formulário (novo/editar) ---------- */
$editando = null;
if (isset($_GET['editar'])) {
    $editando = member_get((int)$_GET['editar']);
}
$mostraForm = $editando || isset($_GET['novo']);

/* ---------- Listagem ---------- */
$busca = trim($_GET['busca'] ?? '');
$filtroStatus = $_GET['status'] ?? 'ativo';
$modoRelatorio = isset($_GET['csv']) || isset($_GET['imprimir']);
$sql = 'SELECT * FROM members WHERE 1=1';
$params = [];
if ($filtroStatus === 'ativo' || $filtroStatus === 'desligado') {
    $sql .= ' AND status = ?';
    $params[] = $filtroStatus;
}
if ($busca !== '') {
    $sql .= ' AND (nome LIKE ? OR indicativo LIKE ? OR email LIKE ? OR cpf_cnpj LIKE ?)';
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, so_digitos($busca) !== '' ? '%' . so_digitos($busca) . '%' : $like);
}
$sql .= ' ORDER BY nome LIMIT ' . ($modoRelatorio ? 10000 : 500);
$st = db()->prepare($sql);
$st->execute($params);
$lista = $st->fetchAll();

$rotuloFiltro = ($filtroStatus === 'todos' ? 'Todos' : ($filtroStatus === 'desligado' ? 'Desligados' : 'Ativos'))
    . ($busca !== '' ? ' · busca: "' . $busca . '"' : '');

/* ---------- Exportação CSV da lista ---------- */
if (isset($_GET['csv'])) {
    audit('associados_exportados', null, null, $rotuloFiltro);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="associados-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Nome', 'Indicativo', 'Email', 'CPF/CNPJ', 'Telefone', 'Cidade', 'UF', 'Categoria', 'Classe', 'Situação', 'Data de adesão'], ';');
    foreach ($lista as $m) {
        fputcsv($out, [
            $m['nome'], $m['indicativo'], $m['email'], $m['cpf_cnpj'], $m['telefone'],
            $m['cidade'], $m['uf'], CATEGORIAS[$m['categoria']] ?? $m['categoria'],
            CLASSES[$m['classe']] ?? $m['classe'],
            $m['status'] === 'ativo' ? 'Ativo' : 'Desligado',
            $m['data_adesao'] ? date('d/m/Y', strtotime($m['data_adesao'])) : '',
        ], ';');
    }
    exit;
}

/* ---------- Relatório imprimível ---------- */
if (isset($_GET['imprimir'])) {
    $voltar = 'associados.php?status=' . urlencode($filtroStatus) . '&busca=' . urlencode($busca);
    report_header('Lista de associados', 'Filtro: ' . $rotuloFiltro . ' · ' . count($lista) . ' associado(s)', $voltar);
    echo '<table class="rel-tabela"><thead><tr><th>#</th><th>Nome</th><th>Indicativo</th><th>Email</th><th>Telefone</th><th>Cidade/UF</th><th>Classe</th><th>Situação</th><th>Adesão</th></tr></thead><tbody>';
    foreach ($lista as $i => $m) {
        echo '<tr><td class="num">' . ($i + 1) . '</td>';
        echo '<td>' . e($m['nome']) . '</td>';
        echo '<td>' . e($m['indicativo'] ?: '—') . '</td>';
        echo '<td>' . e($m['email'] ?: '—') . '</td>';
        echo '<td>' . e($m['telefone'] ?: '—') . '</td>';
        echo '<td>' . e(trim(($m['cidade'] ?? '') . '/' . ($m['uf'] ?? ''), '/') ?: '—') . '</td>';
        echo '<td>' . e(CLASSES[$m['classe']] ?? $m['classe']) . '</td>';
        echo '<td>' . ($m['status'] === 'ativo' ? 'Ativo' : 'Desligado') . '</td>';
        echo '<td>' . e(fmt_data($m['data_adesao'])) . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><td colspan="9">Total: ' . count($lista) . ' associado(s)</td></tr></tfoot></table>';
    report_footer();
    exit;
}

page_header('Associados', 'associados.php', $user);
?>

<?php if ($mostraForm): $m = $editando ?: []; ?>
  <form method="post" class="cartao form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= (int)($m['id'] ?? 0) ?>">
    <h2><?= $editando ? 'Editar associado' : 'Novo associado' ?></h2>
    <div class="linha-campos">
      <label>Nome completo <input type="text" name="nome" value="<?= e($m['nome'] ?? '') ?>" required></label>
      <label>Indicativo <input type="text" name="indicativo" value="<?= e($m['indicativo'] ?? '') ?>" placeholder="PP5XXX"></label>
    </div>
    <div class="linha-campos">
      <label>Email <input type="email" name="email" value="<?= e($m['email'] ?? '') ?>"></label>
      <label>CPF ou CNPJ <input type="text" name="cpf_cnpj" value="<?= e($m['cpf_cnpj'] ?? '') ?>"></label>
      <label>Telefone <input type="tel" name="telefone" value="<?= e($m['telefone'] ?? '') ?>"></label>
    </div>
    <div class="linha-campos">
      <label>Cidade <input type="text" name="cidade" value="<?= e($m['cidade'] ?? '') ?>"></label>
      <label>UF <input type="text" name="uf" maxlength="2" value="<?= e($m['uf'] ?? 'SC') ?>"></label>
      <label>Data de adesão <input type="date" name="data_adesao" value="<?= e($m['data_adesao'] ?? date('Y-m-d')) ?>"></label>
    </div>
    <div class="linha-campos">
      <label>Categoria
        <select name="categoria">
          <?php foreach (CATEGORIAS as $v => $r): ?>
            <option value="<?= $v ?>" <?= ($m['categoria'] ?? 'efetivo') === $v ? 'selected' : '' ?>><?= $r ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Classe
        <span class="dica">Isentos e remidos não recebem cobrança (Art. 26/30 do Estatuto)</span>
        <select name="classe">
          <?php foreach (CLASSES as $v => $r): ?>
            <option value="<?= $v ?>" <?= ($m['classe'] ?? 'contribuinte') === $v ? 'selected' : '' ?>><?= $r ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>Observações <textarea name="obs"><?= e($m['obs'] ?? '') ?></textarea></label>

    <?php if (!$editando): ?>
      <div class="cartao" style="background:#f6f9fc">
        <label style="flex-direction:row;align-items:center;gap:.6rem">
          <input type="checkbox" name="gerar_cobranca" value="1" checked style="width:auto;min-height:auto">
          Gerar e enviar a primeira cobrança proporcional (pro-rata) agora
        </label>
        <label>Valor da primeira cobrança
          <span class="dica">Deixe em branco para o sistema calcular o proporcional até o próximo vencimento (anuidade atual: <?= e(fmt_moeda((float)setting('anuidade_valor'))) ?>)</span>
          <input type="text" name="valor_primeira" value="" placeholder="automático" inputmode="decimal">
        </label>
      </div>
    <?php endif; ?>

    <div style="display:flex;gap:.7rem;flex-wrap:wrap">
      <button type="submit" class="botao botao-primario">Salvar</button>
      <a class="botao" href="associados.php">Cancelar</a>
    </div>
  </form>

  <?php if ($editando): ?>
    <div class="cartao">
      <h2>Ações do associado</h2>
      <div style="display:flex;gap:.7rem;flex-wrap:wrap">
        <a class="botao" href="associados.php?exportar=<?= (int)$editando['id'] ?>">Exportar dados (LGPD)</a>
        <?php if ($editando['status'] === 'ativo'): ?>
          <form method="post" data-confirmar="Desligar este associado? As cobranças em aberto serão canceladas. O histórico será preservado." style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="desligar">
            <input type="hidden" name="id" value="<?= (int)$editando['id'] ?>">
            <label>Motivo do desligamento
              <select name="motivo">
                <?php foreach (MOTIVOS_DESLIGAMENTO as $v => $r): ?><option value="<?= $v ?>"><?= $r ?></option><?php endforeach; ?>
              </select>
            </label>
            <button type="submit" class="botao botao-perigo">Desligar associado</button>
          </form>
        <?php else: ?>
          <?php if (!$editando['anonimizado']): ?>
            <form method="post" data-confirmar="Readmitir este associado? Será gerada uma cobrança proporcional<?= setting('taxa_retorno_ativa') === '1' ? ' com taxa de retorno' : '' ?>.">
              <?= csrf_field() ?>
              <input type="hidden" name="acao" value="readmitir">
              <input type="hidden" name="id" value="<?= (int)$editando['id'] ?>">
              <button type="submit" class="botao botao-verde">Readmitir associado</button>
            </form>
            <form method="post" data-confirmar="ATENÇÃO: remover em definitivo os dados pessoais deste associado (LGPD)? O histórico financeiro fica anônimo. Esta ação NÃO pode ser desfeita.">
              <?= csrf_field() ?>
              <input type="hidden" name="acao" value="anonimizar">
              <input type="hidden" name="id" value="<?= (int)$editando['id'] ?>">
              <button type="submit" class="botao botao-perigo">Anonimizar (LGPD)</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php if ($editando['status'] === 'desligado'): ?>
        <p class="texto-suave">Desligado em <?= e(fmt_data($editando['desligado_em'])) ?> — motivo: <?= e(MOTIVOS_DESLIGAMENTO[$editando['motivo_desligamento']] ?? '—') ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php else: ?>
  <div class="toolbar">
    <form method="get">
      <label>Buscar
        <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Nome, indicativo, email ou CPF">
      </label>
      <label>Situação
        <select name="status">
          <option value="ativo" <?= $filtroStatus === 'ativo' ? 'selected' : '' ?>>Ativos</option>
          <option value="desligado" <?= $filtroStatus === 'desligado' ? 'selected' : '' ?>>Desligados</option>
          <option value="todos" <?= $filtroStatus === 'todos' ? 'selected' : '' ?>>Todos</option>
        </select>
      </label>
      <button type="submit" class="botao">Filtrar</button>
    </form>
    <a class="botao botao-primario" href="associados.php?novo=1">+ Novo associado</a>
    <a class="botao" href="associados.php?status=<?= e(urlencode($filtroStatus)) ?>&busca=<?= e(urlencode($busca)) ?>&csv=1">Exportar CSV</a>
    <a class="botao" href="associados.php?status=<?= e(urlencode($filtroStatus)) ?>&busca=<?= e(urlencode($busca)) ?>&imprimir=1">Imprimir lista</a>
  </div>

  <?php if (!$lista): ?>
    <div class="cartao vazio">
      <strong>Nenhum associado encontrado</strong>
      Cadastre um novo associado ou <a href="importar.php">importe a planilha</a>.
    </div>
  <?php else: ?>
    <div class="tabela-envolve tabela-cards">
      <table class="tabela">
        <thead><tr><th>Nome</th><th>Indicativo</th><th>Email</th><th>Classe</th><th>Situação</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($lista as $m): ?>
          <tr>
            <td data-rotulo="Nome"><?= e($m['nome']) ?></td>
            <td data-rotulo="Indicativo"><?= e($m['indicativo'] ?: '—') ?></td>
            <td data-rotulo="Email"><?= e($m['email'] ?: '—') ?></td>
            <td data-rotulo="Classe"><?= e(CLASSES[$m['classe']] ?? $m['classe']) ?></td>
            <td data-rotulo="Situação"><span class="selo selo-<?= e($m['status']) ?>"><?= $m['status'] === 'ativo' ? 'Ativo' : 'Desligado' ?></span></td>
            <td class="acoes"><a class="botao botao-mini" href="associados.php?editar=<?= (int)$m['id'] ?>">Abrir</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="texto-suave"><?= count($lista) ?> associado(s) listado(s)<?= count($lista) === 500 ? ' (limite de 500 — refine a busca)' : '' ?>.</p>
  <?php endif; ?>
<?php endif; ?>

<?php page_footer(); ?>
