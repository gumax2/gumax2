# Testes executados — GMAX Ofertas 3.1.9

Resultados **gerados automaticamente** a partir da última execução real (não editados à mão).

Condições: sem rede (`allow_url_fopen=0`), `GMAX_TESTING=1` (transporte Telegram real proibido), banco reproduzindo o estado declarado da produção (5 Shopee, ML-001, envio confirmado), **arquivos atuais da HostGator** (cópia de 29/10/2026).

## Unidade e integração (tests/run.php)

Executado em 2026-11-05T01:41:44-03:00 · PHP 8.3.6 · SQLite 3.45.1 · **52/52 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Converte valores brasileiros e de planilha em centavos | 0 s |
| ✅ | Desconto do exemplo (199,90 → 119,90) = 40% OFF, economia R$ 80,00 | 0 s |
| ✅ | Validação de links afiliados e de produto por marketplace | 0 s |
| ✅ | Migração preserva tabelas antigas e copia 5 Shopee + 1 Mercado Livre | 0.093 s |
| ✅ | Sincronização com tabelas antigas é idempotente e capta novos produtos do index.php antigo | 0.098 s |
| ✅ | Reverter migração é recusado com envio não reconciliado | 0.101 s |
| ✅ | Tentativa do cron antigo (slots=tentativa) também bloqueia | 0.1 s |
| ✅ | Reverter migração remove apenas tabelas gmax3_ e mantém dados antigos | 0.146 s |
| ✅ | Backup verificado e restauração das tabelas do painel | 0.112 s |
| ✅ | Preflight: ambiente de teste sem falhas e arquivos iguais à referência | 0.094 s |
| ✅ | Planilha real "25 anúncios" (.xlsx): importa como rascunho sem link e não duplica na reimportação | 0.109 s |
| ✅ | Planilha "16 conferência": páginas de busca descartadas, tudo como rascunho | 0.094 s |
| ✅ | Modelo ML (.xlsx) e CSV de referência atualizam ML-001 em vez de duplicar | 0.101 s |
| ✅ | CSV Shopee reimportado: 5 existentes reconhecidos, nenhum duplicado | 0.098 s |
| ✅ | Linhas inválidas rejeitadas com motivo; válidas aceitas; nada parcial | 0.089 s |
| ✅ | Modelo unificado: Shopee + ML, preço com milhar, cupom e ID extraído da URL | 0.089 s |
| ✅ | Associação de link por reimportação: rascunho recebe link e vai para validação | 0.101 s |
| ✅ | Conflito: mesmo link afiliado já usado por outro produto é rejeitado | 0.087 s |
| ✅ | Desfazer lote remove inseridos e restaura atualizados; prévia descartada não grava | 0.101 s |
| ✅ | Arquivos fora do padrão são recusados (cabeçalho desconhecido, binário, vazio) | 0.101 s |
| ✅ | Mensagem no modelo GMAX com De/Por/% OFF/Economize/Pix | 0 s |
| ✅ | Sem preço original a mensagem não inventa De nem desconto | 0 s |
| ✅ | Aprovação exige link oficial, preço e condição de pagamento | 0.101 s |
| ✅ | Bloqueios de publicação: sem aprovação, preço não conferido, preço vencido, ML em grupo privado | 0.106 s |
| ✅ | Alterar preço retira aprovação e confirmação | 0.105 s |
| ✅ | Item já enviado pelo publicador antigo é bloqueado por duplicidade | 0.104 s |
| ✅ | Item ainda pendente no V2.1 é bloqueado até o V2.1 ser aposentado | 0.111 s |
| ✅ | Ativação do V2.1 presente bloqueia a fila 3.0 (dois publicadores nunca juntos) | 0.113 s |
| ✅ | Pausa pelo painel também cria gmax-publicacao.pause (respeitado pelo V2.1) | 0.111 s |
| ✅ | Lote enfileirado recebe horários distintos (sem rajada) | 0.124 s |
| ✅ | Pausa ou edição entre a checagem e a reserva aborta o envio | 0.108 s |
| ✅ | Duplicidade na fila: o mesmo produto não entra duas vezes na janela | 0.104 s |
| ✅ | Com travas padrão nada é enviado e o transporte nem é criado | 0.108 s |
| ✅ | Simulação não altera estados nem chama transporte | 0.105 s |
| ✅ | Envio confirmado: registra message_id e não republica | 0.126 s |
| ✅ | Falha de rede/timeout: estado incerto, pausa automática, nenhuma repetição | 0.126 s |
| ✅ | Exceção no transporte vira incerto (nunca reenvio automático) | 0.108 s |
| ✅ | Reconciliação: "publicado" impede reenvio; "não publicado" permite reagendar | 0.123 s |
| ✅ | Recusa explícita do Telegram (HTTP 400) = falhou, sem pausa e sem repetição automática | 0.112 s |
| ✅ | Concorrência: com o mutex ocupado (ex.: V2.1 rodando) o processador não age | 0.127 s |
| ✅ | Reserva atômica: item já reservado por outro processo não é enviado de novo | 0.109 s |
| ✅ | Pendência do publicador antigo (attempted) também bloqueia a fila 3.0 | 0.114 s |
| ✅ | Item agendado com dados alterados depois é bloqueado na hora do envio | 0.111 s |
| ✅ | Mercado Livre em grupo privado: bloqueado por padrão mesmo aprovado e conferido | 0.116 s |
| ✅ | Transporte real e HTTP são proibidos em modo de teste | 0 s |
| ✅ | Classificação de respostas do Telegram | 0 s |
| ✅ | Shopee: assinatura SHA256 e tratamento de falha de rede | 0.001 s |
| ✅ | Mercado Livre: consulta de anúncio, sem conversão de links | 0.001 s |
| ✅ | Ranking transparente: sem dados não inventa pontuação | 0.001 s |
| ✅ | Produto com um único dado não supera produto bem documentado | 0 s |
| ✅ | Ranking ordena a planilha real de 25 anúncios e mostra confiança parcial | 0.106 s |
| ✅ | Ações relevantes ficam registradas na auditoria | 0.106 s |

