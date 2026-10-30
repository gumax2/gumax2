# Relatório de limitações das APIs e das automações (3.1)

## Mercado Livre

| Recurso | Situação | Efeito na GMAX |
|---|---|---|
| Busca de anúncios (`/sites/MLB/search`) | Exige token OAuth. Para aplicativos novos, o Mercado Livre restringiu o acesso, e a resposta pode ser **403** | A descoberta trata 401/403 como "busca não autorizada", abre um alerta e não grava nada. Não há raspagem de páginas |
| Consulta de anúncio (`/items/{id}`) | Exige token. Informa preço, preço original, situação e variações | A revisão de preços usa essa rota. Produtos de **catálogo** (`/p/MLB…`) não são consultados: exigem escolher o anúncio vencedor |
| Quantidade vendida | A busca pública deixou de informar o número exato | Só é gravada se a API devolver o campo. Caso contrário fica vazia e reduz a confiança do ranking |
| Reputação do vendedor | Vem da busca quando disponível (`power_seller_status`) ou da indicação "loja oficial" | Sem o dado, o critério fica "sem dado" |
| **Link afiliado** | **Não existe API autorizada de conversão** para o programa de afiliados | Os links continuam vindo do **Gerador oficial**, associados pela tela "Importar links em lote" (3.0.3). Produtos descobertos ficam como rascunho com o alerta "sem link" |
| Regras de divulgação | Links do Mercado Livre em **grupo privado** estão bloqueados por padrão | A seleção automática não coloca ofertas do ML na fila do Telegram privado até você liberar, depois de conferir os termos |

## Shopee

| Recurso | Situação | Efeito |
|---|---|---|
| Affiliate Open API (GraphQL) | **Aguardando liberação**. O conector já está pronto (assinatura SHA256, busca `productOfferV2`, consulta por item) | Sem `shopee-api.php`, a Central mostra "aguardando liberação" e as tarefas pulam a Shopee |
| Link afiliado | A própria API devolve `offerLink` (conversão **oficial**) | Aceito só se for `https://s.shopee.com.br/…` |
| Preço original | A API informa o **% de desconto**, mas não o preço "De" | O "De" fica vazio (não é calculado a partir do %). O desconto aparece como observação |
| Variações | `priceMin` ≠ `priceMax` | A oferta é marcada com "variação pendente" e fica bloqueada até alguém escolher a variação |
| Nomes de campos e endpoint | Seguem a documentação pública. **Confirmar com a documentação entregue junto com as credenciais** | Ajuste pontual no conector, se necessário |

## Amazon e AliExpress

Os dois estão **preparados na arquitetura** (interface `Discovery`, registro de conectores e Central), mas **sem integração**:
- **Amazon PA-API 5:** exige conta Associates aprovada, com vendas qualificadas.
- **AliExpress Affiliate API:** exige aplicativo aprovado no Open Platform.

Também é necessária uma migração para incluí-los no catálogo. Até lá, eles não fazem requisições nem simulam dados.

## Limites da hospedagem (HostGator) e como foram tratados

| Limite | Tratamento |
|---|---|
| Sem processos permanentes | Executor chamado pelo **Cron do cPanel** a cada 15 min, com prazo de **45 s** por chamada. O trabalho restante fica para a próxima chamada |
| `max_execution_time` | Prazo interno por tarefa. Uma execução interrompida pelo servidor é marcada como "falhou" na chamada seguinte |
| Execução simultânea | Trava de arquivo `gmax-automacoes.mutex`, separada da trava do publicador |
| Cron repetido ou atrasado | Cada horário roda **uma vez** (chave única tarefa + horário). Atraso de até 2 h é recuperado; acima disso, o horário é perdido e registrado |
| Saída de rede bloqueada | Toda falha de API vira erro contabilizado e alerta, nunca dado inventado |
| Limite de requisições | Padrão de 500 por dia por fonte e intervalo mínimo de 1,1 s entre chamadas. Falhas aparecem na Central |

## O que continua dependendo de você

1. **Credenciais:** token do Mercado Livre e liberação da Shopee.
2. **Links afiliados do Mercado Livre:** gerados no programa oficial e associados em lote.
3. **Aprovação:** manual por padrão. A automática exige autorização pela linha de comando.
4. **Envio real:** exige as 6 autorizações do item 10 de `INSTALACAO.md`, separadas desta atualização.
5. **Regras de divulgação de cada programa:** conferir antes de liberar o Mercado Livre em grupo privado ou qualquer canal público.
