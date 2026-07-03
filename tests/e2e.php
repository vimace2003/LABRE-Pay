<?php
/**
 * LABRE-Pay — testes E2E automatizados.
 *
 * Roda DENTRO do container app (Docker Compose):
 *   docker compose exec -T app php /var/www/tests/e2e.php
 *
 * Exercita o sistema via HTTP como um usuário real: instala (se preciso),
 * loga, zera dados, importa planilha, gera lotes, valida pro-rata, multa,
 * consulta pública, comprovante, cron, backup e rate-limit.
 * Emails são conferidos no Mailpit. MercadoPago usa token fake — as chamadas
 * falham de propósito e o teste confirma que o sistema degrada bem.
 *
 * Sai com código 0 se tudo passou; 1 se houve falha (para uso em CI).
 */

date_default_timezone_set('America/Sao_Paulo'); // mesmo fuso do sistema

const BASE = 'http://localhost';
const MAILPIT = 'http://mailpit:8025';
const ADMIN_EMAIL = 'admin@labre-sc.org.br';
const ADMIN_SENHA = 'senha-super-secreta-123';
const DUMMY = 'dummy@teste.local';

$cookieJar = tempnam(sys_get_temp_dir(), 'e2e-cookies-');
$falhas = 0;
$passou = 0;

/* ---------------------------------------------------------------- infra */

function http(string $method, string $path, array $dados = [], bool $multipart = false): array
{
    global $cookieJar;
    $ch = curl_init((str_starts_with($path, 'http') ? '' : BASE) . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, // 302 vira GET, como no navegador
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $dados : http_build_query($dados));
    }
    $corpo = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $corpo];
}

function csrf_de(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function csrf_atual(string $pagina = '/configuracoes.php'): string
{
    [, $html] = http('GET', $pagina);
    return csrf_de($html);
}

function check(string $nome, bool $ok, string $detalhe = ''): void
{
    global $falhas, $passou;
    if ($ok) {
        $passou++;
        echo "  ✓ {$nome}\n";
    } else {
        $falhas++;
        echo "  ✗ {$nome}" . ($detalhe !== '' ? " — {$detalhe}" : '') . "\n";
    }
}

function bloco(string $titulo): void
{
    echo "\n== {$titulo}\n";
}

function pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        require_once '/var/www/app/config.php';
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function mailpit(string $path): array
{
    [, $corpo] = http('GET', MAILPIT . $path);
    return json_decode($corpo, true) ?: [];
}

function mailpit_limpar(): void
{
    $ch = curl_init(MAILPIT . '/api/v1/messages');
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true]);
    curl_exec($ch);
    curl_close($ch);
}

/** Replica a regra de pro-rata para conferir o valor de forma independente. */
function prorata_esperada(string $adesao, float $anuidade): array
{
    $ano = (int)substr($adesao, 0, 4);
    $venc = sprintf('%04d-01-31', $ano);
    if ($venc <= $adesao) $venc = sprintf('%04d-01-31', $ano + 1);
    $d = (new DateTime($adesao))->diff(new DateTime($venc));
    $meses = max(1, min(12, $d->y * 12 + $d->m + ($d->d > 0 ? 1 : 0)));
    return [$meses, round($anuidade / 12 * $meses, 2)];
}

/* --------------------------------------------------------- 0. instalação */

bloco('0. Instalação e acesso');

[, $html] = http('GET', '/install.php');
if (!str_contains($html, 'já está instalado')) {
    // O banco pode ainda estar inicializando (primeiro boot do MySQL): insiste.
    for ($tent = 1; $tent <= 30; $tent++) {
        [, $html] = http('POST', '/install.php', [
            'env' => 'dev', 'base_url' => BASE, 'mail_override' => DUMMY,
            'db_host' => 'db', 'db_name' => 'labrepay', 'db_user' => 'labrepay', 'db_pass' => 'labrepay',
            'adm_nome' => 'Admin E2E', 'adm_email' => ADMIN_EMAIL, 'adm_senha' => ADMIN_SENHA,
        ]);
        if (str_contains($html, 'Instalação concluída') || !str_contains($html, 'Não foi possível conectar')) break;
        sleep(3);
    }
    $erroInstall = preg_match('/flash-erro">([^<]+)/', $html, $m) ? $m[1] : '';
    check('instalador executa e conclui', str_contains($html, 'Instalação concluída'), $erroInstall);
} else {
    check('sistema já instalado (lock ativo)', true);
}