## HTTP ponta a ponta e CLI (tests/http_test.php)

Executado em 2026-11-05T01:41:52-03:00 · PHP 8.3.6 · **39/39 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Sem login: tela de entrada, sem dados de ofertas, cabeçalhos de segurança | 0.003 s |
| ✅ | POST sem token CSRF é recusado (inclusive login) | 0.008 s |
| ✅ | Ação sem login retorna 403 mesmo com CSRF válido | 0.005 s |
| ✅ | Arquivos internos não executam pela web (guarda direta e CLI) | 0.002 s |
| ✅ | Login pelo painel antigo (index.php) vale para o novo painel | 0.071 s |
| ✅ | Migração exige confirmação digitada e cria backup | 0.042 s |
| ✅ | Painel unificado mostra as duas lojas (5 Shopee + 1 Mercado Livre) | 0.011 s |
| ✅ | Páginas antigas continuam funcionando após a migração | 0.003 s |
| ✅ | Todas as páginas do painel abrem sem erros | 0.084 s |
| ✅ | Upload da planilha real .xlsx: prévia, confirmação e reimportação sem duplicar | 0.088 s |
| ✅ | XSS: título malicioso importado é exibido como texto | 0.056 s |
| ✅ | Exportação CSV neutraliza fórmulas de planilha | 0.005 s |
| ✅ | Associar link, conferir preço, aprovar e enfileirar (sem envio) | 0.237 s |
| ✅ | Pausa emergencial e retomada com confirmação; nenhum envio pelo navegador | 0.029 s |
| ✅ | Configurações sensíveis não são alteráveis pela web | 0.028 s |
| ✅ | Bloqueio após 5 senhas erradas | 0.375 s |
| ✅ | Logout encerra a sessão | 0.017 s |
| ✅ | Importar links em lote: botão na aba, prévia, CSRF, aplicar e desfazer | 0.153 s |
| ✅ | Central: execução manual, agendamento validado, pausa/retomada, modelo escapado, nada enviado | 0.213 s |
| ✅ | Histórico: "Ver detalhes" abre produtos, motivos e filtros; nada é enviado | 0.104 s |
| ✅ | Conferência: exige CSRF, login e confirmação explícita; salva como manual; páginas novas sem erros | 0.045 s |
| ✅ | Edição de produto no modo desconto: Por salvo, "De" estimado nunca gravado; modo original mantém o cálculo existente | 0.016 s |
| ✅ | Integrações: Testar conexão e Consultar produto da Shopee sem alterar o catálogo nem mostrar segredos | 1.121 s |
| ✅ | Integrações: conectar gera redirecionamento S256 com state; callback exige login e state válido | 0.012 s |
| ✅ | Navegador (Chromium) — com JavaScript: navegador sem login no ML chega à tela de login do ML | 0 s |
| ✅ | Navegador (Chromium) — com JavaScript: nenhuma violação de CSP | 0 s |
| ✅ | Navegador (Chromium) — com JavaScript: autorização com PKCE S256 e state | 0 s |
| ✅ | Navegador (Chromium) — com JavaScript: retorno ao callback mantém a sessão (state aceito; troca de token sem rede no teste) | 0 s |
| ✅ | Navegador (Chromium) — com JavaScript: nenhuma violação de CSP no retorno | 0 s |
| ✅ | Navegador (Chromium) — com JavaScript: state reutilizado é recusado | 0 s |
| ✅ | Navegador (Chromium) — sem JavaScript (meta refresh): navegador sem login no ML chega à tela de login do ML | 0 s |
| ✅ | Navegador (Chromium) — sem JavaScript (meta refresh): nenhuma violação de CSP | 0 s |
| ✅ | Navegador (Chromium) — sem JavaScript (meta refresh): autorização com PKCE S256 e state | 0 s |
| ✅ | Navegador (Chromium) — sem JavaScript (meta refresh): retorno ao callback mantém a sessão (state aceito; troca de token sem rede no teste) | 0 s |
| ✅ | Navegador (Chromium) — sem JavaScript (meta refresh): nenhuma violação de CSP no retorno | 0 s |
| ✅ | Navegador (Chromium) — sem JavaScript (meta refresh): state reutilizado é recusado | 0 s |
| ✅ | cron.php original continua rodando em modo prévia após a migração | 0.026 s |
| ✅ | Publicador antigo continua sem enviar (sem arquivo de ativação) | 0.024 s |
| ✅ | CLI 3.0: verificar, status e simulação da fila | 0.107 s |

## Compatibilidade com a produção e conflito de publicadores (tests/compat_test.php)

