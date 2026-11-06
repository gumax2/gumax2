# Testes executados — GMAX Ofertas 3.2.2

Resultados **gerados automaticamente** a partir da última execução real (não editados à mão).

Condições: sem rede (`allow_url_fopen=0`), `GMAX_TESTING=1` (transporte Telegram real proibido), banco reproduzindo o estado declarado da produção (5 Shopee, ML-001, envio confirmado), **arquivos atuais da HostGator** (cópia de 29/10/2026).

## Unidade e integração (tests/run.php)

Executado em 2026-11-06T09:32:06-03:00 · PHP 8.3.6 · SQLite 3.45.1 · **52/52 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Converte valores brasileiros e de planilha em centavos | 0 s |
| ✅ | Desconto do exemplo (199,90 → 119,90) = 40% OFF, economia R$ 80,00 | 0 s |
| ✅ | Validação de links afiliados e de produto por marketplace | 0 s |
| ✅ | Migração preserva tabelas antigas e copia 5 Shopee + 1 Mercado Livre | 0.084 s |
| ✅ | Sincronização com tabelas antigas é idempotente e capta novos produtos do index.php antigo | 0.084 s |
| ✅ | Reverter migração é recusado com envio não reconciliado | 0.085 s |
| ✅ | Tentativa do cron antigo (slots=tentativa) também bloqueia | 0.084 s |
| ✅ | Reverter migração remove apenas tabelas gmax3_ e mantém dados antigos | 0.147 s |
| ✅ | Backup verificado e restauração das tabelas do painel | 0.123 s |
| ✅ | Preflight: ambiente de teste sem falhas e arquivos iguais à referência | 0.086 s |
| ✅ | Planilha real "25 anúncios" (.xlsx): importa como rascunho sem link e não duplica na reimportação | 0.1 s |
| ✅ | Planilha "16 conferência": páginas de busca descartadas, tudo como rascunho | 0.087 s |
| ✅ | Modelo ML (.xlsx) e CSV de referência atualizam ML-001 em vez de duplicar | 0.087 s |
| ✅ | CSV Shopee reimportado: 5 existentes reconhecidos, nenhum duplicado | 0.086 s |
| ✅ | Linhas inválidas rejeitadas com motivo; válidas aceitas; nada parcial | 0.089 s |
| ✅ | Modelo unificado: Shopee + ML, preço com milhar, cupom e ID extraído da URL | 0.085 s |
| ✅ | Associação de link por reimportação: rascunho recebe link e vai para validação | 0.091 s |
| ✅ | Conflito: mesmo link afiliado já usado por outro produto é rejeitado | 0.083 s |
| ✅ | Desfazer lote remove inseridos e restaura atualizados; prévia descartada não grava | 0.087 s |
| ✅ | Arquivos fora do padrão são recusados (cabeçalho desconhecido, binário, vazio) | 0.077 s |
| ✅ | Mensagem no modelo GMAX com De/Por/% OFF/Economize/Pix | 0 s |
| ✅ | Sem preço original a mensagem não inventa De nem desconto | 0 s |
| ✅ | Aprovação exige link oficial, preço e condição de pagamento | 0.076 s |
| ✅ | Bloqueios de publicação: sem aprovação, preço não conferido, preço vencido, ML em grupo privado | 0.086 s |
| ✅ | Alterar preço retira aprovação e confirmação | 0.093 s |
| ✅ | Item já enviado pelo publicador antigo é bloqueado por duplicidade | 0.089 s |
| ✅ | Item ainda pendente no V2.1 é bloqueado até o V2.1 ser aposentado | 0.086 s |
| ✅ | Ativação do V2.1 presente bloqueia a fila 3.0 (dois publicadores nunca juntos) | 0.091 s |
| ✅ | Pausa pelo painel também cria gmax-publicacao.pause (respeitado pelo V2.1) | 0.091 s |
| ✅ | Lote enfileirado recebe horários distintos (sem rajada) | 0.108 s |
| ✅ | Pausa ou edição entre a checagem e a reserva aborta o envio | 0.1 s |
| ✅ | Duplicidade na fila: o mesmo produto não entra duas vezes na janela | 0.095 s |
| ✅ | Com travas padrão nada é enviado e o transporte nem é criado | 0.094 s |
| ✅ | Simulação não altera estados nem chama transporte | 0.095 s |
| ✅ | Envio confirmado: registra message_id e não republica | 0.113 s |
| ✅ | Falha de rede/timeout: estado incerto, pausa automática, nenhuma repetição | 0.126 s |
| ✅ | Exceção no transporte vira incerto (nunca reenvio automático) | 0.107 s |
| ✅ | Reconciliação: "publicado" impede reenvio; "não publicado" permite reagendar | 0.118 s |
| ✅ | Recusa explícita do Telegram (HTTP 400) = falhou, sem pausa e sem repetição automática | 0.099 s |
| ✅ | Concorrência: com o mutex ocupado (ex.: V2.1 rodando) o processador não age | 0.111 s |
| ✅ | Reserva atômica: item já reservado por outro processo não é enviado de novo | 0.113 s |
| ✅ | Pendência do publicador antigo (attempted) também bloqueia a fila 3.0 | 0.09 s |
| ✅ | Item agendado com dados alterados depois é bloqueado na hora do envio | 0.099 s |
| ✅ | Mercado Livre em grupo privado: bloqueado por padrão mesmo aprovado e conferido | 0.088 s |
| ✅ | Transporte real e HTTP são proibidos em modo de teste | 0 s |
| ✅ | Classificação de respostas do Telegram | 0 s |
| ✅ | Shopee: assinatura SHA256 e tratamento de falha de rede | 0.001 s |
| ✅ | Mercado Livre: consulta de anúncio, sem conversão de links | 0.001 s |
| ✅ | Ranking transparente: sem dados não inventa pontuação | 0.001 s |
| ✅ | Produto com um único dado não supera produto bem documentado | 0 s |
| ✅ | Ranking ordena a planilha real de 25 anúncios e mostra confiança parcial | 0.095 s |
| ✅ | Ações relevantes ficam registradas na auditoria | 0.099 s |

