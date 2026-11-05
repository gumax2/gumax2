# GMAX Ofertas 3.1.9: candidatos do catálogo do Mercado Livre

**Base:** 3.1.8 instalada.
**Banco:** migração **004**, aplicada por você em Sistema → MIGRAR, com backup automático. Ela só **cria** a tabela `gmax3_ml_candidates` e a tarefa `candidatos_ml`, que fica **INATIVA**.
**Não alterado:** Shopee, OAuth, produtos, links afiliados, histórico, Cron e publicação. Os envios continuam pausados e a aprovação automática continua desligada.

## 1. Viabilidade

Os testes com respostas reais **não puderam ser executados deste ambiente**, por falta de acesso à HostGator e às credenciais, e porque a rede de desenvolvimento bloqueia `api.mercadolibre.com`.

Por isso, a 3.1.9 traz a **inspeção de campos no servidor**: Candidatos ML → *Inspecionar campos*, ou `gmax-automacoes-cli.php ml-oauth campos "fone de ouvido"`. Ela consulta, só para leitura, `/products/search`, `/products/{id}`, `domain_discovery`, `/categories/{id}`, `/trends/MLB` (geral e por categoria) e a descrição do anúncio. Grava **somente os nomes e tipos dos campos** e indicadores do tipo sim/não, sem valores nem token.

| Pergunta | Documentação oficial | Confirmação |
|---|---|---|
| 1. `/products/search` pesquisa por palavra-chave? | Sim: parâmetros `q`, `site_id` e `domain_id` | Indicador "Pesquisa por palavra-chave" na inspeção |
| 2. Os resultados trazem ID de catálogo, título, imagem e categoria? | `id`, `name`, `domain_id` e `pictures[]` documentados. Categoria via `domain_discovery`/`/categories` | Indicadores de imagem e campos listados |
| 3. Há preço e disponibilidade reais de anúncios? | `/products/{id}` traz `buy_box_winner`, o anúncio vencedor (`item_id`, `price`, `shipping`, `official_store_id`), que vem `null` sem vencedor | Indicadores "anúncio vencedor com preço" e "com ID MLB". Se vier `null` ou 403, **não há preço** |
| 4. Dá para associar o produto de catálogo a um anúncio sem endpoint bloqueado? | Sim, pelo `buy_box_winner.item_id` do próprio `/products/{id}` | O ID do anúncio é guardado **separado** do ID de catálogo |
| 5. Que dados alimentam o Ranking? | Categoria (potencial da categoria), frete grátis e loja oficial do vencedor | O preço só entra **depois da conferência manual** |
| 6. Como usar tendências e categorias? | `/trends/MLB` e `/trends/MLB/{categoria}` como termos de descoberta; `domain_discovery` para categorizar | Campos de `/trends` sem documentação oficial: leitura defensiva |

## 2. Como funciona

1. **Tarefa `candidatos_ml`** (inativa; rode pelo botão *Buscar candidatos agora*). Ela:
   - pesquisa no catálogo os termos de descoberta do Mercado Livre e as **tendências**, com origem e posição registradas;
   - consulta o detalhe dos produtos novos (até 15 por execução) e o nome da categoria;
   - respeita o limite de requisições e a **suspensão automática**: se `/products` responder 403 repetidamente, para de consultar.
2. **Candidatos ficam separados das ofertas:**
   - não entram no ranking de prontas, na fila nem nas mensagens;
   - a tela mostra o **ID de catálogo** e, à parte, o **ID do anúncio vencedor**;
   - o preço aparece só se a API informar o vencedor, com o selo "API catálogo · não conferido" e a data. Sem vencedor, nenhum preço é exibido.
3. **Imagens** só do CDN oficial (`*.mlstatic.com`, https). A CSP do painel foi ampliada **apenas** para imagens desse domínio.
4. **"Criar rascunho para conferência"** cria um **rascunho** no catálogo com:
   - ID `catalogo:MLB…` e link oficial do produto, se a API informar (domínio do Mercado Livre validado);
   - **sem link afiliado**: você gera o link na Central de Afiliados, como sempre;
   - o preço da API marcado como **não conferido** e **sem "De"**: o preço original informado pela API não vira preço anterior.

   O rascunho só pode ser divulgado depois do link oficial, da conferência manual e da aprovação, pelas mesmas regras de antes.

## 3. Testes

| Suíte | Cobertura |
|---|---|
| `tests/candidatos_ml_test.php` (9) | Leitura do catálogo: IDs válidos e imagem só do CDN. Vencedor e preço só como informados pela API, IDs separados, permalink no domínio oficial. Inspeção sem valores nem token. Tarefa: candidatos separados, catálogo intacto, sem duplicar, tendências. 403 repetido: suspende sem repetir consultas. Rascunho: ID de catálogo, sem link afiliado, preço não conferido, sem "De", fora das prontas. Descartar e filtros. Funciona sem a 004 e a 004 é reversível |
| `tests/ui_test.php` | Página Candidatos ML nos 9 perfis emulados (sem rolagem horizontal, CSP, avisos), mais as demais páginas |
| Demais suítes | Regressão completa (`TESTES.md`) |

Os testes usam **respostas simuladas** no formato documentado. O comportamento real depende da inspeção no servidor.

## 4. Instalação

1. **Backup:** em Sistema, clique em **Criar backup agora** e compacte os arquivos do painel.
2. **Envio:** extraia o ZIP de arquivos em `gmax-auto/`. A lista está no `MANIFESTO.txt`.
3. **Migração:** o painel abre em **Sistema**. Digite `MIGRAR` para aplicar a migração 004, que cria um backup antes.
4. **Recarregue:** Ctrl+F5. O rodapé deve mostrar 3.1.9.
5. **Inspeção:** em **Candidatos ML**, clique em **Inspecionar campos** e veja os indicadores. Se "Anúncio vencedor com preço" der "não", os candidatos terão só dados de catálogo, sem preço.
6. **Busca:** clique em **Buscar candidatos agora**.

## 5. Rollback

1. Restaure o backup de arquivos.
2. Se quiser remover a tabela de candidatos, rode `gmax-admin-cli.php reverter --confirmo` **uma vez**. Isso reverte só a migração 004.

A linha da tarefa `candidatos_ml` continua em `gmax3_jobs`, inativa e sem efeito.
