<?php
/**
 * Aplica migrações de banco pendentes (app/sql/migrations/*.sql).
 * Via painel (login) ou CLI: php public/migrar.php
 */

require __DIR__ . '/../app/bootstrap.php';

$viaCli = PHP_SAPI === 'cli';
if (!$viaCli) {
    require APP_DIR . '/auth.php';
    require APP_DIR . '/layout.php';
    $user = require_login();
}

$aplicadas = db()->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$arquivos = glob(APP_DIR . '/sql/migrations/*.sql') ?: [];
sort($arquivos);
$pendentes = array_filter($arquivos, fn($a) => !in_array(basename($a), $aplicadas, true));

$resultado = [];
$executar = $viaCli;
if (!$viaCli && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $executar = true;
}

if ($executar && $pendentes) {
    foreach ($pendentes as $arq) {
        $nome = basename($arq);
        try {
            db()->exec(file_get_contents($arq));
            db()->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$nome]);
            $resultado[] = "OK: {$nome}";
        } catch (Throwable $ex) {
            $resultado[] = "ERRO em {$nome}: " . $ex->getMessage();
            break; // não continua após falha
        }
    }
    if (!$viaCli) audit('migracoes_aplicadas', null, null, implode(' | ', $resultado));
}

if ($viaCli) {
    echo $pendentes ? implode("\n", $resultado) . "\n" : "Nenhuma migração pendente.\n";
    exit;
}

page_header('Migrações de banco', 'configuracoes.php', $user);
?>
<div class="cartao">
  <?php if ($resultado): ?>
    <h2 style="margin-top:0">Resultado</h2>
    <ul><?php foreach ($resultado as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul>
  <?php elseif (!$pendentes): ?>
    <p>Nenhuma migração pendente — o banco está atualizado.</p>
  <?php else: ?>
    <h2 style="margin-top:0"><?= count($pendentes) ?> migração(ões) pendente(s)</h2>
    <ul><?php foreach ($pendentes as $p): ?><li><?= e(basename($p)) ?></li><?php endforeach; ?></ul>
    <form method="post" data-confirmar="Aplicar as migrações agora? Faça backup antes se estiver em produção.">
      <?= csrf_field() ?>
      <button type="submit" class="botao botao-primario">Aplicar migrações</button>
    </form>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
