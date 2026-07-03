<?php
/** Configurações da entidade, valores, MercadoPago, SMTP e templates de email. */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/layout.php';
require APP_DIR . '/mailer.php';

$user = require_login();

/* Campos editáveis (whitelist) */
$campos = [
    'entidade_nome', 'entidade_sigla', 'entidade_cnpj', 'entidade_site', 'entidade_email_contato', 'tema',
    'anuidade_valor', 'venc_dia', 'venc_mes', 'prazo_venc_meses',
    'multa_percent', 'juros_mes_percent',
    'desconto_ativo', 'desconto_tipo', 'desconto_valor', 'desconto_dia', 'desconto_mes',
    'taxa_admissao_ativa', 'taxa_admissao_valor', 'taxa_retorno_ativa', 'taxa_retorno_valor',
    'meses_exclusao_auto', 'lembretes_ativos', 'lembrete_dias_antes', 'lembrete_dias_depois',
    'mp_access_token', 'mp_public_key', 'mp_webhook_secret',
    'smtp_host', 'smtp_porta', 'smtp_usuario', 'smtp_senha', 'smtp_seguranca',
    'smtp_remetente_email', 'smtp_remetente_nome',
    'backup_ativo', 'backup_copias',
    'email_cobranca_assunto', 'email_cobranca_corpo',
    'email_lembrete_assunto', 'email_lembrete_corpo',
    'email_confirmacao_assunto', 'email_confirmacao_corpo',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['acao'] ?? '') === 'logo') {
        $arq = $_FILES['logo'] ?? null;
        if (!$arq || $arq['error'] !== UPLOAD_ERR_OK) {
            flash_set('erro', 'Falha no envio do arquivo.');
            redirect('configuracoes.php');
        }
        if ($arq['size'] > 2 * 1024 * 1024) {
            flash_set('erro', 'A logo deve ter no máximo 2 MB.');
            redirect('configuracoes.php');
        }
        $info = @getimagesize($arq['tmp_name']);
        $tipos = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!$info || !isset($tipos[$info['mime']])) {
            flash_set('erro', 'Formato inválido — envie uma imagem PNG, JPG ou WebP.');
            redirect('configuracoes.php');
        }
        $dir = __DIR__ . '/assets/uploads';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        // remove a logo anterior
        foreach (glob($dir . '/logo-*') ?: [] as $antiga) @unlink($antiga);
        $nome = 'logo-' . time() . '.' . $tipos[$info['mime']];
        if (!move_uploaded_file($arq['tmp_name'], $dir . '/' . $nome)) {
            flash_set('erro', 'Sem permissão para gravar em assets/uploads/.');
            redirect('configuracoes.php');
        }
        setting_save('logo_arquivo', 'assets/uploads/' . $nome);
        audit('logo_atualizada');
        flash_set('ok', 'Logotipo atualizado! Ele aparece no painel, na consulta pública, nos relatórios, no comprovante e nos emails.');
        redirect('configuracoes.php');
    }

    if (($_POST['acao'] ?? '') === 'logo_remover') {
        foreach (glob(__DIR__ . '/assets/uploads/logo-*') ?: [] as $antiga) @unlink($antiga);
        setting_save('logo_arquivo', '');
        audit('logo_removida');
        flash_set('ok', 'Logotipo removido — o sistema volta a mostrar apenas a sigla.');
        redirect('configuracoes.php');
    }

    if (($_POST['acao'] ?? '') === 'zerar_dados') {
        if (is_production()) {
            flash_set('erro', 'Zerar dados não é permitido em produção.');
            redirect('configuracoes.php');
        }
        if (strtoupper(trim($_POST['confirmacao'] ?? '')) !== 'ZERAR') {
            flash_set('erro', 'Para zerar os dados, digite ZERAR no campo de confirmação.');
            redirect('configuracoes.php');
        }
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['charges', 'members', 'email_log', 'login_attempts', 'audit_log'] as $t) {
            $pdo->exec("TRUNCATE TABLE {$t}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        audit('dados_de_teste_zerados', null, null, 'associados, cobranças e logs apagados; configurações e usuários preservados');
        flash_set('ok', 'Dados de teste zerados! Associados, cobranças e logs foram apagados. Configurações e usuários foram mantidos.');
        redirect('configuracoes.php');
    }

    if (($_POST['acao'] ?? '') === 'testar_email') {
        $ok = mail_enviar($user['email'], $user['nome'], 'Teste de envio — ' . setting('entidade_sigla'),
            '<p>Se você recebeu este email, o SMTP está configurado corretamente.</p>', 'teste');
        flash_set($ok ? 'ok' : 'erro', $ok ? 'Email de teste enviado para ' . $user['email'] . (is_production() ? '' : ' (desviado para o dummy em ambiente de testes)') : 'Falha no envio — confira os dados de SMTP e o registro de emails.');
        redirect('configuracoes.php');
    }

    if (isset($_POST['tema']) && !isset(TEMAS[$_POST['tema']])) {
        unset($_POST['tema']); // valor desconhecido é ignorado
    }

    $token = trim((string)($_POST['mp_access_token'] ?? ''));
    if ($token !== '') {
        if (is_production() && str_starts_with($token, 'TEST-')) {
            flash_set('erro', 'Este ambiente é de PRODUÇÃO: use o access token de produção (APP_USR-…), não o de teste.');
            redirect('configuracoes.php');
        }
        if (!is_production() && str_starts_with($token, 'APP_USR-')) {
            flash_set('ok', 'ATENÇÃO: token APP_USR- salvo em ambiente de testes. Certifique-se de que ele pertence a uma CONTA DE VENDEDOR DE TESTE do MercadoPago — se for de uma conta real, as cobranças geradas serão pagamentos de verdade!');
        }
    }

    foreach ($campos as $k) {
        if (array_key_exists($k, $_POST)) {
            $v = trim((string)$_POST[$k]);
            if (in_array($k, ['anuidade_valor', 'desconto_valor', 'taxa_admissao_valor', 'taxa_retorno_valor'], true)) {
                $v = str_replace(',', '.', $v);
            }
            setting_save($k, $v);
        }
    }
    // checkboxes desmarcados não vêm no POST
    foreach (['desconto_ativo', 'taxa_admissao_ativa', 'taxa_retorno_ativa', 'lembretes_ativos', 'backup_ativo'] as $chk) {
        setting_save($chk, isset($_POST[$chk]) ? '1' : '0');
    }
    audit('configuracoes_alteradas');
    flash_set('ok', 'Configurações salvas.');
    redirect('configuracoes.php');
}

