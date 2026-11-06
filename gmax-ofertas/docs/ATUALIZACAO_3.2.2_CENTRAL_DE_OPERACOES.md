# GMAX Ofertas 3.2.2 — Central de operações

**Base:** 3.2.1 instalada (migrações 005 e 006 aplicadas, AutoLab ativado).
**Banco:** **nenhuma migração.** O funil e a fila de trabalho são **calculados** a partir dos dados existentes. Nenhum estado novo foi criado e nenhum estado existente mudou de significado.
**Não alterado:**
- banco, migrações, histórico e auditoria;
- produtos e links;
- credenciais Shopee, OAuth do Mercado Livre e Cron;
- travas da fila comercial: publicação pausada e aprovação automática desligada;
- **AutoLab:** autorização, destino, limite diário, prazo até 13/11/2026 11:56 e Cron intocados.

> O primeiro envio real do AutoLab ainda **não** foi observado por mim: não tenho acesso ao Telegram nem à HostGator. A página AutoLab e a Visão Geral agora mostram isso de forma explícita até haver uma mensagem confirmada.

## 1. Relatório de melhorias

### 1.1 Funil comercial (camada derivada)

Cada produto mostra **etapa**, **pendência principal**, **próxima ação** e **prioridade**, com as **mesmas regras** que já bloqueiam a aprovação e a fila (`approvalIssues`, `Rules::blockers` e `readiness`).

| Etapa | Quando | Próxima ação |
|---|---|---|
| Descoberta | Candidato ML novo (fora do catálogo) | Avaliar em Candidatos ML |
| Triagem | Rascunho | Avaliar rascunho |
| Conferência | Preço nunca conferido ou vencido, variação pendente, indisponível, "De" < "Por", condição ausente, "De" não confirmado | Conferir agora |
| Link afiliado | Sem link oficial | Salvar link (direto na fila) |
| Aprovação | Dados completos, aguardando | Aprovar (direto na fila) |
| Fila | Aprovada e pronta, ou já agendada | Agendar na fila (o envio continua bloqueado) |
| Publicação | Envio reservado ou incerto | Reconciliar em Publicações |
| Resultado | Publicada (confirmada) | — |

**Prioridade:**
- **alta:** pendência em oferta aprovada ou agendada, que bloquearia uma divulgação, ou envio incerto;
- **média:** aguardando validação, ou pronta para agendar;
- **baixa:** rascunho.

### 1.2 Minha fila de trabalho (menu Operação → Minha fila)

- Reúne preço desatualizado, variação, sem link, aguardando aprovação, inconsistências, disponíveis para seleção e rascunhos.
- Filtros por loja, categoria, prioridade, situação e etapa, com atalhos por situação no topo.
- Botão principal por pendência:
  - **Conferir agora**;
  - **Salvar link** (campo na própria fila);
  - **Aprovar**;
  - **Agendar na fila**.
- Também: **Conferência rápida**, **Conferir na loja** e **Abrir link afiliado**.
- **Salvar e ir para o próximo** na conferência: o próximo item abre direto. Se a ação dele não for conferência, a tela volta à fila **na posição** dele (página e âncora).
- **Histórico** dos últimos registros de auditoria de cada produto.
- **Prevenção de ação duplicada:** cada ação leva a "versão" do produto. Se ele mudou depois que a tela foi aberta (outra aba, automação ou clique repetido), nada é feito e a mensagem explica.
- **Meta de interações, medida no navegador:** resolver um preço desatualizado leva **4 interações**: abrir a fila, *Conferir agora*, *confirmo*, *Salvar e ir para o próximo*. Aprovar ou agendar leva **2**: abrir a fila e o botão.

### 1.3 Visão Geral orientada a decisões

- **Precisa de você agora:** as 5 primeiras pendências da fila.
- **Prontos para divulgar**, com atalho para o Ranking das prontas.
- **Pendências por tipo:**
  - aguardando validação;
  - preços desatualizados;
  - links ausentes;
  - variações;
  - inconsistências.
- **Funil** com contagem por etapa e o total por loja.
- **Próximas ações recomendadas**, em ordem:
  1. reconciliar envios;
  2. pendências altas;
  3. Cron sem chamada;
  4. primeiro envio do AutoLab;
  5. AutoLab pausado ou com falha;
  6. aprovações;
  7. alertas;
  8. candidatos.
- **Integrações e automações:** Shopee, Mercado Livre, Cron, execuções, falhas, alertas, AutoLab (último envio confirmado e próximo horário) e publicação comercial.
- **Todos os indicadores abrem a lista filtrada correspondente.** Os totais por loja que eram cartões viraram uma linha.

### 1.4 Navegação

- **Menu em grupos:**
  - Operação: Início, Minha fila, Publicações, AutoLab;
  - Produtos: Ofertas, Importar, Links afiliados, Candidatos ML, Categorias;
  - Automação: Automações, Integrações;
  - Análise: Ranking, Relatórios;
  - Sistema: Diagnóstico, Sistema.
