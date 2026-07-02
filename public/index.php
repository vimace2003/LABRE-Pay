<?php
/** Login do painel administrativo. */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/layout.php';

session_start_secure();
if (auth_user()) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (auth_login(trim($_POST['email'] ?? ''), (string)($_POST['senha'] ?? ''))) {
        redirect('dashboard.php');
    }
    redirect('index.php');
}

public_header('Acesso ao painel');
?>
<form method="post" class="cartao form-grid" autocomplete="on">
  <?= csrf_field() ?>
  <h2>Acesso da diretoria</h2>
  <label>Email
    <input type="email" name="email" required autofocus autocomplete="username">
  </label>
  <label>Senha
    <input type="password" name="senha" required autocomplete="current-password">
  </label>
  <button type="submit" class="botao botao-primario">Entrar</button>
  <p class="texto-suave">Associado? Você não precisa de senha — <a href="consulta.php">consulte sua anuidade aqui</a>.</p>
</form>
<?php public_footer(); ?>