[, $html] = http('GET', '/index.php');
[, $html] = http('POST', '/index.php', ['csrf' => csrf_de($html), 'email' => ADMIN_EMAIL, 'senha' => ADMIN_SENHA]);
check('login do administrador', str_contains($html, 'Início') || str_contains($html, 'Associados ativos'));

[$st] = http('GET', '/dashboard.php');
check('dashboard acessível autenticado', $st === 200);

/* ----------------------------------------------- 1. baseline de config */

bloco('1. Configurações de base (SMTP Mailpit, multa 2% + 1% a.m., MP fake)');

http('POST', '/configuracoes.php', [
    'csrf' => csrf_atual(),
    'entidade_nome' => 'LABRE Teste E2E', 'entidade_sigla' => 'LABRE-E2E', 'entidade_cnpj' => '00.000.000/0001-00',
    'entidade_site' => 'https://example.org', 'entidade_email_contato' => 'diretoria@example.org', 'tema' => 'azul',
    'anuidade_valor' => '120.00', 'venc_dia' => '31', 'venc_mes' => '1', 'prazo_venc_meses' => '3',
    'multa_percent' => '2', 'juros_mes_percent' => '1',
    'desconto_tipo' => 'percent', 'desconto_valor' => '10', 'desconto_dia' => '31', 'desconto_mes' => '12',
    'taxa_admissao_valor' => '0', 'taxa_retorno_ativa' => '1', 'taxa_retorno_valor' => '25.00',
    'meses_exclusao_auto' => '3', 'lembretes_ativos' => '1', 'lembrete_dias_antes' => '10', 'lembrete_dias_depois' => '15',
    'mp_access_token' => 'TEST-e2e-token-fake', 'mp_public_key' => '', 'mp_webhook_secret' => '',
    'smtp_host' => 'mailpit', 'smtp_porta' => '1025', 'smtp_seguranca' => 'nenhuma', 'smtp_usuario' => '', 'smtp_senha' => '',
    'smtp_remetente_email' => 'sistema@example.org', 'smtp_remetente_nome' => 'LABRE E2E',
    'backup_ativo' => '1', 'backup_copias' => '3',
]);
$temaSalvo = pdo()->query("SELECT v FROM settings WHERE k='tema'")->fetchColumn();
check('configurações salvas', $temaSalvo === 'azul');

[, $html] = http('POST', '/configuracoes.php', ['csrf' => csrf_atual(), 'acao' => 'zerar_dados', 'confirmacao' => 'ZERAR']);
check('zerar dados de teste', str_contains($html, 'zerados'));
check('zerar preservou usuários', (int)pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() >= 1);
mailpit_limpar();

[, $html] = http('POST', '/configuracoes.php', ['csrf' => csrf_atual(), 'acao' => 'testar_email']);
sleep(1);
$msgs = mailpit('/api/v1/messages');
check('email de teste chegou no Mailpit', ($msgs['total'] ?? 0) >= 1);
$m0 = $msgs['messages'][0] ?? [];
check('email desviado para o dummy', ($m0['To'][0]['Address'] ?? '') === DUMMY, 'destinatário: ' . ($m0['To'][0]['Address'] ?? '?'));
check('assunto com prefixo [TESTE]', str_starts_with($m0['Subject'] ?? '', '[TESTE]'));

/* -------------------------------------------------------- 2. importação */

bloco('2. Importação de planilha');

$csv = "Nome;Indicativo;Email;CPF;Cidade;UF;Data de adesao\n" .
    "João da Silva;PP5ABC;joao@example.org;111.444.777-35;Florianópolis;SC;15/03/2020\n" .
    "Maria Oliveira;PP5XYZ;maria@example.org;222.555.888-46;Joinville;SC;01/02/2019\n" .
    "Carlos Pereira;PY3QWE;carlos@example.org;333.666.999-57;Blumenau;SC;10/07/2023\n";
