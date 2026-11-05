# GMAX Ofertas — Auditoria completa da 3.1.9 e proposta para a 3.2 (Etapa A)

**Data:** 06/11/2026. **Escopo:** código real de `src/gmax-auto/` (7.381 linhas de PHP próprio, 22 telas, 44 ações em `painel.php`) e 15 suítes de testes.

**Método:** cinco revisões independentes, todas somente leitura: arquitetura, segurança, desempenho/SQLite (com medição em 1 mil e 10 mil produtos), integrações e lógica comercial, e UX/UI (com as capturas emuladas). Depois conferi pessoalmente no código os achados mais graves.

**O que não foi feito:** nenhum teste com API real nem em aparelho físico. Nada foi instalado e nada foi alterado no código.

---

## 1. Resumo executivo

**O que está certo e deve ser preservado:**
- Preços em centavos inteiros, sem estimativa.
- Fila de publicação à prova de falhas: reserva com `BEGIN IMMEDIATE`, envio incerto pausa tudo e as travas fecham por padrão.
- Backups verificados antes de mudanças de risco.
- CSRF e CSP rígida; tokens cifrados.
- Conectores substituíveis nos testes, 450 testes automatizados.
- Separação entre pontuação e prontidão para publicar.

Não recomendo reescrever o sistema.

**O que precisa de correção antes de crescer:**
1. **Segurança:** dá para contornar o limite de tentativas de login pelo `index.php` antigo, e a sessão aberta por lá também escapa do tempo limite de inatividade. Verificado no código.
2. **Escala:** o Ranking carrega o catálogo inteiro e todo o histórico, e roda as regras de duplicidade em cada linha. Medido: **2,1 s e 76 MB com 10 mil produtos**. O Ranking aparece também no painel inicial e na Central de Automações.
3. **Automações que não terminam:** a revisão de preços promete até 60 produtos, mas o limite de 45 s com 1,1 s entre chamadas dá cerca de **28 a 30 produtos por execução**. Com mais de 60 produtos, parte do catálogo nunca é revisada dentro da validade de 24 h. A execução manual pelo painel não amplia o tempo limite e pode ser interrompida pelo servidor no meio.
4. **Crescimento sem limite:** os registros de execução gravam uma linha "não processado" por produto, sem teto, e a tabela não aceita exclusões. Auditoria, descobertas e backups também não têm retenção. Isso acaba esgotando cota de disco ou de arquivos da hospedagem.
5. **Seleção sem teto:** uma execução da seleção pode enfileirar dezenas de dias de publicações, todas da mesma categoria. Hoje isso está contido porque nada é aprovado automaticamente.
6. **Experiência de uso:** não existe a noção de **etapa do produto** (descoberta → … → publicação). Cada cartão tem de 5 a 6 ações concorrendo e nenhuma "próxima ação". O painel inicial mostra contagens, mas não diz o que fazer agora.

---

## 2. Achados verificados (P0 = crítico · P1 = alto impacto · P2 = evolução)

### Segurança
| # | Pri. | Achado | Evidência | Correção mínima |
|---|---|---|---|---|
| S1 | **P0** | O login pelo `index.php` antigo não tem limite de tentativas e abre também o painel novo. A sessão aberta por lá nunca expira por inatividade | `Auth.php:28-29,43-46`; `index.php` (linha 12) grava `$_SESSION['auth']=true` | O painel novo só aceita sessão criada pelo login dele (marcador próprio; sem marcador = expirada). Recomendo também bloquear o `index.php` antigo via `.htaccess`, **com sua autorização** |
| S2 | P1 | O limite de tentativas permite várias em paralelo (conta antes de gravar) e fica aberto antes da migração | `Auth.php:80-88` | Gravar a tentativa e contar dentro de uma transação; limite global além do limite por IP |
| S3 | P2 | Um link forjado para o retorno do OAuth mostra texto do atacante como alerta do sistema (sem XSS) e apaga a autorização em andamento | `MercadoLivreOAuth.php:125-128` | Validar o `state` antes de ler `error` |
| S4 | P2 | Toda página aberta (GET) pode gravar no banco (sincronização com as tabelas antigas) | `painel.php:582-596` | Levar a sincronização para a automação ou para um botão |
| S5 | P2 | Se a pasta privada não aceitar gravação, os backups vão para `storage/` dentro da área pública, protegida só por `.htaccess` | `Backup.php` | Recusar o backup e alertar, em vez de usar a pasta pública |

