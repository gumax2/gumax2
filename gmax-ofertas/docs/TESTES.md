# Testes executados — GMAX Ofertas 3.1

Resultados **gerados automaticamente** a partir da última execução real (não editados à mão).

Condições: sem rede (`allow_url_fopen=0`), `GMAX_TESTING=1` (transporte Telegram real proibido), banco reproduzindo o estado declarado da produção (5 Shopee, ML-001, envio confirmado), **arquivos atuais da HostGator** (cópia de 29/10/2026).

## Unidade e integração (tests/run.php)

Executado em 2026-10-30T00:24:02-03:00 · PHP 8.3.6 · SQLite 3.45.1 · **52/52 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Converte valores brasileiros e de planilha em centavos | 0 s |
| ✅ | Desconto do exemplo (199,90 → 119,90) = 40% OFF, economia R$ 80,00 | 0 s |
| ✅ | Validação de links afiliados e de produto por marketplace | 0 s |
| ✅ | Migração preserva tabelas antigas e copia 5 Shopee + 1 Mercado Livre | 0.104 s |
| ✅ | Sincronização com tabelas antigas é idempotente e capta novos produtos do index.php antigo | 0.105 s |
| ✅ | Reverter migração é recusado com envio não reconciliado | 0.102 s |
| ✅ | Tentativa do cron antigo (slots=tentativa) também bloqueia | 0.098 s |
| ✅ | Reverter migração remove apenas tabelas gmax3_ e mantém dados antigos | 0.129 s |
| ✅ | Backup verificado e restauração das tabelas do painel | 0.128 s |
| ✅ | Preflight: ambiente de teste sem falhas e arquivos iguais à referência | 0.103 s |
| ✅ | Planilha real "25 anúncios" (.xlsx): importa como rascunho sem link e não duplica na reimportação | 0.127 s |
| ✅ | Planilha "16 conferência": páginas de busca descartadas, tudo como rascunho | 0.102 s |
| ✅ | Modelo ML (.xlsx) e CSV de referência atualizam ML-001 em vez de duplicar | 0.119 s |
| ✅ | CSV Shopee reimportado: 5 existentes reconhecidos, nenhum duplicado | 0.135 s |
| ✅ | Linhas inválidas rejeitadas com motivo; válidas aceitas; nada parcial | 0.114 s |
| ✅ | Modelo unificado: Shopee + ML, preço com milhar, cupom e ID extraído da URL | 0.113 s |
| ✅ | Associação de link por reimportação: rascunho recebe link e vai para validação | 0.12 s |
| ✅ | Conflito: mesmo link afiliado já usado por outro produto é rejeitado | 0.1 s |
| ✅ | Desfazer lote remove inseridos e restaura atualizados; prévia descartada não grava | 0.11 s |
| ✅ | Arquivos fora do padrão são recusados (cabeçalho desconhecido, binário, vazio) | 0.095 s |
| ✅ | Mensagem no modelo GMAX com De/Por/% OFF/Economize/Pix | 0 s |
| ✅ | Sem preço original a mensagem não inventa De nem desconto | 0 s |
| ✅ | Aprovação exige link oficial, preço e condição de pagamento | 0.1 s |
| ✅ | Bloqueios de publicação: sem aprovação, preço não conferido, preço vencido, ML em grupo privado | 0.121 s |
| ✅ | Alterar preço retira aprovação e confirmação | 0.12 s |
| ✅ | Item já enviado pelo publicador antigo é bloqueado por duplicidade | 0.109 s |
| ✅ | Item ainda pendente no V2.1 é bloqueado até o V2.1 ser aposentado | 0.131 s |
| ✅ | Ativação do V2.1 presente bloqueia a fila 3.0 (dois publicadores nunca juntos) | 0.123 s |
| ✅ | Pausa pelo painel também cria gmax-publicacao.pause (respeitado pelo V2.1) | 0.127 s |
| ✅ | Lote enfileirado recebe horários distintos (sem rajada) | 0.128 s |
| ✅ | Pausa ou edição entre a checagem e a reserva aborta o envio | 0.138 s |
| ✅ | Duplicidade na fila: o mesmo produto não entra duas vezes na janela | 0.14 s |
| ✅ | Com travas padrão nada é enviado e o transporte nem é criado | 0.125 s |
| ✅ | Simulação não altera estados nem chama transporte | 0.118 s |
| ✅ | Envio confirmado: registra message_id e não republica | 0.127 s |
| ✅ | Falha de rede/timeout: estado incerto, pausa automática, nenhuma repetição | 0.159 s |
| ✅ | Exceção no transporte vira incerto (nunca reenvio automático) | 0.128 s |
| ✅ | Reconciliação: "publicado" impede reenvio; "não publicado" permite reagendar | 0.143 s |
| ✅ | Recusa explícita do Telegram (HTTP 400) = falhou, sem pausa e sem repetição automática | 0.146 s |
| ✅ | Concorrência: com o mutex ocupado (ex.: V2.1 rodando) o processador não age | 0.197 s |
| ✅ | Reserva atômica: item já reservado por outro processo não é enviado de novo | 0.156 s |
| ✅ | Pendência do publicador antigo (attempted) também bloqueia a fila 3.0 | 0.146 s |
| ✅ | Item agendado com dados alterados depois é bloqueado na hora do envio | 0.129 s |
| ✅ | Mercado Livre em grupo privado: bloqueado por padrão mesmo aprovado e conferido | 0.125 s |
| ✅ | Transporte real e HTTP são proibidos em modo de teste | 0 s |
| ✅ | Classificação de respostas do Telegram | 0 s |
| ✅ | Shopee: assinatura SHA256 e tratamento de falha de rede | 0.001 s |
| ✅ | Mercado Livre: consulta de anúncio, sem conversão de links | 0.001 s |
| ✅ | Ranking transparente: sem dados não inventa pontuação | 0.001 s |
| ✅ | Produto com um único dado não supera produto bem documentado | 0 s |
| ✅ | Ranking ordena a planilha real de 25 anúncios e mostra confiança parcial | 0.132 s |
| ✅ | Ações relevantes ficam registradas na auditoria | 0.131 s |

