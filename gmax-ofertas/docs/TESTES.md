# Testes executados — GMAX Ofertas 3.0 P0

Resultados **gerados automaticamente** a partir da última execução real (não editados à mão).

Condições: sem rede (`allow_url_fopen=0`), `GMAX_TESTING=1` (transporte Telegram real proibido), banco reproduzindo o estado declarado da produção (5 Shopee, ML-001, envio confirmado do V2.1), arquivos de referência do kit.

## Unidade e integração (tests/run.php)

Executado em 2026-10-28T23:15:34-03:00 · PHP 8.3.6 · SQLite 3.45.1 · **52/52 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Converte valores brasileiros e de planilha em centavos | 0 s |
| ✅ | Desconto do exemplo (199,90 → 119,90) = 40% OFF, economia R$ 80,00 | 0 s |
| ✅ | Validação de links afiliados e de produto por marketplace | 0 s |
| ✅ | Migração preserva tabelas antigas e copia 5 Shopee + 1 Mercado Livre | 0.097 s |
| ✅ | Sincronização com tabelas antigas é idempotente e capta novos produtos do index.php antigo | 0.093 s |
| ✅ | Reverter migração é recusado com envio não reconciliado | 0.087 s |
| ✅ | Tentativa do cron antigo (slots=tentativa) também bloqueia | 0.085 s |
| ✅ | Reverter migração remove apenas tabelas gmax3_ e mantém dados antigos | 0.106 s |
| ✅ | Backup verificado e restauração das tabelas do painel | 0.109 s |
| ✅ | Preflight: ambiente de teste sem falhas e arquivos iguais à referência | 0.092 s |
| ✅ | Planilha real "25 anúncios" (.xlsx): importa como rascunho sem link e não duplica na reimportação | 0.11 s |
| ✅ | Planilha "16 conferência": páginas de busca descartadas, tudo como rascunho | 0.105 s |
| ✅ | Modelo ML (.xlsx) e CSV de referência atualizam ML-001 em vez de duplicar | 0.1 s |
| ✅ | CSV Shopee reimportado: 5 existentes reconhecidos, nenhum duplicado | 0.101 s |
| ✅ | Linhas inválidas rejeitadas com motivo; válidas aceitas; nada parcial | 0.097 s |
| ✅ | Modelo unificado: Shopee + ML, preço com milhar, cupom e ID extraído da URL | 0.098 s |
| ✅ | Associação de link por reimportação: rascunho recebe link e vai para validação | 0.113 s |
| ✅ | Conflito: mesmo link afiliado já usado por outro produto é rejeitado | 0.094 s |
| ✅ | Desfazer lote remove inseridos e restaura atualizados; prévia descartada não grava | 0.108 s |
| ✅ | Arquivos fora do padrão são recusados (cabeçalho desconhecido, binário, vazio) | 0.096 s |
| ✅ | Mensagem no modelo GMAX com De/Por/% OFF/Economize/Pix | 0 s |
| ✅ | Sem preço original a mensagem não inventa De nem desconto | 0 s |
| ✅ | Aprovação exige link oficial, preço e condição de pagamento | 0.101 s |
| ✅ | Bloqueios de publicação: sem aprovação, preço não conferido, preço vencido, ML em grupo privado | 0.104 s |
| ✅ | Alterar preço retira aprovação e confirmação | 0.111 s |
| ✅ | Item já enviado pelo publicador V2.1 é bloqueado por duplicidade | 0.095 s |
| ✅ | Item ainda pendente no V2.1 é bloqueado até o V2.1 ser aposentado | 0.098 s |
| ✅ | Ativação do V2.1 presente bloqueia a fila 3.0 (dois publicadores nunca juntos) | 0.101 s |
| ✅ | Pausa pelo painel também cria gmax-publicacao.pause (respeitado pelo V2.1) | 0.094 s |
| ✅ | Lote enfileirado recebe horários distintos (sem rajada) | 0.11 s |
| ✅ | Pausa ou edição entre a checagem e a reserva aborta o envio | 0.105 s |
| ✅ | Duplicidade na fila: o mesmo produto não entra duas vezes na janela | 0.101 s |
| ✅ | Com travas padrão nada é enviado e o transporte nem é criado | 0.098 s |
| ✅ | Simulação não altera estados nem chama transporte | 0.096 s |
| ✅ | Envio confirmado: registra message_id e não republica | 0.104 s |
| ✅ | Falha de rede/timeout: estado incerto, pausa automática, nenhuma repetição | 0.123 s |
| ✅ | Exceção no transporte vira incerto (nunca reenvio automático) | 0.11 s |
| ✅ | Reconciliação: "publicado" impede reenvio; "não publicado" permite reagendar | 0.111 s |
| ✅ | Recusa explícita do Telegram (HTTP 400) = falhou, sem pausa e sem repetição automática | 0.108 s |
| ✅ | Concorrência: com o mutex ocupado (ex.: V2.1 rodando) o processador não age | 0.138 s |
| ✅ | Reserva atômica: item já reservado por outro processo não é enviado de novo | 0.116 s |
| ✅ | Pendência do publicador V2.1 (attempted) também bloqueia a fila 3.0 | 0.115 s |
| ✅ | Item agendado com dados alterados depois é bloqueado na hora do envio | 0.105 s |
| ✅ | Mercado Livre em grupo privado: bloqueado por padrão mesmo aprovado e conferido | 0.093 s |
| ✅ | Transporte real e HTTP são proibidos em modo de teste | 0 s |
| ✅ | Classificação de respostas do Telegram | 0 s |
| ✅ | Shopee: assinatura SHA256 e tratamento de falha de rede | 0 s |
| ✅ | Mercado Livre: consulta de anúncio, sem conversão de links | 0 s |
| ✅ | Ranking transparente: sem dados não inventa pontuação | 0 s |
| ✅ | Produto com um único dado não supera produto bem documentado | 0 s |
| ✅ | Ranking ordena a planilha real de 25 anúncios e mostra confiança parcial | 0.102 s |
| ✅ | Ações relevantes ficam registradas na auditoria | 0.104 s |

