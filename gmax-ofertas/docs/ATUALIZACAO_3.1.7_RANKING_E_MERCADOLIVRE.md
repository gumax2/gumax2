# GMAX Ofertas 3.1.7: Ranking inteligente e diagnóstico do Mercado Livre

**Base:** 3.1.6 instalada. **Banco:** sem migração e sem tabela nova; o estado técnico fica em `gmax3_settings`.
**Não alterado:** produtos, links afiliados, histórico de preços, auditoria, histórico das automações, backups, Cron, OAuth do Mercado Livre (tokens e credenciais), Shopee e travas de publicação.
**Continua como está:** envios pausados e aprovação automática desligada.

## PARTE 1: Ranking inteligente

### Dois modos
| Modo | O que mostra |
|---|---|
| **Potencial comercial** | A pontuação de antes, sem mudança nos pesos nem no cálculo. Todos os produtos ativos aparecem, inclusive os não validados, cada um com a **principal pendência** e a quantidade total. Pontuação alta **não** significa autorização para publicar |
| **Prontas para divulgar** | Só produtos que passam em **todas** as regras de publicação |

**Regras do modo "Prontas para divulgar":**
- São as **mesmas** regras que bloqueiam a fila (`Rules::blockers` e `Rules::duplicate`), não uma lógica paralela.
- Isso cobre: produto aprovado; preço conferido dentro do prazo; link afiliado oficial; disponível; variação definida; condição de pagamento identificada; sem duplicidade nem conflito com o publicador antigo; permitido no destino. No grupo privado, a regra do programa de afiliados do Mercado Livre continua valendo.
- **Regra nova:** um preço "De" que **não foi confirmado** é pendência. Isso vale para um "De" importado de planilha ou para um "De" da Shopee, cuja API não informa preço original.
- O preço original **estimado** pelo % (3.1.6) nunca entra na pontuação, nos destaques nem nas mensagens. Ele aparece só nos detalhes, com o selo "Calculado ≈".

### Filtros (combináveis; "Limpar tudo" com um toque)
- Marketplace, categoria, estado e disponibilidade.
- Faixa de preço (de/até), pontuação mínima e confiança mínima.
- Conferência do preço (API, manual ou nunca), conferido nos últimos 24 h, 3, 7 ou 30 dias, e preço desatualizado.
- Link afiliado presente ou ausente, variação pendente, e pronto ou não para divulgar.
- **Ordenação:** pontuação, confiança, preço (do menor) ou última conferência (da mais recente).
- **Paginação:** 25 por página.

### Layout
- **Computador:** tabela compacta com as colunas posição, pontuação, confiança, produto, preço (sem quebra de linha, com o selo de origem do "De"), situação (pendências) e até 3 destaques curtos, como "65% OFF", "5 mil vendas", "4,8★", "Loja oficial" ou "Pix".
  - Ações por linha: conferir, abrir na loja, abrir link afiliado e **Aprovar**. O botão Aprovar só aparece quando todas as validações estão satisfeitas.
  - O link **Detalhes** abre a pontuação por critério e todas as pendências.
- **Celular:** cartão compacto com posição, produto, marketplace, pontuação, confiança, preço, desconto e origem do "De", situação da conferência, estado e principal pendência.
  - Ações rápidas: Conferência rápida, Editar, abrir na loja, abrir link afiliado, Detalhes e Aprovar, também só quando permitido.
- **Origem dos dados (topo da página):** Shopee usa a API de afiliados; Mercado Livre usa conferência manual, com consultas automáticas suspensas quando houver 403.

## PARTE 2: Investigação da API do Mercado Livre

### O que a pesquisa encontrou (fontes oficiais primeiro)
Não consegui abrir as páginas do Mercado Livre Developers deste ambiente, porque a rede de desenvolvimento bloqueia o domínio. As citações abaixo vêm dos trechos indexados dessas páginas. Confira a redação exata no navegador.

1. **Erro 403 documentado: PolicyAgent.** A página oficial de *Permissões funcionais* (developers.mercadolivre.com.br/permissoes-funcionais) descreve o erro 403 com `code: PA_UNAUTHORIZED_RESULT_FROM_POLICIES`, `blocked_by: PolicyAgent` e a mensagem "At least one policy returned UNAUTHORIZED". A correção indicada é **habilitar a permissão funcional** do grupo de recursos nas configurações do aplicativo, com escopo de leitura, e gerar um token novo.
2. **Página oficial "Erro 403"** (developers.mercadolivre.com.br/erro-403, atualizada em 02/04/2025). Ela lista como causas:
   - aplicativo bloqueado ou desativado;
   - usuário inativo ou com validação de dados pendente;
   - IP fora da lista permitida;
   - escopos errados no DevCenter;
   - token de outro usuário.