**Confirmado sem problema:** CSRF em todas as ações, nenhuma ação GET que altere estado (exceto S4), escape de saída, SQL parametrizado, uploads (limites, XLSX sem entidades externas, CSV contra fórmulas), segredos fora do banco e dos logs, OAuth com PKCE e `state`, restauração segura. **Nenhuma ação web consegue ativar envios ou a aprovação automática.**

### Desempenho, SQLite e automações
| # | Pri. | Achado | Medição/evidência | Correção | Ganho mensurável |
|---|---|---|---|---|---|
| D1 | **P0** | A revisão de preços não cumpre o próprio limite | 45 s com 1,1 s entre chamadas ≈ 28 a 30 por execução | Ampliar o tempo (limite total da chamada já é 150 s) e continuar do ponto onde parou no próximo ciclo de 15 min | De 2 a 3 vezes mais produtos revisados por dia |
| D2 | **P0** | A execução manual web não tem tempo limite próprio | `painel.php:165`, sem `set_time_limit`; o servidor interrompe em cerca de 30 s | `set_time_limit` e prazo coerente com o servidor | Nenhuma execução manual fica "executando" até virar falha |
| D3 | **P0** | O Ranking não escala | 250 ms com 1 mil produtos; **2.147 ms e 76 MB com 10 mil** | Histórico agregado em SQL (`MIN` e `COUNT` por produto); configurações, fila e mapa das tabelas antigas carregados uma vez por requisição | Meta: menos de 300 ms com 10 mil produtos |
| D4 | P1 | Ranking completo recalculado no painel inicial e na Central só para mostrar 5 itens | 36 a 259 ms por carregamento | Usar a versão otimizada (D3) e um cache curto | Menos de 50 ms |
| D5 | P1 | Faltam índices | Consultas leem a tabela inteira em histórico (`verified`), `price_verified_at`, `updated_at` e `category` | Migração só de índices | Ordenação e busca em 10 mil produtos ou mais |
| D6 | P1 | Tabelas e backups sem retenção | "Não processados" sem teto; gatilho de somente-inclusão; nenhuma limpeza | Teto no registro (como o de "fora da revisão"); retenção de 90 dias para execuções e descobertas e de 365 dias para auditoria; manter os últimos N backups | Banco e disco estáveis ao longo do ano |
| D7 | P1 | Gravações em massa uma linha por vez, sem transação | `Jobs.php:97,456-510` | Gravar em lotes dentro de uma transação | De 5 a 20 vezes menos escritas em disco |
| D8 | P1 | Execução interrompida pelo servidor perde o horário sem alerta | `closeStale` só marca "falhou" | Alerta e horário de nova tentativa | Nenhuma falha silenciosa |
| D9 | P2 | Modo de diário do SQLite: rollback padrão | Só `busy_timeout=5000` | WAL só **depois de confirmar** que o disco da HostGator é local (em NFS, o WAL corrompe). Sem confirmação, manter o modo atual com transações em lote | Painel sem "database is locked" durante as automações |

### Integrações e lógica comercial
| # | Pri. | Achado | Evidência | Correção |
|---|---|---|---|---|
| C1 | P1 | A seleção não limita volume nem categoria por execução | `Jobs.php` (seleção), `Queue::nextSlot` | Teto por execução (vagas livres em N dias) e por categoria/segmento |
| C2 | P1 | A comissão da Shopee aparece crua ("0.07" = 7%) | `ShopeeAffiliate.php` | Mostrar em %; não usar na pontuação até a fonte ser marcada |
| C3 | P1 | A pendência de variação da Shopee nunca é removida quando a faixa de preço vira um preço único | `fetch()` não envia `variation_pending=0` | Remover quando min = max; preço continua não conferido até a revisão |
| C4 | P1 | Candidatos ML: a posição na pesquisa não é guardada; sem preço, o detalhe ocupa vagas todo dia; a detecção de duplicados é fraca | `MlCandidates.php`, `catalogSearch` | Guardar posição, contador de tentativas e prioridade "nunca consultado"; detectar duplicados (seção 4) |
| C5 | P2 | A descoberta Shopee sempre pede a página 1 e traz os mesmos itens | `page: 1` | Alternar páginas e guardar um cache por termo e por dia |
| C6 | P2 | Disponibilidade da Shopee nunca é preenchida (a API não traz estoque) | — | Manter "não informada"; após N consultas sem encontrar o item, abrir alerta |