## HTTP ponta a ponta e CLI (tests/http_test.php)

Executado em 2026-10-28T23:15:35-03:00 · PHP 8.3.6 · **20/20 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Sem login: tela de entrada, sem dados de ofertas, cabeçalhos de segurança | 0.003 s |
| ✅ | POST sem token CSRF é recusado (inclusive login) | 0.007 s |
| ✅ | Ação sem login retorna 403 mesmo com CSRF válido | 0.005 s |
| ✅ | Arquivos internos não executam pela web (guarda direta e CLI) | 0.002 s |
| ✅ | Login pelo painel antigo (index.php) vale para o novo painel | 0.074 s |
| ✅ | Migração exige confirmação digitada e cria backup | 0.035 s |
| ✅ | Painel unificado mostra as duas lojas (5 Shopee + 1 Mercado Livre) | 0.009 s |
| ✅ | Páginas antigas continuam funcionando após a migração | 0.003 s |
| ✅ | Todas as páginas do painel abrem sem erros | 0.053 s |
| ✅ | Upload da planilha real .xlsx: prévia, confirmação e reimportação sem duplicar | 0.071 s |
| ✅ | XSS: título malicioso importado é exibido como texto | 0.04 s |
| ✅ | Exportação CSV neutraliza fórmulas de planilha | 0.005 s |
| ✅ | Associar link, conferir preço, aprovar e enfileirar (sem envio) | 0.217 s |
| ✅ | Pausa emergencial e retomada com confirmação; nenhum envio pelo navegador | 0.027 s |
| ✅ | Configurações sensíveis não são alteráveis pela web | 0.019 s |
| ✅ | Bloqueio após 5 senhas erradas | 0.385 s |
| ✅ | Logout encerra a sessão | 0.012 s |
| ✅ | cron.php original continua rodando em modo prévia após a migração | 0.027 s |
| ✅ | Publicador V2.1 continua sem enviar (sem arquivo de ativação) | 0.026 s |
| ✅ | CLI 3.0: verificar, status e simulação da fila | 0.117 s |

## Total

**72/72 testes aprovados.**

## Não testado aqui (depende do servidor real)

- Envio real ao Telegram (proibido nesta fase).
- Apache/.htaccess da HostGator (teste manual no passo 4 da instalação).
- Arquivos e dados reais de produção (verificação na página Sistema).

## Como reproduzir

```
cd desenvolvimento
php -d allow_url_fopen=0 tests/run.php
php tests/http_test.php
```
