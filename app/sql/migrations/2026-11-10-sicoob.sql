-- Gateway Sicoob (Pix + boleto) e endereço completo do associado (necessário para boleto registrado)

ALTER TABLE charges
  ADD COLUMN sicoob_txid VARCHAR(35) NULL AFTER meio_pagamento,
  ADD COLUMN sicoob_pix_copia_cola TEXT NULL AFTER sicoob_txid,
  ADD COLUMN sicoob_pix_location VARCHAR(160) NULL AFTER sicoob_pix_copia_cola,
  ADD COLUMN sicoob_pix_expira DATETIME NULL AFTER sicoob_pix_location,
  ADD COLUMN sicoob_nosso_numero VARCHAR(20) NULL AFTER sicoob_pix_expira,
  ADD COLUMN sicoob_linha_digitavel VARCHAR(60) NULL AFTER sicoob_nosso_numero,
  ADD COLUMN sicoob_codigo_barras VARCHAR(48) NULL AFTER sicoob_linha_digitavel,
  ADD KEY idx_charges_sicoob_txid (sicoob_txid);

ALTER TABLE members
  ADD COLUMN endereco VARCHAR(160) NULL AFTER telefone,
  ADD COLUMN numero VARCHAR(10) NULL AFTER endereco,
  ADD COLUMN complemento VARCHAR(60) NULL AFTER numero,
  ADD COLUMN bairro VARCHAR(80) NULL AFTER complemento,
  ADD COLUMN cep VARCHAR(9) NULL AFTER bairro;

INSERT IGNORE INTO settings (k, v) VALUES
  ('gateway_padrao', 'mercadopago'),
  ('sicoob_ambiente', 'sandbox'),
  ('sicoob_client_id', ''),
  ('sicoob_sandbox_token', ''),
  ('sicoob_cert_path', ''),
  ('sicoob_cert_key_path', ''),
  ('sicoob_chave_pix', ''),
  ('sicoob_numero_cliente', ''),
  ('sicoob_conta_corrente', ''),
  ('sicoob_codigo_modalidade', '1'),
  ('sicoob_webhook_token', ''),
  ('sicoob_token_cache', '');