- Nenhuma página foi removida.
- **Celular e tablet:** barra inferior fixa com **Início, Minha fila, Ofertas, Automações e Menu**. No desktop ela fica oculta.
- Sem JavaScript, o menu completo continua visível.

### 1.5 Candidatos ML

- **Abrir link oficial**, um permalink já validado no domínio do Mercado Livre.
- Busca por palavra, filtros (situação, preço da API, **segmento**), **ordem por prioridade de segmento** e **paginação** de 20 por página.
- **Título comercial resumido:** o original fica no `title` e no banco.
- **Possível duplicado:** semelhança entre títulos (≥ 60%) com outros candidatos e com produtos do catálogo. Só sinaliza, nunca exclui.
- **Já avaliados:** selo e filtro (rascunho criado ou descartado).
- **Dados retornados pela API:** só os campos que a API informou e foram gravados.
- Rascunho só quando você pede (como antes).
- **Nenhuma chamada nova à API e nenhum endpoint bloqueado.**

### 1.6 Acompanhamento do AutoLab

- **Último envio confirmado pelo Telegram:** horário, ID da mensagem, tipo, produto e origem do preço.
- Aviso **"Primeiro envio real ainda não observado"** enquanto não houver confirmação.
- **Motivos de falha e bloqueio** dos últimos 7 dias, agrupados.
- O que já existia: estado, próxima execução, enviadas, bloqueadas, incertas, relatório diário e histórico com o resultado do Telegram.
- Pausa por falha **não** é desfeita automaticamente.

### 1.7 Correções encontradas durante os testes

- **Celular:** o cabeçalho ("GMAX Ofertas" + "Envios pausados" + "Sair") era mais largo que telas de 320 a 375 px. O navegador reduzia o zoom de todas as páginas, e a barra inferior ficava **abaixo da área visível**.
  - O selo curto agora é "Pausado".
  - O teste de largura ficou mais rigoroso: compara com a largura real do aparelho.
  - Ele achou o mesmo problema na página Ranking (selo de fontes sem quebra de linha), também corrigido.
- **Formulários:** o script de "carregando" desativava o botão clicado antes do envio, e o navegador deixava de enviar o nome do botão. Isso quebraria "Salvar e ir para o próximo".
  - Agora o botão clicado vai como campo oculto.
  - **Todos** os botões de envio ficam desativados, o que impede o clique duplo.

### 1.8 Revisões independentes (agentes separados, só leitura)

**Revisão técnica e de segurança.** Veredito: "pode ir, com correções". Nenhuma falha de segurança. Confirmou:
- funil coerente com as regras reais;
- nenhuma gravação em GET;
- CSRF, escape e sem open redirect;
- AutoLab só com leituras novas.

Achados, todos corrigidos e testados:

| Achado | Impacto | Correção |
|---|---|---|
| "Salvar e ir para o próximo" **pulava itens** quando o resolvido mudava de etapa (ex.: conferência → aprovação) e mudava de lugar na ordenação | Itens esquecidos na fila sem filtro | A lista dos próximos é calculada **antes** de gravar, e a fila retoma no primeiro deles que continua pendente. Há teste de regressão |
| Recusa por "produto mudou" apagava o que foi digitado na conferência | Retrabalho | A verificação foi para dentro do bloco que preserva o formulário |
| Candidatos: teto silencioso de 2.000 linhas e o segmento de todas as linhas a cada página | Totais errados e lentidão com o tempo | Contagem e paginação no SQL. O segmento de todos só é calculado ao filtrar ou ordenar por segmento |
| Várias varreduras do catálogo por tela | Custo crescente | Uma passada só, compartilhada entre a lista e o resumo |
| Tamanho de página repetido e página perdida no erro | Âncora errada | Constante única; o erro volta à mesma página e ao mesmo item |

**Revisão visual** (sobre as capturas dos perfis emulados). Ajustes feitos:
- "Precisa de você agora" agrupado por motivo, com o botão "Abrir minha fila (N)" no topo e só 3 itens;
- cartões sem esticar no desktop;
- ações da fila em grade, com alvos de 44 px e botões da loja lado a lado;
- foco visível em todos os controles;
- condições de pagamento em 2 colunas, caixa "confirmo" de 24 px e botão de salvar fixo acima da barra inferior no celular;
- texto para leitores de tela nos botões da loja, com o nome do produto.

Não procedentes:
- a barra inferior "no meio da página" é efeito da captura de página inteira de um elemento fixo. O teste confirma que ela fica no rodapé da tela e recebe o toque;
- o contraste do texto secundário mede cerca de 8:1;
- os números diferentes entre capturas vêm de perfis capturados em momentos diferentes do teste.

Para depois:
- busca recolhida num ícone;
- contador de pendências na barra;
- recolher seções longas do AutoLab e dos Candidatos.