## HTTP ponta a ponta e CLI (tests/http_test.php)

Executado em 2026-11-06T09:32:14-03:00 · PHP 8.3.6 · **51/51 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Sem login: tela de entrada, sem dados de ofertas, cabeçalhos de segurança | 0.004 s |
| ✅ | POST sem token CSRF é recusado (inclusive login) | 0.007 s |
| ✅ | Ação sem login retorna 403 mesmo com CSRF válido | 0.005 s |
| ✅ | Arquivos internos não executam pela web (guarda direta e CLI) | 0.001 s |
| ✅ | index.php antigo (3.2.0): só redireciona ao painel; não tem login nem autentica | 0.003 s |
| ✅ | Login centralizado no painel.php leva à migração pendente | 0.063 s |
| ✅ | Migração exige confirmação digitada e cria backup | 0.047 s |
| ✅ | Painel unificado mostra as duas lojas (5 Shopee + 1 Mercado Livre) | 0.018 s |
| ✅ | mercadolivre.php antigo continua funcionando com a sessão do painel | 0.001 s |
| ✅ | Todas as páginas do painel abrem sem erros | 0.077 s |
| ✅ | Upload da planilha real .xlsx: prévia, confirmação e reimportação sem duplicar | 0.058 s |
| ✅ | XSS: título malicioso importado é exibido como texto | 0.037 s |
| ✅ | Exportação CSV neutraliza fórmulas de planilha | 0.005 s |
| ✅ | Associar link, conferir preço, aprovar e enfileirar (sem envio) | 0.213 s |
| ✅ | Pausa emergencial e retomada com confirmação; nenhum envio pelo navegador | 0.031 s |
| ✅ | Configurações sensíveis não são alteráveis pela web | 0.022 s |
| ✅ | Bloqueio após 5 senhas erradas | 0.301 s |
| ✅ | Logout encerra a sessão | 0.025 s |
| ✅ | 3.2.0: sessão criada pelo login antigo (sem marcador) não abre o painel e pede novo login | 0.007 s |
| ✅ | 3.2.0: inatividade de 2 h e sessão com mais de 12 h expiram | 0.022 s |
| ✅ | 3.2.0: login gera nova sessão (id regenerado) e o mercadolivre.php antigo usa a sessão do painel | 0.074 s |
| ✅ | 3.2.0: mercadolivre.php recusa sessão do login antigo, inativa ou com mais de 12 h | 0.003 s |
| ✅ | Importar links em lote: botão na aba, prévia, CSRF, aplicar e desfazer | 0.137 s |
| ✅ | Central: execução manual, agendamento validado, pausa/retomada, modelo escapado, nada enviado | 0.114 s |
| ✅ | Histórico: "Ver detalhes" abre produtos, motivos e filtros; nada é enviado | 0.037 s |
| ✅ | Conferência: exige CSRF, login e confirmação explícita; salva como manual; páginas novas sem erros | 0.028 s |
| ✅ | Edição de produto no modo desconto: Por salvo, "De" estimado nunca gravado; modo original mantém o cálculo existente | 0.015 s |
| ✅ | Integrações: Testar conexão e Consultar produto da Shopee sem alterar o catálogo nem mostrar segredos | 1.118 s |
| ✅ | Integrações: conectar gera redirecionamento S256 com state; callback exige login e state válido | 0.011 s |
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
| ✅ | AutoLab: página abre; pausa de emergência imediata com CSRF; retomar só de "pausado"; destino e duração não editáveis | 0.043 s |
| ✅ | Diagnóstico: saúde e tarefas; registra o usuário do PHP web; simulação de retenção não apaga; execução não existe no navegador | 0.03 s |
| ✅ | Fila: abre com pendências, títulos maliciosos escapados, barra inferior com 5 atalhos e menu em grupos | 0.007 s |
| ✅ | Aprovar pela fila: uma vez só (clique repetido ou outra aba é recusado) e volta à mesma posição | 0.017 s |
| ✅ | Link pela fila: inválido não grava e volta ao item; válido grava e segue | 0.016 s |
| ✅ | Conferência rápida pela fila: "Salvar e ir para o próximo" abre a conferência do próximo item | 0.019 s |
| ✅ | Abrir Início, Minha fila, Candidatos, AutoLab e Ofertas não grava nada no banco | 0.022 s |
| ✅ | cron.php original continua rodando em modo prévia após a migração | 0.021 s |
| ✅ | Publicador antigo continua sem enviar (sem arquivo de ativação) | 0.019 s |
| ✅ | CLI 3.0: verificar, status e simulação da fila | 0.084 s |

## Compatibilidade com a produção e conflito de publicadores (tests/compat_test.php)

