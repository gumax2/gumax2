# GMAX Ofertas 3.1.3: detalhes das execuções da Central de Automações

**Base:** 3.1.1 instalada. O pacote é cumulativo e já inclui a correção 3.1.2 do botão "Conectar" no Edge.
**Banco:** migração **003**, que só **cria** a tabela `gmax3_job_run_items`. Ela é aplicada pelo botão MIGRAR, com backup automático antes. Nenhuma tabela existente é alterada.
**Não alterado:** Cron, credenciais OAuth, tokens, travas de publicação, produtos Shopee. Nenhuma automação ou envio foi ativado.

## 1. Por que a execução #2 encontrou 5 e bloqueou 5

Reproduzi o banco do diagnóstico da HostGator (5 Shopee pendentes e o ML-001) e obtive **exatamente** os números da produção: 5 encontrados, 0 atualizados, 5 bloqueados e 0 erros.

| Produto | O que aconteceu | Motivo |
|---|---|---|
| 5 produtos **Shopee** (`aguardando_validacao`) | **Bloqueados, sem nenhuma requisição** | Não existe `gmax-secrets/shopee-api.php`, com as credenciais da API de afiliados Shopee. Sem integração, a revisão não consulta nada e não valida preço. Por isso há 0 erros: nenhuma chamada falhou, porque nenhuma chamada foi feita |
| **ML-001**, Parafusadeira 48V | **Nem entrou na revisão** | O produto não tem código de anúncio MLB. A `url_original` está vazia e só existe o link curto `https://meli.la/1uPYV76`. A consulta `/items/{id}` precisa do código MLB, e o sistema não deduz esse código a partir do link curto |

**Conclusão:** a conta OAuth do Mercado Livre conectada e o HTTP 403 da pesquisa `/sites/MLB/search` não tiveram influência na execução #2. Nenhuma chamada ao Mercado Livre foi feita, porque o único produto ML do catálogo não tem um código consultável.