$csvArq = tempnam(sys_get_temp_dir(), 'e2e-') . '.csv';
file_put_contents($csvArq, $csv);

[, $html] = http('POST', '/importar.php', [
    'csrf' => csrf_atual('/importar.php'), 'etapa' => 'upload',
    'planilha' => new CURLFile($csvArq, 'text/csv', 'associados.csv'),
], true);
check('upload lê a planilha (3 linhas)', str_contains($html, '3 linha(s)'));

http('POST', '/importar.php', [
    'csrf' => csrf_de($html), 'etapa' => 'importar',
    'mapa' => ['0' => 'nome', '1' => 'indicativo', '2' => 'email', '3' => 'cpf_cnpj', '4' => 'cidade', '5' => 'uf', '6' => 'data_adesao'],
]);
check('3 associados importados', (int)pdo()->query('SELECT COUNT(*) FROM members')->fetchColumn() === 3);
$joao = pdo()->query("SELECT * FROM members WHERE indicativo='PP5ABC'")->fetch();
check('acentuação preservada (João)', $joao && $joao['nome'] === 'João da Silva');
check('CPF normalizado só dígitos', $joao && $joao['cpf_cnpj'] === '11144477735');

// Reimportação não duplica
[, $html] = http('POST', '/importar.php', [
    'csrf' => csrf_atual('/importar.php'), 'etapa' => 'upload',
    'planilha' => new CURLFile($csvArq, 'text/csv', 'associados.csv'),
], true);
http('POST', '/importar.php', [
    'csrf' => csrf_de($html), 'etapa' => 'importar', 'atualizar_existentes' => '1',
    'mapa' => ['0' => 'nome', '1' => 'indicativo', '2' => 'email', '3' => 'cpf_cnpj', '4' => 'cidade', '5' => 'uf', '6' => 'data_adesao'],
]);
check('reimportar não duplica (dedupe)', (int)pdo()->query('SELECT COUNT(*) FROM members')->fetchColumn() === 3);
unlink($csvArq);

/* --------------------------------------- 3. lote com adesão no meio do ciclo */

bloco('3. Cobrança em lote + pro-rata de meio de ciclo');

$hoje = date('Y-m-d');
http('POST', '/associados.php', [
    'csrf' => csrf_atual('/associados.php?novo=1'), 'acao' => 'salvar', 'id' => '0',
    'nome' => 'Xavier MeioCiclo', 'indicativo' => 'PP5E2E', 'email' => 'xavier@example.org',
    'cpf_cnpj' => '987.654.321-00', 'categoria' => 'efetivo', 'classe' => 'contribuinte',
    'data_adesao' => $hoje, 'valor_primeira' => '',
]);
check('associado de meio de ciclo cadastrado', (int)pdo()->query("SELECT COUNT(*) FROM members WHERE indicativo='PP5E2E'")->fetchColumn() === 1);

$anoAtual = (int)date('Y');
$csrfLote = csrf_atual('/cobrancas.php');
do {
    [, $json] = http('POST', '/cobrancas.php?acao=gerar_lote', [
        'csrf' => $csrfLote, 'ano' => (string)$anoAtual, 'valor' => '120,00', 'vencimento' => '',
    ]);
    $r = json_decode($json, true) ?: ['erro' => 'resposta inválida: ' . substr($json, 0, 120)];
} while (empty($r['erro']) && empty($r['terminou']) && ($r['processados'] ?? 0) > 0);
check('lote executa sem erro estrutural', empty($r['erro']), $r['erro'] ?? '');

$cheias = pdo()->query("SELECT c.* FROM charges c JOIN members m ON m.id=c.member_id WHERE m.indicativo='PP5ABC' AND c.ano={$anoAtual}")->fetch();
check('anuidade cheia criada para associado antigo', $cheias && (float)$cheias['valor'] === 120.00);

$vencEsperado = date('Y-m-d') <= sprintf('%04d-01-31', $anoAtual)
    ? sprintf('%04d-01-31', $anoAtual)
    : date('Y-m-d', strtotime('+3 months'));
