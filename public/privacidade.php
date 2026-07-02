<?php
/** Política de privacidade (LGPD). */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/layout.php';

public_header('Política de privacidade');
$nome = setting('entidade_nome');
$contato = setting('entidade_email_contato');
?>
<div class="cartao">
  <h2>Política de privacidade</h2>
  <p>A <strong><?= e($nome) ?></strong> é a controladora dos dados pessoais tratados por este sistema,
     nos termos da Lei Geral de Proteção de Dados (Lei nº 13.709/2018).</p>

  <h2>Quais dados tratamos e por quê</h2>
  <p>Tratamos apenas os dados necessários à gestão do quadro social e à cobrança das anuidades:
     nome, indicativo de radioamador, email, CPF/CNPJ, telefone e cidade. A base legal é a execução
     do vínculo associativo (Art. 7º, V, da LGPD) previsto no Estatuto Social.</p>

  <h2>Pagamentos</h2>
  <p>Os pagamentos são processados pelo <strong>MercadoPago</strong>, que atua como operador.
     Este sistema não armazena dados de cartão de crédito.</p>

  <h2>Seus direitos</h2>
  <p>Você pode solicitar à diretoria, a qualquer momento: acesso aos seus dados, correção,
     portabilidade (cópia dos dados) e, ao se desligar do quadro social, a eliminação dos seus
     dados pessoais — o histórico financeiro é mantido de forma anônima por obrigação de
     prestação de contas da entidade.</p>

  <h2>Contato</h2>
  <p>Dúvidas e solicitações: <strong><?= e($contato ?: 'diretoria da entidade') ?></strong></p>
</div>
<p style="text-align:center"><a href="consulta.php">← Voltar à consulta</a></p>
<?php public_footer(); ?>