Executado em 2026-11-05T01:41:54-03:00 · PHP 8.3.6 · **13/13 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Arquivos de produção carregam com o pacote 3.0 (mesmas funções db/h/valid/importFile/message/slot) | 0 s |
| ✅ | slot() de produção continua só em prévia no cron (send=false) e exige telegram.php + env para enviar | 0.106 s |
| ✅ | RISCO CONFIRMADO: publicador instalado tenta de novo o MESMO item após envio incerto | 0.147 s |
| ✅ | Revisão 2.2 (opcional/): após envio incerto a fila PAUSA e não tenta de novo | 0.123 s |
| ✅ | Revisão 2.2 respeita a pausa do painel 3.0 (pausado por padrão após a migração) | 0.141 s |
| ✅ | Revisão 2.2 não envia item já tratado pela fila 3.0 | 0.134 s |
| ✅ | Produto importado no 3.0 só com o link afiliado é reconhecido pelos dois publicadores | 0.127 s |
| ✅ | Fila 3.0 bloqueia quando o publicador antigo está ativado ou com envio incerto | 0.095 s |
| ✅ | Sem arquivo de ativação, ambas as versões do publicador apenas simulam | 0.136 s |
| ✅ | Ativação antiga presente SEM arquivo de pausa = FALHA na verificação (migração impedida) | 0.074 s |
| ✅ | Arquivo de pausa manual bloqueia o publicador instalado mesmo com ativação válida | 0.101 s |
| ✅ | Sem gmax_dispatches e com slots vazia: verificação, migração, painel e rollback funcionam | 0.096 s |
| ✅ | Diagnóstico exporta só o esquema: nenhum título, link, preço ou hash de senha; banco intacto | 0.128 s |

## Links afiliados em lote — 3.0.3 (tests/links_test.php)

Executado em 2026-11-05T01:41:56-03:00 · PHP 8.3.6 · **21/21 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Prévia do arquivo de 18 links: 18 links novos por ID do anúncio, nada gravado ainda | 0.115 s |
| ✅ | Aplicar: 18 links associados; preços, condição, disponibilidade e estado intactos; nada criado ou aprovado | 0.118 s |
| ✅ | Associação independe da ordem das linhas (arquivo embaralhado dá o mesmo resultado) | 0.13 s |
| ✅ | Reimportação do mesmo arquivo: 18 "já iguais", nenhuma alteração | 0.131 s |
| ✅ | Links inválidos são recusados (http, link comum, outro domínio, javascript, vazio) | 0.111 s |
| ✅ | ID inexistente não cria produto; ID inválido é recusado; Shopee nunca é alterada | 0.099 s |
| ✅ | Duplicidades no arquivo: mesmo link em 2 anúncios e mesmo anúncio 2 vezes → todas as ocorrências bloqueadas | 0.089 s |
| ✅ | Link já associado a outro produto (ex.: ML-001) é bloqueado | 0.096 s |
| ✅ | Nome sem nenhuma palavra em comum com o cadastrado é bloqueado (possível produto trocado) | 0.092 s |
| ✅ | Linha marcada como inválida na planilha não é aplicada | 0.094 s |
| ✅ | Substituir link existente exige autorização explícita; produto aprovado volta para validação | 0.135 s |
| ✅ | Verificação: confere, diverge (bloqueia), 404 (bloqueia) e indeterminado (rede) | 0.123 s |
| ✅ | Verificador não segue redirecionamento para domínio estranho | 0 s |
| ✅ | Sem verificação "confere" e sem confirmação manual, nenhum link é associado | 0.118 s |
| ✅ | Concorrência: aplicar duas vezes, aplicar após descartar e verificar após aplicar são recusados | 0.132 s |
| ✅ | Verificação que termina depois de o lote ser aplicado não grava nada | 0.111 s |
| ✅ | Número de anúncio ambíguo (item e catálogo) é recusado | 0.098 s |
| ✅ | Desfazer restaura os links anteriores (vazios) e preserva produto alterado depois | 0.111 s |
| ✅ | Auditoria registra prévia, aplicação, cada link e o desfazer, com usuário e data | 0.107 s |
| ✅ | Importador de produtos não opera lotes de links (e vice-versa) | 0.104 s |
| ✅ | Arquivo sem as colunas obrigatórias é recusado; XLSX com as mesmas colunas funciona | 0.114 s |

## Central de Automações — 3.1 (tests/automacoes_test.php)

Executado em 2026-11-05T01:41:59-03:00 · PHP 8.3.6 · **25/25 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Migração 002: tarefas criadas inativas, automações pausadas, aprovação automática desligada; reversível | 0.123 s |
| ✅ | Horários: vence às 07:05, perdido depois de 2 h, respeita dia da semana | 0.086 s |
| ✅ | Pausa geral: nada executa; ativa: executa uma vez por horário (idempotente) | 0.1 s |
| ✅ | Concorrência: com a trava das automações ocupada, nada executa | 0.111 s |
| ✅ | Execução interrompida pelo servidor é encerrada como falha na próxima chamada | 0.09 s |
| ✅ | Descoberta: insere novos como rascunho com origem e data; ignora existentes, indisponíveis e incompletos | 0.126 s |
| ✅ | Shopee: link oficial da API é aceito; link fora do padrão é descartado; integração ausente gera alerta | 0.1 s |
| ✅ | Falha da API na busca: execução parcial, erro registrado, nada inventado | 0.095 s |
| ✅ | Preço igual confirmado pela API: renova a confirmação e mantém aprovação | 0.124 s |
| ✅ | Preço mudou: atualiza Por e retira aprovação; "De" só é limpo por fonte que informa preço original | 0.119 s |
| ✅ | Mesmo preço da API não retira aprovação de "De" cadastrado manualmente (revisão repetida) | 0.121 s |
| ✅ | Edição concorrente durante a revisão não é carimbada como confirmada pela API | 0.124 s |
| ✅ | API falhou: dados não mudam, alerta aberto e publicação bloqueada pelo preço vencido | 0.114 s |
| ✅ | Anúncio pausado: indisponível, agendamento cancelado, alerta | 0.107 s |
| ✅ | Seleção coloca as melhores na fila respeitando o limite diário (nada é enviado) | 0.121 s |
| ✅ | Aprovação: manual por padrão (alerta); automática só com autorização e preço confirmado por API | 0.119 s |
| ✅ | Mercado Livre não entra na fila do grupo privado (regra do programa) mesmo com nota alta | 0.103 s |
| ✅ | Vencidas: aprovada com preço antigo volta à validação; indisponível antiga é arquivada sem perder histórico | 0.116 s |
| ✅ | Saúde: duplicidade por nome, link fora do padrão, alerta fecha sozinho quando resolvido | 0.101 s |
| ✅ | Modelos por loja/categoria; linhas sem dado confirmado são omitidas; validação de modelo | 0.09 s |
| ✅ | Limite diário de requisições por conector e registro de falhas | 0.101 s |
| ✅ | Mercado Livre (busca oficial): interpreta resposta; 403 vira "não autorizada"; vendas só se informadas | 0.002 s |
| ✅ | Shopee (busca oficial): link afiliado da API, desconto informado sem inventar preço original | 0.001 s |
| ✅ | Amazon e AliExpress: preparados, sem requisições e sem dados simulados | 0.092 s |
| ✅ | Todas as tarefas executadas em sequência: nenhum transporte criado, nenhum item reservado/enviado | 0.153 s |