Executado em 2026-11-06T09:32:16-03:00 · PHP 8.3.6 · **13/13 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Arquivos de produção carregam com o pacote 3.0 (mesmas funções db/h/valid/importFile/message/slot) | 0 s |
| ✅ | slot() de produção continua só em prévia no cron (send=false) e exige telegram.php + env para enviar | 0.083 s |
| ✅ | RISCO CONFIRMADO: publicador instalado tenta de novo o MESMO item após envio incerto | 0.146 s |
| ✅ | Revisão 2.2 (opcional/): após envio incerto a fila PAUSA e não tenta de novo | 0.107 s |
| ✅ | Revisão 2.2 respeita a pausa do painel 3.0 (pausado por padrão após a migração) | 0.101 s |
| ✅ | Revisão 2.2 não envia item já tratado pela fila 3.0 | 0.114 s |
| ✅ | Produto importado no 3.0 só com o link afiliado é reconhecido pelos dois publicadores | 0.113 s |
| ✅ | Fila 3.0 bloqueia quando o publicador antigo está ativado ou com envio incerto | 0.082 s |
| ✅ | Sem arquivo de ativação, ambas as versões do publicador apenas simulam | 0.102 s |
| ✅ | Ativação antiga presente SEM arquivo de pausa = FALHA na verificação (migração impedida) | 0.059 s |
| ✅ | Arquivo de pausa manual bloqueia o publicador instalado mesmo com ativação válida | 0.087 s |
| ✅ | Sem gmax_dispatches e com slots vazia: verificação, migração, painel e rollback funcionam | 0.085 s |
| ✅ | Diagnóstico exporta só o esquema: nenhum título, link, preço ou hash de senha; banco intacto | 0.114 s |

## Links afiliados em lote — 3.0.3 (tests/links_test.php)

Executado em 2026-11-06T09:32:18-03:00 · PHP 8.3.6 · **21/21 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Prévia do arquivo de 18 links: 18 links novos por ID do anúncio, nada gravado ainda | 0.092 s |
| ✅ | Aplicar: 18 links associados; preços, condição, disponibilidade e estado intactos; nada criado ou aprovado | 0.101 s |
| ✅ | Associação independe da ordem das linhas (arquivo embaralhado dá o mesmo resultado) | 0.096 s |
| ✅ | Reimportação do mesmo arquivo: 18 "já iguais", nenhuma alteração | 0.104 s |
| ✅ | Links inválidos são recusados (http, link comum, outro domínio, javascript, vazio) | 0.097 s |
| ✅ | ID inexistente não cria produto; ID inválido é recusado; Shopee nunca é alterada | 0.094 s |
| ✅ | Duplicidades no arquivo: mesmo link em 2 anúncios e mesmo anúncio 2 vezes → todas as ocorrências bloqueadas | 0.089 s |
| ✅ | Link já associado a outro produto (ex.: ML-001) é bloqueado | 0.096 s |
| ✅ | Nome sem nenhuma palavra em comum com o cadastrado é bloqueado (possível produto trocado) | 0.086 s |
| ✅ | Linha marcada como inválida na planilha não é aplicada | 0.085 s |
| ✅ | Substituir link existente exige autorização explícita; produto aprovado volta para validação | 0.113 s |
| ✅ | Verificação: confere, diverge (bloqueia), 404 (bloqueia) e indeterminado (rede) | 0.095 s |
| ✅ | Verificador não segue redirecionamento para domínio estranho | 0 s |
| ✅ | Sem verificação "confere" e sem confirmação manual, nenhum link é associado | 0.102 s |
| ✅ | Concorrência: aplicar duas vezes, aplicar após descartar e verificar após aplicar são recusados | 0.105 s |
| ✅ | Verificação que termina depois de o lote ser aplicado não grava nada | 0.097 s |
| ✅ | Número de anúncio ambíguo (item e catálogo) é recusado | 0.085 s |
| ✅ | Desfazer restaura os links anteriores (vazios) e preserva produto alterado depois | 0.099 s |
| ✅ | Auditoria registra prévia, aplicação, cada link e o desfazer, com usuário e data | 0.099 s |
| ✅ | Importador de produtos não opera lotes de links (e vice-versa) | 0.096 s |
| ✅ | Arquivo sem as colunas obrigatórias é recusado; XLSX com as mesmas colunas funciona | 0.088 s |

## Central de Automações — 3.1 (tests/automacoes_test.php)

