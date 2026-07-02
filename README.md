# LABRE-Pay

Sistema de cobrança de anuidades para a LABRE SC (e personalizável para qualquer LABRE estadual).
PHP puro + MySQL, feito para rodar em hospedagem compartilhada com cPanel, com pagamentos via
MercadoPago (Checkout Pro: Pix, boleto e cartão).

> Visão não-técnica do projeto: [ESCOPO.md](ESCOPO.md)

## Funcionalidades

- Importação de associados por planilha (XLSX/XLS/CSV) com mapeamento de colunas e prévia;
- Cobrança anual em lote com envio por email (link de pagamento do MercadoPago);
- Pro-rata para novas adesões e readmissões (com taxas de admissão/retorno opcionais);
- Desconto por antecipação, multa e juros por atraso — calculados automaticamente por data;
- Webhook do MercadoPago + reconciliação diária via cron (à prova de falha do webhook);
- Consulta pública por CPF/indicativo com pagamento e comprovante;
- Listas de adimplentes/inadimplentes, relatórios com gráficos e relatório mensal p/ Comissão Fiscal;
- Lembretes automáticos, backup diário do banco, auditoria completa (LGPD), login seguro.

## Desenvolvimento local (Docker)

```bash
docker compose up -d --build
```

| Serviço | URL |
|---|---|
| Sistema | http://localhost:8080 (instalador abre sozinho na primeira vez) |
| Mailpit (emails capturados) | http://localhost:8025 |
| phpMyAdmin | http://localhost:8081 |

No instalador use: ambiente **Desenvolvimento**, URL base `http://localhost:8080`,
banco `db` / `labrepay` / `labrepay` / senha `labrepay`, e um email dummy qualquer.

Depois, em **Configurações → SMTP**: servidor `mailpit`, porta `1025`, segurança **Nenhuma**,
sem usuário/senha, e um remetente qualquer (ex.: `teste@labre-sc.org.br`). Todo email enviado
aparece no Mailpit — nada sai para a internet.

MercadoPago em dev/homolog: use as **credenciais de teste** (`TEST-…`) — o sistema recusa
credenciais de produção fora de produção, e vice-versa. Webhook local: veja
[docs/simular-webhook.md](docs/simular-webhook.md).

## Instalação no cPanel (produção e homologação)

1. **Subdomínio**: crie (ex.: `pay.labre-sc.org.br`) apontando o docroot para a pasta `public/`
   do projeto (ex.: envie o projeto para `~/labrepay/` e aponte o docroot para `~/labrepay/public`).
   Se o cPanel não permitir docroot fora de `public_html`, envie `public/` para o docroot e
   `app/` para o nível acima (a estrutura `../app` deve ser mantida).
2. **Banco**: crie um banco MySQL + usuário no cPanel.
3. **Instalador**: acesse `https://SEU-SUBDOMINIO/install.php` e siga os passos
   (ambiente, URL base, banco e primeiro administrador). O instalador se auto-bloqueia ao concluir.
4. **HTTPS**: ative o AutoSSL no cPanel. O `.htaccess` já redireciona para HTTPS.
5. **Cron** (cPanel → Cron Jobs), 1× por dia:
   ```
   php /home/SEU_USUARIO/labrepay/public/cron.php
   ```
   (ou use a URL com token mostrada em Configurações, com `curl -s`).
6. **MercadoPago**: crie uma aplicação em [mercadopago.com.br/developers](https://www.mercadopago.com.br/developers/panel/app),
   copie o access token para Configurações e cadastre o webhook `https://SEU-SUBDOMINIO/webhook.php`
   (evento *Pagamentos*); cole a assinatura secreta do webhook nas Configurações.
7. **SMTP**: use o email da entidade criado no próprio cPanel (host, porta 465/SSL ou 587/TLS).
8. Importe a planilha de associados e gere as cobranças do ano.

**Homologação**: repita os passos em outro subdomínio (ex.: `homolog-pay.…`) com **outro banco**,
ambiente "Homologação", email dummy e credenciais de teste do MP.

## Deploy automático (GitHub Actions)

- Branch **`main`** → produção · branch **`homolog`** → homologação.
- Fluxo: feature branch → merge em `homolog` → testar → merge em `main`.
- Configure no GitHub (Settings → Environments) os ambientes `production` e `homolog`,
  cada um com os secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` e `FTP_DIR`.
- O workflow roda `php -l` em tudo antes de publicar e **nunca** envia/sobrescreve
  `app/config.php`, `install.lock` ou backups.
- Hospedagem com SSH? Prefira o job `deploy-ssh` comentado em
  [.github/workflows/deploy.yml](.github/workflows/deploy.yml).
- Mudanças de banco: crie arquivos numerados em `app/sql/migrations/` (ex.: `2026-08-01-nova-coluna.sql`)
  e aplique após o deploy em `https://SEU-SUBDOMINIO/migrar.php` (requer login) ou via CLI.

## Estrutura

```
public/   docroot (telas do painel, consulta pública, webhook, cron, instalador)
app/      código de negócio, bibliotecas vendorizadas e schema — fora do docroot
docker/   ambiente local
docs/     planilha de exemplo, simulação de webhook
```

## Segurança e LGPD (resumo)

- Senhas com bcrypt, lockout por tentativas, sessão com timeout e cookies HttpOnly/SameSite;
- CSRF em todos os formulários; PDO com prepared statements; headers CSP/HSTS/nosniff;
- Trava de credenciais por ambiente (TEST-/APP_USR-) e emails desviados para dummy fora de produção;
- Auditoria de todas as ações administrativas; consulta pública com rate-limit e dados mínimos;
- Exportação (portabilidade) e anonimização de associados desligados; política de privacidade pública.

## Checklist de teste manual

1. Instalar via Docker, logar, salvar Configurações (SMTP Mailpit + MP de teste);
2. Importar `docs/exemplo-associados.csv` e conferir a prévia/dedupe;
3. Gerar cobranças do ano em lote → conferir emails no Mailpit e links do Checkout Pro;
4. Cadastrar associado novo com pro-rata (conferir cálculo e isenção de multa);
5. Desligar um associado (cobranças canceladas) e readmitir (taxa de retorno, se ativa);
6. Consulta pública por CPF e por indicativo (e rate-limit após várias buscas erradas);
7. Baixa manual de pagamento → comprovante por email e na consulta;
8. `docker compose exec app php /var/www/html/cron.php` → vencidas, lembretes e backup;
9. Situação anual + exportar CSV; relatório mensal da Comissão Fiscal;
10. Multa/juros: criar cobrança com vencimento passado (ex.: fevereiro) e conferir o valor de hoje.