## OAuth Mercado Livre — 3.1.1 (tests/oauth_test.php)

Executado em 2026-11-05T01:41:59-03:00 · PHP 8.3.6 · **12/12 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | PKCE S256 confere com o vetor oficial da RFC 7636 | 0 s |
| ✅ | URL de autorização: S256, state aleatório, redirect exata, verifier só na sessão | 0 s |
| ✅ | Callback recusa state errado, ausente, expirado, reutilizado e erro do provedor | 0 s |
| ✅ | Troca do código envia code_verifier, redirect_uri e grant correto; token gravado cifrado (0600) | 0 s |
| ✅ | Token perto de expirar é renovado; refresh token novo substitui o antigo (rotação) | 0.001 s |
| ✅ | Token válido não gera requisição; outro processo renovou antes: não renova de novo | 0 s |
| ✅ | invalid_grant na renovação: token marcado para reautorizar; conector fica desconectado | 0.002 s |
| ✅ | Falha de rede na renovação não invalida o token (tenta de novo depois) | 0.001 s |
| ✅ | Conector: 401 da API renova uma vez e repete a consulta; pesquisa usa o token OAuth | 0.001 s |
| ✅ | Diagnóstico de permissões: busca negada (403) é reportada sem quebrar | 0 s |
| ✅ | Configuração do app: redirect sem https, client_id inválido e ausência do arquivo são apontados | 0 s |
| ✅ | Segredo trocado: token antigo não decifra (exige nova conexão) | 0 s |

## Detalhes das execuções — 3.1.3 (tests/execucoes_test.php)

Executado em 2026-11-05T01:42:00-03:00 · PHP 8.3.6 · **14/14 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Cenário real: 5 Shopee bloqueados por falta de credencial Shopee; ML-001 fora da revisão por não ter código MLB | 0.098 s |
| ✅ | HTTP 403 em /items: bloqueado com código, endpoint e motivo; preço e validação intocados; token não gravado | 0.102 s |
| ✅ | HTTP 401 em /items: motivo de autenticação explícito | 0.1 s |
| ✅ | HTTP 200 com o mesmo preço: validado, com preço anterior, preço da API e horário | 0.1 s |
| ✅ | HTTP 200 com preço novo: atualizado, campos alterados listados e aprovação retirada | 0.099 s |
| ✅ | HTTP 200 sem preço: sem alteração e sem validação (nada inventado) | 0.107 s |
| ✅ | Teste isolado de /items: mostra HTTP e preço sem gravar nada no catálogo | 0.088 s |
| ✅ | Limite por execução: excedentes ficam como "não processado" com o motivo | 0.101 s |
| ✅ | Rascunho e arquivado: fora da revisão com motivo; arquivados não poluem o histórico | 0.104 s |
| ✅ | Histórico somente-inclusão: alterar ou apagar linhas é recusado pelo banco | 0.095 s |
| ✅ | Mensagens de erro passam por limpeza de credenciais | 0 s |
| ✅ | Sem a migração 003 a revisão funciona como na 3.1.1 (sem detalhamento) | 0.106 s |
| ✅ | Execuções e dados anteriores preservados pela migração 003 | 0.113 s |
| ✅ | Prévia da próxima revisão usa as mesmas regras de elegibilidade | 0.095 s |

## Shopee Affiliate GraphQL — 3.1.4 (tests/shopee_test.php)

Executado em 2026-11-05T01:42:00-03:00 · PHP 8.3.6 · **16/16 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Int64 vai como literal só com dígitos; shopId vem do link do produto; JSON sem "variables" vazio | 0.001 s |
| ✅ | Assinatura SHA256(AppId+Timestamp+Payload+Secret) sobre o corpo exato enviado | 0 s |
| ✅ | Item Id de 18 dígitos não perde precisão; sem link de produto não envia shopId | 0 s |
| ✅ | Resposta válida: preço, comissão e link oficial; nada é gravado pelo conector | 0 s |
| ✅ | Faixa de preço entre variações: sem preço único (nada confirmado) | 0 s |
| ✅ | 10010 no literal: tenta uma vez com variáveis Int64 em texto; sucesso registra o formato | 0 s |
| ✅ | 10010 nos dois formatos: falha com código, significado e mensagem; HTTP 200 não é sucesso | 0 s |
| ✅ | Erro 10020: mensagem explicada e sem nova tentativa | 0 s |
| ✅ | Erro 10030: mensagem explicada e sem nova tentativa | 0 s |
| ✅ | Erro 10035: mensagem explicada e sem nova tentativa | 0 s |
| ✅ | Produto inexistente (nodes vazio ou outro item): não encontrado, sem preço | 0 s |
| ✅ | Respostas malformadas com HTTP 200: sem data, JSON inválido, código desconhecido | 0 s |
| ✅ | Revisão com 10010: bloqueado, erro contado, código no histórico, preço não confirmado, catálogo intacto | 0.107 s |
| ✅ | Revisão após a correção: preço igual validado; link afiliado preservado | 0.126 s |
| ✅ | Testar conexão e consulta individual: não alteram o catálogo e não expõem segredos | 0.092 s |
| ✅ | Busca (descoberta) também trata "errors" com HTTP 200 como falha | 0 s |

