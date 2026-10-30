# Testes executados — GMAX Ofertas 3.0 P0

Resultados **gerados automaticamente** a partir da última execução real (não editados à mão).

Condições: sem rede (`allow_url_fopen=0`), `GMAX_TESTING=1` (transporte Telegram real proibido), banco reproduzindo o estado declarado da produção (5 Shopee, ML-001, envio confirmado), **arquivos atuais da HostGator** (cópia de 29/10/2026).

## Unidade e integração (tests/run.php)

Executado em 2026-10-29T22:28:02-03:00 · PHP 8.3.6 · SQLite 3.45.1 · **52/52 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Converte valores brasileiros e de planilha em centavos | 0 s |
| ✅ | Desconto do exemplo (199,90 → 119,90) = 40% OFF, economia R$ 80,00 | 0 s |
| ✅ | Validação de links afiliados e de produto por marketplace | 0 s |
| ✅ | Migração preserva tabelas antigas e copia 5 Shopee + 1 Mercado Livre | 0.094 s |
| ✅ | Sincronização com tabelas antigas é idempotente e capta novos produtos do index.php antigo | 0.107 s |
| ✅ | Reverter migração é recusado com envio não reconciliado | 0.106 s |
| ✅ | Tentativa do cron antigo (slots=tentativa) também bloqueia | 0.107 s |
| ✅ | Reverter migração remove apenas tabelas gmax3_ e mantém dados antigos | 0.138 s |
| ✅ | Backup verificado e restauração das tabelas do painel | 0.149 s |
| ✅ | Preflight: ambiente de teste sem falhas e arquivos iguais à referência | 0.098 s |
| ✅ | Planilha real "25 anúncios" (.xlsx): importa como rascunho sem link e não duplica na reimportação | 0.125 s |
| ✅ | Planilha "16 conferência": páginas de busca descartadas, tudo como rascunho | 0.107 s |
| ✅ | Modelo ML (.xlsx) e CSV de referência atualizam ML-001 em vez de duplicar | 0.105 s |
| ✅ | CSV Shopee reimportado: 5 existentes reconhecidos, nenhum duplicado | 0.103 s |
| ✅ | Linhas inválidas rejeitadas com motivo; válidas aceitas; nada parcial | 0.093 s |
| ✅ | Modelo unificado: Shopee + ML, preço com milhar, cupom e ID extraído da URL | 0.113 s |
| ✅ | Associação de link por reimportação: rascunho recebe link e vai para validação | 0.124 s |
| ✅ | Conflito: mesmo link afiliado já usado por outro produto é rejeitado | 0.109 s |
| ✅ | Desfazer lote remove inseridos e restaura atualizados; prévia descartada não grava | 0.1 s |
| ✅ | Arquivos fora do padrão são recusados (cabeçalho desconhecido, binário, vazio) | 0.091 s |
| ✅ | Mensagem no modelo GMAX com De/Por/% OFF/Economize/Pix | 0 s |
| ✅ | Sem preço original a mensagem não inventa De nem desconto | 0 s |
| ✅ | Aprovação exige link oficial, preço e condição de pagamento | 0.088 s |
| ✅ | Bloqueios de publicação: sem aprovação, preço não conferido, preço vencido, ML em grupo privado | 0.101 s |
| ✅ | Alterar preço retira aprovação e confirmação | 0.099 s |
| ✅ | Item já enviado pelo publicador antigo é bloqueado por duplicidade | 0.101 s |
| ✅ | Item ainda pendente no V2.1 é bloqueado até o V2.1 ser aposentado | 0.1 s |
| ✅ | Ativação do V2.1 presente bloqueia a fila 3.0 (dois publicadores nunca juntos) | 0.108 s |
| ✅ | Pausa pelo painel também cria gmax-publicacao.pause (respeitado pelo V2.1) | 0.096 s |
| ✅ | Lote enfileirado recebe horários distintos (sem rajada) | 0.109 s |
| ✅ | Pausa ou edição entre a checagem e a reserva aborta o envio | 0.116 s |
| ✅ | Duplicidade na fila: o mesmo produto não entra duas vezes na janela | 0.096 s |
| ✅ | Com travas padrão nada é enviado e o transporte nem é criado | 0.097 s |
| ✅ | Simulação não altera estados nem chama transporte | 0.102 s |
| ✅ | Envio confirmado: registra message_id e não republica | 0.162 s |
| ✅ | Falha de rede/timeout: estado incerto, pausa automática, nenhuma repetição | 0.169 s |
| ✅ | Exceção no transporte vira incerto (nunca reenvio automático) | 0.129 s |
| ✅ | Reconciliação: "publicado" impede reenvio; "não publicado" permite reagendar | 0.134 s |
| ✅ | Recusa explícita do Telegram (HTTP 400) = falhou, sem pausa e sem repetição automática | 0.124 s |
| ✅ | Concorrência: com o mutex ocupado (ex.: V2.1 rodando) o processador não age | 0.139 s |
| ✅ | Reserva atômica: item já reservado por outro processo não é enviado de novo | 0.12 s |
| ✅ | Pendência do publicador antigo (attempted) também bloqueia a fila 3.0 | 0.118 s |
| ✅ | Item agendado com dados alterados depois é bloqueado na hora do envio | 0.136 s |
| ✅ | Mercado Livre em grupo privado: bloqueado por padrão mesmo aprovado e conferido | 0.105 s |
| ✅ | Transporte real e HTTP são proibidos em modo de teste | 0 s |
| ✅ | Classificação de respostas do Telegram | 0 s |
| ✅ | Shopee: assinatura SHA256 e tratamento de falha de rede | 0.001 s |
| ✅ | Mercado Livre: consulta de anúncio, sem conversão de links | 0 s |
| ✅ | Ranking transparente: sem dados não inventa pontuação | 0 s |
| ✅ | Produto com um único dado não supera produto bem documentado | 0 s |
| ✅ | Ranking ordena a planilha real de 25 anúncios e mostra confiança parcial | 0.119 s |
| ✅ | Ações relevantes ficam registradas na auditoria | 0.114 s |