check('vencimento segue regra de emissão (' . $vencEsperado . ')', $cheias && $cheias['vencimento'] === $vencEsperado, 'veio ' . ($cheias['vencimento'] ?? '?'));

[$mesesEsp, $valorEsp] = prorata_esperada($hoje, 120.00);
$prXavier = pdo()->query("SELECT c.* FROM charges c JOIN members m ON m.id=c.member_id WHERE m.indicativo='PP5E2E' AND c.status<>'cancelada'")->fetch();
check("meio de ciclo recebeu proporcional ({$mesesEsp} meses = R$ " . number_format($valorEsp, 2, ',', '') . ')',
    $prXavier && abs((float)$prXavier['valor'] - $valorEsp) < 0.005,
    'veio ' . ($prXavier['valor'] ?? 'nenhuma cobrança'));
check('proporcional é isenta de multa', $prXavier && (int)$prXavier['isenta_multa'] === 1);
check('proporcional NÃO é anuidade cheia', $prXavier && (float)$prXavier['valor'] < 120.00);
check('proporcional registrada no ANO VIGENTE (aparece na lista de ' . $anoAtual . ')',
    $prXavier && (int)$prXavier['ano'] === $anoAtual, 'veio ano ' . ($prXavier['ano'] ?? '?'));

[, $json] = http('POST', '/cobrancas.php?acao=gerar_lote', [
    'csrf' => $csrfLote, 'ano' => (string)$anoAtual, 'valor' => '120,00', 'vencimento' => '',
]);
$r2 = json_decode($json, true) ?: [];
check('rodar o lote de novo não duplica ninguém', ($r2['processados'] ?? -1) === 0 && !empty($r2['terminou']));

// Lote só pode ser do ano vigente — nunca passado, nunca futuro
foreach ([$anoAtual - 1 => 'passado', $anoAtual + 1 => 'futuro'] as $anoInvalido => $rotulo) {
    [, $json] = http('POST', '/cobrancas.php?acao=gerar_lote', [
        'csrf' => $csrfLote, 'ano' => (string)$anoInvalido, 'valor' => '120,00', 'vencimento' => '',
    ]);
    $ri = json_decode($json, true) ?: [];
    check("lote recusa ano {$rotulo} ({$anoInvalido})", !empty($ri['erro']) && str_contains($ri['erro'], 'vigente'));
}

/* ------------------------------------------------- 4. baixa manual + email */

bloco('4. Baixa manual, comprovante e emails');

mailpit_limpar();
http('POST', '/cobrancas.php', [
    'csrf' => csrf_atual('/cobrancas.php'), 'acao' => 'pagar_manual',
    'id' => (string)$cheias['id'], 'valor_pago' => '', 'meio' => 'pix na sede',
]);
$paga = pdo()->query('SELECT * FROM charges WHERE id=' . (int)$cheias['id'])->fetch();
check('baixa manual marca como paga', $paga['status'] === 'pago' && (int)$paga['pago_manual'] === 1);
sleep(1);
$msgs = mailpit('/api/v1/messages');
$conf = null;
foreach ($msgs['messages'] ?? [] as $m) {
    if (str_contains($m['Subject'], 'Pagamento confirmado')) $conf = $m;
}
check('email de confirmação enviado ao dummy', $conf !== null && $conf['To'][0]['Address'] === DUMMY);

[$st, $html] = http('GET', '/comprovante.php?c=' . $paga['id'] . '&t=' . $paga['token']);
check('comprovante abre com token válido', $st === 200 && str_contains($html, 'TOTAL R$') && str_contains($html, '*** PAGO ***'));
check('comprovante tem o morse TKS 73', str_contains($html, 'TKS 73'));
[$st] = http('GET', '/comprovante.php?c=' . $paga['id'] . '&t=' . str_repeat('0', 40));
check('comprovante recusa token inválido (404)', $st === 404);

/* ------------------------------------------------------ 5. multa e juros */

bloco('5. Multa e juros por atraso (vencida há 30 dias)');