## Conferência rápida — 3.1.5 (tests/conferencia_test.php)

Executado em 2026-11-05T01:42:01-03:00 · PHP 8.3.6 · **8/8 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Sem confirmação explícita nada é gravado (nem preço, nem conferência, nem histórico) | 0.099 s |
| ✅ | Conferência confirmada: grava como MANUAL (usuário e fonte conferencia_manual), nunca como API | 0.093 s |
| ✅ | Preço alterado retira a aprovação; link afiliado nunca é tocado | 0.084 s |
| ✅ | Validações: Por obrigatório, De ≥ Por, condição e disponibilidade válidas, variação descrita | 0.085 s |
| ✅ | Variação definida remove a pendência com a descrição informada | 0.092 s |
| ✅ | Validação pela API continua identificada como API | 0.091 s |
| ✅ | Botões: só URLs oficiais https; javascript:/outros domínios nunca viram link | 0.086 s |
| ✅ | Filtros novos: disponibilidade e variação pendente | 0.082 s |

## Calculadora de preços — 3.1.6 (tests/calculadora_test.php)

Executado em 2026-11-05T01:42:02-03:00 · PHP 8.3.6 · **9/9 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Percentual: aceita 35, 35%, 35,5 e 35.25; recusa 0, 96, texto e 3 casas | 0 s |
| ✅ | Estimativa exata ao centavo, com a faixa do arredondamento da loja | 0 s |
| ✅ | Modo preço original: desconto e economia pela regra existente (percentual arredondado para baixo) | 0 s |
| ✅ | Origem do "De": API só para o Mercado Livre; Shopee validada pela API continua "importado" | 0 s |
| ✅ | Conferência no modo desconto: "De" estimado NÃO é gravado como preço original; estimativa registrada à parte | 0.086 s |
| ✅ | Preço original confirmado pela API é preservado no modo desconto | 0.082 s |
| ✅ | Sem confirmação explícita, nem o modo desconto grava nada (nem a estimativa) | 0.079 s |
| ✅ | Estimativa deixa de valer quando o preço Por muda | 0.083 s |
| ✅ | Tela: os dois modos no mesmo formulário (valores preservados) e selo "Calculado ≈" na última estimativa | 0.081 s |

## Ranking inteligente e diagnóstico Mercado Livre — 3.1.7 (tests/ranking_ml_test.php)

Executado em 2026-11-05T01:42:03-03:00 · PHP 8.3.6 · **14/14 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Potencial: mantém a pontuação existente e lista produtos não validados com suas pendências | 0.089 s |
| ✅ | Prontas: só aparece o produto que passa em todas as regras de publicação | 0.089 s |
| ✅ | Prontas exclui: preço desatualizado, sem link, variação pendente, indisponível, "De" não confirmado e ML no grupo privado | 0.109 s |
| ✅ | Preço original só estimado: nunca vira "De", não pontua desconto e não aparece na mensagem | 0.095 s |
| ✅ | Filtros combinados: marketplace, conferência API/manual/nunca, link, preço, pontuação, confiança, dias, pronto | 0.1 s |
| ✅ | Ordenação por pontuação, confiança, preço e data; paginação de 25 | 0.178 s |
| ✅ | Diagnóstico: 403 com e sem token = restrição da plataforma; token válido; alternativas mapeadas; sem token no resultado | 0.002 s |
| ✅ | Diagnóstico distingue falta de autorização (sem token 200), token rejeitado (401), anúncio inexistente (404) e limite (429) | 0 s |
| ✅ | Resultados reais da HostGator (05/11): anônimo negado ≠ bloqueio de IP; app ativo e escopo read presentes; restrição específica a dados de terceiros | 0 s |
| ✅ | Cliente HTTP sempre envia User-Agent (o PHP não envia nenhum por padrão), sem duplicar o informado | 0 s |
| ✅ | Três 403 seguidos suspendem /items: sem novas requisições, um único alerta, OAuth preservado; reativação manual | 0.099 s |
| ✅ | Testes manuais (consulta individual e permissões) não contam nem respeitam a suspensão | 0.095 s |
| ✅ | 200 zera a contagem; 404 e 429 não contam; desativar a suspensão impede suspender | 0.1 s |
| ✅ | Automações com consultas suspensas: revisão sem erros repetidos e descoberta com uma linha, sem alerta por termo | 0.118 s |

## Candidatos do catálogo Mercado Livre — 3.1.9 (tests/candidatos_ml_test.php)

Executado em 2026-11-05T01:42:03-03:00 · PHP 8.3.6 · **9/9 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Pesquisa no catálogo: só IDs de catálogo válidos com nome; imagem só do CDN oficial | 0.002 s |
| ✅ | Produto de catálogo: anúncio vencedor e preço só como informados pela API; IDs separados; permalink validado | 0 s |
| ✅ | Inspeção de campos: estrutura e indicadores sem valores nem token | 0 s |
| ✅ | Tarefa: grava candidatos separados das ofertas; detalhe com preço da API não conferido; nada no catálogo | 0.117 s |
| ✅ | Tendências também geram candidatos (origem e posição registradas) | 0.107 s |
| ✅ | Catálogo bloqueado (403 repetido): suspende /products sem repetir consultas nem desconectar o OAuth | 0.104 s |
| ✅ | Criar rascunho: ID de catálogo (não de anúncio), sem link afiliado, preço NÃO conferido, fora das prontas | 0.098 s |
| ✅ | Descartar e filtros da lista | 0.099 s |
| ✅ | Sem a migração 004 a tarefa apenas avisa; a 004 é reversível sem tocar no restante | 0.111 s |

