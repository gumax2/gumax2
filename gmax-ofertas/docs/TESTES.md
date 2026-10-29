# Testes executados — GMAX Ofertas 3.0 P0

Resultados **gerados automaticamente** a partir da última execução real (não editados à mão).

Condições: sem rede (`allow_url_fopen=0`), `GMAX_TESTING=1` (transporte Telegram real proibido), banco reproduzindo o estado declarado da produção (5 Shopee, ML-001, envio confirmado), **arquivos atuais da HostGator** (cópia de 29/10/2026).

## Unidade e integração (tests/run.php)

Executado em 2026-10-29T20:20:13-03:00 · PHP 8.3.6 · SQLite 3.45.1 · **52/52 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Converte valores brasileiros e de planilha em centavos | 0 s |
| ✅ | Desconto do exemplo (199,90 → 119,90) = 40% OFF, economia R$ 80,00 | 0 s |
| ✅ | Validação de links afiliados e de produto por marketplace | 0 s |
| ✅ | Migração preserva tabelas antigas e copia 5 Shopee + 1 Mercado Livre | 0.106 s |
| ✅ | Sincronização com tabelas antigas é idempotente e capta novos produtos do index.php antigo | 0.098 s |
| ✅ | Reverter migração é recusado com envio não reconciliado | 0.089 s |
| ✅ | Tentativa do cron antigo (slots=tentativa) também bloqueia | 0.086 s |
| ✅ | Reverter migração remove apenas tabelas gmax3_ e mantém dados antigos | 0.102 s |
| ✅ | Backup verificado e restauração das tabelas do painel | 0.117 s |
| ✅ | Preflight: ambiente de teste sem falhas e arquivos iguais à referência | 0.089 s |
| ✅ | Planilha real "25 anúncios" (.xlsx): importa como rascunho sem link e não duplica na reimportação | 0.125 s |
| ✅ | Planilha "16 conferência": páginas de busca descartadas, tudo como rascunho | 0.103 s |
| ✅ | Modelo ML (.xlsx) e CSV de referência atualizam ML-001 em vez de duplicar | 0.129 s |
| ✅ | CSV Shopee reimportado: 5 existentes reconhecidos, nenhum duplicado | 0.119 s |
| ✅ | Linhas inválidas rejeitadas com motivo; válidas aceitas; nada parcial | 0.11 s |
| ✅ | Modelo unificado: Shopee + ML, preço com milhar, cupom e ID extraído da URL | 0.101 s |
| ✅ | Associação de link por reimportação: rascunho recebe link e vai para validação | 0.119 s |
| ✅ | Conflito: mesmo link afiliado já usado por outro produto é rejeitado | 0.094 s |
| ✅ | Desfazer lote remove inseridos e restaura atualizados; prévia descartada não grava | 0.102 s |
| ✅ | Arquivos fora do padrão são recusados (cabeçalho desconhecido, binário, vazio) | 0.095 s |
| ✅ | Mensagem no modelo GMAX com De/Por/% OFF/Economize/Pix | 0 s |
| ✅ | Sem preço original a mensagem não inventa De nem desconto | 0 s |
| ✅ | Aprovação exige link oficial, preço e condição de pagamento | 0.097 s |
| ✅ | Bloqueios de publicação: sem aprovação, preço não conferido, preço vencido, ML em grupo privado | 0.103 s |
| ✅ | Alterar preço retira aprovação e confirmação | 0.117 s |
| ✅ | Item já enviado pelo publicador antigo é bloqueado por duplicidade | 0.107 s |
| ✅ | Item ainda pendente no V2.1 é bloqueado até o V2.1 ser aposentado | 0.114 s |
| ✅ | Ativação do V2.1 presente bloqueia a fila 3.0 (dois publicadores nunca juntos) | 0.124 s |
| ✅ | Pausa pelo painel também cria gmax-publicacao.pause (respeitado pelo V2.1) | 0.106 s |
| ✅ | Lote enfileirado recebe horários distintos (sem rajada) | 0.144 s |
| ✅ | Pausa ou edição entre a checagem e a reserva aborta o envio | 0.12 s |
| ✅ | Duplicidade na fila: o mesmo produto não entra duas vezes na janela | 0.107 s |
| ✅ | Com travas padrão nada é enviado e o transporte nem é criado | 0.12 s |
| ✅ | Simulação não altera estados nem chama transporte | 0.105 s |
| ✅ | Envio confirmado: registra message_id e não republica | 0.121 s |
| ✅ | Falha de rede/timeout: estado incerto, pausa automática, nenhuma repetição | 0.145 s |
| ✅ | Exceção no transporte vira incerto (nunca reenvio automático) | 0.121 s |
| ✅ | Reconciliação: "publicado" impede reenvio; "não publicado" permite reagendar | 0.125 s |
| ✅ | Recusa explícita do Telegram (HTTP 400) = falhou, sem pausa e sem repetição automática | 0.133 s |
| ✅ | Concorrência: com o mutex ocupado (ex.: V2.1 rodando) o processador não age | 0.147 s |
| ✅ | Reserva atômica: item já reservado por outro processo não é enviado de novo | 0.148 s |
| ✅ | Pendência do publicador antigo (attempted) também bloqueia a fila 3.0 | 0.135 s |
| ✅ | Item agendado com dados alterados depois é bloqueado na hora do envio | 0.149 s |
| ✅ | Mercado Livre em grupo privado: bloqueado por padrão mesmo aprovado e conferido | 0.109 s |
| ✅ | Transporte real e HTTP são proibidos em modo de teste | 0 s |
| ✅ | Classificação de respostas do Telegram | 0 s |
| ✅ | Shopee: assinatura SHA256 e tratamento de falha de rede | 0.001 s |
| ✅ | Mercado Livre: consulta de anúncio, sem conversão de links | 0 s |
| ✅ | Ranking transparente: sem dados não inventa pontuação | 0.001 s |
| ✅ | Produto com um único dado não supera produto bem documentado | 0 s |
| ✅ | Ranking ordena a planilha real de 25 anúncios e mostra confiança parcial | 0.107 s |
| ✅ | Ações relevantes ficam registradas na auditoria | 0.117 s |