// Cancela a cobrança pendente da Maria e regenera o lote com vencimento
// retroativo (campo livre do admin) — só ela está sem cobrança do ano.
$vencAtras = date('Y-m-d', strtotime('-30 days'));
$chMaria = pdo()->query("SELECT c.* FROM charges c JOIN members m ON m.id=c.member_id WHERE m.indicativo='PP5XYZ' AND c.ano={$anoAtual} AND c.status='pendente'")->fetch();
http('POST', '/cobrancas.php', ['csrf' => csrf_atual('/cobrancas.php'), 'acao' => 'cancelar', 'id' => (string)$chMaria['id']]);
$csrfLote = csrf_atual('/cobrancas.php');
do {
    [, $json] = http('POST', '/cobrancas.php?acao=gerar_lote', [
        'csrf' => $csrfLote, 'ano' => (string)$anoAtual, 'valor' => '120,00', 'vencimento' => $vencAtras,
    ]);
    $r = json_decode($json, true) ?: ['erro' => 'resposta inválida'];
} while (empty($r['erro']) && empty($r['terminou']) && ($r['processados'] ?? 0) > 0);

shell_exec('php /var/www/html/cron.php 2>&1');
$vencida = pdo()->query("SELECT c.* FROM charges c JOIN members m ON m.id=c.member_id WHERE m.indicativo='PP5XYZ' AND c.ano={$anoAtual} AND c.vencimento='{$vencAtras}'")->fetch();
check('cron marcou a cobrança como vencida', $vencida && $vencida['status'] === 'vencida');

// 120 + multa 2% (2,40) + juros 1% a.m. × 30 dias (1,20) = 123,60
[, $html] = http('POST', '/consulta.php', ['csrf' => csrf_atual('/consulta.php'), 'termo' => 'PP5XYZ', 'site' => '']);
check('consulta pública mostra 123,60 (120 + 2,40 multa + 1,20 juros)', str_contains($html, '123,60'), 'valor não encontrado na página');
check('consulta explica multa e juros', str_contains($html, 'multa e juros'));

$backups = glob('/var/www/app/backups/backup-*.sql.gz');
check('cron gerou backup do banco', count($backups) >= 1);

/* --------------------------------------------------- 6. consulta pública */

bloco('6. Consulta pública e privacidade');

[, $html] = http('POST', '/consulta.php', ['csrf' => csrf_atual('/consulta.php'), 'termo' => '111.444.777-35', 'site' => '']);
check('busca por CPF encontra', str_contains($html, 'Olá, João'));
check('privacidade: sobrenome NÃO aparece', !str_contains($html, 'da Silva'));
check('cobrança paga aparece como Pago ✓', str_contains($html, 'Pago'));

[, $html] = http('POST', '/consulta.php', ['csrf' => csrf_atual('/consulta.php'), 'termo' => 'py3qwe', 'site' => '']);
check('busca por indicativo minúsculo encontra', str_contains($html, 'Olá, Carlos'));

[, $html] = http('POST', '/consulta.php', ['csrf' => csrf_atual('/consulta.php'), 'termo' => 'PY0NADA', 'site' => '']);
check('inexistente: mensagem amigável', str_contains($html, 'Não encontramos'));

/* --------------------------------------------- 7. desligamento/readmissão */

bloco('7. Desligamento e readmissão com taxa de retorno');

$carlos = pdo()->query("SELECT * FROM members WHERE indicativo='PY3QWE'")->fetch();
// o token CSRF precisa vir de uma página com formulário (a de edição tem)
$csrfA = csrf_atual('/associados.php?editar=' . $carlos['id']);
http('POST', '/associados.php', ['csrf' => $csrfA, 'acao' => 'desligar', 'id' => (string)$carlos['id'], 'motivo' => 'a_pedido']);
$m = pdo()->query('SELECT status FROM members WHERE id=' . (int)$carlos['id'])->fetchColumn();
check('associado desligado', $m === 'desligado');
$pend = (int)pdo()->query("SELECT COUNT(*) FROM charges WHERE member_id=" . (int)$carlos['id'] . " AND status IN ('pendente','vencida')")->fetchColumn();
check('cobranças em aberto canceladas no desligamento', $pend === 0);

