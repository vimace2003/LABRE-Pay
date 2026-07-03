# Roteiro de testes — LABRE-Pay (homologação)

Validação completa do sistema. Execute na ordem — os blocos seguintes dependem dos anteriores.
Ambiente: `https://pay.pp5kj.com` (homolog). Todos os emails devem chegar **no dummy** com prefixo `[TESTE]`.

Legenda: ☐ pendente · ☑ passou · ✗ falhou (anotar o que aconteceu)

---

## 0. Preparação

| # | Passo | Resultado esperado |
|---|---|---|
| 0.1 | Configurações → Zona de perigo → digitar `ZERAR` | Associados/cobranças/logs zerados; configurações e seu login preservados |
| 0.2 | Conferir topo de qualquer tela | Faixa amarela "AMBIENTE DE TESTES (HOMOLOG)" visível |
| 0.3 | Configurações → MercadoPago | Access token da **conta vendedor de teste** salvo |
| 0.4 | Configurações → clicar "Enviar email de teste para mim" | Email chega no dummy com assunto `[TESTE] Teste de envio` |

## 1. Acesso e segurança

| # | Passo | Resultado esperado |
|---|---|---|
| 1.1 | Sair e errar a senha 5 vezes seguidas | Mensagem "Muitas tentativas — aguarde 15 minutos" |
| 1.2 | Acessar `dashboard.php` sem estar logado | Redireciona para a tela de login |
| 1.3 | Login correto | Entra no painel; Início carrega |
| 1.4 | Usuários → criar um segundo usuário (senha 10+ caracteres) | Criado; consegue logar com ele em outra aba anônima |
| 1.5 | Bloquear o segundo usuário e tentar logar com ele | "Email ou senha incorretos" |
| 1.6 | Tentar criar usuário com senha curta (ex.: `123`) | Recusado com mensagem clara |

## 2. Configurações e personalização

| # | Passo | Resultado esperado |
|---|---|---|
| 2.1 | Preencher nome, sigla, CNPJ e email de contato da entidade | Salvo; sigla aparece no menu |
| 2.2 | Aparência → trocar o tema (ex.: Verde floresta) | Menu, botões e links mudam de cor no painel e na consulta pública |
| 2.3 | Enviar uma logo (PNG) | Aparece no menu lateral, na consulta pública e nas Configurações |
| 2.4 | Tentar salvar access token `APP_USR-` de conta REAL em homolog | Aviso alertando que precisa ser de conta de teste |
| 2.5 | Templates → usar o editor: negrito, tamanho, "Inserir campo… → Nome do associado" | Formatação aplicada; `{{nome}}` inserido no cursor |
| 2.6 | Botão `</>` do editor | Alterna para HTML puro e volta sem perder conteúdo |
| 2.7 | Definir multa 2%, juros 1% a.m., prazo fora do ciclo 3 meses | Salvo |

## 3. Importação de associados

| # | Passo | Resultado esperado |
|---|---|---|
| 3.1 | Importar planilha → enviar `docs/exemplo-associados.csv` | Prévia mostra 5 linhas; colunas já mapeadas automaticamente |
| 3.2 | Confirmar importação | "5 cadastrado(s)"; lista de associados mostra os 5 com acentos corretos |
| 3.3 | Reimportar o mesmo arquivo COM "atualizar existentes" | "0 cadastrado(s), 5 atualizado(s)" — nada duplicado |
| 3.4 | Editar o CSV: trocar um email para `invalido@` e reimportar | Aviso de email inválido; linha importada sem email |

## 4. Associados (ciclo de vida)

| # | Passo | Resultado esperado |
|---|---|---|
| 4.1 | Novo associado com adesão HOJE, "gerar cobrança" marcado, valor em branco | Cobrança proporcional criada (meses até 31/01 × anuidade/12), **isenta de multa**, vencimento = hoje + 3 meses; email no dummy |
| 4.2 | Conferir o valor de 4.1 na calculadora | Ex.: adesão em julho → 7 meses × R$ 10 = R$ 70,00 |
| 4.3 | Ativar taxa de admissão (ex.: R$ 20) e cadastrar outro novo | Primeira cobrança = proporcional + R$ 20 |
| 4.4 | Abrir um associado → Exportar dados (LGPD) | CSV baixado com dados + histórico de cobranças |
| 4.5 | Desligar um associado (motivo: a pedido) | Some da lista de ativos; cobranças pendentes dele CANCELADAS |
| 4.6 | Ativar taxa de retorno (ex.: R$ 25) e Readmitir o desligado | Cobrança proporcional + R$ 25, isenta de multa; email enviado |
| 4.7 | Desligar de novo e Anonimizar (LGPD) | Nome vira "Associado anonimizado nº X"; sem readmissão possível; histórico financeiro preservado |
| 4.8 | Exportar CSV e Imprimir lista com filtro "Ativos" | Relatório com cabeçalho da entidade, filtro descrito, tabela numerada e total |

## 5. Cobranças em lote

| # | Passo | Resultado esperado |
|---|---|---|
| 5.1 | Gerar lote do ano corrente (vencimento sugerido = hoje + 3 meses) | Barra de progresso completa; 1 email por associado no dummy |
| 5.2 | Associado com adesão DEPOIS do vencimento anual (31/01) | Recebe **proporcional** (nunca anuidade cheia) — mensagem no resultado do lote informa meses e valor |
| 5.3 | Rodar o MESMO lote de novo | "0 cobranças" — não duplica ninguém |
| 5.4 | Reenviar uma cobrança | Novo email no dummy com link válido |
| 5.5 | Baixa manual em uma cobrança (ex.: "pix na sede") | Status Pago (manual); email de confirmação + comprovante no dummy |
| 5.6 | Cancelar uma cobrança pendente | Status Cancelada; some da consulta pública |
| 5.7 | Exportar CSV e Imprimir lista do ano | Relatório com totais (valor emitido × valor pago) |