### O que fazer
1. **ML-001:** abra o anúncio pelo link `meli.la` no navegador e copie a URL completa, que tem a forma `https://produto.mercadolivre.com.br/MLB-1234567890-...`. Cole essa URL no campo **URL original** do produto (Ofertas → produto #6 ou o código ML-001) e salve. A próxima revisão passa a consultar `/items/MLB…`.
2. **Testar `/items` separadamente da pesquisa:** em **Integrações → Consulta individual de anúncio (/items/{id})**, informe o código MLB e clique em **Testar /items**. O resultado mostra o código HTTP (200, 401, 403 ou 404), o preço e a situação, e nada é gravado no catálogo. Pelo Cron temporário, o equivalente é `gmax-automacoes-cli.php ml-oauth item MLB1234567890`.
   - **HTTP 200:** a revisão de preços do ML funciona, mesmo que a pesquisa dê 403.
   - **HTTP 403:** o aplicativo também não tem acesso ao recurso de anúncios, e a revisão do ML fica bloqueada com esse motivo registrado.
3. **Shopee:** enquanto as credenciais da API de afiliados não forem liberadas, os produtos Shopee continuam aparecendo como **bloqueados**, com esse motivo. O preço deve ser conferido manualmente.

## 2. O que foi implementado

1. **Botão "Ver detalhes"** em cada linha de **Automações → Histórico de tarefas**.
2. A página **Detalhes das execuções** mostra, para cada produto: nome, marketplace, identificador (Item Id ou `item:MLB…`), resultado e **motivo individual**.
3. **Consulta à API:** endpoint (por exemplo `GET /items/MLB…`), **código HTTP** e erro detalhado, como "Não autenticado (HTTP 401)…" ou "Acesso negado (HTTP 403)…". Tokens nunca são gravados nem exibidos, e as mensagens passam por uma limpeza que mascara `Bearer`, `access_token`, `APP_USR-…` e itens semelhantes.
4. **Preço anterior** (Por e De), **preço devolvido pela API**, situação e **horário da consulta**. Quando a consulta falha, a linha fica sem preço da API e **nenhuma validação é registrada**.
5. **Resultados possíveis:**
   - **Atualizado:** a API respondeu e algum dado mudou. A linha lista os campos alterados e diz se o preço foi confirmado.
   - **Validado:** a API confirmou o mesmo preço, sem mudanças.
   - **Sem alteração:** a API respondeu, mas não validou o preço. Por exemplo, devolveu uma faixa de preços entre variações ou o preço foi editado durante a revisão.
   - **Bloqueado:** não foi possível consultar, por falta de integração ou erro da API.
   - **Não processado:** o produto era elegível, mas o limite por execução ou o prazo foi atingido.
   - **Fora da revisão:** o produto não é elegível, com o motivo. Exemplos: estado "rascunho", sem código MLB, só link `meli.la`.
6. **Elegibilidade:** o resumo da execução informa quantos produtos eram elegíveis, quantos ficaram fora, quantos não foram processados, quantos foram consultados e quantos preços foram confirmados pela API.
7. **Filtros** por execução, produto (nome, #id, MLB ou Item Id), marketplace e resultado.
8. **Histórico persistente e auditável:** a tabela é somente-inclusão, e gatilhos do banco recusam qualquer `UPDATE` ou `DELETE` nas linhas. As execuções e dados anteriores não são alterados.

**Execução #2 (anterior à 3.1.3):** a versão 3.1.1 não gravava o detalhamento por produto. Por isso "Ver detalhes" mostra o resumo original e um aviso de que não dá para reconstruir o detalhamento sem inventar dados. Abaixo do aviso aparece uma **prévia da próxima revisão**, calculada sobre o catálogo atual sem chamar nenhuma API, com o motivo de cada produto.

## 3. Instalação

1. **Backup:** no painel, vá em Sistema → **Criar backup agora**. Depois, compacte `painel.php`, `gmax-lib`, `gmax-assets` e `gmax-automacoes-cli.php` em `gmax-secrets/gmax-arquivos-3.1.1-AAAAMMDD.zip`.
2. **Envio:** extraia `GMAX_Ofertas_3.1.3_incremental_arquivos.zip` em `/home1/gust1922/gmaxcanais.com.br/gmax-auto/` e confirme a substituição. A lista completa está no `MANIFESTO.txt`.
3. **Migração:** ao abrir o painel, ele mostra a tela **Sistema** com a migração **003** pendente. Digite `MIGRAR` e confirme. O painel cria um backup verificado antes e só cria a tabela nova. Até essa etapa, o `index.php`, o `cron.php` e o publicador antigo continuam funcionando normalmente.
4. **Recarregue sem cache** com Ctrl+F5.
5. **Conferência:**
   - Em Automações → Histórico de tarefas, cada linha deve ter **Ver detalhes**.
   - Abra a execução #2 e confira a prévia.
   - O rodapé deve mostrar **3.1.3**.
6. **Opcional:** depois de corrigir a URL do ML-001 (item 1.1), rode **manualmente** a "Revisão de preços e disponibilidade" e confira os detalhes da nova execução. Essa revisão não envia mensagens e não ativa automações. Ela pode atualizar preço ou disponibilidade apenas com os dados devolvidos pela API.

## 4. Rollback

| Ação | Como |
|---|---|
| Voltar os arquivos | Extraia o backup de arquivos do passo 1 |
| Remover a tabela nova | Opcional. Cron temporário, uma vez: `/usr/local/bin/php /home1/gust1922/gmaxcanais.com.br/gmax-auto/gmax-admin-cli.php reverter --confirmo`. Ele faz um backup e reverte **só a última** migração (003), removendo apenas `gmax3_job_run_items`. **Não rode duas vezes**: a segunda execução reverteria a 002 |

A versão 3.1.1 ignora a tabela `gmax3_job_run_items`. Se ela continuar no banco depois da volta dos arquivos, não causa problema.
