<?php
/**
 * Instalador do LABRE-Pay: testa o banco, gera app/config.php, cria o schema
 * e o primeiro administrador. Após concluir, cria app/install.lock e se recusa
 * a rodar de novo.
 */

$appDir = dirname(__DIR__) . '/app';

if (file_exists($appDir . '/install.lock')) {
    http_response_code(403);
    exit('O sistema já está instalado. Para reinstalar, remova o arquivo app/install.lock manualmente.');
}

$erros = [];
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $env = in_array($_POST['env'] ?? '', ['production', 'homolog', 'dev'], true) ? $_POST['env'] : 'dev';
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $baseUrl = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $mailOverride = trim($_POST['mail_override'] ?? '');
    $admNome = trim($_POST['adm_nome'] ?? '');
    $admEmail = trim($_POST['adm_email'] ?? '');
    $admSenha = (string)($_POST['adm_senha'] ?? '');

    if ($dbName === '' || $dbUser === '') $erros[] = 'Informe o banco de dados e o usuário.';
    if ($baseUrl === '' || !preg_match('#^https?://#', $baseUrl)) $erros[] = 'Informe a URL base começando com http:// ou https://.';
    if ($env === 'production' && !str_starts_with($baseUrl, 'https://')) $erros[] = 'Em produção a URL base deve usar https://.';
    if ($env !== 'production' && !filter_var($mailOverride, FILTER_VALIDATE_EMAIL)) $erros[] = 'Em ambiente de testes é obrigatório informar o email dummy que receberá todos os envios.';
    if ($admNome === '' || !filter_var($admEmail, FILTER_VALIDATE_EMAIL)) $erros[] = 'Informe nome e email válidos para o administrador.';
    if (strlen($admSenha) < 10) $erros[] = 'A senha do administrador deve ter pelo menos 10 caracteres.';

    $pdo = null;
    if (!$erros) {
        try {
            $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $ex) {
            $erros[] = 'Não foi possível conectar ao banco: ' . $ex->getMessage();
        }
    }

    if (!$erros && $pdo) {
        $secret = bin2hex(random_bytes(32));
        $config = "<?php\n"
            . "// Gerado pelo instalador em " . date('d/m/Y H:i') . " — NÃO versionar este arquivo.\n"
            . "define('APP_ENV', " . var_export($env, true) . ");\n"
            . "define('DB_HOST', " . var_export($dbHost, true) . ");\n"
            . "define('DB_NAME', " . var_export($dbName, true) . ");\n"
            . "define('DB_USER', " . var_export($dbUser, true) . ");\n"
            . "define('DB_PASS', " . var_export($dbPass, true) . ");\n"
            . "define('BASE_URL', " . var_export($baseUrl, true) . ");\n"
            . "define('MAIL_OVERRIDE_TO', " . var_export($env === 'production' ? '' : $mailOverride, true) . ");\n"
            . "define('APP_SECRET', " . var_export($secret, true) . ");\n";

        if (@file_put_contents($appDir . '/config.php', $config) === false) {
            $erros[] = 'Sem permissão para gravar app/config.php. Ajuste as permissões da pasta app/.';
        } else {
            try {
                $sql = file_get_contents($appDir . '/sql/schema.sql');
                $pdo->exec($sql);
                $st = $pdo->prepare('INSERT INTO users (nome, email, senha_hash) VALUES (?,?,?)');
                $st->execute([$admNome, $admEmail, password_hash($admSenha, PASSWORD_DEFAULT)]);
                $pdo->prepare("INSERT INTO settings (k, v) VALUES ('cron_token', ?) ON DUPLICATE KEY UPDATE v = IF(v = '', VALUES(v), v)")
                    ->execute([bin2hex(random_bytes(24))]);
                // Registra as migrações já contempladas pelo schema base
                foreach (glob($appDir . '/sql/migrations/*.sql') ?: [] as $mig) {
                    $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)')->execute([basename($mig)]);
                }
                file_put_contents($appDir . '/install.lock', date('c'));
                $ok = true;
            } catch (Throwable $ex) {
                @unlink($appDir . '/config.php');
                $erros[] = 'Falha ao criar as tabelas: ' . $ex->getMessage();
            }
        }
    }
}

function ev(string $k, string $def = ''): string
{
    return htmlspecialchars((string)($_POST[$k] ?? $def), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalação — LABRE-Pay</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="publica">
<main class="publica-caixa">
  <header class="publica-topo"><div class="brand">LABRE<span>Pay</span></div><p>Instalação do sistema</p></header>

  <?php if ($ok): ?>
    <div class="flash flash-ok">Instalação concluída com sucesso!</div>
    <div class="cartao">
      <p>O sistema está pronto. Próximos passos:</p>
      <ol>
        <li>Faça login com o administrador criado;</li>
        <li>Preencha as <strong>Configurações</strong> (entidade, valores, MercadoPago e SMTP);</li>
        <li>Configure o <strong>cron</strong> no cPanel (instruções no README);</li>
        <li>Importe a planilha de associados.</li>
      </ol>
      <p><a class="botao botao-primario" href="index.php">Ir para o login</a></p>
    </div>
  <?php else: ?>
    <?php foreach ($erros as $e): ?><div class="flash flash-erro"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    <form method="post" class="cartao form-grid">
      <h2>1. Ambiente</h2>
      <label>Tipo de ambiente
        <select name="env">
          <option value="dev" <?= ($_POST['env'] ?? 'dev') === 'dev' ? 'selected' : '' ?>>Desenvolvimento</option>
          <option value="homolog" <?= ($_POST['env'] ?? '') === 'homolog' ? 'selected' : '' ?>>Homologação / testes</option>
          <option value="production" <?= ($_POST['env'] ?? '') === 'production' ? 'selected' : '' ?>>Produção</option>
        </select>
      </label>
      <label>URL base do sistema
        <input type="url" name="base_url" placeholder="https://pay.labre-sc.org.br" value="<?= ev('base_url') ?>" required>
      </label>
      <label>Email dummy p/ testes (obrigatório fora de produção)
        <input type="email" name="mail_override" placeholder="testes@suaentidade.org.br" value="<?= ev('mail_override') ?>">
      </label>

      <h2>2. Banco de dados MySQL</h2>
      <label>Servidor <input type="text" name="db_host" value="<?= ev('db_host', 'localhost') ?>" required></label>
      <label>Banco <input type="text" name="db_name" value="<?= ev('db_name') ?>" required></label>
      <label>Usuário <input type="text" name="db_user" value="<?= ev('db_user') ?>" required></label>
      <label>Senha <input type="password" name="db_pass" autocomplete="new-password"></label>

      <h2>3. Primeiro administrador</h2>
      <label>Nome <input type="text" name="adm_nome" value="<?= ev('adm_nome') ?>" required></label>
      <label>Email <input type="email" name="adm_email" value="<?= ev('adm_email') ?>" required></label>
      <label>Senha (mínimo 10 caracteres) <input type="password" name="adm_senha" minlength="10" autocomplete="new-password" required></label>

      <button type="submit" class="botao botao-primario">Instalar</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