## HTTP ponta a ponta e CLI (tests/http_test.php)

Executado em 2026-10-30T00:24:04-03:00 · PHP 8.3.6 · **23/23 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Sem login: tela de entrada, sem dados de ofertas, cabeçalhos de segurança | 0.006 s |
| ✅ | POST sem token CSRF é recusado (inclusive login) | 0.012 s |
| ✅ | Ação sem login retorna 403 mesmo com CSRF válido | 0.007 s |
| ✅ | Arquivos internos não executam pela web (guarda direta e CLI) | 0.002 s |
| ✅ | Login pelo painel antigo (index.php) vale para o novo painel | 0.079 s |
| ✅ | Migração exige confirmação digitada e cria backup | 0.053 s |
| ✅ | Painel unificado mostra as duas lojas (5 Shopee + 1 Mercado Livre) | 0.013 s |
| ✅ | Páginas antigas continuam funcionando após a migração | 0.004 s |
| ✅ | Todas as páginas do painel abrem sem erros | 0.119 s |
| ✅ | Upload da planilha real .xlsx: prévia, confirmação e reimportação sem duplicar | 0.108 s |
| ✅ | XSS: título malicioso importado é exibido como texto | 0.066 s |
| ✅ | Exportação CSV neutraliza fórmulas de planilha | 0.007 s |
| ✅ | Associar link, conferir preço, aprovar e enfileirar (sem envio) | 0.36 s |
| ✅ | Pausa emergencial e retomada com confirmação; nenhum envio pelo navegador | 0.047 s |
| ✅ | Configurações sensíveis não são alteráveis pela web | 0.032 s |
| ✅ | Bloqueio após 5 senhas erradas | 0.353 s |
| ✅ | Logout encerra a sessão | 0.017 s |
| ✅ | Importar links em lote: botão na aba, prévia, CSRF, aplicar e desfazer | 0.125 s |
| ✅ | Central: execução manual, agendamento validado, pausa/retomada, modelo escapado, nada enviado | 0.142 s |
| ✅ | Integrações: conectar gera redirecionamento S256 com state; callback exige login e state válido | 0.026 s |
| ✅ | cron.php original continua rodando em modo prévia após a migração | 0.055 s |
| ✅ | Publicador antigo continua sem enviar (sem arquivo de ativação) | 0.035 s |
| ✅ | CLI 3.0: verificar, status e simulação da fila | 0.173 s |

## Compatibilidade com a produção e conflito de publicadores (tests/compat_test.php)

