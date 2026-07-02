<?php
/**
 * LABRE-Pay — modelo de configuração de ambiente.
 * O instalador (public/install.php) gera o app/config.php real.
 * Este arquivo NUNCA deve conter credenciais reais — é só um modelo.
 */

// production | homolog | dev
define('APP_ENV', 'dev');

// Banco de dados MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'labrepay');
define('DB_USER', 'labrepay');
define('DB_PASS', '');

// URL base pública, sem barra final (ex.: https://pay.labre-sc.org.br)
define('BASE_URL', 'http://localhost:8080');

// Em ambientes não-produtivos, TODO email é desviado para este endereço.
// Deixe '' para entregar ao destinatário real (só faz sentido em produção).
define('MAIL_OVERRIDE_TO', APP_ENV === 'production' ? '' : 'testes@example.com');

// Chave secreta da instalação (tokens de cobrança, CSRF, etc.). Gerada pelo instalador.
define('APP_SECRET', 'troque-esta-chave');
