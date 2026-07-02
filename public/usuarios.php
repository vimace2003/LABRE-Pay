<?php
/** Gestão de usuários administradores. */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/layout.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($acao === 'criar') {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = (string)($_POST['senha'] ?? '');
        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('erro', 'Informe nome e email válidos.');
        } elseif (strlen($senha) < 10) {
            flash_set('erro', 'A senha deve ter pelo menos 10 caracteres.');
        } else {
            try {
                db()->prepare('INSERT INTO users (nome, email, senha_hash) VALUES (?,?,?)')
                    ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
                audit('usuario_criado', 'user', (int)db()->lastInsertId(), 'email=' . $email);
                flash_set('ok', 'Usuário criado.');
            } catch (PDOException $ex) {
                flash_set('erro', 'Já existe um usuário com esse email.');
            }
        }
    }

    if ($acao === 'senha' && $id) {
        $senha = (string)($_POST['senha'] ?? '');
        if (strlen($senha) < 10) {
            flash_set('erro', 'A nova senha deve ter pelo menos 10 caracteres.');
        } else {
            db()->prepare('UPDATE users SET senha_hash = ? WHERE id = ?')
                ->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
            audit('usuario_senha_alterada', 'user', $id);
            flash_set('ok', 'Senha alterada.');
        }
    }

    if ($acao === 'alternar' && $id && $id !== (int)$user['id']) {
        db()->prepare('UPDATE users SET ativo = 1 - ativo WHERE id = ?')->execute([$id]);
        audit('usuario_alternado', 'user', $id);
        flash_set('ok', 'Situação do usuário alterada.');
    }
    redirect('usuarios.php');
}

$lista = db()->query('SELECT id, nome, email, ativo, ultimo_login FROM users ORDER BY nome')->fetchAll();

page_header('Usuários do painel', 'usuarios.php', $user);
?>

<div class="cartao form-grid">
  <h2 style="margin-top:0">Novo usuário</h2>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="criar">
    <div class="linha-campos">
      <label>Nome <input type="text" name="nome" required></label>
      <label>Email <input type="email" name="email" required></label>
      <label>Senha (mín. 10) <input type="password" name="senha" minlength="10" required autocomplete="new-password"></label>
    </div>
    <button type="submit" class="botao botao-primario">Criar usuário</button>
  </form>
</div>

<div class="tabela-envolve tabela-cards">
  <table class="tabela">
    <thead><tr><th>Nome</th><th>Email</th><th>Situação</th><th>Último acesso</th><th>Ações</th></tr></thead>
    <tbody>
    <?php foreach ($lista as $u): ?>
      <tr>
        <td data-rotulo="Nome"><?= e($u['nome']) ?><?= (int)$u['id'] === (int)$user['id'] ? ' <span class="texto-suave">(você)</span>' : '' ?></td>
        <td data-rotulo="Email"><?= e($u['email']) ?></td>
        <td data-rotulo="Situação"><span class="selo selo-<?= $u['ativo'] ? 'ativo' : 'desligado' ?>"><?= $u['ativo'] ? 'Ativo' : 'Bloqueado' ?></span></td>
        <td data-rotulo="Último acesso"><?= e(fmt_data_hora($u['ultimo_login'])) ?></td>
        <td class="acoes">
          <form method="post" style="display:flex;gap:.4rem">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="senha">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="password" name="senha" placeholder="Nova senha" minlength="10" autocomplete="new-password" style="min-height:36px">
            <button class="botao botao-mini">Trocar senha</button>
          </form>
          <?php if ((int)$u['id'] !== (int)$user['id']): ?>
            <form method="post" data-confirmar="<?= $u['ativo'] ? 'Bloquear' : 'Reativar' ?> este usuário?">
              <?= csrf_field() ?>
              <input type="hidden" name="acao" value="alternar">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="botao botao-mini <?= $u['ativo'] ? 'botao-perigo' : 'botao-verde' ?>"><?= $u['ativo'] ? 'Bloquear' : 'Reativar' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php page_footer(); ?>
