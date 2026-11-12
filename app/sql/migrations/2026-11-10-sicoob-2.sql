-- Cidade da entidade (campo "merchant city" exigido pelo payload EMV do Pix)

INSERT IGNORE INTO settings (k, v) VALUES
  ('entidade_cidade', '');
