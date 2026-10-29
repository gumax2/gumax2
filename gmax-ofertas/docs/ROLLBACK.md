# Rollback — GMAX Ofertas 3.0 P0

Use do nível mais leve para o mais completo. Todos são feitos pelo cPanel.

## Nível A: desativar o painel novo (1 minuto, sem tocar no banco)

No Gerenciador de Arquivos, em `gmax-auto/`, apague (ou renomeie com `.off`):

- `painel.php`
- `gmax-admin-cli.php`
- `gmax-diagnostico-esquema.php`
- `gmax-lib/`
- `gmax-assets/`

Pronto. `index.php`, `mercadolivre.php`, `cron.php` e o publicador antigo continuam funcionando como antes. As tabelas `gmax3_*` ficam no banco, mas o sistema antigo não as usa.

## Nível B: remover as tabelas novas (mantendo os dados antigos)

Pelo Cron temporário (rode uma vez e **remova o Cron em seguida**):

```
/usr/local/bin/php /home1/gust1922/gmaxcanais.com.br/gmax-auto/gmax-admin-cli.php reverter --confirmo > /home1/gust1922/gmax-secrets/gmax-reverter.log 2>&1
```

O comando faz um backup antes e apaga **somente** as tabelas `gmax3_*`. Ele se recusa a apagar `offers`, `slots`, `users`, `gmax_ml_offers` e `gmax_dispatches`. Depois disso, faça o nível A.

## Nível C: restaurar o arquivo do banco inteiro

Use só se o banco tiver sido danificado.

1. Ative a pausa emergencial (se o painel abrir). Se houver algum Cron de envio, desative-o.
2. Em `gmax-auto/storage/`, renomeie `offers.sqlite` para `offers.sqlite.antes-rollback`.
3. Copie o backup desejado de `/home1/gust1922/gmax-secrets/gmax-backups/` (ex.: `gmax-AAAAMMDD-HHMMSS-antes-migracao-001.sqlite`) para `gmax-auto/storage/` e renomeie a cópia para `offers.sqlite`.
4. Permissão do arquivo: `0600`.
5. Abra `index.php` e confira as ofertas.

> Atenção: o nível C também desfaz registros **antigos** criados depois do backup (por exemplo, um envio do V2.1). Confira `gmax_dispatches` antes.

## Publicador antigo (se a revisão 2.2 do passo 9 foi instalada)

Em `gmax-auto/`, apague o `gmax-publicador-cli.php` novo e renomeie `gmax-publicador-cli.php.bak-AAAAMMDD` de volta para `gmax-publicador-cli.php`. O Cron não muda.

## Restauração parcial pelo painel (só dados do 3.0)

Em **Sistema → Backups → Restaurar dados do painel 3.0**, escolha o backup e digite `RESTAURAR`. Isso restaura só o catálogo, o histórico de preços, as categorias e os lotes. A fila de publicação, as configurações e a auditoria **não** voltam (um item publicado nunca volta a ser agendado), e a pausa é ativada ao final. Um backup do estado atual é feito antes.

## Desfazer só um lote importado

Em **Importar** → abra o lote → **Desfazer este lote**. Os produtos criados são removidos e os atualizados voltam ao estado anterior. Produtos alterados ou colocados na fila depois da importação são preservados e listados.

## Rollback do pacote completo (os arquivos)

Use o ZIP criado no passo 1 da instalação (`gmax-auto-backup-AAAAMMDD.zip`): extraia por cima, depois de mover a pasta atual para `gmax-auto.falha`.
