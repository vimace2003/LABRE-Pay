# Simular o webhook do MercadoPago em desenvolvimento

O MercadoPago não consegue alcançar `http://localhost`, então em desenvolvimento
a confirmação de pagamento chega por dois caminhos:

## 1. Reconciliação pelo cron (recomendado)

O cron consulta a API do MP pelas cobranças pendentes e dá baixa sozinho:

```bash
docker compose exec app php /var/www/html/cron.php
```

Rode depois de pagar com uma conta de teste — a cobrança será marcada como paga.

## 2. Disparar a notificação manualmente

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

## Testar em homologação (na internet)

No ambiente de homologação hospedado no cPanel o webhook funciona de verdade:
cadastre `https://homolog-pay.SEU-DOMINIO/webhook.php` no painel do MP
(aplicação de teste) e pague com a conta de comprador de teste.
