<?php
/** Importação de associados via planilha XLSX, XLS ou CSV com mapeamento e prévia. */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/layout.php';

$user = require_login();

const CAMPOS_IMPORT = [
    '' => '— ignorar coluna —',
    'nome' => 'Nome',
    'indicativo' => 'Indicativo',
    'email' => 'Email',
    'cpf_cnpj' => 'CPF/CNPJ',
    'telefone' => 'Telefone',
    'cidade' => 'Cidade',
    'uf' => 'UF',
    'data_adesao' => 'Data de adesão',
    'obs' => 'Observações',
];

/** Lê a planilha enviada e devolve matriz de linhas (ou lança RuntimeException). */
function ler_planilha(string $tmp, string $nomeOriginal): array
{
    $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') {
        $conteudo = file_get_contents($tmp);
        // Detecta UTF-8 x Latin-1 e ; x ,
        if (!mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        $sep = substr_count($conteudo, ';') >= substr_count($conteudo, ',') ? ';' : ',';
        $linhas = [];
        foreach (preg_split('/\r\n|\r|\n/', $conteudo) as $l) {
            if (trim($l) === '') continue;
            $linhas[] = str_getcsv($l, $sep);
        }
        return $linhas;
    }
    if ($ext === 'xlsx') {
        require_once APP_DIR . '/lib/SimpleXLSX.php';
        $x = \Shuchkin\SimpleXLSX::parse($tmp);
        if (!$x) throw new RuntimeException('Não foi possível ler o XLSX: ' . \Shuchkin\SimpleXLSX::parseError());
        return $x->rows();
    }
    if ($ext === 'xls') {
        require_once APP_DIR . '/lib/SimpleXLS.php';
        $x = \Shuchkin\SimpleXLS::parse($tmp);
        if (!$x) throw new RuntimeException('Não foi possível ler o XLS: ' . \Shuchkin\SimpleXLS::parseError());
        return $x->rows();
    }
    throw new RuntimeException('Formato não suportado. Envie um arquivo .xlsx, .xls ou .csv.');
}

function normalizar_data(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $v, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

$etapa = 'upload';
$linhas = [];
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Etapa 1 → 2: upload e mapeamento
    if (($_POST['etapa'] ?? '') === 'upload' && !empty($_FILES['planilha']['tmp_name'])) {
        try {
            $linhas = ler_planilha($_FILES['planilha']['tmp_name'], $_FILES['planilha']['name']);
            if (count($linhas) < 2) throw new RuntimeException('A planilha precisa ter cabeçalho e pelo menos uma linha de dados.');
            // Guarda fora do docroot para a etapa de confirmação
            $token = bin2hex(random_bytes(12));
            file_put_contents(APP_DIR . '/import-' . $token . '.json', json_encode($linhas, JSON_UNESCAPED_UNICODE));
            $_SESSION['import_token'] = $token;
            $etapa = 'mapear';
        } catch (RuntimeException $ex) {
            $erro = $ex->getMessage();
        }
    }

    // Etapa 2 → 3: importa com o mapeamento escolhido
    if (($_POST['etapa'] ?? '') === 'importar') {
        $token = $_SESSION['import_token'] ?? '';
        $arq = APP_DIR . '/import-' . preg_replace('/[^a-f0-9]/', '', $token) . '.json';
        if (!$token || !file_exists($arq)) {
            $erro = 'Sessão de importação expirou. Envie a planilha novamente.';
        } else {
            $linhas = json_decode(file_get_contents($arq), true);
            unlink($arq);
            unset($_SESSION['import_token']);

            $mapa = [];
            foreach ((array)($_POST['mapa'] ?? []) as $col => $campo) {
                if ($campo !== '' && array_key_exists($campo, CAMPOS_IMPORT)) $mapa[(int)$col] = $campo;
            }
            if (!in_array('nome', $mapa, true)) {
                $erro = 'Mapeie ao menos a coluna Nome.';
            } else {
                $inseridos = $atualizados = $pulados = 0;
                $avisos = [];
                $pdo = db();
                foreach (array_slice($linhas, 1) as $i => $linha) {
                    $reg = ['classe' => 'contribuinte'];
                    foreach ($mapa as $col => $campo) {
                        $reg[$campo] = trim((string)($linha[$col] ?? ''));
                    }
                    $reg['nome'] = $reg['nome'] ?? '';
                    if ($reg['nome'] === '') { $pulados++; continue; }
                    if (!empty($reg['email']) && !filter_var($reg['email'], FILTER_VALIDATE_EMAIL)) {
                        $avisos[] = 'Linha ' . ($i + 2) . ': email inválido (' . $reg['email'] . ') — importado sem email.';
                        $reg['email'] = '';
                    }
                    if (isset($reg['cpf_cnpj'])) $reg['cpf_cnpj'] = so_digitos($reg['cpf_cnpj']);
                    if (isset($reg['indicativo'])) $reg['indicativo'] = strtoupper($reg['indicativo']);
                    if (isset($reg['data_adesao'])) $reg['data_adesao'] = normalizar_data($reg['data_adesao']);
                    if (isset($reg['uf'])) $reg['uf'] = strtoupper(substr($reg['uf'], 0, 2)) ?: null;

                    // Dedupe: indicativo > cpf > email
                    $existente = null;
                    foreach (['indicativo', 'cpf_cnpj', 'email'] as $chave) {
                        if (!empty($reg[$chave])) {
                            $st = $pdo->prepare("SELECT id FROM members WHERE {$chave} = ? LIMIT 1");
                            $st->execute([$reg[$chave]]);
                            $existente = $st->fetchColumn();
                            if ($existente) break;
                        }
                    }
                    $campos = array_intersect_key($reg, CAMPOS_IMPORT);
                    $campos = array_filter($campos, fn($v) => $v !== '' && $v !== null);
                    if ($existente) {
                        if (!empty($_POST['atualizar_existentes'])) {
                            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
                            $pdo->prepare("UPDATE members SET {$sets} WHERE id = ?")->execute([...array_values($campos), $existente]);
                            $atualizados++;
                        } else {
                            $pulados++;
                        }
                    } else {
                        $cols = implode(', ', array_keys($campos));
                        $marks = implode(', ', array_fill(0, count($campos), '?'));
                        $pdo->prepare("INSERT INTO members ({$cols}) VALUES ({$marks})")->execute(array_values($campos));
                        $inseridos++;
                    }
                }
                audit('importacao', 'member', null, "inseridos={$inseridos} atualizados={$atualizados} pulados={$pulados}");
                flash_set('ok', "Importação concluída: {$inseridos} cadastrado(s), {$atualizados} atualizado(s), {$pulados} pulado(s).");
                foreach (array_slice($avisos, 0, 10) as $a) flash_set('erro', $a);
                redirect('associados.php');
            }
        }
    }
}

