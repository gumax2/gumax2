# GMAX Ofertas 3.2.2.2 — conferência progressiva e persistente

**Base:** 3.2.2.1 instalada.
**Banco:** migração **007**, **aditiva**. Ela só **cria** três tabelas (rodadas, progresso por produto e histórico de visitas). Nenhuma coluna existente muda, nenhum produto é alterado ou reclassificado, e há backup automático antes. A reversão é própria.
**Não alterado:**
- SQLite existente, histórico de preços e auditoria;
- produtos e links;
- Shopee, OAuth do Mercado Livre e Cron;
- AutoLab: autorização, destino, limite e período;
- publicação comercial pausada e aprovação automática desligada.

## 1. Causa raiz (confirmada)

Reproduzi a situação da produção (`tests/progresso_rodada_test.php`, pelo servidor PHP real):

| | 3.2.2.1 (`16c8f4f`) | 3.2.2.2 |
|---|---|---|
| 89 produtos; 1ª leva | `7,8,9,10` | `7,8,9,10` |
| 2ª leva, depois de voltar à fila | **`7,8,9,10` de novo** → FALHA | `11,12,13,14` → passa |

Três causas somadas:
1. **"Conferir em sequência (4)" não percorria o catálogo.** Só entravam os produtos cuja ação principal era *conferir preço* (etapa Conferência): 4 das 89 pendências. Os outros 85 tinham pendências de outro tipo (link, aprovação…) e nunca entravam.
2. **A sequência vivia só na sessão.** Ao concluir, ou ao sair, nada ficava registrado sobre quais produtos já tinham sido examinados.
3. **Os 4 conferidos continuavam com outra pendência** (por exemplo, a variação), então permaneciam no topo da fila e a nova fotografia pegava os mesmos 4.

## 2. O que mudou

**Três conceitos separados** (nenhum interfere nos outros):

| | O que é | Onde vive |
|---|---|---|
| **A. Progresso da conferência** | Não analisado · Analisado nesta rodada · Pulado · Precisa de revisão | Tabelas novas da 007 |
| **B. Pendências comerciais** | Variação, preço, disponibilidade, link, condição, aprovação, inconsistências | Calculadas como antes |
| **C. Prontidão para divulgar** | Mesmas regras de sempre, sem nenhuma flexibilização | Calculada como antes |

Um produto **analisado pode continuar pendente e não estar pronto**. Analisar não aprova, não confirma preço e não altera nenhum dado do produto.

**Rodadas (persistentes no SQLite):**
- **Início explícito:** "Iniciar rodada de conferência" fotografa **todos os produtos ativos**, na ordem da fila: primeiro os que têm pendência, depois os demais.
- **Continuar conferência:** abre o **próximo ainda não analisado**. A busca é feita no SQL, sem carregar a lista inteira.
- **Na tela de conferência:**
  - indicador "Rodada #N · 24 de 89 analisados";
  - pendências comerciais e prontidão exibidas à parte;
  - botões: **Salvar conferência e ir para o próximo**, **Analisado, sem alterar → próximo**, **Pular por enquanto**, **Revisar depois**.
- **Tudo fica registrado no banco:** data, administrador e resultado de cada visita, em histórico somente-inclusão.
- **Sem depender da sessão:** logout, novo login, navegador fechado ou página atualizada não perdem nem repetem nada.
- **Fim:** "**Todos os produtos desta rodada foram analisados**". A rodada não recomeça sozinha.
- **Nova rodada** só por ação explícita (com confirmação). A anterior é encerrada e **fica no histórico**.

**Minha fila de trabalho:**
- painel da rodada com o progresso real e **Continuar conferência**;
- seções paginadas no SQLite: **Ainda não analisados**, **Analisados nesta rodada**, **Pulados**, **Precisam de revisão**, **Recém-chegados** e **Prontos para divulgar**;
- abaixo, as pendências comerciais de sempre.

**Produtos novos:**
- não alteram a rodada em andamento e aparecem em **Recém-chegados**;
- **"Incluir recém-chegados nesta rodada"** os coloca no fim (extensão controlada), ou podem entrar numa nova rodada.

**Revisões:**
- Se uma automação mudar um produto analisado, ele aparece em **"Precisam de revisão"** com o selo "mudou depois da análise". Contam como mudança:
  - preço com variação ≥ 1%;
  - disponibilidade;
  - variação reaberta;
  - link;
  - estado.
- O progresso **não é apagado** e o produto **não volta sozinho** aos não analisados.
- **Revisitar** registra uma nova visita, sem perder a original, e atualiza a referência.
- "Devolver pulados" e "Devolver os que precisam de revisão" são ações explícitas.

