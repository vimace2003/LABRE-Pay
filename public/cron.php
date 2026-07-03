<?php
/**
 * Tarefa diária (cron do cPanel):
 *   php /caminho/public/cron.php            (via CLI)
 *   ou GET /cron.php?token=<cron_token>     (via URL, se a hospedagem exigir)
 *
 * Faz: vencimento de cobranças, reconciliação com o MercadoPago,
 * lembretes antes/depois do vencimento e backup do banco.
 */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/cobranca.php';

header('Content-Type: text/plain; charset=UTF-8');

$viaCli = PHP_SAPI === 'cli';
$token = $_GET['token'] ?? '';
if (!$viaCli && (setting('cron_token') === '' || !hash_equals(setting('cron_token'), (string)$token))) {
    http_response_code(403);
    exit("Acesso negado.\n");
}

$log = [];

/* 1. Marca cobranças vencidas */
$n = db()->exec("UPDATE charges SET status = 'vencida' WHERE status = 'pendente' AND vencimento < CURDATE()");
$log[] = "Cobranças marcadas como vencidas: {$n}";

/* 2. Reconciliação com o MercadoPago (fallback do webhook) */
$reconciliadas = 0;
if (trim(setting('mp_access_token')) !== '') {
    $st = db()->query("SELECT * FROM charges WHERE status IN ('pendente','vencida') AND mp_preference_id IS NOT NULL ORDER BY id LIMIT 100");
    foreach ($st->fetchAll() as $charge) {
        try {
            $pgto = mp_buscar_pagamento_por_referencia((int)$charge['id']);
            if ($pgto) {
                charge_processar_pagamento_mp($pgto);
                $reconciliadas++;
            }
        } catch (Throwable $ex) {
            $log[] = 'Reconciliação da cobrança ' . $charge['id'] . ': ' . $ex->getMessage();
        }
    }
}
$log[] = "Pagamentos reconciliados via API: {$reconciliadas}";

/* 3. Lembretes (1 por cobrança por dia-alvo; email_log evita repetição) */
if (setting('lembretes_ativos', '1') === '1') {
    $antes = (int)setting('lembrete_dias_antes', '10');
    $depois = (int)setting('lembrete_dias_depois', '15');
    $enviados = 0;
    $sql = "SELECT c.*, m.nome, m.indicativo, m.email, m.id AS mid FROM charges c
            JOIN members m ON m.id = c.member_id
            WHERE c.tipo = 'anuidade' AND c.status IN ('pendente','vencida') AND m.email IS NOT NULL AND m.status = 'ativo'
              AND (c.vencimento = DATE_ADD(CURDATE(), INTERVAL ? DAY)
                   OR c.vencimento = DATE_SUB(CURDATE(), INTERVAL ? DAY))
              AND NOT EXISTS (SELECT 1 FROM email_log e WHERE e.charge_id = c.id AND e.tipo = 'lembrete' AND DATE(e.criado_em) = CURDATE())";
    $st = db()->prepare($sql);
    $st->execute([$antes, $depois]);
    foreach ($st->fetchAll() as $c) {
        $member = ['id' => $c['mid'], 'nome' => $c['nome'], 'indicativo' => $c['indicativo'], 'email' => $c['email']];
        [$ok] = charge_enviar($c, $member, 'lembrete');
        if ($ok) $enviados++;
    }
    $log[] = "Lembretes enviados: {$enviados}";
}

/* 4. Backup do banco (dump SQL em PHP puro, fora do docroot, com rotação) */
if (setting('backup_ativo', '1') === '1') {
    try {
        $dir = APP_DIR . '/backups';
        if (!is_dir($dir)) { mkdir($dir, 0750, true); file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
        $arquivo = $dir . '/backup-' . date('Y-m-d') . '.sql.gz';
        if (!file_exists($arquivo)) {
            $gz = gzopen($arquivo, 'w6');
            gzwrite($gz, "-- LABRE-Pay backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            $tabelas = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tabelas as $t) {
                $create = db()->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM)[1];
                gzwrite($gz, "\nDROP TABLE IF EXISTS `{$t}`;\n{$create};\n");
                $rs = db()->query("SELECT * FROM `{$t}`");
                while ($row = $rs->fetch(PDO::FETCH_NUM)) {
                    $vals = array_map(fn($v) => $v === null ? 'NULL' : db()->quote((string)$v), $row);
                    gzwrite($gz, "INSERT INTO `{$t}` VALUES (" . implode(',', $vals) . ");\n");
                }
            }
            gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
            gzclose($gz);
            $log[] = 'Backup gerado: ' . basename($arquivo);
        } else {
            $log[] = 'Backup de hoje já existia.';
        }
        // Rotação
        $manter = max(1, (int)setting('backup_copias', '7'));
        $arquivos = glob($dir . '/backup-*.sql.gz');
        rsort($arquivos);
        foreach (array_slice($arquivos, $manter) as $velho) {
            unlink($velho);
            $log[] = 'Backup antigo removido: ' . basename($velho);
        }
    } catch (Throwable $ex) {
        $log[] = 'ERRO no backup: ' . $ex->getMessage();
    }
}

audit('cron_executado', null, null, implode(' | ', $log));
echo implode("\n", $log) . "\n";
