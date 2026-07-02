<?php
/**
 * Consulta pública (sem login): o associado digita CPF ou indicativo e vê
 * suas cobranças com botão de pagamento. Privacidade: exibe apenas o primeiro
 * nome + indicativo; busca exata; rate-limit por IP contra varredura.
 */

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/layout.php';
require APP_DIR . '/cobranca.php';

session_start_secure();

$membro = null;
$cobrancas = [];
$buscou = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $buscou = true;
    $termo = trim($_POST['termo'] ?? '');
    $honeypot = trim($_POST['site'] ?? ''); // campo invisível: humano deixa vazio

    if ($honeypot !== '' || !rate_limit_check('consulta', 10, 10)) {
        flash_set('erro', 'Muitas consultas em sequência. Aguarde alguns minutos e tente novamente.');
        redirect('consulta.php');
    }

    if ($termo !== '') {
        $digitos = so_digitos($termo);
        if (strlen($digitos) === 11 || strlen($digitos) === 14) {
            // CPF/CNPJ: busca exata
            $st = db()->prepare("SELECT * FROM members WHERE cpf_cnpj = ? AND anonimizado = 0 LIMIT 1");
            $st->execute([$digitos]);
        } else {
            // Indicativo: busca exata, caixa alta
            $st = db()->prepare("SELECT * FROM members WHERE indicativo = ? AND anonimizado = 0 LIMIT 1");
            $st->execute([strtoupper($termo)]);
        }
        $membro = $st->fetch() ?: null;
    }
    rate_limit_hit('consulta', null, $membro !== null);

    if ($membro) {
        $st = db()->prepare("SELECT * FROM charges WHERE member_id = ? AND status <> 'cancelada' ORDER BY ano DESC LIMIT 6");
        $st->execute([$membro['id']]);
        $cobrancas = $st->fetchAll();
    }
}

public_header('Consulta de anuidade');

// Retornos do Checkout Pro
if (isset($_GET['pago'])) echo '<div class="flash flash-ok">Pagamento aprovado! Assim que o MercadoPago confirmar, você receberá o comprovante por email.</div>';
if (isset($_GET['pendente'])) echo '<div class="flash flash-ok">Pagamento em processamento. Boleto e Pix podem levar algum tempo para compensar.</div>';
if (isset($_GET['falha'])) echo '<div class="flash flash-erro">O pagamento não foi concluído. Você pode tentar novamente quando quiser.</div>';
?>

<form method="post" class="cartao form-grid">
  <?= csrf_field() ?>
  <h2 style="margin-top:0">Consulte sua anuidade</h2>
  <label>Digite seu CPF ou seu indicativo
    <input type="text" name="termo" placeholder="000.000.000-00 ou PP5XXX" required autofocus
           style="font-size:1.25rem;text-align:center">
  </label>
  <input type="text" name="site" value="" style="display:none" tabindex="-1" autocomplete="off" aria-hidden="true">
  <button type="submit" class="botao botao-primario" style="font-size:1.1rem">Consultar</button>
</form>

<?php if ($buscou && !$membro): ?>
  <div class="cartao vazio">
    <strong>Não encontramos seu cadastro</strong>
    Confira se digitou o CPF ou o indicativo corretamente.<br>
    Se o problema continuar, fale com a diretoria: <?= e(setting('entidade_email_contato', '')) ?>
  </div>
<?php endif; ?>

<?php if ($membro):
    $primeiroNome = explode(' ', trim($membro['nome']))[0];
?>
  <div class="cartao">
    <h2 style="margin-top:0">Olá, <?= e($primeiroNome) ?><?= $membro['indicativo'] ? ' (' . e($membro['indicativo']) . ')' : '' ?>!</h2>

    <?php if ($membro['status'] === 'desligado'): ?>
      <p>Seu cadastro está <strong>desligado</strong> do quadro social. Para retornar à associação,
         entre em contato com a diretoria: <?= e(setting('entidade_email_contato', '')) ?></p>
    <?php elseif ($membro['classe'] !== 'contribuinte'): ?>
      <p><span class="selo selo-adimplente">Em dia ✓</span> — você é associado
         <strong><?= $membro['classe'] === 'remido' ? 'remido' : 'isento' ?></strong> e não paga anuidade.</p>
    <?php elseif (!$cobrancas): ?>
      <p>Não há cobranças registradas para você. Se acabou de se associar, aguarde o email de boas-vindas.</p>
    <?php else: ?>
      <?php foreach ($cobrancas as $c): ?>
        <div class="cartao" style="margin-bottom:.8rem">
          <p style="margin:0 0 .4rem"><strong><?= e($c['descricao']) ?></strong></p>
          <?php if ($c['status'] === 'pago'): ?>
            <p style="margin:0"><span class="selo selo-pago">Pago ✓</span>
              em <?= e(fmt_data($c['pago_em'])) ?> — <?= e(fmt_moeda((float)$c['valor_pago'])) ?>
              &nbsp;<a href="comprovante.php?c=<?= (int)$c['id'] ?>&t=<?= e($c['token']) ?>">Ver comprovante</a></p>
          <?php else:
              $devido = valor_devido($c); ?>
            <p style="margin:0 0 .6rem">
              <span class="selo selo-<?= $c['status'] === 'vencida' ? 'vencida' : 'pendente' ?>">
                <?= $c['status'] === 'vencida' ? 'Em atraso' : 'Em aberto' ?></span>
              Vencimento: <?= e(fmt_data($c['vencimento'])) ?>
            </p>
            <p style="margin:0 0 .8rem;font-size:1.3rem"><strong><?= e(fmt_moeda($devido['valor'])) ?></strong>
              <?php if ($devido['fase'] === 'atraso'): ?>
                <span class="texto-suave" style="font-size:.95rem"><br>(<?= e(fmt_moeda((float)$c['valor'])) ?> + <?= e(fmt_moeda($devido['acrescimos'])) ?> de multa e juros)</span>
              <?php elseif ($devido['fase'] === 'desconto'): ?>
                <span class="texto-suave" style="font-size:.95rem"><br>com desconto por antecipação até <?= e(fmt_data(desconto_data_limite($c))) ?></span>
              <?php endif; ?>
            </p>
            <a class="botao botao-verde" style="font-size:1.1rem;width:100%"
               href="pagar.php?c=<?= (int)$c['id'] ?>&t=<?= e($c['token']) ?>">Pagar agora (Pix, boleto ou cartão)</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php public_footer(); ?>
