<?php
/**
 * Envio de emails via PHPMailer/SMTP com templates personalizáveis.
 * Em ambientes não-produtivos, TODO email é desviado para MAIL_OVERRIDE_TO
 * e o assunto ganha o prefixo [TESTE] — associado real nunca recebe teste.
 */

require_once APP_DIR . '/lib/PHPMailer/PHPMailer.php';
require_once APP_DIR . '/lib/PHPMailer/SMTP.php';
require_once APP_DIR . '/lib/PHPMailer/Exception.php';
require_once APP_DIR . '/valores.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envia um email e registra em email_log.
 * Retorna true/false; nunca lança exceção (falha de email não pode travar lote).
 */
function mail_enviar(string $para, string $paraNome, string $assunto, string $corpoHtml, string $tipo, ?int $chargeId = null, ?int $memberId = null): bool
{
    $destinoReal = $para;
    if (!is_production()) {
        $assunto = '[TESTE] ' . $assunto;
        $override = MAIL_OVERRIDE_TO;
        if ($override !== '') {
            $corpoHtml = '<p style="background:#fff3cd;padding:8px;border:1px solid #ffc107">' .
                'Ambiente de testes — destinatário original: <strong>' . e($para) . '</strong></p>' . $corpoHtml;
            $destinoReal = $override;
        }
    }

    $mail = new PHPMailer(true);
    $status = 'enviado';
    $erro = null;
    try {
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Host = setting('smtp_host');
        $mail->Port = (int)setting('smtp_porta', '587');
        $seg = setting('smtp_seguranca', 'tls');
        if ($seg === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($seg === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $usuario = setting('smtp_usuario');
        if ($usuario !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $usuario;
            $mail->Password = setting('smtp_senha');
        }
        $mail->setFrom(setting('smtp_remetente_email', $usuario), setting('smtp_remetente_nome', setting('entidade_sigla', 'LABRE')));
        $mail->addAddress($destinoReal, $paraNome);
        $mail->isHTML(true);
        $mail->Subject = $assunto;
        $mail->Body = mail_moldura($corpoHtml);
        $mail->AltBody = strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $corpoHtml));
        $mail->send();
    } catch (Throwable $ex) {
        $status = 'erro';
        $erro = $ex->getMessage();
    }

    $st = db()->prepare('INSERT INTO email_log (charge_id, member_id, destinatario, assunto, tipo, status, erro) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$chargeId, $memberId, $destinoReal, mb_substr($assunto, 0, 190), $tipo, $status, $erro]);
    return $status === 'enviado';
}

/** Moldura HTML padrão dos emails (simples, alto contraste, sem imagens externas). */
function mail_moldura(string $conteudo): string
{
    $nome = e(setting('entidade_nome', 'LABRE'));
    $rodape = e(setting('entidade_site', ''));
    $logoRel = setting('logo_arquivo');
    $logoImg = $logoRel !== ''
        ? '<img src="' . e(BASE_URL . '/' . $logoRel) . '" alt="" style="max-height:42px;vertical-align:middle;margin-right:12px">'
        : '';
    $corTema = TEMAS[tema_atual()]['primaria'];
    return '<!DOCTYPE html><html lang="pt-BR"><body style="margin:0;background:#f2f4f7;font-family:Arial,Helvetica,sans-serif;color:#1a2733">' .
        '<div style="max-width:600px;margin:0 auto;padding:24px 16px">' .
        '<div style="background:' . e($corTema) . ';color:#fff;padding:16px 24px;border-radius:8px 8px 0 0;font-size:20px;font-weight:bold">' . $logoImg . $nome . '</div>' .
        '<div style="background:#ffffff;padding:24px;border-radius:0 0 8px 8px;font-size:16px;line-height:1.6">' . $conteudo . '</div>' .
        '<p style="text-align:center;color:#5a6b7b;font-size:13px;margin-top:16px">' . $rodape .
        '<br>Este email foi enviado pelo sistema de anuidades. Dúvidas: ' . e(setting('entidade_email_contato', '')) . '</p>' .
        '</div></body></html>';
}

/** Substitui os placeholders {{...}} de um template configurável. */
function mail_template(string $qual, array $member, array $charge): array
{
    $devido = valor_devido($charge);
    $linkPagar = BASE_URL . '/pagar.php?c=' . $charge['id'] . '&t=' . $charge['token'];
    $linkComprovante = BASE_URL . '/comprovante.php?c=' . $charge['id'] . '&t=' . $charge['token'];
    $botao = '<a href="' . e($linkPagar) . '" style="display:inline-block;background:#0a7d33;color:#ffffff;text-decoration:none;' .
        'padding:14px 28px;border-radius:8px;font-size:17px;font-weight:bold">Pagar agora — ' . e(fmt_moeda($devido['valor'])) . '</a>';

    $troca = [
        '{{nome}}' => e($member['nome']),
        '{{indicativo}}' => e($member['indicativo'] ?: '—'),
        '{{ano}}' => (string)$charge['ano'],
        '{{valor}}' => e(fmt_moeda($devido['valor'])),
        '{{valor_original}}' => e(fmt_moeda((float)$charge['valor'])),
        '{{vencimento}}' => e(fmt_data($charge['vencimento'])),
        '{{entidade}}' => e(setting('entidade_nome', 'LABRE')),
        '{{sigla}}' => e(setting('entidade_sigla', 'LABRE')),
        '{{link_pagamento}}' => e($linkPagar),
        '{{botao_pagar}}' => $botao,
        '{{link_comprovante}}' => '<a href="' . e($linkComprovante) . '">' . e($linkComprovante) . '</a>',
    ];
    $assunto = strtr(setting('email_' . $qual . '_assunto'), array_map('strip_tags', $troca));
    $corpo = strtr(setting('email_' . $qual . '_corpo'), $troca);
    return [$assunto, $corpo];
}

/** Envia o email de um tipo (cobranca | lembrete | confirmacao) para a cobrança. */
function mail_enviar_cobranca(string $tipo, array $member, array $charge): bool
{
    if (empty($member['email'])) return false;
    [$assunto, $corpo] = mail_template($tipo, $member, $charge);
    $ok = mail_enviar($member['email'], $member['nome'], $assunto, $corpo, $tipo, (int)$charge['id'], (int)$member['id']);
    if ($ok && $tipo === 'cobranca') {
        db()->prepare('UPDATE charges SET enviado_em = NOW() WHERE id = ?')->execute([$charge['id']]);
    }
    return $ok;
}
