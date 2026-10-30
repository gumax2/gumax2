# Instalação (HostGator / cPanel)

Requisitos: PHP **8.0 ou superior** com `pdo_mysql`, `curl`, `mbstring`; MySQL 5.7+ ou MariaDB 10.3+. O envio fica **desligado e em simulação** até você ativar.

> A instalação **só adiciona** arquivos e tabelas `gr_*`. Não substitui nenhum arquivo do painel, não altera o `.htaccess` e não toca nas tabelas de clientes, pacotes, recebimentos e custos.

## Passo 0 — Antes de tudo

1. Confira se já não existem em `public_html/painel/` arquivos com estes nomes: `renovacoes.php`, `renovacoes-api.php`, `renovacoes-webhook.php`, `renovacoes-caminho.php`, pasta `renovacoes-assets/`. Se existir algum, **pare** e me avise.
2. **Recomendado:** me envie os arquivos do painel (sem `config.php`, senhas, tokens ou banco de clientes) para eu conferir o mapeamento das tabelas e a chave de sessão do login (ver `PENDENCIAS.md`).

## Passo 1 — Backup

Siga `BACKUP_E_RECUPERACAO.md`, parte A (banco completo + pasta `painel`). Não pule.

## Passo 2 — Enviar os arquivos

No **Gerenciador de Arquivos** do cPanel:

1. Envie o ZIP para `/home/USUARIO/` (a pasta **acima** de `public_html`) e extraia.
2. Mova a pasta `privado/gmax-renovacoes` para `/home/USUARIO/gmax-renovacoes` (fora de `public_html`).
3. Copie **o conteúdo** de `publico/painel/` para `/home/USUARIO/public_html/painel/` (só arquivos novos).
4. Se a biblioteca **não** ficar em `/home/USUARIO/gmax-renovacoes`, crie `public_html/painel/renovacoes-caminho.php` com:
   ```php
   <?php return '/caminho/absoluto/para/gmax-renovacoes';
   ```

## Passo 3 — Configurar

1. Em `/home/USUARIO/gmax-renovacoes/`, copie `config.example.php` para `config.php`.
2. Preencha `db` (os mesmos dados de banco do painel).
3. Preencha `auth.chave_sessao`: é a chave que o login do painel grava em `$_SESSION` (procure por `$_SESSION['` no arquivo de login do painel). Se o painel usa `session_name(...)`, preencha `sessao_nome` também. **Vazio = ninguém entra na aba** (falha segura).
4. Ajuste `esquema` aos nomes reais das tabelas/colunas (clientes e pacotes). `status_ativos` = valores de status que recebem lembrete (o resto — pausado, arquivado — fica de fora).
5. Deixe `provedor.driver = 'simulado'` por enquanto.
6. Permissão do arquivo: **600** (clique direito → Alterar permissões).

## Passo 4 — Diagnóstico e migração

Pelo **Terminal** do cPanel (ou SSH):

```bash
php ~/gmax-renovacoes/bin/renovacoes-admin.php diagnostico   # só lê; mostra tabelas, colunas e falhas de mapeamento
php ~/gmax-renovacoes/bin/renovacoes-admin.php migrar        # cria as tabelas gr_* (pode rodar de novo sem problema)
```

Sem Terminal: importe `sql/001_inicial.sql` no **phpMyAdmin** (aba Importar). É o mesmo conteúdo e também pode ser reimportado.

Se o PHP da linha de comando for antigo, use o caminho do PHP 8 do cPanel, por exemplo `/opt/cpanel/ea-php82/root/usr/bin/php`.

## Passo 5 — Abrir a aba

Entre no painel normalmente e abra `https://gmaxcanais.com.br/painel/renovacoes.php`. Deve aparecer a faixa amarela **MODO SIMULAÇÃO**.

Link no menu do painel: acrescente no menu existente algo como
```html
<a href="renovacoes.php">Renovações</a>
```
Isso exige editar um arquivo do painel. Faço essa alteração quando receber o arquivo do menu, para preservar o restante. No iPhone, a página abre dentro do atalho (PWA), porque fica na mesma pasta `/painel/`.

## Passo 6 — Agendamento (Cron)

cPanel → **Cron Jobs** → configuração comum "A cada 15 minutos" (`*/15 * * * *`), comando:

```
/usr/local/bin/php /home/USUARIO/gmax-renovacoes/cron/renovacoes-cron.php >/dev/null 2>&1
```

- O horário dos lembretes (padrão **09:00**, fuso **America/Sao_Paulo**) é controlado na aba **Configurações**, então o fuso do servidor da HostGator não importa.
- Rodar a cada 15 min permite as novas tentativas de falhas temporárias e o envio para quem foi autorizado depois das 09h.
- Execuções repetidas ou simultâneas não duplicam mensagens.
- Na aba **Vencimentos** aparece "último cron": confirme que muda a cada 15 min.

## Passo 7 — Período de simulação (recomendado: alguns dias)

1. Aba **Configurações**: confira textos, horário, chave Pix e instruções → **Salvar** → marque **Módulo ativo** (ainda em Simulação) → Salvar.
2. Aba **Autorizações**: registre a autorização dos clientes que já concordaram.
3. Acompanhe **Vencimentos** e **Histórico**: as mensagens aparecem com a etiqueta **SIMULAÇÃO**. Nada sai do servidor.
4. Teste uma renovação: atualize o vencimento de um pacote no painel e veja o aviso pendente daquele pacote ser cancelado.

## Passo 8 — Envio real (depois da API e dos modelos aprovados — ver `WHATSAPP_API.md`)

1. No `config.php`: `provedor.driver = 'meta_cloud'` e preencha `phone_number_id`, `waba_id`, `access_token`, `app_secret`, `verify_token`.
2. No app da Meta, configure o webhook: `https://gmaxcanais.com.br/painel/renovacoes-webhook.php`, campo **messages**.
3. Aba **Configurações**:
   - preencha os **nomes dos 6 modelos aprovados**;
   - **confirme a chave Pix e as instruções** (botão de confirmação);
   - em **Teste com contato interno**, cadastre o seu número (ou de alguém da equipe que autorizou), mantenha marcado **"enviar SOMENTE para os números de teste"**;
   - Modo = **Real** → Salvar. A faixa fica verde: **ENVIO REAL ATIVO**.
4. **Primeiro teste real:** botão **Enviar mensagem de teste** (dados fictícios) para o número interno. Confira o recebimento e, no Histórico, a evolução *Aceito → Entregue → Lido*.
5. Opcional: crie um cliente de teste com o seu número, um pacote vencendo em 3 dias e autorização registrada; espere o cron das 09h (ou "Executar agora").
6. Liberar para clientes: desmarque **"enviar SOMENTE para os números de teste"** → Salvar.

Para parar tudo a qualquer momento: **Pausa global** (Configurações). Erros de credencial (token expirado) ativam a pausa global automaticamente.
