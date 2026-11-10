<?php
/**
 * Cliente REST do Sicoob via cURL (sem SDK/Composer).
 * Docs: https://developers.sicoob.com.br/ (API Pix e API Cobrança Bancária v3)
 *
 * Ambiente "sandbox": autenticação por token Bearer fixo (sem OAuth), obtido
 * no portal de desenvolvedores junto com o client_id de teste.
 * Ambiente "producao": OAuth2 client_credentials com mTLS (certificado
 * e-CNPJ convertido para .pem/.key), token válido por ~300s.
 */

require_once APP_DIR . '/valores.php';

class SicoobException extends RuntimeException {}

/** Escopos combinados de Pix e Cobrança Bancária usados pelo LABRE-Pay. */
const SICOOB_ESCOPOS = 'cob.write cob.read pix.read webhook.write webhook.read boletos_inclusao boletos_consulta boletos_alteracao';

function sicoob_ambiente(): string
{
    return setting('sicoob_ambiente', 'sandbox') === 'producao' ? 'producao' : 'sandbox';
}

/** Configuração mínima para emitir Pix (obrigatória para o gateway Sicoob). */
function sicoob_configurado(): bool
{
    if (trim(setting('sicoob_client_id')) === '' || trim(setting('sicoob_chave_pix')) === '') {
        return false;
    }
    if (sicoob_ambiente() === 'producao') {
        return is_readable(trim(setting('sicoob_cert_path'))) && is_readable(trim(setting('sicoob_cert_key_path')));
    }
    return trim(setting('sicoob_sandbox_token')) !== '';
}

/** Configuração adicional exigida para emitir boleto registrado. */
function sicoob_boleto_configurado(): bool
{
    return sicoob_configurado()
        && trim(setting('sicoob_numero_cliente')) !== ''
        && trim(setting('sicoob_conta_corrente')) !== '';
}

function sicoob_base(string $api): string
{
    $producao = sicoob_ambiente() === 'producao';
    return match ($api) {
        'pix' => $producao
            ? 'https://api.sicoob.com.br/pix/api/v2'
            : 'https://sandbox.sicoob.com.br/sicoob/sandbox/pix/api/v2',
        'cobranca' => $producao
            ? 'https://api.sicoob.com.br/cobranca-bancaria/v3'
            : 'https://sandbox.sicoob.com.br/sicoob/sandbox/cobranca-bancaria/v3',
        default => throw new InvalidArgumentException('API Sicoob desconhecida: ' . $api),
    };
}

/**
 * Token de acesso. Em sandbox é o Bearer fixo cadastrado nas Configurações.
 * Em produção, obtém via OAuth2 client_credentials com mTLS e guarda em cache
 * (setting sicoob_token_cache) até ~30s antes de expirar.
 */
function sicoob_token(): string
{
    if (sicoob_ambiente() === 'sandbox') {
        $token = trim(setting('sicoob_sandbox_token'));
        if ($token === '') {
            throw new SicoobException('Token de sandbox do Sicoob não configurado (Configurações → Sicoob).');
        }
        return $token;
    }

    $agora = time();
    $cache = json_decode(setting('sicoob_token_cache'), true);
    if (is_array($cache) && !empty($cache['access_token']) && !empty($cache['expira_em']) && ($cache['expira_em'] - 30) > $agora) {
        return $cache['access_token'];
    }

    $clientId = trim(setting('sicoob_client_id'));
    $certPath = trim(setting('sicoob_cert_path'));
    $certKeyPath = trim(setting('sicoob_cert_key_path'));
    if ($clientId === '' || !is_readable($certPath) || !is_readable($certKeyPath)) {
        throw new SicoobException('Credenciais/certificado do Sicoob não configurados para produção (Configurações → Sicoob).');
    }

    $ch = curl_init('https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'scope' => SICOOB_ESCOPOS,
        ]),
        CURLOPT_SSLCERT => $certPath,
        CURLOPT_SSLKEY => $certKeyPath,
    ]);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno !== 0 || $resp === false) {
        throw new SicoobException('Falha de comunicação com o Sicoob (autenticação, cURL ' . $errno . ').');
    }
    $data = json_decode($resp, true);
    if ($status >= 400 || empty($data['access_token'])) {
        throw new SicoobException('Sicoob (autenticação) respondeu HTTP ' . $status . ': ' . mb_substr((string)$resp, 0, 300));
    }
    $expiraEm = $agora + (int)($data['expires_in'] ?? 300);
    setting_save('sicoob_token_cache', json_encode(['access_token' => $data['access_token'], 'expira_em' => $expiraEm]));
    return $data['access_token'];
}

/**
 * Requisição autenticada a uma API do Sicoob. Em produção anexa o certificado
 * mTLS em toda chamada (exigido pelo Sicoob, não só na autenticação).
 */