## Interface responsiva no navegador — 3.1.5 (tests/ui_test.php, Chromium com emulação de dispositivos)

Executado em 2026-11-05T01:42:41-03:00 · PHP 8.3.6 · **216/216 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | 18 páginas sem rolagem horizontal da página | 0 s |
| ✅ | Nenhum erro de PHP nas páginas | 0 s |
| ✅ | CSP presente e restritiva em todas as páginas (form-action 'self', sem unsafe-inline) | 0 s |
| ✅ | Menu móvel: recolhido, abre pelo botão (foco no menu), fecha com Esc | 0 s |
| ✅ | Teclado: primeiro Tab leva ao link "Pular para o conteúdo" | 0 s |
| ✅ | Estado de pausa dos envios visível e anunciado por leitores de tela | 0 s |
| ✅ | Campos com fonte ≥ 16 px (sem zoom involuntário no iPhone) | 0 s |
| ✅ | Botões com altura confortável para toque (≥ 44 px; pequenos ≥ 36 px) | 0 s |
| ✅ | Ofertas: cartões visíveis, tabela oculta | 0 s |
| ✅ | Filtros recolhidos quando nenhum filtro está ativo | 0 s |
| ✅ | Filtro ativo: painel de filtros aberto, contador e resultados só da Shopee | 0 s |
| ✅ | Ranking: cartões compactos com posição, pontuação, confiança e pendência | 0 s |
| ✅ | Ranking "Prontas para divulgar": só produtos sem pendências (aqui nenhum, com explicação) | 0 s |
| ✅ | Integrações: diagnóstico do Mercado Livre com conclusões, situação dos recursos e solicitação ao suporte | 0 s |
| ✅ | Candidatos ML: aviso de que não são ofertas, ID de catálogo, imagem oficial e preço marcado como não conferido | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em cartões com rótulo em cada campo | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 18 páginas sem rolagem horizontal da página | 0 s |
| ✅ | Nenhum erro de PHP nas páginas | 0 s |
| ✅ | CSP presente e restritiva em todas as páginas (form-action 'self', sem unsafe-inline) | 0 s |
| ✅ | Menu móvel: recolhido, abre pelo botão (foco no menu), fecha com Esc | 0 s |
| ✅ | Teclado: primeiro Tab leva ao link "Pular para o conteúdo" | 0 s |
| ✅ | Estado de pausa dos envios visível e anunciado por leitores de tela | 0 s |
| ✅ | Campos com fonte ≥ 16 px (sem zoom involuntário no iPhone) | 0 s |
| ✅ | Botões com altura confortável para toque (≥ 44 px; pequenos ≥ 36 px) | 0 s |
| ✅ | Ofertas: cartões visíveis, tabela oculta | 0 s |
| ✅ | Filtros recolhidos quando nenhum filtro está ativo | 0 s |
| ✅ | Filtro ativo: painel de filtros aberto, contador e resultados só da Shopee | 0 s |
| ✅ | Ranking: cartões compactos com posição, pontuação, confiança e pendência | 0 s |
| ✅ | Ranking "Prontas para divulgar": só produtos sem pendências (aqui nenhum, com explicação) | 0 s |
| ✅ | Integrações: diagnóstico do Mercado Livre com conclusões, situação dos recursos e solicitação ao suporte | 0 s |
| ✅ | Candidatos ML: aviso de que não são ofertas, ID de catálogo, imagem oficial e preço marcado como não conferido | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em cartões com rótulo em cada campo | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 18 páginas sem rolagem horizontal da página | 0 s |
| ✅ | Nenhum erro de PHP nas páginas | 0 s |
| ✅ | CSP presente e restritiva em todas as páginas (form-action 'self', sem unsafe-inline) | 0 s |
| ✅ | Menu móvel: recolhido, abre pelo botão (foco no menu), fecha com Esc | 0 s |
| ✅ | Teclado: primeiro Tab leva ao link "Pular para o conteúdo" | 0 s |
| ✅ | Estado de pausa dos envios visível e anunciado por leitores de tela | 0 s |
| ✅ | Campos com fonte ≥ 16 px (sem zoom involuntário no iPhone) | 0 s |
| ✅ | Botões com altura confortável para toque (≥ 44 px; pequenos ≥ 36 px) | 0 s |
| ✅ | Ofertas: cartões visíveis, tabela oculta | 0 s |
| ✅ | Filtros recolhidos quando nenhum filtro está ativo | 0 s |
| ✅ | Filtro ativo: painel de filtros aberto, contador e resultados só da Shopee | 0 s |
| ✅ | Ranking: cartões compactos com posição, pontuação, confiança e pendência | 0 s |
| ✅ | Ranking "Prontas para divulgar": só produtos sem pendências (aqui nenhum, com explicação) | 0 s |
| ✅ | Integrações: diagnóstico do Mercado Livre com conclusões, situação dos recursos e solicitação ao suporte | 0 s |
| ✅ | Candidatos ML: aviso de que não são ofertas, ID de catálogo, imagem oficial e preço marcado como não conferido | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em cartões com rótulo em cada campo | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 18 páginas sem rolagem horizontal da página | 0 s |
| ✅ | Nenhum erro de PHP nas páginas | 0 s |
| ✅ | CSP presente e restritiva em todas as páginas (form-action 'self', sem unsafe-inline) | 0 s |
| ✅ | Menu móvel: recolhido, abre pelo botão (foco no menu), fecha com Esc | 0 s |
| ✅ | Teclado: primeiro Tab leva ao link "Pular para o conteúdo" | 0 s |
| ✅ | Estado de pausa dos envios visível e anunciado por leitores de tela | 0 s |
| ✅ | Campos com fonte ≥ 16 px (sem zoom involuntário no iPhone) | 0 s |
| ✅ | Botões com altura confortável para toque (≥ 44 px; pequenos ≥ 36 px) | 0 s |
| ✅ | Ofertas: cartões visíveis, tabela oculta | 0 s |
| ✅ | Filtros recolhidos quando nenhum filtro está ativo | 0 s |
| ✅ | Filtro ativo: painel de filtros aberto, contador e resultados só da Shopee | 0 s |
| ✅ | Ranking: cartões compactos com posição, pontuação, confiança e pendência | 0 s |
| ✅ | Ranking "Prontas para divulgar": só produtos sem pendências (aqui nenhum, com explicação) | 0 s |
| ✅ | Integrações: diagnóstico do Mercado Livre com conclusões, situação dos recursos e solicitação ao suporte | 0 s |
| ✅ | Candidatos ML: aviso de que não são ofertas, ID de catálogo, imagem oficial e preço marcado como não conferido | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em cartões com rótulo em cada campo | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 18 páginas sem rolagem horizontal da página | 0 s |
| ✅ | Nenhum erro de PHP nas páginas | 0 s |
| ✅ | CSP presente e restritiva em todas as páginas (form-action 'self', sem unsafe-inline) | 0 s |
| ✅ | Menu móvel: recolhido, abre pelo botão (foco no menu), fecha com Esc | 0 s |
| ✅ | Teclado: primeiro Tab leva ao link "Pular para o conteúdo" | 0 s |
| ✅ | Estado de pausa dos envios visível e anunciado por leitores de tela | 0 s |
| ✅ | Campos com fonte ≥ 16 px (sem zoom involuntário no iPhone) | 0 s |
| ✅ | Botões com altura confortável para toque (≥ 44 px; pequenos ≥ 36 px) | 0 s |
| ✅ | Ofertas: cartões visíveis, tabela oculta | 0 s |
| ✅ | Filtros recolhidos quando nenhum filtro está ativo | 0 s |
| ✅ | Filtro ativo: painel de filtros aberto, contador e resultados só da Shopee | 0 s |
| ✅ | Ranking: cartões compactos com posição, pontuação, confiança e pendência | 0 s |
| ✅ | Ranking "Prontas para divulgar": só produtos sem pendências (aqui nenhum, com explicação) | 0 s |
| ✅ | Integrações: diagnóstico do Mercado Livre com conclusões, situação dos recursos e solicitação ao suporte | 0 s |
| ✅ | Candidatos ML: aviso de que não são ofertas, ID de catálogo, imagem oficial e preço marcado como não conferido | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em cartões com rótulo em cada campo | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 18 páginas sem rolagem horizontal da página | 0 s |
| ✅ | Nenhum erro de PHP nas páginas | 0 s |
| ✅ | CSP presente e restritiva em todas as páginas (form-action 'self', sem unsafe-inline) | 0 s |
| ✅ | Menu móvel: recolhido, abre pelo botão (foco no menu), fecha com Esc | 0 s |
| ✅ | Teclado: primeiro Tab leva ao link "Pular para o conteúdo" | 0 s |
| ✅ | Estado de pausa dos envios visível e anunciado por leitores de tela | 0 s |
| ✅ | Ofertas: tabela completa com seleção em lote visível, cartões ocultos | 0 s |
| ✅ | Filtros sempre abertos no computador | 0 s |
| ✅ | Ranking: tabela compacta, preço sem quebra de linha | 0 s |
| ✅ | Ranking "Prontas para divulgar": só produtos sem pendências (aqui nenhum, com explicação) | 0 s |
| ✅ | Integrações: diagnóstico do Mercado Livre com conclusões, situação dos recursos e solicitação ao suporte | 0 s |
| ✅ | Candidatos ML: aviso de que não são ofertas, ID de catálogo, imagem oficial e preço marcado como não conferido | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em tabela completa | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 18 páginas sem rolagem horizontal da página | 0 s |
| ✅ | Nenhum erro de PHP nas páginas | 0 s |
| ✅ | CSP presente e restritiva em todas as páginas (form-action 'self', sem unsafe-inline) | 0 s |
| ✅ | Menu lateral fixo visível; botão de menu oculto | 0 s |
| ✅ | Teclado: primeiro Tab leva ao link "Pular para o conteúdo" | 0 s |
| ✅ | Estado de pausa dos envios visível e anunciado por leitores de tela | 0 s |
| ✅ | Ofertas: tabela completa com seleção em lote visível, cartões ocultos | 0 s |
| ✅ | Filtros sempre abertos no computador | 0 s |
| ✅ | Ranking: tabela compacta, preço sem quebra de linha | 0 s |
| ✅ | Ranking "Prontas para divulgar": só produtos sem pendências (aqui nenhum, com explicação) | 0 s |
| ✅ | Integrações: diagnóstico do Mercado Livre com conclusões, situação dos recursos e solicitação ao suporte | 0 s |
| ✅ | Candidatos ML: aviso de que não são ofertas, ID de catálogo, imagem oficial e preço marcado como não conferido | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em tabela completa | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 18 páginas sem rolagem horizontal da página | 0 s |
| ✅ | Nenhum erro de PHP nas páginas | 0 s |
| ✅ | CSP presente e restritiva em todas as páginas (form-action 'self', sem unsafe-inline) | 0 s |
| ✅ | Menu lateral fixo visível; botão de menu oculto | 0 s |
| ✅ | Teclado: primeiro Tab leva ao link "Pular para o conteúdo" | 0 s |
| ✅ | Estado de pausa dos envios visível e anunciado por leitores de tela | 0 s |
| ✅ | Ofertas: tabela completa com seleção em lote visível, cartões ocultos | 0 s |
| ✅ | Filtros sempre abertos no computador | 0 s |
| ✅ | Ranking: tabela compacta, preço sem quebra de linha | 0 s |
| ✅ | Ranking "Prontas para divulgar": só produtos sem pendências (aqui nenhum, com explicação) | 0 s |
| ✅ | Integrações: diagnóstico do Mercado Livre com conclusões, situação dos recursos e solicitação ao suporte | 0 s |
| ✅ | Candidatos ML: aviso de que não são ofertas, ID de catálogo, imagem oficial e preço marcado como não conferido | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em tabela completa | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 18 páginas sem rolagem horizontal da página | 0 s |
| ✅ | Nenhum erro de PHP nas páginas | 0 s |
| ✅ | CSP presente e restritiva em todas as páginas (form-action 'self', sem unsafe-inline) | 0 s |
| ✅ | Menu lateral fixo visível; botão de menu oculto | 0 s |
| ✅ | Teclado: primeiro Tab leva ao link "Pular para o conteúdo" | 0 s |
| ✅ | Estado de pausa dos envios visível e anunciado por leitores de tela | 0 s |
| ✅ | Ofertas: tabela completa com seleção em lote visível, cartões ocultos | 0 s |
| ✅ | Filtros sempre abertos no computador | 0 s |
| ✅ | Ranking: tabela compacta, preço sem quebra de linha | 0 s |
| ✅ | Ranking "Prontas para divulgar": só produtos sem pendências (aqui nenhum, com explicação) | 0 s |
| ✅ | Integrações: diagnóstico do Mercado Livre com conclusões, situação dos recursos e solicitação ao suporte | 0 s |
| ✅ | Candidatos ML: aviso de que não são ofertas, ID de catálogo, imagem oficial e preço marcado como não conferido | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em tabela completa | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | Sem marcar "Conferi agora na loja" a conferência não é enviada | 0 s |
| ✅ | Conferência salva como MANUAL (não como API), com desconto calculado ao vivo | 0 s |
| ✅ | "Voltar aos resultados" mantém filtros e posição do produto | 0 s |
| ✅ | Desktop: "Voltar aos resultados" rola até a linha do produto na tabela | 0 s |
| ✅ | Modo preço original: desconto e economia em tempo real | 0 s |
| ✅ | Modo desconto: estimativa, faixa e economia iguais ao cálculo do servidor (35, 35,5 e 7%), marcada como CALCULADO | 0 s |
| ✅ | Percentual inválido é sinalizado na hora | 0 s |
| ✅ | Alternar entre os modos não perde os valores digitados | 0 s |
| ✅ | Salvar no modo desconto: Por conferido, "De" estimado registrado como CALCULADO (não confirmado) | 0 s |
| ✅ | Preço validado pela API identificado como "API Shopee" | 0 s |
| ✅ | Importação: arquivo selecionado mostrado, prévia gerada sem gravar e duplicados reconhecidos | 0 s |
| ✅ | Arquivar pede confirmação; ao cancelar nada muda | 0 s |
| ✅ | Copiar mensagem pelo cartão | 0 s |
| ✅ | Indicador de conexão: aparece sem rede e some quando a rede volta | 0 s |
| ✅ | PWA: manifesto válido (standalone, ícones 192/512/maskable), apple-touch-icon e nenhum service worker | 0 s |
| ✅ | Nenhum erro de console nos fluxos | 0 s |
| ✅ | Envios continuam pausados, automações pausadas, aprovação automática desligada; nada enviado | 0.001 s |
| ✅ | Links afiliados preservados (nenhum trocado pelo link comum) | 0 s |
| ✅ | Abrir o anúncio não confirmou preço; só a conferência salva gerou registro manual | 0 s |
| ✅ | Modo desconto pela tela: "De" estimado não gravado; estimativa registrada à parte | 0 s |
| ✅ | Nenhum service worker nem cache: o servidor não serve sw.js | 0 s |

## Total

**448/448 testes aprovados.**

## Não testado aqui (depende do servidor real)

- Envio real ao Telegram (proibido nesta fase).
- Chamadas reais à API do Mercado Livre (diagnóstico com respostas simuladas no formato do PolicyAgent; executar no servidor).
- Chamada real à Shopee Affiliate API (sem credenciais neste ambiente; validar pelo botão Testar conexão Shopee no servidor).
- Aparelhos físicos (iPhone, Android, tablet): a interface foi testada por EMULAÇÃO no Chromium (viewport, densidade, toque e user agent).
- Safari/WebKit real: não disponível neste ambiente; os perfis de iPhone usam o motor Chromium.
- Microsoft Edge real: testado com o user agent do Edge sobre Chromium, o mesmo motor do Edge.
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
php -d allow_url_fopen=0 tests/execucoes_test.php
php -d allow_url_fopen=0 tests/shopee_test.php
php -d allow_url_fopen=0 tests/conferencia_test.php
php -d allow_url_fopen=0 tests/calculadora_test.php
php -d allow_url_fopen=0 tests/ranking_ml_test.php
php -d allow_url_fopen=0 tests/candidatos_ml_test.php
php tests/ui_test.php
```