Executado em 2026-10-30T00:24:06-03:00 · PHP 8.3.6 · **13/13 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Arquivos de produção carregam com o pacote 3.0 (mesmas funções db/h/valid/importFile/message/slot) | 0 s |
| ✅ | slot() de produção continua só em prévia no cron (send=false) e exige telegram.php + env para enviar | 0.128 s |
| ✅ | RISCO CONFIRMADO: publicador instalado tenta de novo o MESMO item após envio incerto | 0.165 s |
| ✅ | Revisão 2.2 (opcional/): após envio incerto a fila PAUSA e não tenta de novo | 0.18 s |
| ✅ | Revisão 2.2 respeita a pausa do painel 3.0 (pausado por padrão após a migração) | 0.14 s |
| ✅ | Revisão 2.2 não envia item já tratado pela fila 3.0 | 0.146 s |
| ✅ | Produto importado no 3.0 só com o link afiliado é reconhecido pelos dois publicadores | 0.152 s |
| ✅ | Fila 3.0 bloqueia quando o publicador antigo está ativado ou com envio incerto | 0.1 s |
| ✅ | Sem arquivo de ativação, ambas as versões do publicador apenas simulam | 0.206 s |
| ✅ | Ativação antiga presente SEM arquivo de pausa = FALHA na verificação (migração impedida) | 0.082 s |
| ✅ | Arquivo de pausa manual bloqueia o publicador instalado mesmo com ativação válida | 0.137 s |
| ✅ | Sem gmax_dispatches e com slots vazia: verificação, migração, painel e rollback funcionam | 0.105 s |
| ✅ | Diagnóstico exporta só o esquema: nenhum título, link, preço ou hash de senha; banco intacto | 0.143 s |

## Links afiliados em lote — 3.0.3 (tests/links_test.php)

Executado em 2026-10-30T00:24:09-03:00 · PHP 8.3.6 · **21/21 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Prévia do arquivo de 18 links: 18 links novos por ID do anúncio, nada gravado ainda | 0.115 s |
| ✅ | Aplicar: 18 links associados; preços, condição, disponibilidade e estado intactos; nada criado ou aprovado | 0.125 s |
| ✅ | Associação independe da ordem das linhas (arquivo embaralhado dá o mesmo resultado) | 0.15 s |
| ✅ | Reimportação do mesmo arquivo: 18 "já iguais", nenhuma alteração | 0.176 s |
| ✅ | Links inválidos são recusados (http, link comum, outro domínio, javascript, vazio) | 0.129 s |
| ✅ | ID inexistente não cria produto; ID inválido é recusado; Shopee nunca é alterada | 0.127 s |
| ✅ | Duplicidades no arquivo: mesmo link em 2 anúncios e mesmo anúncio 2 vezes → todas as ocorrências bloqueadas | 0.122 s |
| ✅ | Link já associado a outro produto (ex.: ML-001) é bloqueado | 0.117 s |
| ✅ | Nome sem nenhuma palavra em comum com o cadastrado é bloqueado (possível produto trocado) | 0.125 s |
| ✅ | Linha marcada como inválida na planilha não é aplicada | 0.126 s |
| ✅ | Substituir link existente exige autorização explícita; produto aprovado volta para validação | 0.143 s |
| ✅ | Verificação: confere, diverge (bloqueia), 404 (bloqueia) e indeterminado (rede) | 0.119 s |
| ✅ | Verificador não segue redirecionamento para domínio estranho | 0 s |
| ✅ | Sem verificação "confere" e sem confirmação manual, nenhum link é associado | 0.135 s |
| ✅ | Concorrência: aplicar duas vezes, aplicar após descartar e verificar após aplicar são recusados | 0.131 s |
| ✅ | Verificação que termina depois de o lote ser aplicado não grava nada | 0.157 s |
| ✅ | Número de anúncio ambíguo (item e catálogo) é recusado | 0.117 s |
| ✅ | Desfazer restaura os links anteriores (vazios) e preserva produto alterado depois | 0.134 s |
| ✅ | Auditoria registra prévia, aplicação, cada link e o desfazer, com usuário e data | 0.155 s |
| ✅ | Importador de produtos não opera lotes de links (e vice-versa) | 0.137 s |
| ✅ | Arquivo sem as colunas obrigatórias é recusado; XLSX com as mesmas colunas funciona | 0.126 s |

## Central de Automações — 3.1 (tests/automacoes_test.php)

