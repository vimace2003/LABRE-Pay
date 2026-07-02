<?php
/** Autenticação do painel administrativo. */

require_once APP_DIR . '/bootstrap.php';

const LOGIN_MAX_TENTATIVAS = 5;   // por IP
const LOGIN_JANELA_MIN = 15;      // minutos

function auth_user(): ?array
{
    session_start_secure();
    if (empty($_SESSION['user_id'])) return null;
    static $user = null;
    if ($user === null) {
        $st = db()->prepare('SELECT id, nome, email, ativo FROM users WHERE id = ?');
        $st->execute([$_SESSION['user_id']]);
        $user = $st->fetch() ?: null;
        if ($user && !$user['ativo']) $user = null;
    }
    return $user;
}

function require_login(): array
{
    $user = auth_user();
    if (!$user) {
        redirect('index.php');
    }
    return $user;
}

function auth_login(string $email, string $senha): bool
{
    session_start_secure();
    if (!rate_limit_check('login', LOGIN_MAX_TENTATIVAS, LOGIN_JANELA_MIN)) {
        flash_set('erro', 'Muitas tentativas de acesso. Aguarde ' . LOGIN_JANELA_MIN . ' minutos e tente novamente.');
        return false;
    }
    $st = db()->prepare('SELECT id, senha_hash, ativo FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    $ok = $u && $u['ativo'] && password_verify($senha, $u['senha_hash']);
    rate_limit_hit('login', $email, $ok);
    if (!$ok) {
        flash_set('erro', 'Email ou senha incorretos.');
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    db()->prepare('UPDATE users SET ultimo_login = NOW() WHERE id = ?')->execute([$u['id']]);
    audit('login', 'user', (int)$u['id']);
    return true;
}

function auth_logout(): void
{
    session_start_secure();
    if (!empty($_SESSION['user_id'])) {
        audit('logout', 'user', (int)$_SESSION['user_id']);
    }
    session_unset();
    session_destroy();
}