Executado em 2026-11-06T09:32:20-03:00 · PHP 8.3.6 · **25/25 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Migração 002: tarefas criadas inativas, automações pausadas, aprovação automática desligada; reversível | 0.132 s |
| ✅ | Horários: vence às 07:05, perdido depois de 2 h, respeita dia da semana | 0.083 s |
| ✅ | Pausa geral: nada executa; ativa: executa uma vez por horário (idempotente) | 0.095 s |
| ✅ | Concorrência: com a trava das automações ocupada, nada executa | 0.101 s |
| ✅ | Execução interrompida pelo servidor é encerrada como falha na próxima chamada | 0.09 s |
| ✅ | Descoberta: insere novos como rascunho com origem e data; ignora existentes, indisponíveis e incompletos | 0.117 s |
| ✅ | Shopee: link oficial da API é aceito; link fora do padrão é descartado; integração ausente gera alerta | 0.098 s |
| ✅ | Falha da API na busca: execução parcial, erro registrado, nada inventado | 0.086 s |
| ✅ | Preço igual confirmado pela API: renova a confirmação e mantém aprovação | 0.104 s |
| ✅ | Preço mudou: atualiza Por e retira aprovação; "De" só é limpo por fonte que informa preço original | 0.108 s |
| ✅ | Mesmo preço da API não retira aprovação de "De" cadastrado manualmente (revisão repetida) | 0.104 s |
| ✅ | Edição concorrente durante a revisão não é carimbada como confirmada pela API | 0.096 s |
| ✅ | API falhou: dados não mudam, alerta aberto e publicação bloqueada pelo preço vencido | 0.093 s |
| ✅ | Anúncio pausado: indisponível, agendamento cancelado, alerta | 0.101 s |
| ✅ | Seleção coloca as melhores na fila respeitando o limite diário (nada é enviado) | 0.11 s |
| ✅ | Aprovação: manual por padrão (alerta); automática só com autorização e preço confirmado por API | 0.099 s |
| ✅ | Mercado Livre não entra na fila do grupo privado (regra do programa) mesmo com nota alta | 0.084 s |
| ✅ | Vencidas: aprovada com preço antigo volta à validação; indisponível antiga é arquivada sem perder histórico | 0.093 s |
| ✅ | Saúde: duplicidade por nome, link fora do padrão, alerta fecha sozinho quando resolvido | 0.088 s |
| ✅ | Modelos por loja/categoria; linhas sem dado confirmado são omitidas; validação de modelo | 0.078 s |
| ✅ | Limite diário de requisições por conector e registro de falhas | 0.082 s |
| ✅ | Mercado Livre (busca oficial): interpreta resposta; 403 vira "não autorizada"; vendas só se informadas | 0.001 s |
| ✅ | Shopee (busca oficial): link afiliado da API, desconto informado sem inventar preço original | 0 s |
| ✅ | Amazon e AliExpress: preparados, sem requisições e sem dados simulados | 0.077 s |
| ✅ | Todas as tarefas executadas em sequência: nenhum transporte criado, nenhum item reservado/enviado | 0.128 s |

## OAuth Mercado Livre — 3.1.1 (tests/oauth_test.php)

Executado em 2026-11-06T09:32:20-03:00 · PHP 8.3.6 · **12/12 aprovados**

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

Executado em 2026-11-06T09:32:21-03:00 · PHP 8.3.6 · **14/14 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Cenário real: 5 Shopee bloqueados por falta de credencial Shopee; ML-001 fora da revisão por não ter código MLB | 0.086 s |
| ✅ | HTTP 403 em /items: bloqueado com código, endpoint e motivo; preço e validação intocados; token não gravado | 0.084 s |
| ✅ | HTTP 401 em /items: motivo de autenticação explícito | 0.08 s |
| ✅ | HTTP 200 com o mesmo preço: validado, com preço anterior, preço da API e horário | 0.084 s |
| ✅ | HTTP 200 com preço novo: atualizado, campos alterados listados e aprovação retirada | 0.095 s |
| ✅ | HTTP 200 sem preço: sem alteração e sem validação (nada inventado) | 0.093 s |
| ✅ | Teste isolado de /items: mostra HTTP e preço sem gravar nada no catálogo | 0.08 s |
| ✅ | Limite por execução: excedentes ficam como "não processado" com o motivo | 0.089 s |
| ✅ | Rascunho e arquivado: fora da revisão com motivo; arquivados não poluem o histórico | 0.09 s |
| ✅ | Histórico somente-inclusão: alterar ou apagar linhas é recusado pelo banco | 0.086 s |
| ✅ | Mensagens de erro passam por limpeza de credenciais | 0 s |
| ✅ | Sem a migração 003 a revisão funciona como na 3.1.1 (sem detalhamento) | 0.108 s |
| ✅ | Execuções e dados anteriores preservados pela migração 003 | 0.12 s |
| ✅ | Prévia da próxima revisão usa as mesmas regras de elegibilidade | 0.08 s |

## Shopee Affiliate GraphQL — 3.1.4 (tests/shopee_test.php)

Executado em 2026-11-06T09:32:21-03:00 · PHP 8.3.6 · **16/16 aprovados**

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
| ✅ | Revisão com 10010: bloqueado, erro contado, código no histórico, preço não confirmado, catálogo intacto | 0.094 s |
| ✅ | Revisão após a correção: preço igual validado; link afiliado preservado | 0.103 s |
| ✅ | Testar conexão e consulta individual: não alteram o catálogo e não expõem segredos | 0.078 s |
| ✅ | Busca (descoberta) também trata "errors" com HTTP 200 como falha | 0 s |

## Conferência rápida — 3.1.5 (tests/conferencia_test.php)

Executado em 2026-11-06T09:32:22-03:00 · PHP 8.3.6 · **8/8 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Sem confirmação explícita nada é gravado (nem preço, nem conferência, nem histórico) | 0.076 s |
| ✅ | Conferência confirmada: grava como MANUAL (usuário e fonte conferencia_manual), nunca como API | 0.075 s |
| ✅ | Preço alterado retira a aprovação; link afiliado nunca é tocado | 0.079 s |
| ✅ | Validações: Por obrigatório, De ≥ Por, condição e disponibilidade válidas, variação descrita | 0.077 s |
| ✅ | Variação definida remove a pendência com a descrição informada | 0.078 s |
| ✅ | Validação pela API continua identificada como API | 0.08 s |
| ✅ | Botões: só URLs oficiais https; javascript:/outros domínios nunca viram link | 0.08 s |
| ✅ | Filtros novos: disponibilidade e variação pendente | 0.081 s |

## Calculadora de preços — 3.1.6 (tests/calculadora_test.php)

