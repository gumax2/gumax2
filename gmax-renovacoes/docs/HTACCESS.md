# `.htaccess`: diagnóstico e liberação mínima (evitar o erro 403)

## O problema

Anteriormente, uma regra no `.htaccess` bloqueava todos os arquivos PHP e, depois da correção, passou a liberar só o `api.php`. Se essa regra continuar ativa, o Apache responderá **403** para os três arquivos novos:

- `renovacoes.php` (a aba);
- `renovacoes-api.php` (dados da aba);
- `renovacoes-webhook.php` (callbacks da Meta).

O 403 vem do servidor, antes de o módulo rodar. Por isso o módulo envia o cabeçalho `X-GMAX-Renovacoes: 1` em todas as respostas: **com** o cabeçalho, quem respondeu foi o módulo; **sem** ele, foi o servidor.

## O que a liberação faz e o que NÃO faz

- **Faz:** acrescenta um bloco marcado que libera **somente** os três nomes exatos (`^renovacoes(-api|-webhook)?\.php$`), na mesma forma da exceção que já existe para o `api.php`.
- **Não faz:**
  - não libera PHP em geral;
  - não remove nem altera as regras existentes;
  - não reativa outros arquivos. Nos testes, `suspeito.php`, `xrenovacoes.php`, `renovacoes-teste.php` e `renovacoes-caminho.php` continuaram bloqueados.
- **Segurança preservada:** as páginas continuam exigindo a sessão do painel (sem login: página → redireciona; API → 401). O webhook continua exigindo a assinatura `X-Hub-Signature-256` (sem assinatura ou com assinatura falsa → 401).

## Passo a passo (Terminal do cPanel)

Faça antes o backup da instalação (`BACKUP_E_RECUPERACAO.md`). O comando também guarda uma cópia do `.htaccess`.

### 1. Diagnóstico (só lê)
```bash
cd /home1/gust1922/gmax-renovacoes
php bin/renovacoes-admin.php htaccess verificar /home1/gust1922/gmaxcanais.com.br/painel
```
O comando procura `.htaccess` na pasta do painel e em até 3 pastas acima: `gmaxcanais.com.br/`, `/home1/gust1922/` etc. Para cada arquivo encontrado, informa:

- **"Nenhuma regra deste arquivo bloqueia os arquivos do módulo"**: nada a fazer.
- **"formato: Files"** ou **"formato: Rewrite"**: mostra o patch proposto e o comando para aplicar.
- **"formato: desconhecido"**: **não altera nada**. Envie o `.htaccess` (sem senhas) para análise.

Também é possível ver o arquivo com o Gerenciador de Arquivos do cPanel ("Mostrar arquivos ocultos").

### 2. Aplicar (somente se o passo 1 indicar)
```bash
php bin/renovacoes-admin.php htaccess aplicar /home1/gust1922/gmaxcanais.com.br/painel/.htaccess /home1/gust1922/gmaxcanais.com.br/painel
```
Use o caminho do `.htaccess` que o diagnóstico apontou, seja o da pasta do painel ou o da raiz `gmaxcanais.com.br/.htaccess`. O comando faz o seguinte:

1. grava a cópia `.htaccess.antes-renovacoes-AAAAMMDD-HHMMSS`, conferida por hash;
2. grava o novo arquivo de forma atômica, mantendo as permissões;
3. mostra exatamente as linhas acrescentadas.

### 3. Conferir pela web
```bash
php bin/renovacoes-admin.php verificar-http https://gmaxcanais.com.br/painel/
```
Todos os itens devem dar **OK**:
- página sem sessão redireciona;
- API sem sessão → 401;
- webhook sem token → 403 do módulo;
- webhook sem assinatura ou com assinatura falsa → 401;
- `api.php` continua respondendo;
- um PHP qualquer continua bloqueado.

Se algum item disser "bloqueado pelo servidor", a regra ainda está ativa. Nesse caso, desfaça (passo 4) e me envie a saída.

### 4. Desfazer
```bash
php bin/renovacoes-admin.php htaccess remover /home1/gust1922/gmaxcanais.com.br/painel/.htaccess
```
Remove só o bloco marcado e devolve o arquivo **byte a byte** ao original. Isso foi testado nos 4 formatos. Também guarda uma cópia antes de remover. Alternativa manual: restaurar a cópia `.htaccess.antes-renovacoes-*` pelo Gerenciador de Arquivos.

## Como fica o bloco (exemplos testados no Apache 2.4.58)

**Formato Files (Apache 2.4).** Copia a exceção do `api.php`, só trocando o nome:
```apache
<FilesMatch "\.php$">
  Require all denied
</FilesMatch>
<Files "api.php">
  Require all granted
</Files>
# BEGIN GMAX Renovacoes (liberar somente os 3 arquivos do modulo)
<FilesMatch "^renovacoes(-api|-webhook)?\.php$">
  Require all granted
</FilesMatch>
# END GMAX Renovacoes
```
**Formato Files antigo (`Order/Deny/Allow`):** igual, com `Order allow,deny` / `Allow from all`, copiados da exceção existente.

**Formato Rewrite.** Regra de passagem inserida **antes** da regra de bloqueio, ancorada na pasta do painel:
```apache
RewriteEngine On
# BEGIN GMAX Renovacoes (liberar somente os 3 arquivos do modulo)
RewriteRule ^renovacoes(-api|-webhook)?\.php$ - [L]
# END GMAX Renovacoes
RewriteCond %{REQUEST_URI} !/api\.php$
RewriteRule \.php$ - [F,L]
```
Quando o `.htaccess` está na raiz do domínio, a âncora vira `^painel/renovacoes(-api|-webhook)?\.php$`.

Observação sobre o formato Files: `<FilesMatch>` compara só o **nome** do arquivo, como já acontece com a exceção do `api.php`. Um arquivo com um desses três nomes exatos em uma subpasta também seria liberado; nenhum existe. O formato Rewrite fica ancorado na pasta `painel/`.

## Webhook e proteção por senha

Se a pasta `/painel/` usar senha HTTP (`AuthType Basic`), a Meta não alcançará o webhook. O diagnóstico não altera esse tipo de proteção. Nesse caso, me avise antes de configurar o webhook.
