# Instalação — GMAX Ofertas 3.0 P0 (atualização incremental)

> **Situação: PRÉVIA, AINDA NÃO LIBERADA PARA INSTALAÇÃO.** O pacote foi desenvolvido e testado com as versões de referência dos arquivos. A instalação **adiciona** arquivos e **não substitui** nenhum existente. Mesmo assim, só instale quando decidir, depois de ler este guia. Nenhum envio ao Telegram é ativado.

Tempo estimado: 15 a 20 minutos. Tudo é feito pelo cPanel e pelo navegador.

## 0. O que será adicionado (nada será substituído)

```
/home1/gust1922/gmaxcanais.com.br/gmax-auto/
├── painel.php            NOVO  painel multiloja (mesmo login do index.php)
├── gmax-admin-cli.php    NOVO  ferramenta de linha de comando (bloqueada na web)
├── gmax-assets/          NOVO  painel.css, painel.js
└── gmax-lib/             NOVO  código interno (.htaccess bloqueia acesso web)
```

Continuam **intocados**: `app.php`, `index.php`, `cron.php`, `gmax-publicador-cli.php`, `mercadolivre.php`, `gmax-ml-importar-cli.php`, `.htaccess`, `storage/`, Crons, credenciais e `gmax-secrets/`.

## 1. Backup antes de tudo (obrigatório)

1. cPanel → **Gerenciador de Arquivos** → abra `/home1/gust1922/gmaxcanais.com.br/`.
2. Clique com o botão direito em `gmax-auto` → **Compress** → *Zip Archive* → nome `gmax-auto-backup-AAAAMMDD.zip`.
3. Mova esse ZIP para `/home1/gust1922/gmax-secrets/` (fora da área pública).
4. Confira se o ZIP tem a pasta `storage/` com o arquivo `.sqlite`.

> O painel também faz um backup verificado do banco antes da migração, mas este primeiro backup cobre **todos** os arquivos.

## 2. Confirmar que não há conflito de nomes

Em `gmax-auto/`, confirme que **não** existem `painel.php`, `gmax-admin-cli.php`, `gmax-lib` nem `gmax-assets`. Se algum existir, **pare** e me avise.

## 3. Enviar os arquivos

1. Em `gmax-auto/`, clique em **Upload** e envie `GMAX_Ofertas_3.0_P0_arquivos.zip` (está dentro do pacote).
2. Clique com o botão direito no ZIP → **Extract** → destino `/home1/gust1922/gmaxcanais.com.br/gmax-auto/`.
3. Confirme que apareceram `painel.php`, `gmax-admin-cli.php`, `gmax-lib/` e `gmax-assets/` direto em `gmax-auto/`. Se tiverem caído numa subpasta, mova o conteúdo para `gmax-auto/`.
4. Apague o ZIP enviado.
5. Permissões: pastas `0755`, arquivos `0644`. O pacote não exige nenhuma permissão especial.

## 4. Testar a proteção (30 segundos)

No navegador, abra estes endereços. Cada um deve ser **bloqueado** (erro 403) ou aparecer **em branco**:

- `https://gmaxcanais.com.br/gmax-auto/gmax-lib/reference-hashes.json` → **403**
- `https://gmaxcanais.com.br/gmax-auto/gmax-lib/pages/painel.php` → 403 ou em branco
- `https://gmaxcanais.com.br/gmax-auto/gmax-admin-cli.php` → "Apenas CLI" (403)

Se o primeiro endereço mostrar o conteúdo do arquivo, o servidor não está lendo o `.htaccess` da subpasta. **Pare** e me avise; não é perigoso para os dados, mas precisa de ajuste.

## 5. Verificação do ambiente (somente leitura)

1. Abra `https://gmaxcanais.com.br/gmax-auto/painel.php` e entre com **a mesma senha** do painel atual.
2. Você vai cair em **Sistema → Migração pendente**. Leia a tabela **Verificação do ambiente**:
   - **FALHA** em qualquer linha → **não migre**. Me envie um print da tabela (ela não mostra senhas nem tokens).
   - **AVISO** → leia o detalhe. É comum ver "Arquivo … DIFERENTE da referência do kit": significa só que o servidor tem uma versão mais nova. A 3.0 não substitui esses arquivos.