Executado em 2026-11-06T09:32:23-03:00 · PHP 8.3.6 · **9/9 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Percentual: aceita 35, 35%, 35,5 e 35.25; recusa 0, 96, texto e 3 casas | 0 s |
| ✅ | Estimativa exata ao centavo, com a faixa do arredondamento da loja | 0 s |
| ✅ | Modo preço original: desconto e economia pela regra existente (percentual arredondado para baixo) | 0 s |
| ✅ | Origem do "De": API só para o Mercado Livre; Shopee validada pela API continua "importado" | 0 s |
| ✅ | Conferência no modo desconto: "De" estimado NÃO é gravado como preço original; estimativa registrada à parte | 0.077 s |
| ✅ | Preço original confirmado pela API é preservado no modo desconto | 0.075 s |
| ✅ | Sem confirmação explícita, nem o modo desconto grava nada (nem a estimativa) | 0.078 s |
| ✅ | Estimativa deixa de valer quando o preço Por muda | 0.081 s |
| ✅ | Tela: os dois modos no mesmo formulário (valores preservados) e selo "Calculado ≈" na última estimativa | 0.088 s |

## Ranking inteligente e diagnóstico Mercado Livre — 3.1.7 (tests/ranking_ml_test.php)

Executado em 2026-11-06T09:32:24-03:00 · PHP 8.3.6 · **14/14 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Potencial: mantém a pontuação existente e lista produtos não validados com suas pendências | 0.087 s |
| ✅ | Prontas: só aparece o produto que passa em todas as regras de publicação | 0.082 s |
| ✅ | Prontas exclui: preço desatualizado, sem link, variação pendente, indisponível, "De" não confirmado e ML no grupo privado | 0.104 s |
| ✅ | Preço original só estimado: nunca vira "De", não pontua desconto e não aparece na mensagem | 0.084 s |
| ✅ | Filtros combinados: marketplace, conferência API/manual/nunca, link, preço, pontuação, confiança, dias, pronto | 0.086 s |
| ✅ | Ordenação por pontuação, confiança, preço e data; paginação de 25 | 0.152 s |
| ✅ | Diagnóstico: 403 com e sem token = restrição da plataforma; token válido; alternativas mapeadas; sem token no resultado | 0.002 s |
| ✅ | Diagnóstico distingue falta de autorização (sem token 200), token rejeitado (401), anúncio inexistente (404) e limite (429) | 0 s |
| ✅ | Resultados reais da HostGator (05/11): anônimo negado ≠ bloqueio de IP; app ativo e escopo read presentes; restrição específica a dados de terceiros | 0 s |
| ✅ | Cliente HTTP sempre envia User-Agent (o PHP não envia nenhum por padrão), sem duplicar o informado | 0 s |
| ✅ | Três 403 seguidos suspendem /items: sem novas requisições, um único alerta, OAuth preservado; reativação manual | 0.079 s |
| ✅ | Testes manuais (consulta individual e permissões) não contam nem respeitam a suspensão | 0.079 s |
| ✅ | 200 zera a contagem; 404 e 429 não contam; desativar a suspensão impede suspender | 0.093 s |
| ✅ | Automações com consultas suspensas: revisão sem erros repetidos e descoberta com uma linha, sem alerta por termo | 0.106 s |

## Candidatos do catálogo Mercado Livre — 3.1.9 (tests/candidatos_ml_test.php)

Executado em 2026-11-06T09:32:24-03:00 · PHP 8.3.6 · **9/9 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Pesquisa no catálogo: só IDs de catálogo válidos com nome; imagem só do CDN oficial | 0.001 s |
| ✅ | Produto de catálogo: anúncio vencedor e preço só como informados pela API; IDs separados; permalink validado | 0 s |
| ✅ | Inspeção de campos: estrutura e indicadores sem valores nem token | 0.001 s |
| ✅ | Tarefa: grava candidatos separados das ofertas; detalhe com preço da API não conferido; nada no catálogo | 0.1 s |
| ✅ | Tendências também geram candidatos (origem e posição registradas) | 0.086 s |
| ✅ | Catálogo bloqueado (403 repetido): suspende /products sem repetir consultas nem desconectar o OAuth | 0.085 s |
| ✅ | Criar rascunho: ID de catálogo (não de anúncio), sem link afiliado, preço NÃO conferido, fora das prontas | 0.09 s |
| ✅ | Descartar e filtros da lista | 0.092 s |
| ✅ | Sem a migração 004 a tarefa apenas avisa; a 004 é reversível sem tocar no restante | 0.1 s |

## Autenticação centralizada, limites operacionais, continuação de tarefas e desempenho — 3.2.0 (tests/v320_test.php)

