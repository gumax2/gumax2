# GMAX Ofertas 3.1.8: investigação do HTTP 403 do Mercado Livre e correções do conector

**Base:** 3.1.7 instalada. **Banco:** sem migração. **Não alterado:** credenciais, tokens, Shopee, Cron, histórico de preços, links afiliados e publicação. Os envios continuam pausados e a aprovação automática continua desligada.

> **Resumo honesto:** a 3.1.8 **não remove** o 403. A negativa vem do PolicyAgent do Mercado Livre. A 3.1.8 corrige duas falhas reais do conector e acrescenta os testes que mostram, com dados oficiais, o estado do aplicativo e os escopos do token. Com isso dá para fechar as hipóteses que ainda dependem de você ou do Mercado Livre.

## 1. Falhas reais encontradas no conector (corrigidas)

| Falha | Evidência | Correção |
|---|---|---|
| As requisições à API saíam **sem cabeçalho User-Agent** | `ini_get('user_agent')` vazio; o cliente HTTP não definia um (só o verificador de links tinha) | Todas as requisições enviam `User-Agent: GMAX-Ofertas/3.1.8 (PHP 8.3; integracao-oficial)`. Coberto por teste |
| O diagnóstico da 3.1.7 fazia `/sites/MLB` **só sem token** e sugeria "bloqueio de IP" | Nos seus resultados, `/sites/MLB` deu 403, mas `/users/me` e `domain_discovery` deram 200 **com token a partir da mesma HostGator** | O teste agora roda com e sem token. A conclusão passa a ser: "o acesso anônimo está fechado para esta origem; não é bloqueio geral do IP" |
| O multiget podia ser contado como fonte de preço disponível | O envelope responde 200 mesmo com o item negado (403) por dentro | Vale o código de cada item |

O User-Agent ausente é uma falha real, mas **não há evidência de que ele cause o 403**. Desenvolvedores relatam o mesmo 403 enviando User-Agent. Corrigir remove uma variável da investigação.

## 2. Testes novos do diagnóstico (somente leitura)

- **`GET /applications/{Client ID}`:** endpoint oficial de "Gerenciar aplicações". Mostra se o app está **ativo**, o **status de certificação**, o **limite de requisições por hora** e o modo sandbox.
- **`GET /users/{id}/applications`:** mostra os **escopos efetivamente concedidos** ao token (`read`, `write`, `offline_access`) e a data da autorização. Prova se a reconexão de fato trouxe as permissões novas.
- **`/sites/MLB` com token**, além do teste anônimo.
- **`/items/{id}/description`:** descrição de anúncio de terceiro. Um projeto open source atualizado em 02/11/2026 relata que ela é liberada mesmo quando o preço não é.
- **`/categories/{id}` e `/trends/MLB`:** apoio à descoberta, sem preço.
- **Conclusões novas:** aplicativo inativo, escopo "read" ausente, restrição específica a dados comerciais de terceiros e "nenhuma fonte oficial com preço".
- **Tempo:** a ação ganhou mais tempo de execução (≈ 18 chamadas espaçadas pelo limitador de requisições).

Nenhum token, segredo ou dado confidencial é exibido ou gravado. O Client ID não é segredo e entra apenas na solicitação ao suporte.

## 3. Instalação e rollback

**Instalação:**
1. Faça backup dos arquivos.
2. Extraia o ZIP de arquivos em `gmax-auto/`. A lista está no `MANIFESTO.txt`.
3. Recarregue com Ctrl+F5. O rodapé deve mostrar 3.1.8.
4. Em Integrações, clique em **Executar diagnóstico** com `MLB7519481690`.

**Rollback:** restaure o backup dos arquivos. O banco não precisa de nada.