3. **Não encontrei anúncio oficial** de descontinuação de `/sites/MLB/search` nem de `/items/{id}`. A documentação "Itens e buscas" ainda descreve os dois.
4. **Relatos da comunidade (não oficiais, mas consistentes):** desde o início de 2026, desenvolvedores relatam 403 em `/sites/MLB/search` com token válido e `/users/me` funcionando, exatamente o seu caso. O Mercado Livre respondeu publicamente só que era "uma questão técnica de integração". Projetos de terceiros retiraram a busca por causa de "mudanças de política".
5. **Alternativas oficiais documentadas:** `/products/search?site_id=MLB&q=…` e `/products/{id}` (catálogo); `/items/{id}/sale_price` e `/items/{id}/prices`, que substituem os campos de preço de `/items`; multiget `/items?ids=…`; `/sites/MLB/domain_discovery`. Não está documentado se o PolicyAgent libera esses recursos para um aplicativo de leitura que não é o vendedor. **O diagnóstico novo testa cada um.**
6. **API do Programa de Afiliados:** **não encontrei API oficial** de geração de links ou de catálogo de afiliados. O processo oficial é manual, pela Central de Afiliados ou pela barra de afiliado na página do produto. A GMAX continua sem converter links do Mercado Livre.
7. **Limite (429):** é aplicado por Client ID e por endpoint. O recomendado é *backoff* exponencial e consolidar chamadas, por exemplo com multiget.

### Conclusão técnica
- **Não é falha de implementação.** O mesmo token e o mesmo cabeçalho `Authorization: Bearer` funcionam em `/users/me`, o fluxo OAuth com PKCE está correto e o token nunca vai na URL (isso agora é testado).
- **Causas mais prováveis, em ordem:**
  1. **Restrição da plataforma (PolicyAgent)** à pesquisa pública e à leitura de anúncios de outros vendedores por aplicativos genéricos.
  2. **Permissão funcional de leitura não habilitada** no DevCenter, ou token emitido antes de habilitá-la.
  3. Menos provável: lista de IPs, validação de dados da conta ou situação do aplicativo.
- **Para distinguir as causas**, use o novo **diagnóstico completo** no servidor:
  - se `/sites/MLB/search` e `/items/{id}` derem **403 com e sem token**, é restrição da plataforma;
  - se derem **200 sem token e 403 com token**, é autorização ou permissão do aplicativo;
  - se o multiget mostrar **404** para o item, o anúncio não existe ou foi removido.

**A integração não foi "corrigida"**, porque a negativa vem do Mercado Livre. O que foi corrigido e implementado: diagnóstico preciso, suspensão das consultas negadas, fluxo comercial completo sem a API e a solicitação ao suporte pronta.

### Diagnóstico completo (Integrações → Mercado Livre — diagnóstico dos recursos)
- **Testes, somente leitura e com o token nunca exibido:**
  - `/sites/MLB` sem token, como controle de rede e IP;
  - `/users/me`;
  - `/sites/MLB/search` **com e sem token**;
  - `/items/{MLB}` **com e sem token**;
  - multiget `/items?ids=`, com o código de cada item;
  - `/items/{MLB}/sale_price`;
  - `/products/search` e `/products/{id}`;
  - `/sites/MLB/domain_discovery`;
  - anúncios da própria conta (`/users/{id}/items/search` e `/items/{próprio}`).
- **Cada teste mostra:** endpoint, método, se usou token, código HTTP, resultado, mensagem da API (por exemplo `PA_UNAUTHORIZED_RESULT_FROM_POLICIES`), causa provável, orientação e o tipo: implementação, configuração, falta de autorização, restrição da plataforma ou anúncio indisponível.
- **No final:** conclusões automáticas, recomendações, histórico de testes e erros, e a **solicitação ao suporte pronta para copiar**, sem tokens nem segredos.
- **Pela linha de comando:** `gmax-automacoes-cli.php ml-oauth diagnostico MLB7519481690`.
- **Importante:** este ambiente de desenvolvimento não tem suas credenciais nem acesso à API, então **nenhum teste real foi executado**. Os testes automatizados usam respostas simuladas no formato documentado do PolicyAgent. O resultado real aparece quando você clicar em **Executar diagnóstico** no servidor.