## HTTP ponta a ponta e CLI (tests/http_test.php)

Executado em 2026-10-29T22:28:04-03:00 · PHP 8.3.6 · **21/21 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Sem login: tela de entrada, sem dados de ofertas, cabeçalhos de segurança | 0.005 s |
| ✅ | POST sem token CSRF é recusado (inclusive login) | 0.008 s |
| ✅ | Ação sem login retorna 403 mesmo com CSRF válido | 0.007 s |
| ✅ | Arquivos internos não executam pela web (guarda direta e CLI) | 0.002 s |
| ✅ | Login pelo painel antigo (index.php) vale para o novo painel | 0.073 s |
| ✅ | Migração exige confirmação digitada e cria backup | 0.055 s |
| ✅ | Painel unificado mostra as duas lojas (5 Shopee + 1 Mercado Livre) | 0.017 s |
| ✅ | Páginas antigas continuam funcionando após a migração | 0.005 s |
| ✅ | Todas as páginas do painel abrem sem erros | 0.068 s |
| ✅ | Upload da planilha real .xlsx: prévia, confirmação e reimportação sem duplicar | 0.104 s |
| ✅ | XSS: título malicioso importado é exibido como texto | 0.052 s |
| ✅ | Exportação CSV neutraliza fórmulas de planilha | 0.006 s |
| ✅ | Associar link, conferir preço, aprovar e enfileirar (sem envio) | 0.292 s |
| ✅ | Pausa emergencial e retomada com confirmação; nenhum envio pelo navegador | 0.045 s |
| ✅ | Configurações sensíveis não são alteráveis pela web | 0.027 s |
| ✅ | Bloqueio após 5 senhas erradas | 0.353 s |
| ✅ | Logout encerra a sessão | 0.016 s |
| ✅ | Importar links em lote: botão na aba, prévia, CSRF, aplicar e desfazer | 0.169 s |
| ✅ | cron.php original continua rodando em modo prévia após a migração | 0.05 s |
| ✅ | Publicador antigo continua sem enviar (sem arquivo de ativação) | 0.033 s |
| ✅ | CLI 3.0: verificar, status e simulação da fila | 0.172 s |

## Compatibilidade com a produção e conflito de publicadores (tests/compat_test.php)