## 2. Melhorias propostas (não implementadas, ou só parcialmente)

| Proposta | Benefício | Por que não agora |
|---|---|---|
| Ordenar a fila pela pontuação do Ranking dentro da mesma prioridade | Atacar primeiro as melhores ofertas | Custo de cálculo maior com 10 mil produtos; avaliar com dados reais |
| Conferência em lote para produtos API (Shopee) com preço vencido: "revisar agora" pela API | Menos conferência manual | Já coberto pela tarefa Revisão de preços; um botão a mais exige limite de requisições por clique |
| Atalhos de teclado na fila (J/K para navegar, C para conferir) | Velocidade no desktop | Ganho pequeno frente ao toque; acessibilidade a validar |
| Contador de pendências altas na barra inferior | Visibilidade | Exigiria calcular a fila em toda página (custo) |
| Segmentos editáveis pelo painel (palavras-chave) | Ajuste fino | Fica para a 3.2.3, com os limites por segmento |

## 3. Testes executados

Resultados completos em `TESTES.md`.

| Suíte | Cobertura |
|---|---|
| `tests/v322_test.php` (12) | Cada situação na etapa, prioridade e ação certas. Fila comercial (agendado, incerto e confirmado). "Aprovar" e "Agendar" com as mesmas regras do sistema (Mercado Livre bloqueado não aparece como disponível). Somente leitura (banco idêntico). Ordem, filtros e resumo = lista. Salvar e avançar (posição e fim), **sem pular itens quando o resolvido muda de etapa**. Versão. Título resumido (números e marca preservados). Semelhança e segmentos. Candidatos (paginação, segmento, avaliados, semelhantes, campos). Desempenho: funil + fila com 10 mil produtos em cerca de 0,2 s (ambiente de desenvolvimento) |
| `tests/http_test.php` | Pelo servidor PHP real: fila com título malicioso escapado, barra inferior com 5 atalhos e 5 grupos no menu. Aprovar **uma vez só** (o reenvio é recusado e a auditoria registra uma aprovação). Link inválido volta ao item e o válido segue. Conferir e "salvar e ir para o próximo" abre o próximo, e o reenvio é recusado. Abrir Início, fila, candidatos, AutoLab e ofertas **não grava nada** |
| `tests/ui_test.php` | 9 perfis emulados: nenhuma página alarga a tela; barra inferior e botão Menu; menu em grupos no desktop; indicadores levam a listas filtradas; **fluxo da fila em 4 interações**; integridade depois dos testes |
| Regressão | Todas as suítes anteriores, incluindo AutoLab (isolamento da fila comercial), interrupção e retomada (3.2.1), OAuth, Shopee e candidatos |

## 4. Instalação

1. **Backup:** em Sistema, clique em Criar backup agora e compacte a pasta `gmax-auto/`.
2. **Envio:** extraia `GMAX_Ofertas_3.2.2_incremental_arquivos.zip` em `gmax-auto/` (lista no `MANIFESTO.txt`). **Não há migração.**
3. Recarregue com Ctrl+F5. O rodapé deve mostrar **3.2.2**.
4. O AutoLab continua como está. Não é preciso pausar, porque a instalação não muda nada do AutoLab. Se quiser cautela máxima, instale fora dos horários de 10:00 e 18:00.

## 5. Rollback

Restaure os arquivos da 3.2.1 (backup do passo 1). Não há banco a reverter. As preferências de filtro da fila ficam só na sessão do navegador.

## 6. Critérios objetivos de homologação

| # | Critério | Como verificar | Aceite |
|---|---|---|---|
| 1 | Rodapé 3.2.2 e todas as páginas abrem | Navegar pelo menu | Nenhum erro |
| 2 | Visão Geral: cada número abre a lista correspondente | Tocar em cada indicador e etapa | Lista filtrada com o mesmo total |
| 3 | Minha fila mostra o mesmo total que "Precisa de você agora" + "Prontos" | Comparar | Iguais |
| 4 | Resolver um preço desatualizado | Fila, Conferir agora, confirmo, Salvar e ir para o próximo | 4 interações; abre o próximo |
| 5 | Clique duplo ou duas abas | Aprovar na fila; repetir na outra aba | A segunda é recusada; uma aprovação na auditoria |
| 6 | Celular (Android e iPhone) | Barra inferior sempre visível; nada corta na lateral | Sim |
| 7 | Candidatos ML | Busca, segmento, páginas, "Abrir link oficial", duplicados | Funciona; nenhum preço inventado |
| 8 | AutoLab | Página mostra o estado, o último envio confirmado ou o aviso de "ainda não observado" | Coincide com o grupo do Telegram |
| 9 | Primeiro envio real do AutoLab | Conferir no grupo a mensagem "Ref. AutoLab …" | Mensagem rotulada TESTE ou SIMULAÇÃO, no horário |
| 10 | Travas comerciais | Sistema e Publicações | Pausado, aprovação automática desligada, nada enviado |
