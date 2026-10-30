# Atualização incremental 3.0.3: links afiliados em lote

**Base:** GMAX Ofertas 3.0.2 já instalada e migrada (o rodapé do painel mostra `3.0.2-p0`).
**Banco:** **sem migração**. A atualização usa as tabelas de lotes que já existem.
**Envios:** nenhum habilitado. Cron e credenciais não são alterados.

## O que muda

| Arquivo em `gmax-auto/` | Situação |
|---|---|
| `gmax-lib/Gmax/LinkImporter.php` | **novo**: prévia, associação por ID do anúncio, desfazer e auditoria |
| `gmax-lib/Gmax/LinkVerifier.php` | **novo**: verifica para onde cada link `meli.la` leva |
| `gmax-lib/pages/lote_links.php` | **novo**: tela da prévia e do resultado do lote |
| `painel.php` | atualizado: ações de lote de links (com CSRF e login) |
| `gmax-lib/pages/links.php` | atualizado: botão **Importar links em lote** e histórico dos lotes |
| `gmax-lib/Gmax/Http.php` | atualizado: consulta de link sem seguir redirecionamento |
| `gmax-lib/Gmax/Importer.php` | atualizado: não opera lotes de links por engano |
| `gmax-lib/pages/importar.php`, `_layout_top.php` | atualizados: navegação |
| `gmax-lib/bootstrap.php` | versão `3.0.3-p0` |

Nenhum arquivo do sistema antigo (`app.php`, `index.php`, `cron.php`, publicador e outros) é tocado.

## Regras da associação

- **Casamento:** só por **ID do anúncio** (`MLB-…`) em produtos **Mercado Livre já cadastrados**. Nunca pela posição da linha ou só pelo nome. ID não cadastrado é recusado, e **nenhum produto é criado**.
- **O que muda no produto:** **só o link afiliado**, mais data de atualização e auditoria. Preço original, promocional, desconto, condição de pagamento e disponibilidade não mudam (teste automatizado compara tudo antes e depois).
- **Estado do produto:** rascunhos continuam rascunhos e nada é aprovado. Se um produto já aprovado tiver o link trocado, ele volta para "aguardando validação".
- **Shopee:** produtos Shopee nunca são alterados por este importador.
- **Bloqueios:** link fora do padrão `https://meli.la/…`, link repetido no arquivo, anúncio repetido no arquivo, link que já pertence a outro produto, nome sem nenhuma palavra em comum com o produto cadastrado e linha marcada como inválida na coluna `status_validacao`.
- **Link já existente:** trocar um link que já existe exige marcar **Substituir links existentes**.
- **Formato não basta:** o botão **Verificar destino dos links** faz o servidor consultar cada link curto, sem abrir a página nem publicar nada, e compara o ID do anúncio de destino com o cadastrado.
  - **Confere:** ok.
  - **Diverge** (outro anúncio ou link inexistente): **bloqueado**.
  - **Indeterminado** (sem resposta ou destino sem ID): fica marcado para você conferir.
- **Registro:** data, usuário (`admin`), contagens e cada link alterado ficam na auditoria (Sistema → Auditoria) e no próprio lote.
- **Desfazer:** restaura os links anteriores. Um produto editado depois do lote é preservado e aparece listado.

## Instalação na HostGator (cerca de 10 minutos)

1. **Backup do banco:** painel → **Sistema** → **Criar backup agora**. Anote o nome do arquivo gerado.
2. **Backup dos arquivos 3.0.2:** cPanel → Gerenciador de Arquivos → `gmax-auto/`. Selecione `painel.php`, `gmax-lib` e `gmax-assets` → **Compress** → `gmax-3.0.2-arquivos-AAAAMMDD.zip` → mova para `gmax-secrets/`.
3. **Envio:** faça upload de `GMAX_Ofertas_3.0.3_incremental_arquivos.zip` para `gmax-auto/` → **Extract** em `/home1/gust1922/gmaxcanais.com.br/gmax-auto/`. Isso sobrescreve os 7 arquivos da lista e adiciona os 3 novos. Depois apague o ZIP.
4. **Conferência:**
   - o rodapé do painel mostra **3.0.3-p0**;
   - **Sistema → Verificação** sem nenhuma FALHA;
   - `https://gmaxcanais.com.br/gmax-auto/gmax-lib/Gmax/LinkImporter.php` responde **403** ou abre em branco.

## Associar os 18 links

1. **Links afiliados** → **Importar links em lote** → escolha `GMAX_18_Links_Recebidos_Para_Associacao.csv` → **Importar links em lote (gerar prévia)**.
2. Na prévia, confira os contadores e a coluna **Observações**:
   - o esperado são **18 links novos** e 0 erros;
   - qualquer linha em vermelho mostra o motivo e **não é aplicada**.
3. (Recomendado) Clique em **Verificar destino dos links**. Linhas **Diverge** viram erro. Linhas **Indeterminado** podem ser conferidas abrindo o link.
4. Clique em **Associar links**. A mensagem mostra quantos links foram associados e o nome do backup feito antes.
5. **Validação:**
   - **Ofertas** → filtro Mercado Livre: os 18 produtos mostram o link "✓ oficial" e continuam como **Rascunho**;
   - **Links afiliados** → "Produtos sem link afiliado": restam **7** dos 25;
   - abra 2 ou 3 produtos e confira que o preço é o mesmo de antes;
   - **Publicações:** continua "Pausado", e a fila está vazia.

## Rollback

| O que desfazer | Como |
|---|---|
| Só a associação dos links | Abra o lote (Links afiliados → histórico) → **Desfazer: restaurar links anteriores** |
| Os arquivos da 3.0.3 | Extraia `gmax-3.0.2-arquivos-AAAAMMDD.zip` por cima e apague `gmax-lib/Gmax/LinkImporter.php`, `gmax-lib/Gmax/LinkVerifier.php` e `gmax-lib/pages/lote_links.php` |
| O banco inteiro (só em caso extremo) | `ROLLBACK.md`, nível C, com o backup do passo 1 |

Como não há mudança de esquema, voltar os arquivos para a 3.0.2 não exige nenhuma ação no banco. Os lotes de links já gravados continuam visíveis na tela Importar.
