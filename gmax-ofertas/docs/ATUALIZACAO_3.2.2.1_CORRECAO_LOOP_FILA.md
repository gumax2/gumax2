# GMAX Ofertas 3.2.2.1 — correção do ciclo na fila de trabalho

**Base:** 3.2.2 instalada.
**Banco:** **nenhuma migração.** O estado da sequência fica só na sessão do administrador: nada é gravado no banco além do que a conferência já gravava.
**Não alterado:**
- SQLite, histórico e auditoria;
- links afiliados, Shopee, OAuth do Mercado Livre e Cron;
- AutoLab: autorização, destino, limite e prazo;
- publicação comercial pausada e aprovação automática desligada.

## 1. Causa raiz (comprovada)

Na 3.2.2, "Salvar e ir para o próximo" calculava o próximo produto sobre a fila **reordenada depois de cada salvamento**. A lista de "próximos" também **dava a volta** para os itens anteriores. Não havia nenhum registro de "já visitado".

Um produto conferido que continua com outra pendência (por exemplo, a variação ainda não definida) permanece na etapa Conferência. Como a fila ordena por "preço conferido há mais tempo primeiro", o recém-salvo vai para o **fim** do grupo. Quando os demais acabam, ele volta a ser "o próximo", e o ciclo recomeça sem terminar.

**Reprodução:** `tests/loop_fila_test.php`, pelo servidor PHP real e sem JavaScript, com 10 produtos que continuam pendentes depois de conferidos:

| Versão | Produtos visitados | Resultado |
|---|---|---|
| 3.2.2 (commit `b30b0df`) | `7,8,…,16, 7,8,…,16, 13,…,16, 7,…` (30 visitas, 10 distintas, sem fim) | **FALHA** |
| 3.2.2.1 | `7,8,…,16` (10 visitas, 10 distintas) e "Conferência da sequência concluída" | **passa** |

O **mesmo teste** foi executado nas duas versões.

## 2. Correção: conferência sequencial

1. Na **Minha fila**, toque em **Conferir agora** num produto (a sequência começa nele) ou em **Conferir em sequência (N)** (todos os produtos a conferir dos filtros atuais).
2. O sistema tira uma **fotografia ordenada** dos IDs elegíveis naquele momento e a guarda na sessão.
3. Cada produto **salvo** ou **pulado** é registrado como **visitado nesta sequência** e não volta automaticamente, mesmo que continue com outra pendência.
   - **Visitado não é validado:** aprovação, preço e regras comerciais continuam exatamente como antes.
4. O próximo é sempre o **primeiro da fotografia ainda não visitado**.
   - Produtos que deixaram de precisar de conferência (resolvidos em outra aba ou por automação) e produtos arquivados são pulados e registrados.
   - **Produtos novos não entram** numa sequência já iniciada.
5. No fim aparece **"Conferência da sequência concluída: X conferido(s), Y pulado(s)…"**.

**Na tela de conferência:**
- indicador **"Conferência 12 de 89"**;
- **Salvar e ir para o próximo**;
- **Pular por enquanto**;
- **← Voltar ao anterior**, que só *revisita*: mostra "Revisitando", não reinsere o produto nem muda o progresso;
- **Encerrar sequência**.

**Na fila:**
- aviso **"Sequência em andamento"** com **Retomar sequência** (ao sair e voltar);
- no fim, **Revisitar pulados (N)**, que abre uma nova sequência só com eles;
- **Iniciar nova sequência**, que pede confirmação se houver uma em andamento.

**Proteções:**

| Situação | Comportamento |
|---|---|
| Salvamento recusado (falta "confirmo", dado inválido) | Fica no **mesmo** produto, com os dados digitados preservados e o progresso inalterado |
| Produto alterado em outra aba entre abrir e salvar | Recusado com explicação, no mesmo produto. Nada é gravado |
| Clique duplo ou reenvio do formulário | O segundo envio é ignorado ("já salvo nesta sequência"): uma única conferência na auditoria, e o próximo produto não é pulado |
| Atualizar a página | Mesmo produto e mesmo indicador |
| Formulário de uma sequência encerrada ou substituída | Salva o produto (regras normais), mas não "avança" numa sequência que não existe mais |
| Sair e voltar à fila | Retomar continua no próximo não visitado |