Executado em 2026-11-06T09:32:35-03:00 · PHP 8.3.6 · **15/15 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Limite global de tentativas (50/15 min) além do limite por IP; bloqueado não grava (sem crescimento) | 2.904 s |
| ✅ | Concorrência real: 12 processos tentando ao mesmo tempo não passam de 5 tentativas | 1.179 s |
| ✅ | Senha inicial: só cria se não existir usuário; nunca sobrescreve; mínimo de 14 caracteres | 0.232 s |
| ✅ | CLI criar-senha apaga o arquivo da senha mesmo em caso de erro; recusa link simbólico; ignora BOM | 0.321 s |
| ✅ | IPv6 agrupado por /64; tentativas antigas descartadas; desbloqueio pela CLI | 0.353 s |
| ✅ | Regras com contexto pré-carregado = regras originais, produto a produto | 0.165 s |
| ✅ | Visão Geral com cache: mesmo resultado; invalida ao editar produto ou preço; expira em 5 min | 0.138 s |
| ✅ | Tarefa longa: execução parcial (não "concluída"), continuação nos ciclos seguintes, cada produto uma vez | 2.105 s |
| ✅ | Interrompida pelo servidor: vira falha com alerta e é retomada do ponto em que parou | 0.17 s |
| ✅ | Concorrência: com a trava ocupada nada executa; pausa geral impede a continuação | 0.609 s |
| ✅ | Limite de continuações: alerta e encerramento (sem laço infinito) | 1.775 s |
| ✅ | Execução manual pelo navegador: prazo menor que o limite do servidor | 0 s |
| ✅ | Seleção: teto de vagas livres na janela e no máximo 2 por categoria; nada é enviado | 0.231 s |
| ✅ | Limites de seleção configuráveis pelo painel, com validação; chaves de envio continuam só na CLI | 0.083 s |
| ✅ | Registro de "não processados" limitado a 100 linhas por execução (total no resumo) | 0.592 s |

## Índices, retenção, monitor, permissões e retomada — 3.2.1 (tests/v321_test.php)

Executado em 2026-11-06T09:32:44-03:00 · PHP 8.3.6 · **13/13 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Consultas principais usam os índices novos (EXPLAIN QUERY PLAN) | 0.085 s |
| ✅ | Gatilhos: auditoria somente-inclusão; detalhe e descobertas só saem dentro de uma retenção autorizada | 0.085 s |
| ✅ | Simulação: conjunto exato (quantidade, intervalo e lista de IDs em arquivo); nada apagado; protegidos listados | 0.154 s |
| ✅ | Execução: frase, simulação recente e idêntica; backup verificado com as linhas; exportação verificada; apaga só o simulado | 0.168 s |
| ✅ | Retenção interrompida (processo morto antes do COMMIT): nada apagado, nenhum "executando" sobra; nova execução funciona | 0.175 s |
| ✅ | Nada apaga automaticamente: nenhuma tarefa, página ou Cron chama a execução; pelo navegador é recusada | 0 s |
| ✅ | Revisão morta pelo servidor (SIGKILL): o Cron seguinte fecha na hora (sem esperar 15 min), alerta e continua sem repetir | 0.212 s |
| ✅ | Dois Crons ao mesmo tempo: um executa, o outro recusa (trava); o AutoLab tem trava própria e não bloqueia as automações | 1.693 s |
| ✅ | validar-retomada (para a hospedagem): cópia temporária, processo morto, retomada aprovada; dados reais intactos | 5.887 s |
| ✅ | Validação: só aceita pasta própria dentro de gmax-secrets (nunca a raiz nem pasta vizinha); cópias antigas são limpas | 0.134 s |
| ✅ | Monitor: tarefas, Cron, travas, banco, disco, backups e permissões; integridade sob demanda | 0.09 s |
| ✅ | Permissões: plano só é "seguro" com o mesmo usuário no site, no Cron e como dono; aplicar mantém o banco gravável | 0.085 s |
| ✅ | SQLite continua no modo atual (sem WAL) e com espera de bloqueio | 0.079 s |

## GMAX AutoLab: homologação automática no Telegram privado — 3.2.1 (tests/autolab_test.php)

Executado em 2026-11-06T09:32:48-03:00 · PHP 8.3.6 · **21/21 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Destino permitido: validação somente leitura, ativação só com frase própria e destino validado | 0.105 s |
| ✅ | Destino não autorizado é rejeitado: público, canal, outro chat, membros demais, bot fora — pausa em falha e nada é enviado | 0.496 s |
| ✅ | Canal real: recusa outro chat_id e texto sem rótulo SEM fazer requisição; não pode ser criado em modo de teste | 0 s |
| ✅ | Oferta validada: preço confirmado pela API agora, sem "De" nem desconto, link afiliado Shopee; catálogo intacto | 0.112 s |
| ✅ | Preço alterado: mensagem usa o preço confirmado agora e avisa a diferença; o catálogo NÃO é alterado | 0.109 s |
| ✅ | Dados ausentes (faixa de variações) e link inválido viram TESTE — CANDIDATO, sem preço inventado e sem link | 0.101 s |
| ✅ | Mercado Livre: candidato com ID de catálogo separado do anúncio, sem link afiliado, preço só se recente e marcado "NÃO conferido" | 0.131 s |
| ✅ | Sem produto real: SIMULAÇÃO rotulada, dados fictícios e nenhum link | 0.077 s |
| ✅ | Falha de rede: API Shopee fora → candidato/simulação; Telegram sem resposta na validação → horário pulado sem pausar | 0.095 s |
| ✅ | Duplicidade: mesmo horário nunca envia duas vezes; mesmo produto não se repete na semana | 0.099 s |
| ✅ | Concorrência real: 6 processos no mesmo horário → um único envio | 1.307 s |
| ✅ | Interrompida pelo servidor no meio do envio (processo morto): fica "incerto", NÃO é reenviada e o próximo horário segue | 0.135 s |
| ✅ | Timeout do Telegram: "incerto" sem repetir; dois seguidos pausam o AutoLab; retomar exige estar pausado | 0.114 s |
| ✅ | Reconciliação: reservado órfão vira incerto; preparado órfão vira "falhou" (nada enviado); execução órfã vira falha | 0.104 s |
| ✅ | Limite diário e intervalo mínimo: nunca acima do configurado; configuração nunca acima dos limites de segurança | 0.111 s |
| ✅ | Pausa emergencial: painel, arquivo de pausa e pausa no meio da preparação — nada é enviado | 0.137 s |
| ✅ | Período autorizado (7 dias): terminado, o AutoLab se encerra; renovar só pela CLI | 0.099 s |
| ✅ | Revisão de segurança: painel não transforma falha/desativado em pausado; links sem http também são barrados | 0.104 s |
| ✅ | Isolamento da fila comercial: AutoLab ativo não libera nenhuma trava comercial; fila e processador seguem bloqueados | 0.132 s |
| ✅ | Compatibilidade: sem a migração 006 o Cron segue igual; 006 e 005 revertem separadamente | 0.109 s |
| ✅ | CLI: status, prévia sem envio e ativação recusada sem destino validado | 0.181 s |

