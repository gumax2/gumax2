# GMAX Ofertas 3.1.1: OAuth do Mercado Livre (somente leitura)

**Base:** 3.0.2 migrada. Este ZIP é cumulativo e já inclui a 3.0.3 e a 3.1.
**Banco:** nenhuma migração nova. Se a 3.1 ainda não estiver instalada, a migração 002 da 3.1 é aplicada pela tela Sistema.
**Envios e anúncios:** nada é publicado. Nenhum anúncio do Mercado Livre é criado ou alterado: o código só faz consultas `GET`.

## 1. O que foi verificado na 3.1 e o que mudou

| Pergunta | Na 3.1 | Na 3.1.1 |
|---|---|---|
| Endpoint de autorização e callback | **Não existia.** O conector lia um token fixo, que expira em cerca de 6 h | Botão **Conectar conta** (painel → Integrações) e callback `ml-oauth-callback.php` |
| PKCE S256 e state | Não | `code_verifier` aleatório de 86 caracteres e `code_challenge` em S256, testado com o vetor oficial da RFC 7636. `state` aleatório de 256 bits, de uso único, válido por 10 min e comparado em tempo constante. O callback exige o administrador logado na mesma sessão |
| Renovação e armazenamento | Não | Tokens **cifrados** (libsodium, chave derivada da Secret Key) em `gmax-secrets/mercadolivre-token.enc`, com permissão 0600 e gravação atômica |
| Rotação do refresh token | Não | Renovação automática 10 min antes de expirar. O refresh token novo substitui o antigo, e uma trava de arquivo impede que dois processos usem o mesmo refresh token. Uma resposta 401 da API provoca uma renovação e uma nova tentativa. Com `invalid_grant`, o sistema pede para reconectar; falha de rede não derruba a conexão |
| Segredos expostos | — | Nenhuma tela, log ou auditoria mostra a Secret Key ou os tokens. A tela de status mostra só os 4 últimos dígitos do usuário |

## 2. Redirect URI que deve ser cadastrada

O painel roda em `https://gmaxcanais.com.br/gmax-auto/`. A Redirect URI **recomendada** aponta direto para o arquivo de callback, sem exigir subdomínio novo nem regra de reescrita:

```
https://gmaxcanais.com.br/gmax-auto/ml-oauth-callback.php
```

**Troque** no Mercado Livre Developers a URI atual (`https://painel.gmaxcanais.com.br/oauth/callback`) por essa. A URI cadastrada precisa ser idêntica, caractere por caractere, à do arquivo `mercadolivre-app.php`. A tela **Integrações** mostra lado a lado a URI configurada e a URI do servidor.

> Manter `painel.gmaxcanais.com.br/oauth/callback` exigiria criar o subdomínio no cPanel, com SSL e apontando para `gmax-auto/`, além de uma regra de reescrita no `.htaccess` existente. O login também teria de ser feito pelo subdomínio, porque o cookie de sessão é por domínio. Não recomendo.

**Configuração do aplicativo "GMAX Smart Sales"**
- Authorization Code: ativado.
- Refresh Token: ativado. Ele concede o escopo `offline_access`.
- PKCE: ativado.
- Publicação e sincronização: **sem acesso** (correto para pesquisa e consulta de preços).

## 3. A pesquisa de produtos está disponível?

**Não dá para confirmar daqui.** O Mercado Livre restringiu a busca pública (`/sites/MLB/search`) para muitos aplicativos, que passaram a receber **403**. Depois de conectar, use **Integrações → Testar permissões**. O teste chama `/users/me` e uma busca mínima, sem alterar nada, e mostra:
- **Pesquisa: Disponível** → a descoberta automática do ML funciona.
- **Pesquisa: Não autorizado (HTTP 403)** → a descoberta do ML fica inativa, com alerta e sem raspagem nem dados inventados. A **revisão de preços** por anúncio (`/items/{id}`) continua funcionando para os produtos já cadastrados, desde que a conta responda.

## 4. Configuração na HostGator

1. **Backup:** painel → Sistema → Criar backup agora. Depois, compacte `painel.php`, `gmax-lib`, `gmax-assets` e os arquivos `gmax-*.php` em `gmax-secrets/gmax-arquivos-AAAAMMDD.zip`.
2. **Envio:** extraia `GMAX_Ofertas_3.1.1_incremental_arquivos.zip` em `/home1/gust1922/gmaxcanais.com.br/gmax-auto/` e confirme a extração. Se a 3.1 não estava instalada, aplique a migração (Sistema → `MIGRAR`).
3. **Credenciais do aplicativo:** crie você mesmo, pelo Gerenciador de Arquivos do cPanel. **Não envie esses valores por chat.**
   - Caminho: `/home1/gust1922/gmax-secrets/mercadolivre-app.php`
   - Conteúdo:

     ```php
     <?php
     return [
         'client_id' => 'SEU_APP_ID',
         'client_secret' => 'SUA_SECRET_KEY',
         'redirect_uri' => 'https://gmaxcanais.com.br/gmax-auto/ml-oauth-callback.php',
     ];
     ```

   - Permissão do arquivo: **0600** (botão direito → Change Permissions → só Read e Write do proprietário).
4. **Mercado Livre Developers:** cadastre a Redirect URI do item 2 e salve.
5. **Conectar:** painel → **Automações** → link **Integrações (OAuth)** → **Conectar conta do Mercado Livre** → autorize na tela do Mercado Livre. Você volta ao painel com a mensagem "Mercado Livre conectado".
6. **Testar:** clique em **Testar permissões** e anote o resultado da pesquisa.
7. **Proteção web:** `https://gmaxcanais.com.br/gmax-auto/gmax-lib/Gmax/OAuth/TokenStore.php` deve dar **403** ou abrir em branco. Os arquivos de `gmax-secrets/` ficam fora da área pública.
8. **Opcional, pela linha de comando** (Cron temporário, uma vez):

   ```
   /usr/local/bin/php /home1/gust1922/gmaxcanais.com.br/gmax-auto/gmax-automacoes-cli.php ml-oauth status > /home1/gust1922/gmax-secrets/ml-oauth.log 2>&1
   ```

   Também existem `ml-oauth testar` e `ml-oauth renovar`. Nenhum deles mostra tokens.

A renovação não precisa de Cron próprio. Ela acontece sozinha quando as automações ou o teste usam a API. Se a conta ficar meses sem uso e o refresh token expirar, a tela pede para **Reconectar**.

## 5. Rollback

| Ação | Como |
|---|---|
| Desconectar a conta | Integrações → Desconectar (digite `DESCONECTAR`). Isso apaga o token do servidor. Para revogar também no Mercado Livre, remova o aplicativo nas configurações de segurança da sua conta |
| Remover a 3.1.1 | Extraia o backup de arquivos do passo 1 e apague `ml-oauth-callback.php` e `gmax-lib/Gmax/OAuth/` |
| Credenciais | Apague `gmax-secrets/mercadolivre-app.php` e `mercadolivre-token.enc` |

Como não há migração nova, o banco não precisa de rollback para esta etapa.
