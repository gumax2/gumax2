# GMAX Ofertas 3.1.6: calculadora de preços (dois modos)

**Base:** 3.1.5 instalada. **Banco:** sem migração e sem tabela nova.
**Não alterado:** produtos, links afiliados, histórico, integrações Shopee e Mercado Livre, Cron, travas de publicação. Os envios continuam pausados e a aprovação automática continua desligada.

## 1. Como funciona

A calculadora aparece em **Conferência rápida** e em **Editar produto**, no celular e no computador. Basta escolher o modo, preencher os valores, ver o resultado e salvar.

| Modo | Você informa | O sistema mostra | O que é gravado |
|---|---|---|---|
| **Preço original da loja** | Por e De, como aparecem na loja | % de desconto e economia em R$, em tempo real | Por e De digitados. Na conferência, ficam como **manuais** depois da sua confirmação |
| **Calcular pelo desconto %** | Por e o % exibido pela Shopee ou pelo Mercado Livre | Preço original **estimado** (≈), a faixa possível e a economia estimada, com o selo **CALCULADO — não confirmado** | Só o Por. A estimativa é registrada **à parte** e **nunca** vira o preço "De" |

- **Alternar o modo não apaga nada.** Os dois campos (De e %) continuam no formulário; o modo só mostra um ou outro.
- **Faixa da estimativa:** a loja arredonda o percentual. Uma etiqueta de 35% pode significar de 34,5% a 35,5%. Por isso a tela mostra a estimativa central e a faixa: para R$ 99,90 com 35%, o original fica em ≈ R$ 153,69, entre R$ 152,52 e R$ 154,88.
- **Validação em tempo real:** valores monetários no formato 119,90; percentual de 1 a 95, com até duas casas decimais (35 / 35,5 / 35,25). O desconto não pode ser negativo e o De não pode ser menor que o Por. O servidor repete todas as validações e faz as mesmas contas, só com números inteiros, então não há divergência de centavos entre a tela e o que é gravado.

## 2. Regras de segurança dos preços

1. **"De" confirmado pela API é preservado.** Se o preço original veio da API do Mercado Livre, o modo desconto **não** o substitui. A tela avisa e a conferência mantém o valor. A API da Shopee não informa preço original, então nenhum "De" da Shopee é tratado como confirmado pela API.
2. **Origem sempre visível:** os selos **API**, **Manual**, **Importado** (veio de planilha e ainda não foi conferido) e **Calculado ≈** aparecem ao lado do preço original.
3. **Confirmação manual explícita:** a conferência só é registrada depois que você marca "Conferi agora, na loja". Sem essa marcação nada é gravado, nem a estimativa.
4. **Estimativa nunca é confirmação:** um "De" calculado a partir de um percentual arredondado fica registrado com `confirmado: false`.
5. **Publicações:** as mensagens e o ranking usam só o preço original real. A estimativa nunca vai para esse campo, então não aparece como "De R$" nas publicações. No modo desconto, um "De" antigo não confirmado é retirado, porque o próprio modo indica que a loja não mostra o preço original. A exceção é o "De" da API, que é mantido.
6. **Estimativa vinculada ao preço:** se o Por mudar depois, a estimativa antiga deixa de ser exibida.

## 3. Testes realizados

| Suíte | Cobertura |
|---|---|
| `tests/calculadora_test.php` | Percentuais válidos e inválidos; estimativa exata ao centavo, com faixa; modo original; origem do "De"; modo desconto sem gravar o De; "De" da API preservado; nada gravado sem confirmação; estimativa invalidada pela mudança do Por; os dois modos no mesmo formulário; sem estilo em linha (CSP) |
| `tests/ui_test.php` (Chromium, iPhone emulado) | Desconto e economia ao vivo; **mesmos números da tela e do servidor** para 35%, 35,5% e 7%; % inválido sinalizado; alternância sem perda de valores; salvar no modo desconto; verificação no banco (Por salvo, De vazio, estimativa registrada, ausente da mensagem). Mais as 9 resoluções × 15 páginas sem rolagem horizontal, já com a calculadora |
| `tests/http_test.php` | Edição de produto nos dois modos, com % inválido recusado |
| Demais suítes | Regressão completa (resultado em `TESTES.md`) |

**Limitação:** a interface foi testada no Chromium, com **emulação** de iPhone e Android, e não em aparelhos físicos.

## 4. Instalação

1. **Backup:** em Sistema, clique em **Criar backup agora**. Depois, compacte `painel.php`, `gmax-lib` e `gmax-assets`.
2. **Envio:** extraia `GMAX_Ofertas_3.1.6_incremental_arquivos.zip` em `/home1/gust1922/gmaxcanais.com.br/gmax-auto/`. A lista de arquivos está no `MANIFESTO.txt`.
3. **Recarregue:** Ctrl+F5 no computador; no celular, feche e reabra o painel. O rodapé deve mostrar **3.1.6**.
4. **Teste rápido:**
   1. Abra **Conferência rápida** de um produto e escolha **Calcular pelo desconto %**.
   2. Digite o Por e o % e confira a estimativa.
   3. Volte para **Preço original da loja** e veja que os valores continuam lá.

## 5. Rollback

Restaure o backup de arquivos do passo 1. Sem migração, o banco não precisa de nada. As estimativas registradas ficam só na auditoria e são ignoradas pela 3.1.5.