**Proteções:**
- **salvamento recusado:** você fica no mesmo produto, com os dados digitados e o progresso inalterado;
- **produto alterado em outra aba:** a gravação é recusada;
- **clique duplo:** uma visita registrada, e o próximo produto não é pulado;
- **formulário de rodada encerrada:** recusado;
- **concorrência:** uma única rodada aberta por administrador, garantida por índice único e transação.

**Removido:** a sequência só na sessão da 3.2.2.1 (`Sequence.php`). Se o arquivo continuar no servidor, não é usado.

## 3. Testes

| Teste | Prova |
|---|---|
| `tests/progresso_rodada_test.php` | **Falhava na 3.2.2.1 e passa agora**: cenário de produção com 89 produtos. A 2ª leva não repete nenhum dos 4 |
| `tests/loop_fila_test.php` | O ciclo da 3.2.2 continua corrigido (cada produto uma vez, com fim) |
| `tests/v3222_test.php` (8) | Os 89 entram e são analisados uma vez cada, com fim sem reinício. Logout e nova conexão continuam do mesmo ponto. Analisado ≠ validado ≠ pronto, com catálogo intacto. Pular, revisar depois, devolver e revisitar. Automação muda preço (1% como limite), disponibilidade ou variação: sinalizado, sem reinserir. Recém-chegados e extensão. Nova rodada com histórico, e exclusão bloqueada. **Concorrência real** com 6 processos (uma rodada aberta; registros consistentes). Migração 007 reversível, com catálogo intacto |
| `tests/http_test.php` | Pelo servidor real: início explícito, todos os ativos na rodada, indicador, **logout e novo login**, 2ª leva sem repetição, fim sem reinício, nada aprovado ou enfileirado. Recusa, clique duplo, atualizar a página, outra aba, pular, revisar depois, analisado sem alterar, atualização por API → "Precisam de revisão", revisitar, recém-chegados e extensão, rodada antiga recusada, histórico |
| `tests/ui_test.php` | 9 perfis: "Rodada #1 · 0 de 11" → "1 de 11" em 4 interações, e o painel com todas as seções |
| Regressão completa | Todas as suítes anteriores (AutoLab, retomada, OAuth, Shopee…) |

## 4. Instalação

1. **Backup:** em Sistema, clique em Criar backup agora e compacte a pasta `gmax-auto/`.
2. **Envio:** extraia `GMAX_Ofertas_3.2.2.2_incremental_arquivos.zip` em `gmax-auto/` (lista no `MANIFESTO.txt`).
3. **Migração:** em Sistema, digite `MIGRAR`. Isso aplica a 007, que só cria tabelas, com backup automático antes.
4. Recarregue com Ctrl+F5. O rodapé deve mostrar **3.2.2.2**.

## 5. Rollback

1. `php gmax-admin-cli.php reverter --confirmo` **uma vez**. Isso remove só as tabelas da 007, com backup antes. Os produtos não são tocados.
2. Restaure os arquivos da 3.2.2.1 (backup do passo 1).

O histórico de rodadas some com a reversão, mas continua no backup `antes-reverter-007`.

## 6. Homologação na HostGator

| # | Passo | Aceite |
|---|---|---|
| 1 | Minha fila → **Iniciar rodada de conferência** | "Rodada #1 · 0 de 89 analisados" (o total de produtos ativos) |
| 2 | Conferir 4 produtos (salvar ou "Analisado, sem alterar") | "4 de 89" |
| 3 | Voltar à fila e tocar **Continuar conferência** | Abre um produto **diferente** dos 4 |
| 4 | **Sair** (logout), entrar de novo e Continuar | Segue do 5º; nenhum dos 4 volta |
| 5 | Pular 1 e marcar "Revisar depois" em outro | Aparecem em **Pulados** e **Precisam de revisão**; não voltam sozinhos |
| 6 | Cadastrar ou importar um produto | Aparece em **Recém-chegados**; só entra com "Incluir recém-chegados" |
| 7 | Depois da revisão de preços automática | Analisados com preço alterado aparecem em "Precisam de revisão", com o selo |
| 8 | Ao terminar | "Todos os produtos desta rodada foram analisados"; nada recomeça sozinho |
| 9 | Pendências e prontidão | Produtos analisados com variação continuam pendentes; nenhum aprovado pela conferência |
| 10 | Travas | Publicação pausada, aprovação automática desligada, AutoLab inalterado |
