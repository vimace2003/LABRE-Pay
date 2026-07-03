-- LABRE-Pay — schema inicial (MySQL 5.7+/8, utf8mb4)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_login DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(160) NOT NULL,
  indicativo VARCHAR(20) NULL,
  email VARCHAR(190) NULL,
  cpf_cnpj VARCHAR(18) NULL,
  telefone VARCHAR(30) NULL,
  cidade VARCHAR(120) NULL,
  uf CHAR(2) NULL,
  categoria ENUM('efetivo','juvenil','benemerito','correspondente','agremiacao') NOT NULL DEFAULT 'efetivo',
  classe ENUM('contribuinte','isento','remido') NOT NULL DEFAULT 'contribuinte',
  status ENUM('ativo','desligado') NOT NULL DEFAULT 'ativo',
  motivo_desligamento ENUM('a_pedido','falecimento','exclusao_administrativa','inadimplencia') NULL,
  desligado_em DATE NULL,
  data_adesao DATE NULL,
  readmitido_em DATE NULL,
  anonimizado TINYINT(1) NOT NULL DEFAULT 0,
  obs TEXT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_members_indicativo (indicativo),
  KEY idx_members_cpf (cpf_cnpj),
  KEY idx_members_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS charges (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  ano SMALLINT UNSIGNED NOT NULL,
  descricao VARCHAR(190) NOT NULL,
  valor DECIMAL(10,2) NOT NULL,
  vencimento DATE NOT NULL,
  status ENUM('pendente','pago','vencida','cancelada') NOT NULL DEFAULT 'pendente',
  isenta_multa TINYINT(1) NOT NULL DEFAULT 0,
  valor_pago DECIMAL(10,2) NULL,
  desconto_aplicado DECIMAL(10,2) NULL,
  acrescimos_aplicados DECIMAL(10,2) NULL,
  mp_preference_id VARCHAR(80) NULL,
  mp_init_point TEXT NULL,
  mp_pref_expira DATETIME NULL,
  mp_payment_id VARCHAR(40) NULL,
  meio_pagamento VARCHAR(40) NULL,
  pago_em DATETIME NULL,
  pago_manual TINYINT(1) NOT NULL DEFAULT 0,
  enviado_em DATETIME NULL,
  token CHAR(40) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_charge_token (token),
  KEY idx_charges_member (member_id),
  KEY idx_charges_ano (ano),
  KEY idx_charges_status (status),
  CONSTRAINT fk_charges_member FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  charge_id INT UNSIGNED NULL,
  member_id INT UNSIGNED NULL,
  destinatario VARCHAR(190) NOT NULL,
  assunto VARCHAR(190) NOT NULL,
  tipo VARCHAR(30) NOT NULL,
  status ENUM('enviado','erro') NOT NULL,
  erro TEXT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_charge (charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  contexto VARCHAR(30) NOT NULL DEFAULT 'login',
  identificador VARCHAR(190) NULL,
  sucesso TINYINT(1) NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_attempts_ip (ip, contexto, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  acao VARCHAR(60) NOT NULL,
  entidade VARCHAR(40) NULL,
  entidade_id INT UNSIGNED NULL,
  detalhes TEXT NULL,
  ip VARCHAR(45) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_user (user_id),
  KEY idx_audit_entidade (entidade, entidade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(80) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configurações padrão (INSERT IGNORE para não sobrescrever instalação existente)
INSERT IGNORE INTO settings (k, v) VALUES
  ('entidade_nome', 'LABRE Santa Catarina'),
  ('entidade_sigla', 'LABRE-SC'),
  ('entidade_cnpj', ''),
  ('logo_arquivo', ''),
  ('tema', 'azul'),
  ('entidade_site', 'https://www.labre-sc.org.br'),
  ('entidade_email_contato', ''),
  ('anuidade_valor', '120.00'),
  ('venc_dia', '31'),
  ('venc_mes', '1'),
  ('prazo_venc_meses', '3'),
  ('multa_percent', '2'),
  ('juros_mes_percent', '1'),
  ('desconto_ativo', '0'),
  ('desconto_tipo', 'percent'),
  ('desconto_valor', '10'),
  ('desconto_dia', '31'),
  ('desconto_mes', '12'),
  ('taxa_admissao_ativa', '0'),
  ('taxa_admissao_valor', '0'),
  ('taxa_retorno_ativa', '0'),
  ('taxa_retorno_valor', '0'),
  ('meses_exclusao_auto', '3'),
  ('lembrete_dias_antes', '10'),
  ('lembrete_dias_depois', '15'),
  ('lembretes_ativos', '1'),
  ('mp_access_token', ''),
  ('mp_public_key', ''),
  ('mp_webhook_secret', ''),
  ('smtp_host', ''),
  ('smtp_porta', '587'),
  ('smtp_usuario', ''),
  ('smtp_senha', ''),
  ('smtp_seguranca', 'tls'),
  ('smtp_remetente_email', ''),
  ('smtp_remetente_nome', ''),
  ('cron_token', ''),
  ('backup_ativo', '1'),
  ('backup_copias', '7'),
  ('email_cobranca_assunto', 'Anuidade {{ano}} — {{sigla}}'),
  ('email_cobranca_corpo', '<p>Olá, <strong>{{nome}}</strong> ({{indicativo}})!</p><p>Está disponível a cobrança da sua anuidade <strong>{{ano}}</strong> da {{entidade}}, no valor de <strong>{{valor}}</strong>, com vencimento em <strong>{{vencimento}}</strong>.</p><p>Para pagar com Pix, boleto ou cartão, use o botão abaixo:</p><p>{{botao_pagar}}</p><p>Se preferir, consulte sua situação a qualquer momento em nossa página de consulta.</p><p>73!<br>{{entidade}}</p>'),
  ('email_lembrete_assunto', 'Lembrete: anuidade {{ano}} — {{sigla}}'),
  ('email_lembrete_corpo', '<p>Olá, <strong>{{nome}}</strong> ({{indicativo}})!</p><p>Este é um lembrete sobre a anuidade <strong>{{ano}}</strong> da {{entidade}}, no valor atual de <strong>{{valor}}</strong> (vencimento {{vencimento}}).</p><p>{{botao_pagar}}</p><p>Se você já pagou, por favor desconsidere este aviso.</p><p>73!<br>{{entidade}}</p>'),
  ('email_confirmacao_assunto', 'Pagamento confirmado — anuidade {{ano}} {{sigla}}'),
  ('email_confirmacao_corpo', '<p>Olá, <strong>{{nome}}</strong> ({{indicativo}})!</p><p>Confirmamos o recebimento do pagamento da sua anuidade <strong>{{ano}}</strong> da {{entidade}}, no valor de <strong>{{valor}}</strong>.</p><p>Seu comprovante: {{link_comprovante}}</p><p>Obrigado por manter sua associação em dia. 73!<br>{{entidade}}</p>');