Executado em 2026-10-29T22:28:05-03:00 · PHP 8.3.6 · **13/13 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Arquivos de produção carregam com o pacote 3.0 (mesmas funções db/h/valid/importFile/message/slot) | 0 s |
| ✅ | slot() de produção continua só em prévia no cron (send=false) e exige telegram.php + env para enviar | 0.13 s |
| ✅ | RISCO CONFIRMADO: publicador instalado tenta de novo o MESMO item após envio incerto | 0.167 s |
| ✅ | Revisão 2.2 (opcional/): após envio incerto a fila PAUSA e não tenta de novo | 0.16 s |
| ✅ | Revisão 2.2 respeita a pausa do painel 3.0 (pausado por padrão após a migração) | 0.163 s |
| ✅ | Revisão 2.2 não envia item já tratado pela fila 3.0 | 0.158 s |
| ✅ | Produto importado no 3.0 só com o link afiliado é reconhecido pelos dois publicadores | 0.159 s |
| ✅ | Fila 3.0 bloqueia quando o publicador antigo está ativado ou com envio incerto | 0.093 s |
| ✅ | Sem arquivo de ativação, ambas as versões do publicador apenas simulam | 0.156 s |
| ✅ | Ativação antiga presente SEM arquivo de pausa = FALHA na verificação (migração impedida) | 0.087 s |
| ✅ | Arquivo de pausa manual bloqueia o publicador instalado mesmo com ativação válida | 0.125 s |
| ✅ | Sem gmax_dispatches e com slots vazia: verificação, migração, painel e rollback funcionam | 0.096 s |
| ✅ | Diagnóstico exporta só o esquema: nenhum título, link, preço ou hash de senha; banco intacto | 0.146 s |

## Links afiliados em lote — 3.0.3 (tests/links_test.php)

Executado em 2026-10-29T22:28:08-03:00 · PHP 8.3.6 · **21/21 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Prévia do arquivo de 18 links: 18 links novos por ID do anúncio, nada gravado ainda | 0.113 s |
| ✅ | Aplicar: 18 links associados; preços, condição, disponibilidade e estado intactos; nada criado ou aprovado | 0.111 s |
| ✅ | Associação independe da ordem das linhas (arquivo embaralhado dá o mesmo resultado) | 0.116 s |
| ✅ | Reimportação do mesmo arquivo: 18 "já iguais", nenhuma alteração | 0.123 s |
| ✅ | Links inválidos são recusados (http, link comum, outro domínio, javascript, vazio) | 0.115 s |
| ✅ | ID inexistente não cria produto; ID inválido é recusado; Shopee nunca é alterada | 0.124 s |
| ✅ | Duplicidades no arquivo: mesmo link em 2 anúncios e mesmo anúncio 2 vezes → todas as ocorrências bloqueadas | 0.108 s |
| ✅ | Link já associado a outro produto (ex.: ML-001) é bloqueado | 0.098 s |
| ✅ | Nome sem nenhuma palavra em comum com o cadastrado é bloqueado (possível produto trocado) | 0.099 s |
| ✅ | Linha marcada como inválida na planilha não é aplicada | 0.104 s |
| ✅ | Substituir link existente exige autorização explícita; produto aprovado volta para validação | 0.139 s |
| ✅ | Verificação: confere, diverge (bloqueia), 404 (bloqueia) e indeterminado (rede) | 0.119 s |
| ✅ | Verificador não segue redirecionamento para domínio estranho | 0 s |
| ✅ | Sem verificação "confere" e sem confirmação manual, nenhum link é associado | 0.144 s |
| ✅ | Concorrência: aplicar duas vezes, aplicar após descartar e verificar após aplicar são recusados | 0.14 s |
| ✅ | Verificação que termina depois de o lote ser aplicado não grava nada | 0.127 s |
| ✅ | Número de anúncio ambíguo (item e catálogo) é recusado | 0.109 s |
| ✅ | Desfazer restaura os links anteriores (vazios) e preserva produto alterado depois | 0.129 s |
| ✅ | Auditoria registra prévia, aplicação, cada link e o desfazer, com usuário e data | 0.121 s |
| ✅ | Importador de produtos não opera lotes de links (e vice-versa) | 0.112 s |
| ✅ | Arquivo sem as colunas obrigatórias é recusado; XLSX com as mesmas colunas funciona | 0.111 s |

## Total

**107/107 testes aprovados.**

## Não testado aqui (depende do servidor real)

- Envio real ao Telegram (proibido nesta fase).
- Apache/.htaccess da HostGator (teste manual no passo 4 da instalação).
- Arquivos e dados reais de produção (verificação na página Sistema).

## Como reproduzir

```
cd desenvolvimento
php -d allow_url_fopen=0 tests/run.php
php tests/http_test.php
php tests/compat_test.php
php -d allow_url_fopen=0 tests/links_test.php
```