function campo(string $k, string $rotulo, string $tipo = 'text', string $dica = ''): void
{
    $v = setting($k);
    // O editor rico não pode viver dentro de <label> (o clique na área de edição
    // desviaria o foco para o textarea oculto), então usa um <div> próprio.
    if ($tipo === 'editor') {
        echo '<div class="campo-editor"><span class="campo-rotulo">' . e($rotulo) . '</span>';
        if ($dica) echo '<span class="dica">' . e($dica) . '</span>';
        echo '<textarea name="' . e($k) . '" rows="7" class="editor-rico">' . e($v) . '</textarea></div>';
        return;
    }
    echo '<label>' . e($rotulo);
    if ($dica) echo '<span class="dica">' . e($dica) . '</span>';
    if ($tipo === 'textarea') {
        echo '<textarea name="' . e($k) . '" rows="5">' . e($v) . '</textarea>';
    } else {
        echo '<input type="' . e($tipo) . '" name="' . e($k) . '" value="' . e($v) . '">';
    }
    echo '</label>';
}

function chk(string $k, string $rotulo): void
{
    $on = setting($k) === '1' ? 'checked' : '';
    echo '<label style="flex-direction:row;align-items:center;gap:.6rem"><input type="checkbox" name="' . e($k) . '" value="1" ' . $on . ' style="width:auto;min-height:auto">' . e($rotulo) . '</label>';
}

page_header('Configurações', 'configuracoes.php', $user);
?>