## Funil comercial, fila de trabalho, títulos, semelhantes e candidatos — 3.2.2 (tests/v322_test.php)

Executado em 2026-11-06T09:32:49-03:00 · PHP 8.3.6 · **12/12 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | Cada situação cai na etapa certa, com pendência principal, próxima ação e prioridade | 0.128 s |
| ✅ | Fila comercial: agendado = etapa Fila; reservado/incerto = Publicação (prioridade alta); confirmado = Resultado | 0.088 s |
| ✅ | Mesmas regras do sistema: "Aprovar" da fila aprova de fato; "Agendar" passa nas travas de entrada da fila | 0.089 s |
| ✅ | Somente leitura: calcular funil, fila e resumo não grava nada no banco | 0.096 s |
| ✅ | Ordem de atendimento, filtros (loja, categoria, prioridade, situação, etapa) e resumo coerente com a lista | 0.09 s |
| ✅ | Salvar e avançar: resolvido sai e o próximo ocupa a posição; pendente avança; fim da fila | 0.113 s |
| ✅ | Revisão técnica: resolvido que muda de etapa (conferência → aprovação) não faz pular os seguintes | 0.113 s |
| ✅ | Versão do produto muda a cada alteração relevante (base da proteção contra ação duplicada) | 0.089 s |
| ✅ | Título resumido: tira repetições e termos genéricos; mantém marca (1ª palavra) e todos os números | 0 s |
| ✅ | Semelhança (possível duplicado) e segmento comercial | 0.001 s |
| ✅ | Candidatos: paginação, segmento, já avaliados, semelhantes (candidato e catálogo) e só campos gravados | 0.125 s |
| ✅ | Fila e resumo com 1.000 e 10.000 produtos (tempo registrado) | 0.738 s |

## Interface responsiva no navegador — 3.1.5 (tests/ui_test.php, Chromium com emulação de dispositivos)

Executado em 2026-11-06T09:33:29-03:00 · PHP 8.3.6 · **257/257 aprovados**