http('POST', '/associados.php', ['csrf' => $csrfA, 'acao' => 'readmitir', 'id' => (string)$carlos['id']]);
$m = pdo()->query('SELECT status FROM members WHERE id=' . (int)$carlos['id'])->fetchColumn();
check('associado readmitido', $m === 'ativo');
[$mesesR, $valorR] = prorata_esperada($hoje, 120.00);
$chR = pdo()->query('SELECT * FROM charges WHERE member_id=' . (int)$carlos['id'] . " AND status='pendente' ORDER BY id DESC LIMIT 1")->fetch();
$esperadoR = round($valorR + 25.00, 2);
check("readmissão cobra proporcional + taxa de retorno (R$ " . number_format($esperadoR, 2, ',', '') . ')',
    $chR && abs((float)$chR['valor'] - $esperadoR) < 0.005, 'veio ' . ($chR['valor'] ?? 'nada'));
check('cobrança de readmissão isenta de multa', $chR && (int)$chR['isenta_multa'] === 1);

/* -------------------------------------------------- 7b. cobrança avulsa */

bloco('7b. Cobrança avulsa (serviços extras)');

mailpit_limpar();
$xavier = pdo()->query("SELECT * FROM members WHERE indicativo='PP5E2E'")->fetch();
$csrfX = csrf_atual('/associados.php?editar=' . $xavier['id']);
$vencAvulsa = date('Y-m-d', strtotime('+1 month'));
http('POST', '/associados.php', [
    'csrf' => $csrfX, 'acao' => 'cobranca_avulsa', 'id' => (string)$xavier['id'],
    'descricao' => 'Envio de cartão QSL registrado', 'valor' => '35,00', 'vencimento' => $vencAvulsa,
]);
$avulsa = pdo()->query("SELECT * FROM charges WHERE member_id=" . (int)$xavier['id'] . " AND tipo='avulsa' ORDER BY id DESC LIMIT 1")->fetch();
check('avulsa criada com tipo/valor/vencimento corretos',
    $avulsa && (float)$avulsa['valor'] === 35.00 && $avulsa['vencimento'] === $vencAvulsa);
check('avulsa sem multa por padrão (checkbox desmarcado)', $avulsa && (int)$avulsa['isenta_multa'] === 1);
sleep(1);
$msgs = mailpit('/api/v1/messages');
$temAvulsa = false;
foreach ($msgs['messages'] ?? [] as $m) {
    if (str_contains($m['Subject'], 'QSL registrado')) $temAvulsa = true;
}
check('email da avulsa usa o template próprio (assunto com a descrição)', $temAvulsa);

// Regressão-chave: a avulsa NÃO pode suprimir a anuidade no lote nem mudar a situação
$anuidadesXavier = (int)pdo()->query("SELECT COUNT(*) FROM charges WHERE member_id=" . (int)$xavier['id'] . " AND tipo='anuidade' AND ano={$anoAtual} AND status<>'cancelada'")->fetchColumn();
check('anuidade do associado continua existindo ao lado da avulsa', $anuidadesXavier === 1);
[, $json] = http('POST', '/cobrancas.php?acao=gerar_lote', [
    'csrf' => csrf_atual('/cobrancas.php'), 'ano' => (string)$anoAtual, 'valor' => '120,00', 'vencimento' => '',
]);
$rAv = json_decode($json, true) ?: [];
check('lote não recria nada por causa da avulsa', ($rAv['processados'] ?? -1) === 0);

// Desconto por antecipação nunca se aplica à avulsa — nem quando ela tem
// multa habilitada (isenta_multa=0), que é o caso que exercita a trava por tipo
http('POST', '/associados.php', [
    'csrf' => $csrfX, 'acao' => 'cobranca_avulsa', 'id' => (string)$xavier['id'],
    'descricao' => 'Taxa de evento', 'valor' => '50,00', 'vencimento' => $vencAvulsa, 'aplicar_multa' => '1',
]);
$avulsaMulta = pdo()->query("SELECT * FROM charges WHERE member_id=" . (int)$xavier['id'] . " AND tipo='avulsa' ORDER BY id DESC LIMIT 1")->fetch();
check('avulsa com multa habilitada grava isenta_multa=0', $avulsaMulta && (int)$avulsaMulta['isenta_multa'] === 0);

