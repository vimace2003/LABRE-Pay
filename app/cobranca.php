<?php
/** Operações de cobrança compartilhadas entre painel, webhook e cron. */

require_once APP_DIR . '/valores.php';
require_once APP_DIR . '/mercadopago.php';
require_once APP_DIR . '/mailer.php';

function charge_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM charges WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function member_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM members WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Cria uma cobrança (anuidade ou avulsa) e devolve o registro completo. */
function charge_criar(int $memberId, int $ano, string $descricao, float $valor, string $vencimento, bool $isentaMulta, string $tipo = 'anuidade'): array
{
    $pdo = db();
    $st = $pdo->prepare('INSERT INTO charges (member_id, tipo, ano, descricao, valor, vencimento, isenta_multa, token) VALUES (?,?,?,?,?,?,?,?)');
    // token provisório único; o definitivo (HMAC do id) é gravado em seguida
    $st->execute([$memberId, $tipo, $ano, $descricao, number_format($valor, 2, '.', ''), $vencimento, $isentaMulta ? 1 : 0, bin2hex(random_bytes(20))]);
    $id = (int)$pdo->lastInsertId();
    $token = token_para('charge', $id);
    $pdo->prepare('UPDATE charges SET token = ? WHERE id = ?')->execute([$token, $id]);
    audit('cobranca_criada', 'charge', $id, "membro={$memberId} tipo={$tipo} ano={$ano} valor={$valor}");
    return charge_get($id);
}

/**
 * Gera (se preciso) o link de pagamento e envia o email de cobrança.
 * Retorna [ok(bool), mensagem(string)].
 */
function charge_enviar(array $charge, ?array $member = null, string $tipoEmail = 'cobranca'): array
{
    // Avulsas usam o template próprio (com {{descricao}})
    if (($charge['tipo'] ?? 'anuidade') === 'avulsa' && $tipoEmail === 'cobranca') {
        $tipoEmail = 'avulsa';
    }
    $member = $member ?: member_get((int)$charge['member_id']);
    if (!$member) return [false, 'Associado não encontrado.'];
    if (empty($member['email'])) return [false, $member['nome'] . ': sem email cadastrado.'];
    try {
        $charge = mp_garantir_preferencia($charge, $member);
    } catch (MercadoPagoException $ex) {
        return [false, $member['nome'] . ': ' . $ex->getMessage()];
    }
    $ok = mail_enviar_cobranca($tipoEmail, $member, $charge);
    return [$ok, $ok ? 'Enviado para ' . $member['email'] : $member['nome'] . ': falha no envio do email (ver registro de emails).'];
}

/** Marca uma cobrança como paga (via MP ou manualmente) e envia a confirmação. */
function charge_marcar_paga(array $charge, float $valorPago, ?string $mpPaymentId, ?string $meio, bool $manual = false): void
{
    if ($charge['status'] === 'pago') return;
    $devido = valor_devido($charge);
    $st = db()->prepare(
        'UPDATE charges SET status = "pago", valor_pago = ?, desconto_aplicado = ?, acrescimos_aplicados = ?,
         mp_payment_id = ?, meio_pagamento = ?, pago_em = NOW(), pago_manual = ? WHERE id = ?'
    );
    $st->execute([
        number_format($valorPago, 2, '.', ''),
        number_format($devido['desconto'], 2, '.', ''),
        number_format($devido['acrescimos'], 2, '.', ''),
        $mpPaymentId, $meio, $manual ? 1 : 0, $charge['id'],
    ]);
    audit($manual ? 'pagamento_manual' : 'pagamento_confirmado', 'charge', (int)$charge['id'], "valor={$valorPago} meio={$meio} mp={$mpPaymentId}");

    $charge = charge_get((int)$charge['id']);
    $member = member_get((int)$charge['member_id']);
    if ($member && !empty($member['email'])) {
        $tipoConfirmacao = ($charge['tipo'] ?? 'anuidade') === 'avulsa' ? 'avulsa_confirmacao' : 'confirmacao';
        mail_enviar_cobranca($tipoConfirmacao, $member, $charge);
    }
}

/** Processa um pagamento retornado pela API do MP contra a cobrança correspondente. */
function charge_processar_pagamento_mp(array $payment): void
{
    $ref = (string)($payment['external_reference'] ?? '');
    if (!preg_match('/^labrepay-(\d+)$/', $ref, $m)) return;
    $charge = charge_get((int)$m[1]);
    if (!$charge || $charge['status'] === 'pago' || $charge['status'] === 'cancelada') return;
    if (($payment['status'] ?? '') !== 'approved') return;

    $valorPago = (float)($payment['transaction_amount'] ?? 0);
    $devidoHoje = valor_devido($charge);
    // Tolerância: o pagamento pode ter sido feito com o valor de uma fase anterior
    // (ex.: pagou no último dia do desconto e o MP confirmou no dia seguinte).
    if ($valorPago + 0.01 < min($devidoHoje['valor'], (float)$charge['valor']) * 0.90) {
        audit('pagamento_valor_divergente', 'charge', (int)$charge['id'], 'recebido=' . $valorPago . ' devido=' . $devidoHoje['valor']);
    }
    $meio = (string)($payment['payment_type_id'] ?? '');
    charge_marcar_paga($charge, $valorPago, (string)$payment['id'], $meio);
}

/** Situação de um associado num ano: adimplente | inadimplente | isento | sem_cobranca. */
function situacao_do_membro(array $member, ?array $chargeDoAno): string
{
    if ($member['classe'] !== 'contribuinte') return 'isento';
    if (!$chargeDoAno) return 'sem_cobranca';
    if ($chargeDoAno['status'] === 'pago') return 'adimplente';
    if ($chargeDoAno['status'] === 'cancelada') return 'sem_cobranca';
    return 'inadimplente';
}