### Suspensão automática de consultas negadas
- **Como funciona:** depois de **3 respostas HTTP 403 seguidas** (configurável de 2 a 20) num grupo de recursos — pesquisa, anúncios ou catálogo —, as automações **param de consultar esse grupo**.
  - Abre **um único alerta** e registra na auditoria.
  - A conta OAuth **não** é desconectada e os tokens não são tocados.
- **Revisão de preços:** com o grupo suspenso, a revisão registra o produto como "bloqueado — use a conferência manual", **sem contar erro**.
- **Descoberta:** escreve **uma** linha, em vez de um erro e um alerta por termo pesquisado.
- **Respostas que não contam:** 200 zera a contagem; 404 e 429 não entram na conta.
- **Reativação supervisionada:** Integrações → Reativar (digite REATIVAR), depois de liberada a permissão. O diagnóstico manual sempre consulta, mesmo com o grupo suspenso, e não altera a suspensão.
- **Desligar:** a suspensão pode ser desativada na mesma tela.

### Fluxo comercial do Mercado Livre sem a API
Continuam funcionando:
- cadastro e importação de produtos;
- links afiliados em lote;
- **conferência manual**, com os dois modos de preço;
- validação de disponibilidade e ranking;
- identificação de preços desatualizados, mensagens e aprovação pelas regras.

Preços importados **não** são tratados como conferidos.

## 3. Testes realizados

| Suíte | Cobertura |
|---|---|
| `tests/ranking_ml_test.php` (11) | **Modos:** potencial com pontuação preservada; prontas com as mesmas regras da fila. **Exclusões:** preço desatualizado, sem link, variação, indisponível, "De" não confirmado, Mercado Livre no grupo privado. **Estimativa:** fora do "De", da pontuação e da mensagem. **Filtros, ordenação e paginação:** combinados. **Diagnóstico:** plataforma × autorização × 401 × 404 × 429 × rede, sem token no resultado nem na URL. **Suspensão:** sem novas requisições, um alerta, reativação manual, 200, 404 e 429, desativação, e revisão e descoberta sem erros repetidos |
| `tests/ui_test.php` (Chromium, 9 perfis emulados) | 17 páginas sem rolagem horizontal, incluindo os dois modos do Ranking com filtros. **Ranking:** cartões no celular; tabela com preço sem quebra no computador; modo "prontas" com explicação. **Integrações:** diagnóstico com conclusões e solicitação ao suporte. Mais os fluxos já existentes |
| Demais suítes | Regressão completa (resultado em `TESTES.md`), incluindo OAuth, Shopee, calculadora e conferência |

**Limitação:** interface testada por **emulação** no Chromium, não em aparelhos físicos. API do Mercado Livre testada só com respostas **simuladas**.

## 4. Instalação na HostGator
1. **Backup:** em Sistema, clique em **Criar backup agora**. Depois, compacte `painel.php`, `gmax-lib`, `gmax-assets` e `gmax-automacoes-cli.php`.
2. **Envio:** extraia `GMAX_Ofertas_3.1.7_incremental_arquivos.zip` em `/home1/gust1922/gmaxcanais.com.br/gmax-auto/`. A lista de arquivos está no `MANIFESTO.txt`. Não há migração.
3. **Recarregue:** Ctrl+F5. O rodapé deve mostrar **3.1.7**.
4. **Ranking:** abra o Ranking e confira os dois modos.
5. **Diagnóstico:** em Integrações → Mercado Livre, clique em **Executar diagnóstico**, informando por exemplo `MLB7519481690`. Leia as conclusões.
   - **Se der "Restrição da plataforma"**, siga as recomendações:
     1. Confira as permissões funcionais no DevCenter.
     2. Clique em **Reconectar conta**.
     3. Rode o diagnóstico de novo.
     4. Se o resultado continuar igual, copie a **solicitação ao suporte**.
   - **Antes de enviar a solicitação**, preencha o App ID: ele está no DevCenter e foi deixado de fora de propósito.

## 5. Rollback
Restaure o backup de arquivos do passo 1. Sem migração, o banco não precisa de nada. As chaves novas de `gmax3_settings` (`ml_endpoints_estado`, `ml_diagnostico_ultimo`, `ml_suspensao_403`, `ml_403_limite`) são ignoradas pela 3.1.6.