3. Confirme estas três linhas:
   - **Ativação do publicador V2.1: ausente (bloqueado)** e **Ativação da fila 3.0: ausente (bloqueado)**
   - **cron.php em modo prévia: slot(db(), false) preservado**
   - **Pendências de reconciliação de envios: nenhuma**

## 6. Migrar

1. Ainda em Sistema, digite `MIGRAR` e clique em **Fazer backup e migrar**.
2. A mensagem de sucesso mostra o nome do backup e quantos produtos foram copiados. O esperado é `{"shopee":5,"mercadolivre":1}`.
3. O backup fica em `/home1/gust1922/gmax-secrets/gmax-backups/`.

## 7. Validação (critérios de aceite)

Marque cada item:

- [ ] **Visão geral** mostra **Shopee 5** e **Mercado Livre 1**.
- [ ] `index.php` continua listando as 5 ofertas Shopee, e `mercadolivre.php` continua mostrando a parafusadeira (ML-001).
- [ ] **Ofertas** → filtro Mercado Livre → ML-001 com **De R$ 299,90 / Por R$ 104,31 / 65% OFF** e "Preço não conferido".
- [ ] **Importar** → envie `GMAX_25_Anuncios_Individuais_MercadoLivre.xlsx` → prévia com **25 novos** → **Confirmar**.
- [ ] Envie o **mesmo arquivo de novo** → prévia com **0 novos / 25 sem alteração** → descarte a prévia.
- [ ] **Links afiliados** → cole um link `meli.la` gerado no programa oficial em um dos 25 → o produto sai de rascunho.
- [ ] Abra um produto → digite os preços → veja o **% OFF e a economia** calculados na hora → confira a **Prévia da mensagem**.
- [ ] **Publicações** → "Pausado" + "Automação comercial desativada". O botão **Pausa emergencial** fica no topo de todas as telas.
- [ ] Na tabela `gmax_dispatches` (vista em Publicações), os registros do V2.1 continuam iguais.

Pronto: o painel está em uso, e **nenhum envio foi habilitado**.

## 8. (Opcional) Monitoramento sem envio

Para a página Publicações mostrar o "batimento" do agendador, crie no cPanel → **Cron Jobs** (a cada 5 minutos):

```
/usr/local/bin/php /home1/gust1922/gmaxcanais.com.br/gmax-auto/gmax-admin-cli.php fila:simular > /dev/null 2>&1
```

`fila:simular` **não envia nada e não altera a fila**: só registra a hora da execução e mostra o que seria publicado.

**Não crie** Cron de `fila:processar`. Isso só deve acontecer depois de uma autorização separada e explícita (veja abaixo).

## 9. O que continua exigindo sua autorização explícita

Nada disto é feito pelo pacote, e cada item precisa de uma ação deliberada sua:

1. `php gmax-admin-cli.php automacao --autorizar AUTORIZO_AUTOMACAO_COMERCIAL`
2. Criar `gmax-secrets/gmax3-publicacao-privada.php`, com `enabled => true`, `chat_id => '-1004443626419'` e `authorization => 'AUTORIZO_FILA_GMAX3_PRIVADA'`. Esse arquivo é **diferente** do arquivo do V2.1. A fila 3.0 se recusa a operar enquanto o arquivo do V2.1 (`gmax-publicacao-privada.php`) existir.
3. Decidir qual publicador fica ativo. Os itens Shopee que o V2.1 ainda tem como `pendente` ficam bloqueados no 3.0 até você registrar que o V2.1 foi desativado: `php gmax-admin-cli.php publicador-v21 --aposentar CONFIRMO_V21_DESATIVADO`. Depois disso, **nunca** reative o V2.1.
4. Remover a pausa no painel (digitando `RETOMAR`)
5. Criar o Cron de `fila:processar`
6. Para Mercado Livre em grupo privado: `ml-grupo-privado --permitir CONFIRMO_REGRAS_ML_AFILIADOS`, **somente** se as regras do programa permitirem

A pausa emergencial do painel também cria `gmax-secrets/gmax-publicacao.pause`, que o V2.1 respeita.

Para executar comandos CLI sem SSH, use um **Cron temporário** (como já é feito hoje), redirecionando a saída para um log em `gmax-secrets/`, e remova o Cron logo depois.
