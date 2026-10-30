# Instalação no painel GMAX (HostGator / cPanel)

> **Não instale antes da revisão** do ZIP (Codex/você). Nesta etapa o envio real fica **desligado** e nenhuma ação com clientes reais está autorizada.

A instalação **só acrescenta**:
- arquivos novos: a biblioteca privada e 3 páginas + assets em `/painel/`;
- 8 tabelas `gr_*`;
- 2 linhas no `index.html` (link do menu), aplicadas com conferência e cópia de segurança.

Ela **não** executa DROP, TRUNCATE, DELETE, ALTER ou seed em tabelas existentes. **Não** reimporta migrations antigas de pacotes. **Não** altera `api.php`, `app.js`, `config.php` do painel, `.htaccess` ou outros projetos.

Caminhos usados abaixo (confira no seu servidor):
- conta: `/home1/gust1922`;
- painel público: `/home1/gust1922/public_html/painel/`;
- config privado do painel: `/home1/gust1922/gmax-painel/private/config.php` (lido pelo `api.php`).

Requisitos: PHP 8.0+ com `pdo_mysql`, `curl`, `mbstring`.

## Passo 1 — Backup completo (obrigatório)

Siga `BACKUP_E_RECUPERACAO.md`, parte A: dump SQL **completo** do banco `gust1922_gmaxpainel` e ZIP da pasta `painel`. Guarde **fora** do servidor e **fora** do repositório/ZIP do módulo. Não continue sem esses dois arquivos.

## Passo 2 — Enviar os arquivos (não ativa nada)

Gerenciador de Arquivos do cPanel:
1. Envie o ZIP para `/home1/gust1922/` (acima de `public_html`) e extraia.
2. Mova `privado/gmax-renovacoes` para `/home1/gust1922/gmax-renovacoes`.
3. Confira que **não existem** em `public_html/painel/`: `renovacoes.php`, `renovacoes-api.php`, `renovacoes-webhook.php`, `renovacoes-caminho.php`, `renovacoes-assets/`. Se existir algum, pare.
4. Copie o **conteúdo** de `publico/painel/` para `public_html/painel/` (somente arquivos novos).

## Passo 3 — Configurar (sem segredos novos)

1. Em `/home1/gust1922/gmax-renovacoes/`, copie `config.example.php` → `config.php`, permissão **600**.
2. Confira `db.config_painel` = o mesmo caminho que aparece em `painel/api.php` (`$configPath`). O módulo lê `dsn`, `db_user` e `db_password` de lá — **não copie senha nenhuma**.
3. Deixe `auth` com os padrões do painel e `provedor.driver = 'simulado'`.

## Passo 4 — Diagnóstico e retrato ANTES (não altera nada)

No **Terminal** do cPanel:
```bash
cd ~/gmax-renovacoes
php bin/renovacoes-admin.php diagnostico                      # tabelas/colunas mapeadas, versão do PHP/MySQL
php bin/renovacoes-admin.php integridade ~/integridade-antes.json   # contagens + hashes + totais, sem dados pessoais
php bin/renovacoes-admin.php menu verificar ~/public_html/painel/index.html   # mostra a mudança do menu, não grava
```
O diagnóstico deve mostrar `clients`, `client_plans` e `client_exclusions` encontradas, com "mapeamento ok". Se aparecer FALHA, pare e me envie a saída. Ela lista só nomes de tabelas e colunas.

## Passo 5 — Migração (só tabelas gr_*) e retrato DEPOIS

```bash
php bin/renovacoes-admin.php migrar
php bin/renovacoes-admin.php integridade ~/integridade-depois.json
php bin/renovacoes-admin.php integridade-comparar ~/integridade-antes.json ~/integridade-depois.json
```
O resultado deve ser **"dados do painel preservados"**, com todas as tabelas IGUAL e os totais financeiros IGUAIS. Se aparecer qualquer DIFERENTE, **pare** e siga `BACKUP_E_RECUPERACAO.md`. Rodar `migrar` de novo não muda nada (pode repetir o comparativo para confirmar).

Sem Terminal: importe `sql/001_inicial.sql` no phpMyAdmin. Ele contém só `CREATE TABLE IF NOT EXISTS gr_*` e `INSERT IGNORE INTO gr_*`. Nesse caso, compare **contagem de linhas** das tabelas do painel antes e depois na aba Estrutura do phpMyAdmin.

## Passo 6 — Link no menu

```bash
php bin/renovacoes-admin.php menu aplicar ~/public_html/painel/index.html
```
O comando confere o arquivo atual, grava uma cópia `index.html.antes-renovacoes-AAAAMMDD-HHMMSS` e acrescenta o link. Para desfazer: `menu remover`. Detalhes e diff em `patch-painel/`.

Abra o painel (no iPhone, pelo atalho). No menu aparece **Renovações**, e a página abre com a faixa amarela **MODO SIMULAÇÃO**. A sessão é a mesma do painel: sair do painel encerra o acesso à aba, e 1 hora sem uso também.

## Passo 7 — Agendamento (Cron)

cPanel → Cron Jobs → "A cada 15 minutos" (`*/15 * * * *`):
```
/usr/local/bin/php /home1/gust1922/gmax-renovacoes/cron/renovacoes-cron.php >/dev/null 2>&1
```
- Use o PHP de **linha de comando** (`php`, não `php-cgi`). Se a versão for antiga, use o caminho do PHP 8 do cPanel (ex.: `/opt/cpanel/ea-php82/root/usr/bin/php`).
- O horário dos avisos (09:00, America/Sao_Paulo) é configurado na aba. O fuso do servidor não importa.
- Com o módulo **desativado** (padrão), o cron só registra a execução e não gera nada.
- Na aba Vencimentos, "último cron" deve mudar a cada 15 minutos.

## Passo 8 — Simulação (sem clientes reais)

1. Configurações:
   - confira os textos, o horário, a chave Pix e as instruções → **Salvar**;
   - **não** confirme o Pix ainda, se quiser manter o bloqueio duplo.
2. Os clientes existentes aparecem em **Autorizações** como **sem autorização**: importar não é consentir. Registre autorização somente de quem concordou de fato, com a origem.
3. Para ver a simulação, marque **Módulo ativo** (o modo continua **Simulação**). Mensagens aparecem no Histórico com a etiqueta **SIMULAÇÃO**. Nada sai do servidor e isso **não** prova entrega real.
4. Para desligar: desmarque Módulo ativo ou use Pausa global.

## Passo 9 — Envio real (somente em etapa futura, após sua autorização)

Pré-requisitos e passos: `WHATSAPP_API.md`. Resumo:
- credenciais no `config.php` do módulo, no servidor;
- 6 modelos aprovados;
- webhook configurado;
- Pix confirmado;
- Modo Real com "somente números de teste" marcado;
- teste com contato interno;
- só então desmarcar "somente números de teste".