## 6. Pagamento MercadoPago (teste)

| # | Passo | Resultado esperado |
|---|---|---|
| 6.1 | Abrir o "Pagar agora" de um email do dummy | Checkout Pro abre com o valor certo e o nome da entidade |
| 6.2 | Pagar logado como **comprador de teste** (saldo ou cartão `5031 4332 1540 6351`, titular `APRO`, CPF `12345678909`) | Aprovado; redireciona de volta com "Pagamento aprovado!" |
| 6.3 | Aguardar ~1 minuto | Cobrança vira **Paga** sozinha (webhook); comprovante chega no dummy |
| 6.4 | Abrir o comprovante | Cupom com logo, itens, TOTAL, `*** PAGO ***`, transação MP, morse TKS 73 |
| 6.5 | Imprimir/salvar PDF do comprovante | PDF idêntico à tela (fundo creme, código de barras), sem cabeçalho do site |

## 7. Multa, juros e desconto

| # | Passo | Resultado esperado |
|---|---|---|
| 7.1 | Gerar lote de um ano antigo (ex.: ano passado) com vencimento = ONTEM | Cobranças criadas já vencendo ontem |
| 7.2 | Rodar o cron (URL com token, em Configurações) | Cobranças de 7.1 viram **Vencidas** |
| 7.3 | Consultar um desses associados na consulta pública | Valor exibido = original + multa 2% + juros proporcionais, com o detalhamento |
| 7.4 | Pagar essa cobrança (MP teste) ou dar baixa manual | Comprovante mostra a linha "MULTA E JUROS +X,XX" |
| 7.5 | Cobrança proporcional (adesão) vencida | NÃO recebe multa (isenta) — valor não muda |
| 7.6 | Ativar desconto por antecipação (ex.: 10% até 31/12) e gerar cobrança do ano seguinte | Consulta mostra valor com desconto e a data-limite |

## 8. Consulta pública

| # | Passo | Resultado esperado |
|---|---|---|
| 8.1 | Buscar por CPF completo de um associado | Mostra só primeiro nome + indicativo + cobranças (sem CPF/email na tela) |
| 8.2 | Buscar por indicativo (minúsculas, ex.: `pp5abc`) | Encontra do mesmo jeito |
| 8.3 | Buscar CPF/indicativo inexistente | "Não encontramos seu cadastro" + contato da diretoria |
| 8.4 | Errar a busca 10 vezes seguidas | Bloqueio temporário: "Muitas consultas em sequência" |
| 8.5 | Associado isento/remido (criar um para testar) | "Em dia ✓ — associado isento, não paga anuidade" |
| 8.6 | Link "Política de privacidade" no rodapé | Página abre com o texto LGPD e contato |

## 9. Cron, backup e lembretes

| # | Passo | Resultado esperado |
|---|---|---|
| 9.1 | Chamar a URL do cron (Configurações → Informações do ambiente) | Resposta em texto: vencidas, reconciliadas, lembretes, backup |
| 9.2 | Conferir `app/backups/` via Gerenciador de Arquivos do cPanel | Arquivo `backup-AAAA-MM-DD.sql.gz` do dia |
| 9.3 | Chamar o cron com token errado | "Acesso negado" (403) |
| 9.4 | Criar cobrança com vencimento daqui a N dias (= "lembrar antes") e rodar o cron | Email de lembrete no dummy; rodar de novo NÃO repete o lembrete no mesmo dia |
| 9.5 | Conferir o Cron Job diário do cPanel no dia seguinte | Executou sozinho (novo backup na pasta) |

## 10. Relatórios

| # | Passo | Resultado esperado |
|---|---|---|
| 10.1 | Relatórios → gráficos | Barras de arrecadação e pagas×abertas por ano, nas cores do tema |
| 10.2 | Relatório mensal do mês corrente | Lista os pagamentos de teste com total — formato pronto p/ Comissão Fiscal |
| 10.3 | Exportar CSV e Imprimir o mensal | CSV abre no Excel com acentos; impressão limpa |
| 10.4 | Situação anual → filtros e busca | Contadores baten com a realidade; Imprimir lista traz o resumo no subtítulo |

## 11. Pipeline e ambientes

| # | Passo | Resultado esperado |
|---|---|---|
| 11.1 | Commit na `homolog` | Actions roda SÓ homolog; site atualiza sozinho |
| 11.2 | Tentar `git push` direto na `main` | Rejeitado (branch protegida — só via Pull Request) |
| 11.3 | Após deploy, recarregar o site (F5 normal) | CSS/JS novos carregam sem Ctrl+F5 |

## 12. Encerramento

| # | Passo | Resultado esperado |
|---|---|---|
| 12.1 | Zerar dados de teste novamente | Base limpa para a validação do presidente |
| 12.2 | Repetir 3.1, 5.1 e 6.2 rapidinho | Fluxo feliz completo funcionando de ponta a ponta |

---

**Critério de aprovação:** todos os itens ☑. Qualquer ✗ → anotar o número do item e o comportamento observado para correção antes de montar a produção.