## HTTP ponta a ponta e CLI (tests/http_test.php)

Executado em 2026-10-29T20:20:15-03:00 · PHP 8.3.6 · **20/20 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Sem login: tela de entrada, sem dados de ofertas, cabeçalhos de segurança | 0.007 s |
| ✅ | POST sem token CSRF é recusado (inclusive login) | 0.01 s |
| ✅ | Ação sem login retorna 403 mesmo com CSRF válido | 0.005 s |
| ✅ | Arquivos internos não executam pela web (guarda direta e CLI) | 0.002 s |
| ✅ | Login pelo painel antigo (index.php) vale para o novo painel | 0.066 s |
| ✅ | Migração exige confirmação digitada e cria backup | 0.042 s |
| ✅ | Painel unificado mostra as duas lojas (5 Shopee + 1 Mercado Livre) | 0.012 s |
| ✅ | Páginas antigas continuam funcionando após a migração | 0.004 s |
| ✅ | Todas as páginas do painel abrem sem erros | 0.066 s |
| ✅ | Upload da planilha real .xlsx: prévia, confirmação e reimportação sem duplicar | 0.096 s |
| ✅ | XSS: título malicioso importado é exibido como texto | 0.059 s |
| ✅ | Exportação CSV neutraliza fórmulas de planilha | 0.009 s |
| ✅ | Associar link, conferir preço, aprovar e enfileirar (sem envio) | 0.33 s |
| ✅ | Pausa emergencial e retomada com confirmação; nenhum envio pelo navegador | 0.038 s |
| ✅ | Configurações sensíveis não são alteráveis pela web | 0.032 s |
| ✅ | Bloqueio após 5 senhas erradas | 0.37 s |
| ✅ | Logout encerra a sessão | 0.014 s |
| ✅ | cron.php original continua rodando em modo prévia após a migração | 0.035 s |
| ✅ | Publicador antigo continua sem enviar (sem arquivo de ativação) | 0.038 s |
| ✅ | CLI 3.0: verificar, status e simulação da fila | 0.152 s |

## Compatibilidade com a produção e conflito de publicadores (tests/compat_test.php)

Executado em 2026-10-29T20:20:17-03:00 · PHP 8.3.6 · **13/13 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Arquivos de produção carregam com o pacote 3.0 (mesmas funções db/h/valid/importFile/message/slot) | 0 s |
| ✅ | slot() de produção continua só em prévia no cron (send=false) e exige telegram.php + env para enviar | 0.138 s |
| ✅ | RISCO CONFIRMADO: publicador instalado tenta de novo o MESMO item após envio incerto | 0.181 s |
| ✅ | Revisão 2.2 (opcional/): após envio incerto a fila PAUSA e não tenta de novo | 0.213 s |
| ✅ | Revisão 2.2 respeita a pausa do painel 3.0 (pausado por padrão após a migração) | 0.166 s |
| ✅ | Revisão 2.2 não envia item já tratado pela fila 3.0 | 0.184 s |
| ✅ | Produto importado no 3.0 só com o link afiliado é reconhecido pelos dois publicadores | 0.155 s |
| ✅ | Fila 3.0 bloqueia quando o publicador antigo está ativado ou com envio incerto | 0.111 s |
| ✅ | Sem arquivo de ativação, ambas as versões do publicador apenas simulam | 0.206 s |
| ✅ | Ativação antiga presente SEM arquivo de pausa = FALHA na verificação (migração impedida) | 0.084 s |
| ✅ | Arquivo de pausa manual bloqueia o publicador instalado mesmo com ativação válida | 0.145 s |
| ✅ | Sem gmax_dispatches e com slots vazia: verificação, migração, painel e rollback funcionam | 0.12 s |
| ✅ | Diagnóstico exporta só o esquema: nenhum título, link, preço ou hash de senha; banco intacto | 0.184 s |

## Total

**85/85 testes aprovados.**

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
```
