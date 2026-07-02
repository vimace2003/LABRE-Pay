# LABRE-Pay — Sistema de Cobrança de Anuidades

## O que é este projeto?

O LABRE-Pay é um sistema para automatizar a cobrança das anuidades dos associados da LABRE SC. Hoje esse trabalho é feito manualmente; com o sistema, a diretoria passa a gerar e enviar as cobranças de todos os associados com poucos cliques, e a acompanhar quem já pagou e quem está em atraso.

Embora criado para a LABRE SC, o sistema é totalmente personalizável (nome, logotipo, valores, textos dos emails) e pode ser usado por qualquer outra LABRE estadual.

As regras de cobrança seguem o **Estatuto Social da LABRE-SC (2024)**: vencimento em 31 de janeiro, isenções previstas, taxa de admissão, exclusão e readmissão de associados.

## Como funciona, em resumo

1. A diretoria cadastra os associados no sistema — pode importar de uma só vez a planilha (Excel) que já existe hoje, sem precisar digitar um por um.
2. Todo ano, o sistema gera a cobrança da anuidade de todos os associados ativos e envia por email.
3. O email traz um link de pagamento do MercadoPago, onde o associado escolhe como quer pagar: **Pix, boleto ou cartão**.
4. Quando o associado paga, o sistema é avisado automaticamente pelo MercadoPago e marca a cobrança como quitada — sem ninguém precisar conferir extrato.
5. A diretoria acompanha tudo em um painel: quantos pagaram, quantos estão em atraso, valores arrecadados.

## O que o sistema faz

### Cadastro de associados
- Importação da lista de associados a partir da planilha atual (Excel ou CSV), com conferência antes de gravar para evitar duplicados.
- Cadastro manual de novos associados a qualquer momento.
- Dados básicos: nome, indicativo, email, CPF ou CNPJ (o estatuto admite agremiações associadas), telefone e cidade.
- Respeita as categorias e classes do Estatuto: associados **isentos** (benemérito, correspondente, juvenil, cônjuge com COER, doença grave) e **remidos** (25 anuidades) **não recebem cobrança** e aparecem nas listas como "em dia".

### Desligamento e retorno de associados
- O associado pode ser desligado do quadro social a qualquer momento, registrando o motivo previsto no Estatuto: a pedido do próprio, falecimento, exclusão administrativa ou inadimplência.
- O desligado deixa de receber cobranças, mas **todo o seu histórico é preservado** — nada é apagado.
- Se ele quiser voltar no futuro, basta readmiti-lo com um clique: o cadastro é reativado com o histórico intacto.
- No painel de controle a diretoria define se o retorno paga **taxa de retorno** ou não, e qual o valor. Se ativada, ela é somada à primeira cobrança (proporcional, sem multa).
- O Estatuto (Art. 40) prevê exclusão automática após **3 meses de inadimplência**: o sistema avisa a diretoria quando algum associado passa desse prazo e permite fazer o desligamento com um clique — a decisão final é sempre da diretoria.

### Cobrança anual
- Geração das cobranças do ano para todos os associados ativos de uma só vez, com vencimento em **31 de janeiro**, conforme o Estatuto (Art. 27).
- **Desconto por antecipação** (opcional): a diretoria pode oferecer desconto para quem pagar até uma data-limite (ex.: até 31/12). O sistema aplica e retira o desconto sozinho, conforme a data.
- Quando o pagamento é confirmado, o associado recebe por email um **comprovante de pagamento** que pode ser impresso.
- Envio automático por email, com texto personalizável e link para pagamento.
- Reenvio de cobrança e lembretes automáticos (antes e depois do vencimento).
- Possibilidade de marcar um pagamento manualmente (ex.: associado que pagou em espécie na sede).

### Novas adesões no meio do ano
- Quem entra depois do início do ano paga apenas o valor proporcional aos meses que faltam até o próximo vencimento (pro-rata).
- O sistema calcula esse valor sozinho, mas a diretoria pode ajustá-lo antes de enviar.
- Essa primeira cobrança proporcional **não sofre multa nem juros**.
- O painel também permite ativar uma **taxa de admissão** para novos associados (prevista no Art. 29 do Estatuto), somada à primeira cobrança.

### Multa e juros por atraso
- Se a anuidade vence (por exemplo, em fevereiro) e o associado paga depois (por exemplo, em abril), ele paga o valor original **mais multa e juros pelo tempo de atraso**.
- Os percentuais de multa e de juros são definidos pela própria diretoria no painel de controle.
- O sistema calcula o valor atualizado automaticamente no dia do pagamento — ninguém precisa fazer conta.

### Consulta pública para o associado
- Uma página aberta (sem senha) onde o associado digita o **CPF ou o indicativo** e vê sua situação.
- Se houver cobrança em aberto, aparece o botão para pagar na hora, já com o valor atualizado (com multa e juros, se estiver em atraso).
- Por segurança, a página mostra apenas o mínimo necessário (primeiro nome, indicativo e valor) — nunca dados pessoais completos.

### Acompanhamento pela diretoria
- Painel com o resumo do ano: total de associados, pagos, pendentes e em atraso.
- Aviso destacado de associados inadimplentes há mais de 3 meses (sujeitos à exclusão automática pelo Estatuto).
- **Relatórios com gráficos**: evolução da arrecadação e da adimplência por ano, e relatório mensal já formatado para a tomada de contas da **Comissão Fiscal** (Art. 15 do Estatuto).
- **Backup automático diário** do banco de dados, com cópias guardadas em local seguro — proteção extra além do backup da hospedagem.
- Lista de **adimplentes e inadimplentes** por ano, com busca e filtros.
- Exportação das listas para planilha.
- Registro dos emails enviados (para saber se a cobrança chegou).

### Personalização (uso por outras LABREs)
Tudo o que identifica a entidade é configurável pelo painel, sem mexer em programação:
- Nome e sigla da entidade, logotipo;
- Valor da anuidade e data de vencimento;
- Percentuais de multa e juros;
- Taxa de admissão e taxa de retorno (ligar/desligar e valor);
- Prazo de inadimplência para o aviso de exclusão automática;
- Textos dos emails de cobrança, lembrete e confirmação;
- Contas do MercadoPago e do email remetente.

### Segurança
- O painel administrativo só é acessado com usuário e senha, com bloqueio após várias tentativas erradas.
- Cada diretor/tesoureiro pode ter seu próprio usuário.
- Os pagamentos acontecem no ambiente do MercadoPago — o sistema **não guarda dados de cartão** de ninguém.

## O que o sistema NÃO faz (fora do escopo)

- Não emite nota fiscal nem recibo com validade fiscal.
- Não faz contabilidade da entidade (apenas controla as anuidades).
- Não gerencia outros serviços da LABRE (cursos, eventos, QSL etc.).
- Não envia cobrança por WhatsApp ou SMS — apenas por email (pode ser avaliado no futuro).

## O que é preciso para colocar no ar

- Uma **hospedagem de site comum com cPanel** (a maioria das hospedagens brasileiras serve).
- Uma **conta no MercadoPago** da entidade (gratuita; o MercadoPago cobra apenas a taxa por transação recebida).
- Um **email da entidade** para ser o remetente das cobranças.
- A **planilha atual de associados** para a carga inicial.

## Quem usa o quê

| Quem | O que faz |
|---|---|
| Diretoria / Tesouraria | Entra com senha, gerencia associados, gera cobranças e acompanha pagamentos |
| Associado | Recebe o email e paga pelo link; pode consultar sua situação pelo CPF ou indicativo |
| MercadoPago | Processa os pagamentos e avisa o sistema automaticamente quando alguém paga |