function sicoob_request(string $api, string $method, string $path, ?array $body = null, int $tentativa = 0): array
{
    $producao = sicoob_ambiente() === 'producao';
    $headers = [
        'client_id: ' . trim(setting('sicoob_client_id')),
        'Authorization: Bearer ' . sicoob_token(),
        'Content-Type: application/json',
    ];
    $ch = curl_init(sicoob_base($api) . $path);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    if ($producao) {
        $opts[CURLOPT_SSLCERT] = trim(setting('sicoob_cert_path'));
        $opts[CURLOPT_SSLKEY] = trim(setting('sicoob_cert_key_path'));
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno !== 0 || $resp === false) {
        throw new SicoobException('Falha de comunicação com o Sicoob (cURL ' . $errno . ').');
    }

    // Token expirado/inválido: força renovação e tenta mais uma vez (só em produção; em
    // sandbox o token é fixo e um 401 é erro de configuração, não expiração).
    if ($status === 401 && $tentativa === 0 && $producao) {
        setting_save('sicoob_token_cache', '');
        return sicoob_request($api, $method, $path, $body, $tentativa + 1);
    }

    $data = json_decode($resp, true);
    if ($status >= 400) {
        $msg = is_array($data) ? ($data['mensagem'] ?? $data['message'] ?? $resp) : $resp;
        throw new SicoobException('Sicoob respondeu HTTP ' . $status . ': ' . mb_substr((string)$msg, 0, 300));
    }
    return is_array($data) ? $data : [];
}

/**
 * txid determinístico por (cobrança, fase) — novo txid quando a fase de valor
 * muda, mesmo txid em retries dentro da mesma fase (PUT /cob é idempotente).
 * Formato exigido pelo BACEN: 26 a 35 caracteres alfanuméricos.
 */
function sicoob_txid(array $charge, string $fase): string
{
    $prefixo = 'LABREPAY' . str_pad((string)$charge['id'], 8, '0', STR_PAD_LEFT);
    $sufixo = substr(hash_hmac('sha256', $charge['id'] . '|' . $fase, APP_SECRET), 0, 16);
    return strtoupper($prefixo . $sufixo);
}

/**
 * Payload Pix (BR Code / EMV QRCPS-MPM — Manual de Padrões para Iniciação do
 * Pix do Bacen). Não é específico do Sicoob: monta o texto "copia e cola" e o
 * QR a partir do "location" devolvido pela cob — a API do Sicoob (como a de
 * vários outros PSPs) não devolve o pixCopiaECola pronto, só o location do
 * Pix dinâmico, então o payload precisa ser montado aqui.
 */
function pix_tlv(string $id, string $valor): string
{
    return $id . str_pad((string)strlen($valor), 2, '0', STR_PAD_LEFT) . $valor;
}

/** CRC16-CCITT (polinômio 0x1021, inicial 0xFFFF) — checksum exigido no fim do payload. */
function pix_crc16(string $payload): string
{
    $crc = 0xFFFF;
    for ($i = 0, $len = strlen($payload); $i < $len; $i++) {
        $crc ^= (ord($payload[$i]) << 8);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

/** Maiúsculas, sem acento, só [A-Z0-9 espaço] — exigido pelos campos de nome/cidade do payload. */
function pix_texto_seguro(string $s, int $max): string
{
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
    $s = preg_replace('/[^A-Za-z0-9 ]/', '', $s);
    $s = trim((string)$s);
    return $s !== '' ? mb_substr(strtoupper($s), 0, $max) : 'NA';
}

/** Monta o BR Code (texto "copia e cola") de um Pix dinâmico a partir do location da cob. */
function pix_montar_payload(string $location, string $txid): string
{
    $loc = preg_replace('#^https?://#', '', $location); // o manual do Bacen exige sem o esquema
    $contaRecebedor = pix_tlv('00', 'br.gov.bcb.pix') . pix_tlv('25', (string)$loc);
    $nome = pix_texto_seguro(setting('entidade_sigla', 'LABRE'), 25);
    $cidade = pix_texto_seguro(setting('entidade_cidade', ''), 15);
    $txidSeguro = mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $txid), 0, 25) ?: '***';

    $payload = pix_tlv('00', '01')                          // Payload Format Indicator
        . pix_tlv('01', '12')                                // Point of Initiation: 12 = dinâmico
        . pix_tlv('26', $contaRecebedor)                      // Merchant Account Information
        . pix_tlv('52', '0000')                               // Merchant Category Code
        . pix_tlv('53', '986')                                // Transaction Currency: BRL
        . pix_tlv('58', 'BR')                                 // Country Code
        . pix_tlv('59', $nome)                                // Merchant Name
        . pix_tlv('60', $cidade)                              // Merchant City
        . pix_tlv('62', pix_tlv('05', $txidSeguro));          // Additional Data Field: txid

    return $payload . '6304' . pix_crc16($payload . '6304');
}

