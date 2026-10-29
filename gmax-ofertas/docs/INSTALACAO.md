# Instalação no cPanel — GMAX Ofertas 3.0.2 P0 (atualização incremental)

> **Situação:** o pacote foi conferido com a cópia dos arquivos instalados na HostGator (29/10/2026). **Só instale quando você autorizar.** A instalação **adiciona** arquivos e não substitui nenhum existente. Nenhum envio ao Telegram é ativado em nenhum passo.

Tempo estimado: 20 minutos. Tudo pelo cPanel e pelo navegador.

## 0. O que será adicionado

```
/home1/gust1922/gmaxcanais.com.br/gmax-auto/
├── painel.php                    NOVO  painel multiloja (mesmo login do index.php)
├── gmax-admin-cli.php            NOVO  ferramenta de linha de comando (bloqueada na web)
├── gmax-diagnostico-esquema.php  NOVO  diagnóstico do banco (só estrutura, só CLI)
├── gmax-assets/                  NOVO  painel.css, painel.js
└── gmax-lib/                     NOVO  código interno (.htaccess bloqueia acesso web)
```

Continuam **intocados**: `app.php`, `index.php`, `cron.php`, `gmax-publicador-cli.php`, `mercadolivre.php`, `gmax-ml-importar-cli.php`, `.htaccess`, `storage/`, Crons, credenciais e `gmax-secrets/`.

O arquivo `opcional/gmax-publicador-cli.php` **não** é instalado agora (veja o passo 9).

## 1. Backup (obrigatório)

1. cPanel → **Gerenciador de Arquivos** → `/home1/gust1922/gmaxcanais.com.br/`.
2. Botão direito em `gmax-auto` → **Compress** → *Zip Archive* → `gmax-auto-backup-AAAAMMDD.zip`.
3. Mova o ZIP para `/home1/gust1922/gmax-secrets/`.
4. Abra o ZIP (botão direito → View) e confirme que ele tem `storage/offers.sqlite`.

## 2. Enviar os arquivos

1. Confirme que em `gmax-auto/` **não** existem `painel.php`, `gmax-admin-cli.php`, `gmax-diagnostico-esquema.php`, `gmax-lib` nem `gmax-assets`. Se existir algum, **pare**.
2. **Upload** de `GMAX_Ofertas_3.0.2_P0_arquivos.zip` para `gmax-auto/` → botão direito → **Extract** para `/home1/gust1922/gmaxcanais.com.br/gmax-auto/`.
3. Confira que os 5 itens novos estão direto em `gmax-auto/` e apague o ZIP enviado.

## 3. Diagnóstico do banco (só leitura, sem dados)

1. cPanel → **Cron Jobs** → adicione um Cron **temporário** para *Uma vez por minuto* (`* * * * *`):

   ```
   /usr/local/bin/php /home1/gust1922/gmaxcanais.com.br/gmax-auto/gmax-diagnostico-esquema.php > /home1/gust1922/gmax-secrets/gmax-esquema.txt 2>&1
   ```

2. Espere 1 a 2 minutos e **apague o Cron**.
3. Abra `gmax-secrets/gmax-esquema.txt`. Ele lista tabelas, colunas, quantidade de linhas e contagem por estado. **Não** contém títulos, links, preços, senhas nem tokens, e o banco é aberto em modo somente leitura.
4. Confira:
   - `SQLite` com versão **3.27 ou maior**;
   - `TABELA offers (5 linha(s))` e `TABELA gmax_ml_offers (1 linha(s))`;
   - `gmax-publicacao-privada.php  ausente`.
5. Se quiser, me envie o arquivo: ele foi feito para poder ser compartilhado.

## 4. Testar a proteção web

Cada endereço abaixo deve dar **403** ou abrir **em branco**:

- `https://gmaxcanais.com.br/gmax-auto/gmax-lib/reference-hashes.json` → **403**
- `https://gmaxcanais.com.br/gmax-auto/gmax-lib/pages/painel.php` → 403 ou em branco
- `https://gmaxcanais.com.br/gmax-auto/gmax-admin-cli.php` → "Apenas CLI"
- `https://gmaxcanais.com.br/gmax-auto/gmax-diagnostico-esquema.php` → "Apenas CLI"

Se o primeiro mostrar o conteúdo do arquivo, **pare** e me avise.

## 5. Verificação no painel

