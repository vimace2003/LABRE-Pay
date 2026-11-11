# Simular o webhook do MercadoPago/Sicoob em desenvolvimento

Nenhum dos dois gateways consegue alcançar `http://localhost`, então em
desenvolvimento a confirmação de pagamento chega por dois caminhos.

## 1. Reconciliação pelo cron (recomendado)

O cron consulta a API do MP e do Sicoob (o gateway configurado) pelas cobranças
pendentes e dá baixa sozinho:

```bash
docker compose exec app php /var/www/html/cron.php
```

Rode depois de pagar com uma conta de teste — a cobrança será marcada como paga.

## 2. Disparar a notificação manualmente (MercadoPago)

Com um pagamento de teste criado (você tem o `payment_id` do MP):

```bash
curl -X POST "http://localhost:8080/webhook.php?type=payment&data.id=SEU_PAYMENT_ID"
```

Sem a assinatura secreta configurada nas Configurações, o webhook aceita a
notificação e **confirma o pagamento consultando a API** com o seu token de
teste — ou seja, só marca como pago se o pagamento realmente existir no MP.

Se você configurou a assinatura secreta, gere os headers assim (substitua
`SECRET`, `PAYMENT_ID` e use um `request-id` qualquer):

```bash
TS=$(date +%s)
RID=teste-123
SIG=$(printf 'id:%s;request-id:%s;ts:%s;' "PAYMENT_ID" "$RID" "$TS" | openssl dgst -sha256 -hmac "SECRET" -hex | sed 's/^.* //')
curl -X POST "http://localhost:8080/webhook.php?type=payment&data.id=PAYMENT_ID" \
  -H "x-request-id: $RID" \
  -H "x-signature: ts=$TS,v1=$SIG"
```

## 3. Disparar a notificação manualmente (Sicoob)

O Sicoob chama `{webhookUrl}/pix` com `{"pix": [{"txid": "...", "endToEndId": "...", "valor": "..."}]}`.
Como `webhook-sicoob.php` sempre reconsulta a cob pelo `txid` (nunca confia só no
corpo), o valor enviado aqui não precisa ser exato — só o `txid` de uma cob real
(coluna `sicoob_txid` da cobrança) importa:

```bash
curl -X POST "http://localhost:8080/webhook-sicoob.php?token=SEU_TOKEN_DO_WEBHOOK" \
  -H "Content-Type: application/json" \
  -d '{"pix":[{"txid":"SEU_TXID","endToEndId":"teste-123","valor":"120.00"}]}'
```

Isso só confirma o pagamento se a cob estiver `CONCLUIDA` no sandbox do Sicoob —
útil para testar o parsing e a idempotência do endpoint, não para simular um
pagamento real (o sandbox do Sicoob não liquida pagamentos).

## Testar em homologação (na internet)

No ambiente de homologação hospedado no cPanel os webhooks funcionam de verdade:
- **MercadoPago**: cadastre `https://homolog-pay.SEU-DOMINIO/webhook.php` no painel do MP
  (aplicação de teste) e pague com a conta de comprador de teste.
- **Sicoob**: use o botão "Registrar webhook Pix" em Configurações → Sicoob
  (registra `https://homolog-pay.SEU-DOMINIO/webhook-sicoob.php?token=...` na chave Pix cadastrada).