Executado em 2026-10-30T00:24:12-03:00 · PHP 8.3.6 · **25/25 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Migração 002: tarefas criadas inativas, automações pausadas, aprovação automática desligada; reversível | 0.137 s |
| ✅ | Horários: vence às 07:05, perdido depois de 2 h, respeita dia da semana | 0.101 s |
| ✅ | Pausa geral: nada executa; ativa: executa uma vez por horário (idempotente) | 0.129 s |
| ✅ | Concorrência: com a trava das automações ocupada, nada executa | 0.145 s |
| ✅ | Execução interrompida pelo servidor é encerrada como falha na próxima chamada | 0.12 s |
| ✅ | Descoberta: insere novos como rascunho com origem e data; ignora existentes, indisponíveis e incompletos | 0.174 s |
| ✅ | Shopee: link oficial da API é aceito; link fora do padrão é descartado; integração ausente gera alerta | 0.119 s |
| ✅ | Falha da API na busca: execução parcial, erro registrado, nada inventado | 0.092 s |
| ✅ | Preço igual confirmado pela API: renova a confirmação e mantém aprovação | 0.122 s |
| ✅ | Preço mudou: atualiza Por e retira aprovação; "De" só é limpo por fonte que informa preço original | 0.127 s |
| ✅ | Mesmo preço da API não retira aprovação de "De" cadastrado manualmente (revisão repetida) | 0.143 s |
| ✅ | Edição concorrente durante a revisão não é carimbada como confirmada pela API | 0.162 s |
| ✅ | API falhou: dados não mudam, alerta aberto e publicação bloqueada pelo preço vencido | 0.144 s |
| ✅ | Anúncio pausado: indisponível, agendamento cancelado, alerta | 0.174 s |
| ✅ | Seleção coloca as melhores na fila respeitando o limite diário (nada é enviado) | 0.162 s |
| ✅ | Aprovação: manual por padrão (alerta); automática só com autorização e preço confirmado por API | 0.146 s |
| ✅ | Mercado Livre não entra na fila do grupo privado (regra do programa) mesmo com nota alta | 0.133 s |
| ✅ | Vencidas: aprovada com preço antigo volta à validação; indisponível antiga é arquivada sem perder histórico | 0.133 s |
| ✅ | Saúde: duplicidade por nome, link fora do padrão, alerta fecha sozinho quando resolvido | 0.147 s |
| ✅ | Modelos por loja/categoria; linhas sem dado confirmado são omitidas; validação de modelo | 0.105 s |
| ✅ | Limite diário de requisições por conector e registro de falhas | 0.11 s |
| ✅ | Mercado Livre (busca oficial): interpreta resposta; 403 vira "não autorizada"; vendas só se informadas | 0.001 s |
| ✅ | Shopee (busca oficial): link afiliado da API, desconto informado sem inventar preço original | 0.001 s |
| ✅ | Amazon e AliExpress: preparados, sem requisições e sem dados simulados | 0.097 s |
| ✅ | Todas as tarefas executadas em sequência: nenhum transporte criado, nenhum item reservado/enviado | 0.204 s |

## OAuth Mercado Livre — 3.1.1 (tests/oauth_test.php)

Executado em 2026-10-30T00:24:12-03:00 · PHP 8.3.6 · **12/12 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | PKCE S256 confere com o vetor oficial da RFC 7636 | 0.001 s |
| ✅ | URL de autorização: S256, state aleatório, redirect exata, verifier só na sessão | 0.001 s |
| ✅ | Callback recusa state errado, ausente, expirado, reutilizado e erro do provedor | 0 s |
| ✅ | Troca do código envia code_verifier, redirect_uri e grant correto; token gravado cifrado (0600) | 0.001 s |
| ✅ | Token perto de expirar é renovado; refresh token novo substitui o antigo (rotação) | 0.002 s |
| ✅ | Token válido não gera requisição; outro processo renovou antes: não renova de novo | 0.001 s |
| ✅ | invalid_grant na renovação: token marcado para reautorizar; conector fica desconectado | 0.002 s |
| ✅ | Falha de rede na renovação não invalida o token (tenta de novo depois) | 0.002 s |
| ✅ | Conector: 401 da API renova uma vez e repete a consulta; pesquisa usa o token OAuth | 0.001 s |
| ✅ | Diagnóstico de permissões: busca negada (403) é reportada sem quebrar | 0.001 s |
| ✅ | Configuração do app: redirect sem https, client_id inválido e ausência do arquivo são apontados | 0 s |
| ✅ | Segredo trocado: token antigo não decifra (exige nova conexão) | 0.001 s |

## Total

**146/146 testes aprovados.**

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
php -d allow_url_fopen=0 tests/automacoes_test.php
php -d allow_url_fopen=0 tests/oauth_test.php
```