<form method="post" class="form-grid">
  <?= csrf_field() ?>

  <div class="cartao form-grid">
    <h2 style="margin-top:0">Entidade</h2>
    <div class="linha-campos">
      <?php campo('entidade_nome', 'Nome da entidade'); ?>
      <?php campo('entidade_sigla', 'Sigla'); ?>
      <?php campo('entidade_cnpj', 'CNPJ', 'text', 'Aparece no comprovante de pagamento'); ?>
    </div>
    <div class="linha-campos">
      <?php campo('entidade_site', 'Site'); ?>
      <?php campo('entidade_email_contato', 'Email de contato', 'email'); ?>
    </div>
  </div>

  <div class="cartao form-grid">
    <h2 style="margin-top:0">Aparência (cores do sistema)</h2>
    <p class="texto-suave">Escolha a paleta que combina com a sua entidade. Vale para o painel,
       a consulta pública, os relatórios impressos e o cabeçalho dos emails.
       As cores de status (pago/pendente/vencido) não mudam.</p>
    <div class="temas-grid">
      <?php foreach (TEMAS as $chave => $t): ?>
        <label class="tema-opcao <?= tema_atual() === $chave ? 'ativo' : '' ?>">
          <input type="radio" name="tema" value="<?= e($chave) ?>" <?= tema_atual() === $chave ? 'checked' : '' ?>>
          <span class="tema-cores">
            <span style="background:<?= e($t['primaria']) ?>"></span><span style="background:<?= e($t['clara']) ?>"></span>
          </span>
          <?= e($t['nome']) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="cartao form-grid">
    <h2 style="margin-top:0">Anuidade e vencimento</h2>
    <div class="linha-campos">
      <?php campo('anuidade_valor', 'Valor da anuidade (R$)'); ?>
      <?php campo('venc_dia', 'Dia do vencimento', 'number', 'Estatuto LABRE-SC: 31'); ?>
      <?php campo('venc_mes', 'Mês do vencimento', 'number', 'Estatuto LABRE-SC: 1 (janeiro)'); ?>
      <?php campo('prazo_venc_meses', 'Prazo p/ cobranças fora do ciclo (meses)', 'number', 'Cobrança emitida após o vencimento anual (adesões, lotes atrasados) vence N meses após a emissão — depois disso, multa'); ?>
    </div>
  </div>

  <div class="cartao form-grid">
    <h2 style="margin-top:0">Multa e juros por atraso</h2>
    <div class="linha-campos">
      <?php campo('multa_percent', 'Multa (%)', 'number', 'Aplicada uma vez após o vencimento (usual: 2%)'); ?>
      <?php campo('juros_mes_percent', 'Juros de mora (% ao mês)', 'number', 'Proporcional por dia de atraso (usual: 1%)'); ?>
    </div>
  </div>

  <div class="cartao form-grid">
    <h2 style="margin-top:0">Desconto por antecipação</h2>
    <?php chk('desconto_ativo', 'Oferecer desconto para pagamento antecipado'); ?>
    <div class="linha-campos">
      <label>Tipo
        <select name="desconto_tipo">
          <option value="percent" <?= setting('desconto_tipo') === 'percent' ? 'selected' : '' ?>>Percentual (%)</option>
          <option value="fixo" <?= setting('desconto_tipo') === 'fixo' ? 'selected' : '' ?>>Valor fixo (R$)</option>
        </select>
      </label>
      <?php campo('desconto_valor', 'Desconto'); ?>
      <?php campo('desconto_dia', 'Válido até (dia)', 'number'); ?>
      <?php campo('desconto_mes', 'Válido até (mês)', 'number', 'Ex.: 31/12 = paga com desconto até o fim do ano anterior ao vencimento'); ?>
    </div>
  </div>

  <div class="cartao form-grid">
    <h2 style="margin-top:0">Taxas de admissão e retorno</h2>
    <?php chk('taxa_admissao_ativa', 'Cobrar taxa de expediente na admissão de novos associados (Art. 29)'); ?>
    <?php campo('taxa_admissao_valor', 'Valor da taxa de admissão (R$)'); ?>
    <?php chk('taxa_retorno_ativa', 'Cobrar taxa de retorno na readmissão de associados desligados'); ?>
    <?php campo('taxa_retorno_valor', 'Valor da taxa de retorno (R$)'); ?>
  </div>

  <div class="cartao form-grid">
    <h2 style="margin-top:0">Lembretes e inadimplência</h2>
    <?php chk('lembretes_ativos', 'Enviar lembretes automáticos (requer cron configurado)'); ?>
    <div class="linha-campos">
      <?php campo('lembrete_dias_antes', 'Lembrar quantos dias ANTES do vencimento', 'number'); ?>
      <?php campo('lembrete_dias_depois', 'Lembrar quantos dias DEPOIS do vencimento', 'number'); ?>
      <?php campo('meses_exclusao_auto', 'Alerta de exclusão automática após (meses)', 'number', 'Estatuto Art. 40: 3 meses'); ?>
    </div>
  </div>

  <div class="cartao form-grid">
    <h2 style="margin-top:0">MercadoPago</h2>
    <p class="texto-suave">Ambiente atual: <strong><?= is_production() ? 'PRODUÇÃO — use as credenciais de produção (APP_USR-…) da conta real da entidade' : 'TESTES — use as credenciais de uma conta de VENDEDOR DE TESTE (crie em "Contas de teste" no painel do MP; o token dela começa com APP_USR-, mas é sandbox)' ?></strong>.
       Obtenha em <a href="https://www.mercadopago.com.br/developers/panel/app" target="_blank" rel="noopener">mercadopago.com.br/developers</a>.</p>
    <?php campo('mp_access_token', 'Access token', 'password'); ?>
    <?php campo('mp_public_key', 'Public key'); ?>
    <?php campo('mp_webhook_secret', 'Assinatura secreta do webhook', 'password', 'Configure o webhook no painel do MP apontando para ' . BASE_URL . '/webhook.php e cole aqui a assinatura secreta'); ?>
  </div>

  <div class="cartao form-grid">
    <h2 style="margin-top:0">Envio de email (SMTP)</h2>
    <?php if (!is_production()): ?>
      <p class="texto-suave">Ambiente de testes: todos os emails são desviados para <strong><?= e(MAIL_OVERRIDE_TO ?: '(dummy não configurado!)') ?></strong> com assunto [TESTE].</p>
    <?php endif; ?>
    <div class="linha-campos">
      <?php campo('smtp_host', 'Servidor SMTP', 'text', 'Ex.: mail.labre-sc.org.br'); ?>
      <?php campo('smtp_porta', 'Porta', 'number', '587 (TLS) ou 465 (SSL)'); ?>
      <label>Segurança
        <select name="smtp_seguranca">
          <option value="tls" <?= setting('smtp_seguranca') === 'tls' ? 'selected' : '' ?>>STARTTLS (porta 587)</option>
          <option value="ssl" <?= setting('smtp_seguranca') === 'ssl' ? 'selected' : '' ?>>SSL (porta 465)</option>
          <option value="nenhuma" <?= setting('smtp_seguranca') === 'nenhuma' ? 'selected' : '' ?>>Nenhuma (só testes locais)</option>
        </select>
      </label>
    </div>
    <div class="linha-campos">
      <?php campo('smtp_usuario', 'Usuário'); ?>
      <?php campo('smtp_senha', 'Senha', 'password'); ?>
    </div>
    <div class="linha-campos">
      <?php campo('smtp_remetente_email', 'Email remetente', 'email'); ?>
      <?php campo('smtp_remetente_nome', 'Nome do remetente'); ?>
    </div>
  </div>

  <div class="cartao form-grid">
    <h2 style="margin-top:0">Backup automático</h2>
    <?php chk('backup_ativo', 'Gerar backup diário do banco pelo cron'); ?>
    <?php campo('backup_copias', 'Quantas cópias manter', 'number'); ?>
  </div>

  <div class="cartao form-grid">
    <h2 style="margin-top:0">Templates de email</h2>
    <p class="texto-suave">Campos disponíveis: {{nome}}, {{indicativo}}, {{ano}}, {{valor}}, {{valor_original}},
       {{vencimento}}, {{entidade}}, {{sigla}}, {{botao_pagar}}, {{link_pagamento}}, {{link_comprovante}}</p>
    <?php campo('email_cobranca_assunto', 'Cobrança — assunto'); ?>
    <?php campo('email_cobranca_corpo', 'Cobrança — corpo', 'editor'); ?>
    <?php campo('email_lembrete_assunto', 'Lembrete — assunto'); ?>
    <?php campo('email_lembrete_corpo', 'Lembrete — corpo', 'editor'); ?>
    <?php campo('email_confirmacao_assunto', 'Confirmação de pagamento — assunto'); ?>
    <?php campo('email_confirmacao_corpo', 'Confirmação de pagamento — corpo', 'editor'); ?>
  </div>

  <div style="display:flex;gap:.7rem;flex-wrap:wrap">
    <button type="submit" class="botao botao-primario">Salvar configurações</button>
  </div>