/**
 * Garante que a cobrança tem uma cob Pix válida para o valor devido HOJE.
 * Regenera (novo txid) quando não existe ou quando a fase de valor mudou.
 * Retorna a charge atualizada (com sicoob_txid/pix_copia_cola/pix_location).
 */
function sicoob_garantir_pix(array $charge, array $member): array
{
    $devido = valor_devido($charge);
    $hoje = date('Y-m-d');
    $precisaNova = empty($charge['sicoob_txid'])
        || ($charge['sicoob_pix_expira'] !== null && substr($charge['sicoob_pix_expira'], 0, 10) < $hoje);

    if (!$precisaNova) {
        return $charge;
    }

    $expiraData = $devido['fase_expira'] ?? date('Y-m-d', strtotime('+180 days'));
    $segundosExpiracao = max(3600, strtotime($expiraData . ' 23:59:59') - time());
    $txid = sicoob_txid($charge, $devido['fase']);

    $payload = [
        'calendario' => ['expiracao' => $segundosExpiracao],
        'valor' => ['original' => number_format((float)$devido['valor'], 2, '.', '')],
        'chave' => setting('sicoob_chave_pix'),
        'solicitacaoPagador' => mb_substr($charge['descricao'], 0, 140),
    ];
    $cpfCnpj = so_digitos((string)($member['cpf_cnpj'] ?? ''));
    if (strlen($cpfCnpj) === 11) {
        $payload['devedor'] = ['cpf' => $cpfCnpj, 'nome' => $member['nome']];
    } elseif (strlen($cpfCnpj) === 14) {
        $payload['devedor'] = ['cnpj' => $cpfCnpj, 'nome' => $member['nome']];
    }

    $resp = sicoob_request('pix', 'PUT', '/cob/' . $txid, $payload);
    $location = $resp['location'] ?? null;
    // A API do Sicoob não devolve o pixCopiaECola pronto — só o location do Pix
    // dinâmico; o BR Code é montado aqui a partir dele (ver pix_montar_payload).
    $copiaCola = $resp['pixCopiaECola'] ?? ($location ? pix_montar_payload($location, $txid) : null);
    $expiraDateTime = $expiraData . ' 23:59:59';

    db()->prepare('UPDATE charges SET sicoob_txid = ?, sicoob_pix_copia_cola = ?, sicoob_pix_location = ?, sicoob_pix_expira = ? WHERE id = ?')
        ->execute([$txid, $copiaCola, $location, $expiraDateTime, $charge['id']]);

    $charge['sicoob_txid'] = $txid;
    $charge['sicoob_pix_copia_cola'] = $copiaCola;
    $charge['sicoob_pix_location'] = $location;
    $charge['sicoob_pix_expira'] = $expiraDateTime;
    return $charge;
}

function sicoob_consultar_pix(string $txid): array
{
    return sicoob_request('pix', 'GET', '/cob/' . rawurlencode($txid));
}