### Arquitetura e manutenção
| # | Pri. | Achado | Correção (sem reescrever) |
|---|---|---|---|
| A1 | P1 | `painel.php` concentra 44 ações, com SQL e validação dentro | Extrair aos poucos: validação do formulário para `Catalog`, modelos e categorias para classes pequenas, conectores criados via `Registry` |
| A2 | P1 | Chaves estrangeiras declaradas, mas não ativadas | Rodar `PRAGMA foreign_key_check`; só ativar depois de corrigir órfãos (muda comportamento de exclusão) |
| A3 | P2 | Salvar oferta não é atômico (estimativa e confirmação ficam fora da transação) | Uma única transação |
| A4 | P2 | Lógica de preço duplicada em JS e PHP | Vetores de teste compartilhados entre PHP e navegador |
| A5 | P2 | `Migrator::down` só remove tabelas | Cada migração nova passa a ter `down` próprio (índices e colunas) |

### UX/UI
| # | Pri. | Problema | Proposta |
|---|---|---|---|
| U1 | P1 | Não há etapa do produto | **Funil derivado, sem coluna nova**: Descoberta → Triagem → Conferência → Link afiliado → Aprovação → Fila → Publicação → Resultado, calculado a partir de estado, conferência, link e fila. Selo da etapa e indicador de passos em cada cartão |
| U2 | P1 | 5 a 6 ações por cartão; cartão de cerca de 230 px no celular | **Uma ação principal por etapa** ("Conferir preço", "Adicionar link", "Aprovar", "Enfileirar"); o resto vai para "⋯". Modo lista compacto (cerca de 90 px) |
| U3 | P1 | Painel inicial mostra contagens sem dizer o que fazer | **"Precisa de você agora"** (só itens com n > 0) + **barra do funil** clicável + oportunidades por categoria + saúde das automações e integrações |
| U4 | P1 | 11 itens soltos no menu | 5 grupos (Hoje · Ofertas · Publicação · Automação · Configuração) e **barra inferior no celular** (Hoje · Ofertas · Fila · Mais) |
| U5 | P1 | Filtros longos antes da lista | **Atalhos de filtro** (Sem link · Preço vencido · Prontas · Indisponíveis), com os filtros avançados recolhidos |
| U6 | P2 | "Sem link afiliado" é um texto desativado, sem ação | Botão "Adicionar link", que leva direto ao campo |
| U7 | P2 | Texto pequeno, botões com alturas diferentes, nomes técnicos no histórico | Mínimo de 14 px, altura única de 44 px, rótulos humanizados |

---

## 3. Respostas às suas 15 perguntas

1. **O que estamos construindo corretamente?** A integridade dos preços, as travas de publicação, a separação entre pontuação e prontidão, a recusa a inventar dados, a auditoria e os testes. Isso é raro e vale preservar.
2. **O que está mal estruturado?** `painel.php` concentra tudo; o Ranking é recalculado de forma completa a cada página; estado estruturado fica guardado em configurações; não há retenção de registros.
3. **O que simplificar?**
   - Os cartões: uma ação principal.
   - O menu: 5 grupos.
   - Os filtros do Ranking: atalhos de filtro, com os 14 filtros só no modo avançado.
   - A tela de diagnóstico do ML: manter os detalhes recolhidos.
4. **O que falta?**
   - Funil e etapa de cada produto.
   - Fila de trabalho do dia.
   - Segmentos e diversidade.
   - Retenção de registros.
   - Monitor de automações com alertas de falha.
   - Duplicados aproximados.
   - Ações em lote no celular.
5. **O que prejudica a produtividade?**
   - De 7 a 9 toques por produto.
   - Ausência de "próxima ação".
   - Filtros grandes.
   - Repetição de texto ("Preço não conferido" em todo cartão).
6. **O que pode aumentar vendas?** Sem prometer números, só consigo indicar o que dá para **medir**:
   - divulgar só produtos com preço atual e link oficial (já existe);
   - variar segmentos;
   - registrar o resultado de cada publicação (cliques e conversões, quando os relatórios dos programas permitirem), para aprender o que funciona.
7. **Automações que de fato economizam tempo:**
   - Revisão de preços que termina (D1).
   - Fila de trabalho do dia.
   - Alerta de preço vencido de item aprovado.
   - Pré-seleção diversificada.
   - Alertas de links afiliados quebrados.
8. **O que parece interessante, mas não compensa agora:**
   - Migrar para MySQL ou outro servidor.
   - Aplicativo nativo.
   - Inteligência artificial para escrever mensagens.
   - Previsão de vendas.
   - Raspagem de páginas (também proibida).
9. **Problemas com centenas ou milhares de produtos:**
   - Ranking lento e com muita memória (D3).
   - Revisão que nunca cobre o catálogo (D1).
   - Tabelas de registro crescendo (D6).
   - Banco travado durante gravações em massa (D7).
   - Seleção concentrada numa categoria (C1).