http('POST', '/configuracoes.php', ['csrf' => csrf_atual(), 'desconto_ativo' => '1',
    'desconto_tipo' => 'percent', 'desconto_valor' => '10',
    'desconto_dia' => date('d', strtotime('+10 days')), 'desconto_mes' => date('m', strtotime('+10 days')),
    'lembretes_ativos' => '1', 'backup_ativo' => '1']);
[, $html] = http('POST', '/consulta.php', ['csrf' => csrf_atual('/consulta.php'), 'termo' => 'PP5E2E', 'site' => '']);
check('avulsa com multa NÃO ganha o desconto de anuidade (50,00 cheio)', str_contains($html, '50,00') && !str_contains($html, '45,00'));
http('POST', '/configuracoes.php', ['csrf' => csrf_atual(), 'lembretes_ativos' => '1', 'backup_ativo' => '1']); // desliga o desconto

// Baixa manual → confirmação própria + comprovante com a descrição
mailpit_limpar();
http('POST', '/cobrancas.php', ['csrf' => csrf_atual('/cobrancas.php'), 'acao' => 'pagar_manual',
    'id' => (string)$avulsa['id'], 'valor_pago' => '', 'meio' => 'dinheiro na sede']);
$avulsaPaga = pdo()->query('SELECT * FROM charges WHERE id=' . (int)$avulsa['id'])->fetch();
check('baixa manual da avulsa', $avulsaPaga['status'] === 'pago');
sleep(1);
$msgs = mailpit('/api/v1/messages');
$confAvulsa = false;
foreach ($msgs['messages'] ?? [] as $m) {
    if (str_contains($m['Subject'], 'Pagamento confirmado') && str_contains($m['Subject'], 'QSL')) $confAvulsa = true;
}
check('confirmação da avulsa com template próprio', $confAvulsa);
[$st, $html] = http('GET', '/comprovante.php?c=' . $avulsa['id'] . '&t=' . $avulsa['token']);
check('comprovante da avulsa igual ao da anuidade (cupom com a descrição)',
    $st === 200 && stripos($html, 'QSL registrado') !== false && str_contains($html, 'TOTAL R$'));

/* ----------------------------------------------------- 8. relatórios/CSV */

bloco('8. Relatórios, CSV e telas');

[$st, $html] = http('GET', '/situacao.php?ano=' . $anoAtual . '&imprimir=1');
check('relatório imprimível da situação', $st === 200 && str_contains($html, 'rel-tabela') && str_contains($html, 'Adimplentes'));
[$st, $html] = http('GET', '/associados.php?status=todos&csv=1');
check('CSV de associados com BOM e cabeçalho', $st === 200 && str_starts_with($html, "\xEF\xBB\xBF") && str_contains($html, 'Indicativo'));
[$st, $html] = http('GET', '/relatorios.php');
check('relatórios com gráficos SVG', $st === 200 && substr_count($html, '<svg') >= 2);
[$st] = http('GET', '/migrar.php');
check('tela de migrações acessível', $st === 200);

/* ------------------------------------------------------- 9. rate limit */

bloco('9. Rate-limit da consulta pública (por último, polui as tentativas)');

$csrfC = csrf_atual('/consulta.php');
for ($i = 1; $i <= 10; $i++) {
    http('POST', '/consulta.php', ['csrf' => $csrfC, 'termo' => 'NAOEXISTE' . $i, 'site' => '']);
}
[, $html] = http('POST', '/consulta.php', ['csrf' => $csrfC, 'termo' => 'PP5ABC', 'site' => '']);
check('11ª tentativa bloqueada', str_contains($html, 'Muitas consultas'));
pdo()->exec('DELETE FROM login_attempts'); // não deixa o ambiente bloqueado

/* ------------------------------------------------------------- resumo */

echo "\n----------------------------------------\n";
echo "PASSARAM: {$passou}   FALHARAM: {$falhas}\n";
@unlink($cookieJar);
exit($falhas > 0 ? 1 : 0);