| Resultado | Teste | Tempo |
|---|---|---|
| ✅ | 21 páginas sem rolagem horizontal da página | 0 s |
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
| ✅ | AutoLab: aviso de homologação, pausa imediata, destino fixo e histórico com tipo e resultado do Telegram | 0 s |
| ✅ | Barra inferior visível com Início, Minha fila, Ofertas, Automações e Menu | 0 s |
| ✅ | Barra inferior acima do conteúdo (recebe o toque) | 0 s |
| ✅ | Botão Menu da barra inferior abre e fecha o menu completo (5 grupos) | 0 s |
| ✅ | Visão Geral: indicadores e etapas do funil levam a listas filtradas | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em cartões com rótulo em cada campo | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 21 páginas sem rolagem horizontal da página | 0 s |
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
| ✅ | AutoLab: aviso de homologação, pausa imediata, destino fixo e histórico com tipo e resultado do Telegram | 0 s |
| ✅ | Barra inferior visível com Início, Minha fila, Ofertas, Automações e Menu | 0 s |
| ✅ | Barra inferior acima do conteúdo (recebe o toque) | 0 s |
| ✅ | Botão Menu da barra inferior abre e fecha o menu completo (5 grupos) | 0 s |
| ✅ | Visão Geral: indicadores e etapas do funil levam a listas filtradas | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em cartões com rótulo em cada campo | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 21 páginas sem rolagem horizontal da página | 0 s |
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
| ✅ | AutoLab: aviso de homologação, pausa imediata, destino fixo e histórico com tipo e resultado do Telegram | 0 s |
| ✅ | Barra inferior visível com Início, Minha fila, Ofertas, Automações e Menu | 0 s |
| ✅ | Barra inferior acima do conteúdo (recebe o toque) | 0 s |
| ✅ | Botão Menu da barra inferior abre e fecha o menu completo (5 grupos) | 0 s |
| ✅ | Visão Geral: indicadores e etapas do funil levam a listas filtradas | 0 s |
| ✅ | Fila: conferir e avançar ao próximo em 4 interações (abrir a fila + 3) | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em cartões com rótulo em cada campo | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 21 páginas sem rolagem horizontal da página | 0 s |
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
| ✅ | AutoLab: aviso de homologação, pausa imediata, destino fixo e histórico com tipo e resultado do Telegram | 0 s |
| ✅ | Barra inferior visível com Início, Minha fila, Ofertas, Automações e Menu | 0 s |
| ✅ | Barra inferior acima do conteúdo (recebe o toque) | 0 s |
| ✅ | Botão Menu da barra inferior abre e fecha o menu completo (5 grupos) | 0 s |
| ✅ | Visão Geral: indicadores e etapas do funil levam a listas filtradas | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em cartões com rótulo em cada campo | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 21 páginas sem rolagem horizontal da página | 0 s |
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
| ✅ | AutoLab: aviso de homologação, pausa imediata, destino fixo e histórico com tipo e resultado do Telegram | 0 s |
| ✅ | Barra inferior visível com Início, Minha fila, Ofertas, Automações e Menu | 0 s |
| ✅ | Barra inferior acima do conteúdo (recebe o toque) | 0 s |
| ✅ | Botão Menu da barra inferior abre e fecha o menu completo (5 grupos) | 0 s |
| ✅ | Visão Geral: indicadores e etapas do funil levam a listas filtradas | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em cartões com rótulo em cada campo | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 21 páginas sem rolagem horizontal da página | 0 s |
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
| ✅ | AutoLab: aviso de homologação, pausa imediata, destino fixo e histórico com tipo e resultado do Telegram | 0 s |
| ✅ | Barra inferior visível com Início, Minha fila, Ofertas, Automações e Menu | 0 s |
| ✅ | Barra inferior acima do conteúdo (recebe o toque) | 0 s |
| ✅ | Botão Menu da barra inferior abre e fecha o menu completo (5 grupos) | 0 s |
| ✅ | Visão Geral: indicadores e etapas do funil levam a listas filtradas | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em tabela completa | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 21 páginas sem rolagem horizontal da página | 0 s |
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
| ✅ | AutoLab: aviso de homologação, pausa imediata, destino fixo e histórico com tipo e resultado do Telegram | 0 s |
| ✅ | Desktop: menu lateral em 5 grupos; barra inferior oculta | 0 s |
| ✅ | Visão Geral: indicadores e etapas do funil levam a listas filtradas | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em tabela completa | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 21 páginas sem rolagem horizontal da página | 0 s |
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
| ✅ | AutoLab: aviso de homologação, pausa imediata, destino fixo e histórico com tipo e resultado do Telegram | 0 s |
| ✅ | Desktop: menu lateral em 5 grupos; barra inferior oculta | 0 s |
| ✅ | Visão Geral: indicadores e etapas do funil levam a listas filtradas | 0 s |
| ✅ | Busca global por nome leva às ofertas filtradas | 0 s |
| ✅ | Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer | 0 s |
| ✅ | Abrir link afiliado: link oficial distinto e visualmente diferenciado | 0 s |
| ✅ | Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado | 0 s |
| ✅ | Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido | 0 s |
| ✅ | Produto com variação pendente: alerta e opção de definir a variação | 0 s |
| ✅ | Automações: histórico em tabela completa | 0 s |
| ✅ | Nenhum erro de JavaScript ou violação de CSP no console | 0 s |
| ✅ | 21 páginas sem rolagem horizontal da página | 0 s |
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
| ✅ | AutoLab: aviso de homologação, pausa imediata, destino fixo e histórico com tipo e resultado do Telegram | 0 s |
| ✅ | Desktop: menu lateral em 5 grupos; barra inferior oculta | 0 s |
| ✅ | Visão Geral: indicadores e etapas do funil levam a listas filtradas | 0 s |
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
| ✅ | Fila pela interface: exatamente um produto conferido (manual), sem aprovação automática nem fila comercial | 0 s |
| ✅ | Abrir o anúncio não confirmou preço; só a conferência salva gerou registro manual | 0 s |
| ✅ | Modo desconto pela tela: "De" estimado não gravado; estimativa registrada à parte | 0 s |
| ✅ | Nenhum service worker nem cache: o servidor não serve sw.js | 0 s |

## Total

**562/562 testes aprovados.**

## Não testado aqui (depende do servidor real)

- Envio real ao Telegram: nenhum (o AutoLab foi testado com canal falso; o primeiro envio real só acontece depois da instalação, da validação do destino e da ativação).
- Validação real do destino (getChat/getChatMemberCount): só no servidor (`autolab validar-destino`).
- Retomada na HostGator: só no servidor (`gmax-automacoes-cli.php validar-retomada`).
- Chamadas reais à API do Mercado Livre (diagnóstico com respostas simuladas no formato do PolicyAgent; executar no servidor).
- Chamada real à Shopee Affiliate API (sem credenciais neste ambiente; validar pelo botão Testar conexão Shopee no servidor).
- Aparelhos físicos (iPhone, Android, tablet): a interface foi testada por EMULAÇÃO no Chromium (viewport, densidade, toque e user agent).
- Safari/WebKit real: não disponível neste ambiente; os perfis de iPhone usam o motor Chromium.
- Microsoft Edge real: testado com o user agent do Edge sobre Chromium, o mesmo motor do Edge.
- Apache/.htaccess da HostGator (teste manual no passo 4 da instalação).
- Limite real de `max_execution_time` e comportamento do Cron da HostGator (prazo de 100 s e continuação testados com relógio e conector simulados).
- Benchmarks medidos neste ambiente de desenvolvimento (4 núcleos), não na HostGator: servem para comparar antes/depois, não como tempo absoluto do servidor.
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
php -d allow_url_fopen=0 tests/v320_test.php
php -d allow_url_fopen=0 tests/v321_test.php
php -d allow_url_fopen=0 tests/autolab_test.php
php -d allow_url_fopen=0 -d memory_limit=1G tests/v322_test.php
php -d memory_limit=1G tools/benchmark_operacao.php 10000 200000
php -d memory_limit=512M tools/benchmark_ranking.php depois 1000,10000
php tests/ui_test.php
```