page_header('Importar planilha', 'importar.php', $user);
?>

<?php if ($erro): ?><div class="flash flash-erro"><?= e($erro) ?></div><?php endif; ?>

<?php if ($etapa === 'upload'): ?>
  <form method="post" enctype="multipart/form-data" class="cartao form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="etapa" value="upload">
    <h2>1. Enviar a planilha</h2>
    <p>Envie a lista de associados em <strong>.xlsx</strong>, <strong>.xls</strong> ou <strong>.csv</strong>.
       A primeira linha deve ser o cabeçalho (títulos das colunas). Na próxima etapa você indica
       qual coluna corresponde a cada campo — nada é gravado antes da sua confirmação.</p>
    <label>Arquivo <input type="file" name="planilha" accept=".xlsx,.xls,.csv" required></label>
    <button type="submit" class="botao botao-primario">Continuar</button>
  </form>

<?php elseif ($etapa === 'mapear'):
    $cabecalho = $linhas[0];
    $previa = array_slice($linhas, 1, 5);
?>
  <form method="post" class="cartao form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="etapa" value="importar">
    <h2>2. Conferir e mapear as colunas</h2>
    <p><?= count($linhas) - 1 ?> linha(s) de dados encontradas. Indique o que é cada coluna:</p>
    <div class="tabela-envolve">
      <table class="tabela">
        <thead>
          <tr>
            <?php foreach ($cabecalho as $col => $titulo): ?>
              <th>
                <?= e((string)$titulo) ?><br>
                <select name="mapa[<?= (int)$col ?>]">
                  <?php
                    // pré-seleção esperta pelo título da coluna
                    $t = mb_strtolower((string)$titulo);
                    $palpite = '';
                    foreach (['nome' => 'nome', 'indicativo' => 'indicativo', 'call' => 'indicativo', 'mail' => 'email',
                              'cpf' => 'cpf_cnpj', 'cnpj' => 'cpf_cnpj', 'fone' => 'telefone', 'telefone' => 'telefone', 'celular' => 'telefone',
                              'cidade' => 'cidade', 'municip' => 'cidade', 'uf' => 'uf', 'estado' => 'uf',
                              'ades' => 'data_adesao', 'entrada' => 'data_adesao', 'obs' => 'obs'] as $chave => $campo) {
                        if (str_contains($t, $chave)) { $palpite = $campo; break; }
                    }
                  ?>
                  <?php foreach (CAMPOS_IMPORT as $v => $r): ?>
                    <option value="<?= $v ?>" <?= $v === $palpite ? 'selected' : '' ?>><?= $r ?></option>
                  <?php endforeach; ?>
                </select>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($previa as $l): ?>
            <tr><?php foreach ($cabecalho as $col => $_): ?><td><?= e((string)($l[$col] ?? '')) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <label style="flex-direction:row;align-items:center;gap:.6rem">
      <input type="checkbox" name="atualizar_existentes" value="1" style="width:auto;min-height:auto">
      Atualizar dados de associados que já existem (identificados por indicativo, CPF ou email)
    </label>
    <div style="display:flex;gap:.7rem">
      <button type="submit" class="botao botao-primario">Importar agora</button>
      <a class="botao" href="importar.php">Cancelar</a>
    </div>
  </form>
<?php endif; ?>

<?php page_footer(); ?>