</form>

<div class="cartao form-grid" style="margin-top:1rem">
  <h2 style="margin-top:0">Logotipo da entidade</h2>
  <?php $logo = logo_url(); ?>
  <?php if ($logo): ?>
    <p><img src="<?= e($logo) ?>" alt="Logotipo atual" style="max-height:80px;max-width:240px"></p>
  <?php else: ?>
    <p class="texto-suave">Nenhuma logo enviada — o sistema mostra a sigla da entidade.</p>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:.7rem;flex-wrap:wrap;align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="logo">
    <label>Nova logo (PNG, JPG ou WebP, até 2 MB — ideal: fundo transparente)
      <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
    </label>
    <button type="submit" class="botao botao-primario">Enviar logo</button>
  </form>
  <?php if ($logo): ?>
    <form method="post" data-confirmar="Remover o logotipo?">
      <?= csrf_field() ?>
      <input type="hidden" name="acao" value="logo_remover">
      <button type="submit" class="botao botao-perigo botao-mini">Remover logo</button>
    </form>
  <?php endif; ?>
</div>

<form method="post" style="margin-top:1rem">
  <?= csrf_field() ?>
  <input type="hidden" name="acao" value="testar_email">
  <button type="submit" class="botao">Enviar email de teste para mim</button>
</form>