1. Abra `https://gmaxcanais.com.br/gmax-auto/painel.php` e entre com **a mesma senha** do painel atual.
2. Em **Sistema → Verificação do ambiente**, o esperado é:
   - **Nenhuma linha FALHA.** Se houver alguma, não migre.
   - `Arquivo index.php / cron.php / mercadolivre.php / gmax-ml-importar-cli.php / app.php / gmax-publicador-cli.php: idêntico à cópia de produção de 29/10/2026`. Se algum aparecer **DIFERENTE**, ele foi alterado depois da cópia: me avise antes de migrar.
   - **AVISO** `Publicador antigo … versão SEM pausa global…`. É **esperado**: a versão instalada é anterior ao V2.1. Não impede a migração (veja o passo 9).
   - `Ativação do publicador antigo: ausente` e `Ativação da fila 3.0: ausente`.
   - `cron.php em modo prévia: slot(db(), false) preservado`.

## 6. Migrar

1. Em Sistema, digite `MIGRAR` → **Fazer backup e migrar**.
2. O esperado é a mensagem com `{"shopee":5,"mercadolivre":1}` e o nome do backup (gravado em `gmax-secrets/gmax-backups/`).

## 7. Validação (critérios de aceite)

- [ ] **Visão geral**: Shopee **5**, Mercado Livre **1**.
- [ ] `index.php` lista as 5 ofertas Shopee e `mercadolivre.php` mostra a ML-001.
- [ ] **Ofertas** → ML-001: De R$ 299,90 / Por R$ 104,31 / **65% OFF** / "Preço não conferido".
- [ ] Os 5 links `s.shopee.com.br` e o `meli.la/1uPYV76` aparecem iguais aos do painel antigo.
- [ ] **Importar** → `GMAX_25_Anuncios_Individuais_MercadoLivre.xlsx` → **25 novos** → Confirmar. Reenviar o mesmo arquivo → **0 novos** → Descartar.
- [ ] **Publicações**: "Pausado" e "Automação comercial desativada"; os registros antigos de `gmax_dispatches` aparecem iguais.
- [ ] Os Crons existentes continuam como estavam (nada foi criado ou alterado).

## 8. (Opcional) Batimento do agendador, sem envio

Cron a cada 5 minutos, que **não envia e não altera a fila**:

```
/usr/local/bin/php /home1/gust1922/gmaxcanais.com.br/gmax-auto/gmax-admin-cli.php fila:simular > /dev/null 2>&1
```

## 9. (Opcional, só antes de uma ativação futura) Revisão 2.2 do publicador antigo

O `gmax-publicador-cli.php` instalado reenviaria a mesma oferta depois de um envio incerto **se** fosse ativado (veja `COMPARATIVO_PRODUCAO.md`). Enquanto continuar sem ativação, ele só simula. Para trocá-lo:

1. Renomeie o atual para `gmax-publicador-cli.php.bak-AAAAMMDD` (**não apague**).
2. Envie `opcional/gmax-publicador-cli.php` para `gmax-auto/`.
3. O Cron existente continua o mesmo (mesmo nome de arquivo). Em 5 minutos o log continua mostrando `simulacao_sem_envio` ou `fora_do_horario`.
4. Na página Sistema, a linha **Publicador antigo** passa a **OK (revisão 2.2)**.

Para desfazer, apague o novo e renomeie o `.bak` de volta.

## 10. O que continua exigindo sua autorização explícita

Nada disto é feito pelo pacote:

1. Escolher **um** publicador: a fila 3.0 **ou** o antigo (revisão 2.2). Nunca os dois.
2. Para a fila 3.0:
   - `gmax-admin-cli.php automacao --autorizar AUTORIZO_AUTOMACAO_COMERCIAL`;
   - criar `gmax-secrets/gmax3-publicacao-privada.php` com a frase `AUTORIZO_FILA_GMAX3_PRIVADA`;
   - aposentar o antigo (`publicador-v21 --aposentar CONFIRMO_V21_DESATIVADO`);
   - remover a pausa (`RETOMAR`);
   - criar o Cron de `fila:processar`.
3. Para o publicador antigo: instalar a revisão 2.2, criar `gmax-publicacao-privada.php` e remover a pausa no painel (a revisão 2.2 também respeita essa pausa).
4. Mercado Livre em grupo privado: só com `ml-grupo-privado --permitir CONFIRMO_REGRAS_ML_AFILIADOS`, depois de você conferir as regras do programa.