10. **O SQLite continua adequado?** **Sim**, com as correções D3, D5, D6 e D7. Os critérios objetivos para reavaliar (MySQL, que a HostGator oferece) são:
    - mais de 25 mil produtos;
    - mais de 300 mil linhas de histórico de preços;
    - banco com mais de 500 MB;
    - várias pessoas gravando ao mesmo tempo.
11. **Celular:**
    - Barra inferior.
    - Modo lista compacto.
    - Uma ação por cartão.
    - Fila do dia.
    - Ações em lote no celular.
12. **Menos operações manuais:**
    - Fila do dia ordenada pela próxima ação.
    - Conferência rápida com "próximo produto".
    - Colar vários links afiliados em sequência.
    - Revisão automática que termina.
13. **Mais confiabilidade:**
    - Execuções que continuam de onde pararam.
    - Alerta de falha.
    - Retenção de registros.
    - Backup com rotação e teste de restauração.
    - Testes de integridade semanais.
    - `down` em toda migração.
14. **Melhorias visuais de maior impacto:**
    - Selo da etapa com indicador de passos.
    - Uma ação principal por cartão.
    - Painel inicial em forma de lista de tarefas.
    - Menu agrupado.
    - Contraste e tamanhos uniformes.
15. **Arquitetura ideal com os mesmos recursos:**
    - Mesmo PHP + SQLite + Cron.
    - `painel.php` como roteador fino, com ações agrupadas por domínio.
    - Contexto por requisição, com cache de configurações e modelos.
    - Tabela de "pontuação calculada", atualizada a cada gravação e na automação, para o Ranking virar uma consulta indexada com paginação.
    - Uma automação de manutenção (retenção, backups, `PRAGMA optimize`).
    - Sem novos servidores.

---

## 4. Minhas 12 propostas próprias

| # | Proposta | Problema que resolve | Benefício | Complexidade | Risco | Dependências | Pri. | Critério de sucesso |
|---|---|---|---|---|---|---|---|---|
| 1 | **Fila de trabalho do dia**: lista única ordenada pela próxima ação (conferir, adicionar link, aprovar), com "concluir e ir para o próximo" | Hoje é preciso caçar o que fazer em várias telas | Menos toques e decisões por produto | Média | Baixo | Funil (U1) | P1 | Toques por produto de 7–9 para ≤ 4 no teste de interface |
| 2 | **Funil derivado** (etapa calculada) em todas as telas | Não se sabe onde cada produto está | Clareza e métricas por etapa | Média | Baixo | — | P1 | 100% dos produtos com uma etapa; contagens do funil batem com os filtros |
| 3 | **Revisão de preços que continua de onde parou** | Revisão nunca cobre o catálogo | Preços válidos em todo o catálogo aprovado | Média | Médio | D1 | **P0** | Todo aprovado revisado em ≤ 24 h num catálogo de 200 itens (teste com relógio simulado) |
| 4 | **Manutenção automática**: retenção, rotação de backups, `PRAGMA optimize`, verificação de integridade semanal | Crescimento sem limite | Disco e banco estáveis | Baixa | Baixo (exige migração para relaxar o gatilho) | D6 | P1 | Tamanho do banco estável num ano simulado; nenhuma linha recente apagada |
| 5 | **Pré-seleção diversificada**: sugestões por segmento com teto, sem aprovar nada | Fila concentrada numa categoria | Variedade e menos repetição | Média | Baixo | Segmentos (7) | P1 | Nenhum segmento acima de X% da fila em 7 dias |
| 6 | **Monitor de saúde** no painel inicial: última execução de cada tarefa, falhas, integrações, espaço em disco e tamanho do banco | Falhas silenciosas | Intervenção rápida | Baixa | Baixo | D8 | P1 | Toda execução com falha aparece em ≤ 1 carregamento |
| 7 | **Segmentos** (Tecnologia, Ferramentas, Casa e organização, Beleza, Utilidades, Maternidade/infantil) ligados às categorias, com limites por segmento | Descoberta e seleção sem foco | Alinhamento ao seu nicho | Média | Médio (migração) | Migração 005 | P1 | Descoberta e seleção respeitam os limites nos testes |
| 8 | **Detector de links afiliados quebrados ou trocados** (verificação periódica de redirecionamento, só leitura e limitada) | Publicar link morto | Menos perda de comissão | Média | Baixo | Verificador já existente | P2 | Links inválidos sinalizados antes da fila |
| 9 | **Registro de resultado por publicação** (cliques e conversões importados dos relatórios oficiais dos programas, quando disponíveis) | Sem dados de resultado não há como saber o que funciona | Decisões com base em evidência | Média | Baixo | Relatórios dos programas | P2 | Cada publicação ligada ao seu resultado importado |
| 10 | **Duplicados aproximados** (título normalizado + categoria): só **sinaliza**, nunca exclui; variações de cor e tamanho viram "irmãos" | Mesmo produto cadastrado duas vezes | Catálogo limpo sem perda | Média | Baixo | — | P1 | Zero exclusões automáticas; pares sinalizados nos testes |
| 11 | **Título comercial curto** só para leitura administrativa (regra determinística: remove repetições e termos genéricos, preserva marca, modelo e números) | Títulos do catálogo ML longos e repetitivos | Leitura mais rápida no celular | Baixa | Baixo (o original fica preservado) | — | P1 | Nenhum número, marca ou modelo removido (testes com títulos reais) |
| 12 | **Plano de execução visível**: prévia do que cada automação fará no próximo horário (quantos itens e quais chamadas de API) | Consumo de API e tempo imprevisíveis | Planejamento e menos surpresas | Baixa | Baixo | D1 | P2 | Prévia bate com a execução real nos testes |