<?php if (!is_production()): ?>
<div class="cartao" style="margin-top:1rem;border-color:var(--vermelho)">
  <h2 style="margin-top:0;color:var(--vermelho)">Zona de perigo — só existe em ambiente de testes</h2>
  <p>Apaga <strong>todos os associados, cobranças e registros de log</strong> para recomeçar os testes do zero.
     As <strong>configurações</strong> (MercadoPago, SMTP, templates, tema, logo) e os <strong>usuários do painel</strong> são preservados.
     Esta ação não pode ser desfeita.</p>
  <form method="post" data-confirmar="Apagar TODOS os associados, cobranças e logs deste ambiente de testes? Esta ação não pode ser desfeita." style="display:flex;gap:.7rem;flex-wrap:wrap;align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="zerar_dados">
    <label>Digite ZERAR para confirmar
      <input type="text" name="confirmacao" autocomplete="off" placeholder="ZERAR">
    </label>
    <button type="submit" class="botao botao-perigo">Zerar dados de teste</button>
  </form>
</div>
<?php endif; ?>

<div class="cartao" style="margin-top:1rem">
  <h2 style="margin-top:0">Informações do ambiente</h2>
  <p>Ambiente: <strong><?= e(APP_ENV) ?></strong> · URL base: <strong><?= e(BASE_URL) ?></strong></p>
  <p>URL do cron (se não usar CLI): <code><?= e(BASE_URL . '/cron.php?token=' . setting('cron_token')) ?></code></p>
  <p>URL do webhook para o painel do MercadoPago: <code><?= e(BASE_URL . '/webhook.php') ?></code></p>
</div>

<?php page_footer(); ?>
