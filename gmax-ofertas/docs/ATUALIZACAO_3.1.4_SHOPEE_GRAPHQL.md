# GMAX Ofertas 3.1.4: correção da consulta Shopee Affiliate (GraphQL 10010 "wrong type")

**Base:** 3.1.3 instalada, com a migração 003 já aplicada. **Arquivos:** 7, todos substituições.
**Banco:** sem migração nova, nenhuma tabela alterada.
**Não alterado:** produtos, links afiliados, preços gravados, histórico de execuções, OAuth do Mercado Livre, credenciais, Cron, aprovação automática (continua desligada) e envios (continuam bloqueados).

## 1. Diagnóstico da execução #3

| Sintoma | Causa |
|---|---|
| `POST /graphql` → HTTP 200 com `errors`, código **10010**, mensagem `"wrong type"` | Na 3.1.3, a consulta de produto declarava `query($itemId: Int64)` e enviava `"variables": {"itemId": 22812345602}`, com o ID como **número JSON**. O escalar `Int64` da Shopee não aceita número em variável: ele exige texto (`"22812345602"`) ou o valor escrito diretamente na consulta. A API respondeu 10010 para os 5 produtos |
| 5 encontrados, 5 bloqueados, 5 erros, 0 preços confirmados | Comportamento correto diante do erro: nenhum preço foi gravado nem marcado como confirmado. O painel já tratava `errors` com HTTP 200 como falha, mas a mensagem gravada era genérica e não mostrava o código |

**Fontes da causa:** dois projetos independentes que usam a mesma API relatam o mesmo erro e a mesma correção, ambos depois de testar contra a API real:
- Projeto pricebuddy: o escalar `Int64` exige o valor como string no JSON; enviando número, a API responde "wrong type" (10010).
- Projeto rafaelnm90/shopee: a consulta foi recusada com "wrong type" porque a variável estava declarada como `Int64`; a correção foi colocar os números direto na consulta.

**Não consegui validar daqui contra a API real.** As credenciais ficam só no seu servidor, e o host `open-api.affiliate.shopee.com.br` é bloqueado pela rede deste ambiente de desenvolvimento. A validação é o passo 4 da instalação, pelo botão **Testar conexão Shopee** no servidor.

### Verificações feitas no conector

| Item | Situação |
|---|---|
| Endpoint | `https://open-api.affiliate.shopee.com.br/graphql`, com `POST` e `Content-Type: application/json` |
| Assinatura | `SHA256 Credential=<AppId>, Timestamp=<unix em segundos>, Signature=<sha256(AppId + Timestamp + Payload + Secret)>`, calculada sobre o **corpo exato enviado**. Estava correta e foi mantida; agora há teste que recalcula a assinatura |
| `itemId` e `shopId` (`Int64`) | **Corrigido.** Agora vão como **literal** na consulta, por exemplo `productOfferV2(itemId: 22812345602, shopId: 111112, limit: 1)`. Só dígitos validados entram no literal, então não há injeção. O `shopId` vem do link do produto (`/product/LOJA/ITEM` ou `-i.LOJA.ITEM`), quando existe. IDs longos não perdem precisão, porque não passam por número |
| Plano B | Se a API recusar o literal com 10010, o conector tenta **uma vez** com variáveis `Int64` em texto (`{"itemId": "22812345602"}`). O formato que funcionou aparece no teste do painel |
| `variables` vazio | O corpo não envia mais `"variables": []`; no JSON, `[]` é lista e não objeto |
| Campos pedidos | `itemId shopId productName priceMin priceMax commissionRate offerLink shopName`. `priceMin` e `priceMax` são texto; o preço só é usado quando os dois são iguais. Quando são diferentes, a faixa de variações fica registrada **sem confirmar** o preço |
| HTTP 200 com `errors` | Falha. Grava `GraphQL <código> (<significado>): "<mensagem da API>"`. Respostas sem `data`, com JSON inválido ou com `nodes` vazio também contam como falha |
| Produto inexistente | `nodes` vazio ou outro `itemId` gera: "Produto não encontrado na API (itemId …, shopId …)", sem preço |

**Códigos explicados no painel**, conforme a documentação pública da Shopee Affiliate; a mensagem original da API sempre acompanha:
- 10000: erro de sistema da Shopee.
- 10010: Parse Error (sintaxe, campo ou tipo inválido).
- 10020: falha de autenticação (assinatura, AppId ou Secret incorretos, ou relógio do servidor fora da janela aceita).
- 10030: limite de requisições excedido.
- 10035: sem permissão de acesso a esta API.

## 2. Novas funções (somente leitura, não alteram o catálogo)

- **Integrações → Shopee → Testar conexão Shopee.** Faz uma consulta mínima (`productOfferV2(keyword: "fone", limit: 1)`) e mostra HTTP, código GraphQL, mensagem e o relógio UTC do servidor, útil para diagnosticar 10020. Exibe só os 4 últimos dígitos do AppId e nunca o Secret.
- **Integrações → Shopee → Consultar produto.** Recebe o Item Id e, se quiser, o Shop Id, e mostra o resultado com nome, preço ou faixa, comissão, se a API informou link oficial e o formato aceito (`literal` ou `variaveis_texto`). Nada é gravado; o resultado fica só na sessão. A auditoria registra apenas HTTP, código e ok/falha.
- **CLI pelo Cron temporário:** `gmax-automacoes-cli.php shopee testar` e `gmax-automacoes-cli.php shopee item ITEM_ID [SHOP_ID]`.
- **Histórico de execuções:** o motivo passa a mostrar `HTTP 200, GraphQL 10010`, e a coluna de consulta traz a mensagem completa, com credenciais mascaradas.

## 3. Instalação

1. **Backup:** em Sistema, clique em **Criar backup agora**. Depois, compacte `painel.php`, `gmax-lib` e `gmax-automacoes-cli.php` em `gmax-secrets/gmax-arquivos-3.1.3-AAAAMMDD.zip`.
2. **Envio:** extraia `GMAX_Ofertas_3.1.4_incremental_arquivos.zip` em `/home1/gust1922/gmaxcanais.com.br/gmax-auto/` e confirme a substituição dos 7 arquivos listados no `MANIFESTO.txt`.
3. **Confira a versão** com Ctrl+F5: o rodapé deve mostrar **3.1.4**. Não há migração.
4. **Validação contra a API real**, que tem de ser feita no seu servidor:
   1. Integrações → **Testar conexão Shopee**. O esperado é "Conexão, assinatura e permissão OK".
      - **10020:** confira AppId e Secret no `shopee-api.php` e o relógio UTC mostrado.
      - **10035:** a conta de afiliado não tem acesso; peça liberação à Shopee.
   2. **Consultar produto** com um dos 5 Item Ids do catálogo (aparecem na lista). O esperado é um preço ou uma faixa e o formato `literal`.
   3. Se o resultado ainda for **10010** nos dois formatos, anote a mensagem exibida, que não contém segredos, e me envie. Isso indicaria outra divergência de schema, diferente desta.
5. **Opcional:** rode **manualmente** a "Revisão de preços e disponibilidade" e confira em **Ver detalhes**. Essa revisão não envia mensagens e só grava preço quando a API devolve preço único. A revisão não troca o link afiliado.

## 4. Rollback

Extraia o backup de arquivos do passo 1. Como não há migração, o banco não precisa de rollback. Os preços eventualmente confirmados pela nova revisão ficam no histórico de preços como confirmações normais da API.