**Recomendo não fazer agora:** migrar para MySQL, aplicativo nativo, IA generativa nas mensagens, previsão de vendas e qualquer forma de contornar o bloqueio do Mercado Livre.

---

## 5. Plano incremental proposto

| Versão | Conteúdo | Migração | Risco |
|---|---|---|---|
| **3.2.0 (P0)** | S1 (login), D1 + prop. 3 (revisão que continua), D2 (execução manual), D3 + D4 (Ranking escalável), teto dos "não processados" (parte de D6), C1 (teto da seleção), S3, A3 | **Não** | Baixo: corrige comportamento sem mudar dados |
| **3.2.1 (P1, operação)** | D5 (índices), D6 (retenção e rotação), D7 (lotes), D8 (alerta de falha), prop. 4 e 6 (manutenção e monitor), C2 a C4 (Shopee e candidatos), S2, S4, S5 | **Sim (005)**: índices; ajuste do gatilho de somente-inclusão para permitir apagar só registros com mais de 90 dias; colunas de posição e tentativas nos candidatos. `down` próprio | Médio: altera uma garantia (registro somente-inclusão) para permitir retenção |
| **3.2.2 (P1, experiência)** | U1 a U5, prop. 1, 2, 10 e 11 (funil, fila do dia, uma ação por cartão, painel inicial, menu agrupado e barra inferior, atalhos de filtro, duplicados, título curto) | Não | Médio: mudança visual ampla, coberta por testes de interface |
| **3.2.3 (P1, comercial)** | Prop. 5 e 7 (segmentos e diversidade), descoberta com segmentos e limites | **Sim (006)**: segmento e limite por categoria; segmento nos candidatos | Médio |
| **3.3 (P2)** | Prop. 8, 9 e 12, A1 (desmembrar `painel.php` aos poucos), A2 (chaves estrangeiras), C5, C6, D9 (WAL, só com o disco confirmado) | Talvez | — |

Cada versão tem as próprias suítes de regressão, revisão independente de segurança e de interface, ZIP incremental e rollback. Envios, aprovação automática, credenciais e Cron **não mudam**.

**Agenda operacional sugerida (os horários atuais não foram alterados):**
- Manter o Cron a cada 15 min.
- `saude_catalogo` às 06:30, com a manutenção pesada só aos domingos.
- `descoberta` às 07:00.
- `analise_semanal` (segunda) e `revisao_vencidas` (sexta) às 07:30. Já ficam em dias diferentes.
- `revisao_precos` às 12:00 e 18:00, continuando nos ciclos seguintes até terminar.
- `selecao` às 18:30, depois da revisão.
- `candidatos_ml` fora das janelas de revisão (ex.: 05:00 ou 13:00), para não disputar o limite diário de requisições.

## 6. Decisões que dependem de você
1. **Aprovar os pacotes**: 3.2.0 → 3.2.1 → 3.2.2 → 3.2.3, ou outra ordem.
2. **Painel antigo (`index.php`)**: aceitar que ele deixe de dar acesso ao painel novo (o login passa a ser só pelo `painel.php`)? E bloqueá-lo pelo `.htaccess`?
3. **Retenção de registros**: aceitar apagar registros de execução e descobertas com mais de 90 dias (e auditoria com mais de 365)? Isso exige ajustar o gatilho de somente-inclusão.
4. **Segmentos**: confirmar os 6 segmentos iniciais e os limites (sugestão: no máximo 2 publicações por segmento por dia).
5. **Disco da HostGator**: verificar com o suporte se o disco é local, antes de qualquer mudança no modo de diário (WAL).