A lógica antiga de "próximo pela lista reordenada" foi **removida** do código (`Funnel::successors/pick/next`). Sem sequência, as ações da fila (Aprovar, Salvar link, Agendar, conferência rápida) voltam à **mesma posição** da lista, sem adivinhar o próximo.

## 3. Outras telas (investigadas)

| Tela | Ciclo? | Achado | Correção |
|---|---|---|---|
| Ofertas | Não | Retorno à lista filtrada e à âncora do produto já correto | — |
| Ranking | Não | "Aprovar" voltava à página inicial do Ranking, perdendo modo, filtros, página e posição | Volta ao mesmo modo, filtros e página, na âncora do produto |
| Candidatos ML | Não | "Descartar" voltava à página 1, sem busca nem filtros | Volta aos mesmos filtros e à mesma página |

Durante a regressão também apareceu (e foi corrigido **antes** da entrega) um erro meu da edição: "Aprovar" pela tela da oferta respondia "Ação desconhecida". A lista de ações foi conferida contra a 3.2.2: **todas** continuam presentes, mais as 5 novas da sequência.

## 4. Testes

| Teste | O que prova |
|---|---|
| `tests/loop_fila_test.php` | **Falhava na 3.2.2 e passa na 3.2.2.1**: 10 conferências seguidas sem repetição, com término |
| `tests/http_test.php`, sequência 1 | 10 produtos que continuam pendentes depois do salvamento, sem repetição. O aprovado tem o preço alterado, perde a aprovação pela regra comercial e muda de prioridade. A ordenação muda durante o processo. O filtro é respeitado (um produto de outra categoria não entra). Um produto novo durante a sequência não entra. Atualizar a página mantém a posição. "Concluída" no último. Nenhum produto aprovado ou enfileirado pela sequência. Variações continuam pendentes (visitado ≠ validado) |
| `tests/http_test.php`, sequência 2 | Salvamento recusado (dados preservados, sem avançar). Clique duplo (uma conferência, não pula o próximo). Alteração em outra aba (recusado). Resolvido em outra aba (pulado e registrado). Pular. Sair e Retomar. Voltar ao anterior (revisita sem reinserir). Último produto. Revisitar pulados. Encerrar. Formulário de sequência encerrada |
| `tests/http_test.php`, Ranking e Candidatos | Retorno ao mesmo modo, filtros e página |
| `tests/v322_test.php` | Unidade da sequência: fotografia, ordem, visitados, pulados, resolvidos, arquivados, produtos novos, progresso, revisitar pulados |
| `tests/ui_test.php` | Chromium, 9 perfis: "Conferência 1 de 4" → "2 de 4" em 4 interações; nada corta na tela |
| Regressão completa | Todas as suítes anteriores (AutoLab, retomada, OAuth, Shopee, candidatos…) |

Resultados completos em `TESTES.md`.

## 5. Instalação

1. **Backup:** em Sistema, clique em Criar backup agora e compacte a pasta `gmax-auto/`.
2. **Envio:** extraia `GMAX_Ofertas_3.2.2.1_incremental_arquivos.zip` em `gmax-auto/` (lista no `MANIFESTO.txt`). **Sem migração.**
3. Recarregue com Ctrl+F5. O rodapé deve mostrar **3.2.2.1**.
4. Se uma aba antiga estava no meio do fluxo da 3.2.2, abra **Minha fila** de novo e use **Conferir em sequência**.

## 6. Rollback

Restaure os arquivos da 3.2.2 (backup do passo 1). Não há banco a reverter. A sequência em andamento some com a sessão.

## 7. Critérios de aceite (no servidor)

| # | Critério | Aceite |
|---|---|---|
| 1 | Conferir em sequência uma fila com produtos que continuam com variação pendente | Cada produto aparece **uma vez**; no fim, "Conferência da sequência concluída" |
| 2 | Indicador | "Conferência k de N" avança de 1 em 1 |
| 3 | Clique duplo em Salvar | Uma conferência na auditoria; o próximo não é pulado |
| 4 | Fechar a aba no meio e voltar à fila | Aparece "Retomar sequência" e continua de onde parou |
| 5 | Pular um produto | Ele aparece em "Revisitar pulados" no fim |
| 6 | Voltar ao anterior | Mostra "Revisitando"; salvar volta ao próximo não visitado |
| 7 | Aprovar pelo Ranking com filtros | Volta ao mesmo modo, filtros e produto |
| 8 | Travas | Publicação pausada, aprovação automática desligada, AutoLab inalterado |
