-- Cobranças avulsas (serviços extras, ex.: QSL registrado)

ALTER TABLE charges
  ADD tipo ENUM('anuidade','avulsa') NOT NULL DEFAULT 'anuidade' AFTER member_id;

INSERT IGNORE INTO settings (k, v) VALUES
  ('email_avulsa_assunto', 'Cobrança: {{descricao}} — {{sigla}}'),
  ('email_avulsa_corpo', '<p>Olá, <strong>{{nome}}</strong> ({{indicativo}})!</p><p>Está disponível a cobrança de <strong>{{descricao}}</strong>, no valor de <strong>{{valor}}</strong>, com vencimento em <strong>{{vencimento}}</strong>.</p><p>Para pagar com Pix, boleto ou cartão, use o botão abaixo:</p><p>{{botao_pagar}}</p><p>73!<br>{{entidade}}</p>'),
  ('email_avulsa_confirmacao_assunto', 'Pagamento confirmado: {{descricao}} — {{sigla}}'),
  ('email_avulsa_confirmacao_corpo', '<p>Olá, <strong>{{nome}}</strong> ({{indicativo}})!</p><p>Confirmamos o recebimento do pagamento de <strong>{{descricao}}</strong>, no valor de <strong>{{valor}}</strong>.</p><p>Seu comprovante: {{link_comprovante}}</p><p>Obrigado! 73!<br>{{entidade}}</p>');
