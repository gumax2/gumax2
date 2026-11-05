# Solicitação ao suporte do Mercado Livre Developers (modelo)

> A versão preenchida automaticamente, com os códigos HTTP e as mensagens reais do último diagnóstico, aparece em **Integrações → Mercado Livre → Solicitação ao suporte** depois que você clica em **Executar diagnóstico** no servidor. Use este modelo só se precisar escrever à mão.
> **Não inclua** Secret Key, tokens, códigos de autorização ou senhas. O App ID não é segredo, mas deve ser copiado do DevCenter.

**Assunto:** GMAX Smart Sales — HTTP 403 (PolicyAgent) em recursos de leitura de anúncios (MLB)

**Aplicação:** GMAX Smart Sales — App ID: `[copiar do DevCenter]`
**Conta autorizada:** `[usuário / apelido]` — OAuth Authorization Code com PKCE S256; escopos concedidos: `[ver Integrações]`
**Uso pretendido:** somente leitura (GET) de preço, disponibilidade e dados públicos de anúncios, para conferir ofertas divulgadas pelo Programa de Afiliados. Não há publicação, alteração de anúncios nem venda pela aplicação.

**Resultados (data/hora: `[do diagnóstico]`):**

| Requisição | Com token | Sem token | Resposta da API |
|---|---|---|---|
| GET /users/me | 200 | — | — |
| GET /sites/MLB/search?q=… | 403 | `[ver diagnóstico]` | `PA_UNAUTHORIZED_RESULT_FROM_POLICIES` (PolicyAgent) |
| GET /items/MLB7519481690 | 403 | `[ver diagnóstico]` | `[ver diagnóstico]` |
| GET /items?ids=MLB7519481690 | `[ver diagnóstico]` | — | `[ver diagnóstico]` |
| GET /products/search?site_id=MLB&q=… | `[ver diagnóstico]` | — | `[ver diagnóstico]` |

**Perguntas:**
1. Os recursos `/sites/MLB/search` e `/items/{id}` de anúncios de **outros vendedores** estão restritos para este tipo de aplicação? Desde quando e por qual política?
2. Qual **permissão funcional**, escopo ou aprovação libera a leitura desses recursos? Existe formulário, certificação ou programa de parceiros?
3. Quais endpoints oficiais são recomendados para consultar **preço e disponibilidade** de anúncios de terceiros no uso de afiliados? Por exemplo `/products/{id}`, `/items/{id}/sale_price` e multiget.
4. Existe API oficial do **Programa de Afiliados** (geração de links ou catálogo de produtos)?
5. Há limite de requisições específico para esta aplicação?

Nenhum token, segredo ou dado pessoal sensível foi incluído nesta mensagem.