/** Remove (expira manualmente) uma cob Pix — usada no cancelamento da cobrança. */
function sicoob_remover_pix(string $txid): void
{
    sicoob_request('pix', 'PATCH', '/cob/' . rawurlencode($txid), ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR']);
}

/**
 * Emite o boleto registrado UMA ÚNICA VEZ para a cobrança, com desconto por
 * antecipação, multa e juros de mora registrados nativamente (o banco calcula
 * o valor pela data de pagamento — sem necessidade de reemissão).
 *
 * Os códigos de tipoDesconto/tipoMulta/tipoJurosMora seguem a convenção comum
 * às APIs de cobrança bancária do segmento (1=valor fixo, 2=percentual);
 * CONFIRMAR no sandbox do Sicoob antes de usar em produção.
 */
function sicoob_garantir_boleto(array $charge, array $member): array
{
    if (!empty($charge['sicoob_nosso_numero'])) {
        return $charge;
    }

    $enderecoCompleto = trim($member['endereco'] . (($member['numero'] ?? '') !== '' ? ', ' . $member['numero'] : ', S/N')
        . (($member['complemento'] ?? '') !== '' ? ' - ' . $member['complemento'] : ''));

    $payload = [
        'numeroCliente' => (int)setting('sicoob_numero_cliente'),
        'codigoModalidade' => (int)setting('sicoob_codigo_modalidade', '1'),
        'numeroContaCorrente' => (int)setting('sicoob_conta_corrente'),
        'codigoEspecieDocumento' => 'DM',
        'dataEmissao' => date('Y-m-d'),
        'seuNumero' => 'labrepay-' . $charge['id'],
        'identificacaoEmissaoBoleto' => 1,
        'identificacaoDistribuicaoBoleto' => 1,
        'valor' => (float)$charge['valor'],
        'dataVencimento' => $charge['vencimento'],
        'pagador' => [
            'numeroCpfCnpj' => so_digitos((string)$member['cpf_cnpj']),
            'nome' => $member['nome'],
            'endereco' => $enderecoCompleto,
            'bairro' => $member['bairro'],
            'cidade' => $member['cidade'],
            'cep' => so_digitos((string)$member['cep']),
            'uf' => $member['uf'],
        ],
        'gerarPdf' => false,
    ];

    if (empty($charge['isenta_multa'])) {
        $diaSeguinte = date('Y-m-d', strtotime($charge['vencimento'] . ' +1 day'));
        $multaPct = (float)setting('multa_percent', '0');
        if ($multaPct > 0) {
            $payload['tipoMulta'] = 2; // percentual
            $payload['valorMulta'] = $multaPct;
            $payload['dataMulta'] = $diaSeguinte;
        }
        $jurosPct = (float)setting('juros_mes_percent', '0');
        if ($jurosPct > 0) {
            $payload['tipoJurosMora'] = 2; // taxa mensal
            $payload['valorJurosMora'] = $jurosPct;
            $payload['dataJurosMora'] = $diaSeguinte;
        }
    }

    $limiteDesconto = ($charge['tipo'] ?? 'anuidade') === 'anuidade' ? desconto_data_limite($charge) : null;
    if ($limiteDesconto !== null) {
        $tipoDesconto = setting('desconto_tipo', 'percent');
        $payload['tipoDesconto'] = $tipoDesconto === 'percent' ? 2 : 1;
        $payload['valorPrimeiroDesconto'] = (float)setting('desconto_valor', '0');
        $payload['dataPrimeiroDesconto'] = $limiteDesconto;
    }

    $resp = sicoob_request('cobranca', 'POST', '/boletos', $payload);
    $resultado = $resp['resultado'] ?? $resp;
    $nossoNumero = $resultado['nossoNumero'] ?? null;
    if (!$nossoNumero) {
        throw new SicoobException('Sicoob não retornou o nosso número do boleto.');
    }

    db()->prepare('UPDATE charges SET sicoob_nosso_numero = ?, sicoob_linha_digitavel = ?, sicoob_codigo_barras = ? WHERE id = ?')
        ->execute([$nossoNumero, $resultado['linhaDigitavel'] ?? null, $resultado['codigoBarras'] ?? null, $charge['id']]);

    $charge['sicoob_nosso_numero'] = $nossoNumero;
    $charge['sicoob_linha_digitavel'] = $resultado['linhaDigitavel'] ?? null;
    $charge['sicoob_codigo_barras'] = $resultado['codigoBarras'] ?? null;
    return $charge;
}

function sicoob_consultar_boleto(string $nossoNumero): ?array
{
    $qs = http_build_query([
        'numeroCliente' => setting('sicoob_numero_cliente'),
        'codigoModalidade' => setting('sicoob_codigo_modalidade', '1'),
        'nossoNumero' => $nossoNumero,
    ]);
    $resp = sicoob_request('cobranca', 'GET', '/boletos?' . $qs);
    $lista = $resp['resultado'] ?? $resp;
    if (isset($lista['nossoNumero'])) return $lista;
    if (is_array($lista) && isset($lista[0])) return $lista[0];
    return null;
}

/** Baixa (ou gera) o PDF do boleto sob demanda — nunca gravado em disco (LGPD). */
function sicoob_pdf_boleto(string $nossoNumero): string
{
    $qs = http_build_query([
        'numeroCliente' => setting('sicoob_numero_cliente'),
        'codigoModalidade' => setting('sicoob_codigo_modalidade', '1'),
        'nossoNumero' => $nossoNumero,
        'gerarPdf' => 'true',
    ]);
    $resp = sicoob_request('cobranca', 'GET', '/boletos?' . $qs);
    $lista = $resp['resultado'] ?? $resp;
    $boleto = isset($lista['nossoNumero']) ? $lista : ($lista[0] ?? null);
    $pdfBase64 = $boleto['pdfBoleto'] ?? null;
    if (!$pdfBase64) {
        throw new SicoobException('Sicoob não retornou o PDF do boleto.');
    }
    return base64_decode($pdfBase64);
}

function sicoob_baixar_boleto(string $nossoNumero): void
{
    sicoob_request('cobranca', 'POST', '/boletos/' . rawurlencode($nossoNumero) . '/baixar', []);
}

/** Registra a URL de webhook de recebimentos Pix junto à chave cadastrada. */
function sicoob_registrar_webhook(string $url): void
{
    $chave = setting('sicoob_chave_pix');
    if ($chave === '') {
        throw new SicoobException('Cadastre a chave Pix do Sicoob antes de registrar o webhook.');
    }
    sicoob_request('pix', 'PUT', '/webhook/' . rawurlencode($chave), ['webhookUrl' => $url]);
}
