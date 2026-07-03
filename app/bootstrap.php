<?php
/**
 * LABRE-Pay — bootstrap: configuração, sessão segura e helpers.
 * Todo script em public/ começa com: require __DIR__ . '/../app/bootstrap.php';
 */

define('APP_DIR', __DIR__);
define('APP_VERSION', '1.0.0');

if (!file_exists(APP_DIR . '/config.php')) {
    // Instalação ainda não feita — manda para o instalador.
    $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($self !== 'install.php') {
        header('Location: install.php');
        exit;
    }
} else {
    require APP_DIR . '/config.php';
}

if (!defined('APP_ENV')) define('APP_ENV', 'dev');
if (!defined('APP_SECRET')) define('APP_SECRET', 'sem-config');
if (!defined('BASE_URL')) define('BASE_URL', '');
if (!defined('MAIL_OVERRIDE_TO')) define('MAIL_OVERRIDE_TO', '');

date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

require APP_DIR . '/db.php';

function is_production(): bool
{
    return APP_ENV === 'production';
}

/* ---------- Sessão segura ---------- */

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('LABREPAY');
    session_start();

    // Timeout de inatividade: 30 minutos
    $agora = time();
    if (isset($_SESSION['last_seen']) && ($agora - (int)$_SESSION['last_seen']) > 1800) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_seen'] = $agora;
}

/* ---------- Saída segura ---------- */

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    session_start_secure();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    session_start_secure();
    $ok = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('Sessão expirada ou requisição inválida. Volte e tente novamente.');
    }
}

/* ---------- Mensagens flash ---------- */

function flash_set(string $tipo, string $msg): void
{
    session_start_secure();
    $_SESSION['flash'][] = ['tipo' => $tipo, 'msg' => $msg];
}

function flash_get(): array
{
    session_start_secure();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Configurações (tabela settings) ---------- */

function settings_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT k, v FROM settings') as $row) {
            $cache[$row['k']] = $row['v'];
        }
    }
    return $cache;
}

function setting(string $k, string $default = ''): string
{
    $all = settings_all();
    return isset($all[$k]) && $all[$k] !== null && $all[$k] !== '' ? $all[$k] : $default;
}

function setting_save(string $k, string $v): void
{
    $st = db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute([$k, $v]);
}

/* ---------- Auditoria (LGPD / prestação de contas) ---------- */

function audit(string $acao, ?string $entidade = null, ?int $entidadeId = null, string $detalhes = ''): void
{
    $userId = $_SESSION['user_id'] ?? null;
    $st = db()->prepare('INSERT INTO audit_log (user_id, acao, entidade, entidade_id, detalhes, ip) VALUES (?,?,?,?,?,?)');
    $st->execute([$userId, $acao, $entidade, $entidadeId, mb_substr($detalhes, 0, 60000), client_ip()]);
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* ---------- Rate limit genérico (login e consulta pública) ---------- */

function rate_limit_check(string $contexto, int $max, int $janelaMin): bool
{
    $st = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND contexto = ? AND sucesso = 0 AND criado_em > (NOW() - INTERVAL ? MINUTE)'
    );
    $st->execute([client_ip(), $contexto, $janelaMin]);
    return (int)$st->fetchColumn() < $max;
}

function rate_limit_hit(string $contexto, ?string $identificador, bool $sucesso): void
{
    $st = db()->prepare('INSERT INTO login_attempts (ip, contexto, identificador, sucesso) VALUES (?,?,?,?)');
    $st->execute([client_ip(), $contexto, $identificador !== null ? mb_substr($identificador, 0, 190) : null, $sucesso ? 1 : 0]);
}

/* ---------- Formatação pt-BR ---------- */

function fmt_moeda(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function fmt_data(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/Y', $ts) : '—';
}

function fmt_data_hora(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

/** Temas de cores disponíveis (cor primária e variação clara). */
const TEMAS = [
    'azul'     => ['nome' => 'Azul clássico',  'primaria' => '#123a5c', 'clara' => '#1d5c8f'],
    'verde'    => ['nome' => 'Verde floresta', 'primaria' => '#1b4d2e', 'clara' => '#2e7d4f'],
    'petroleo' => ['nome' => 'Petróleo',       'primaria' => '#0f4c4c', 'clara' => '#1d7a74'],
    'vinho'    => ['nome' => 'Vinho',          'primaria' => '#5c1220', 'clara' => '#8f2d42'],
    'roxo'     => ['nome' => 'Roxo',           'primaria' => '#3b1d5c', 'clara' => '#6b3fa0'],
    'grafite'  => ['nome' => 'Grafite',        'primaria' => '#263238', 'clara' => '#546e7a'],
];

/** Tema escolhido nas Configurações (sempre um válido). */
function tema_atual(): string
{
    $t = setting('tema', 'azul');
    return isset(TEMAS[$t]) ? $t : 'azul';
}

/** Nome amigável do meio de pagamento retornado pelo MercadoPago. */
function fmt_meio_pagamento(?string $meio): string
{
    return match ($meio) {
        'account_money' => 'Saldo Mercado Pago',
        'credit_card' => 'Cartão de crédito',
        'debit_card' => 'Cartão de débito',
        'bank_transfer' => 'Pix',
        'ticket' => 'Boleto',
        null, '' => 'Online',
        default => $meio,
    };
}

/** Normaliza CPF/CNPJ para apenas dígitos. */
function so_digitos(string $s): string
{
    return preg_replace('/\D+/', '', $s);
}

/** Link wa.me para um telefone brasileiro ('' se o número não servir). */
function whatsapp_url(?string $telefone): string
{
    $d = so_digitos((string)$telefone);
    if (strlen($d) === 10 || strlen($d) === 11) {
        $d = '55' . $d; // DDD + número → adiciona o código do Brasil
    }
    if (strlen($d) < 12 || strlen($d) > 13 || !str_starts_with($d, '55')) {
        return '';
    }
    return 'https://wa.me/' . $d;
}

/** Token estável e não adivinhável ligado a um registro (comprovantes, links de pagamento). */
function token_para(string $tipo, int $id): string
{
    return substr(hash_hmac('sha256', $tipo . ':' . $id, APP_SECRET), 0, 40);
}

/* ---------- Cabeçalhos de segurança ---------- */

function security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'");
    if (is_production()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
