# GMAX Ofertas 3.1.2: correção do botão "Conectar conta do Mercado Livre" (CSP)

**Base:** 3.1.1 instalada. **Arquivos:** 3, todos substituições. **Banco:** nenhuma alteração. **Credenciais, tokens, Cron, automações e envios:** não alterados.

## 1. Sintoma

No Edge, o botão ficava carregando e o console mostrava:

```
Sending form data to 'https://gmaxcanais.com.br/gmax-auto/painel.php' violates the following
Content Security Policy directive: "form-action 'self' https://auth.mercadolivre.com.br".
The request has been blocked.
```

No Chrome, o mesmo botão funcionava.

## 2. Causa raiz (reproduzida em teste)

1. O botão é um formulário `POST` para `painel.php`, que é o que garante o CSRF. A 3.1.1 respondia com **303** para `https://auth.mercadolivre.com.br/authorization?...`.
2. O Chromium, motor do Chrome e do Edge, aplica a regra `form-action` da CSP a **todos os redirecionamentos** que seguem um envio de formulário, e não só ao primeiro destino.
3. Quando o navegador **não está logado no Mercado Livre**, `auth.mercadolivre.com.br` redireciona para a tela de login em **outro host** (`www.mercadolivre.com.br/jms/...`). Esse host não está no `form-action`, e o navegador cancela a navegação.
4. Por privacidade, o navegador cita a URL de **origem** da cadeia na mensagem (`painel.php`), e não o host que foi bloqueado. Por isso o erro parece absurdo à primeira vista.
5. **Chrome × Edge:** o Chrome estava logado no Mercado Livre, então o autorizador não redirecionou para outro host e nada foi bloqueado. O Edge não estava logado e caiu no redirecionamento.

O teste de navegador reproduz exatamente a mensagem acima com o código da 3.1.1: 7 verificações falham. Com a 3.1.2, todas passam.

## 3. Correção (sem afrouxar a CSP)

- O `POST` com CSRF continua igual: ele cria o `state` (uso único, 10 min) e o PKCE S256 na sessão do administrador logado.
- Em vez do 303, o painel responde com uma **página intermediária** ("Redirecionando ao Mercado Livre…"). Ela navega até o endereço fixo de autorização de três formas: `location.replace` pelo `painel.js`, `<meta refresh>` como alternativa sem JavaScript, e um link visível "Continuar no Mercado Livre". Essa navegação é um **GET comum**, e não um envio de formulário, então a regra `form-action` não se aplica, mesmo que o Mercado Livre redirecione para o login.
- Antes de exibir o endereço, o código confere que ele começa com `https://auth.mercadolivre.com.br/authorization?`. Não existe redirecionamento aberto.
- A CSP do painel ficou **mais restrita**: `form-action 'self'`. O domínio do Mercado Livre saiu da lista porque nenhum formulário do painel envia mais dados para ele. Não há `form-action *`.
- O callback (`ml-oauth-callback.php`) não mudou. Ele exige login na mesma sessão, `state` válido e de uso único, e envia o `code_verifier` na troca do código pelo token.

**Domínio de autorização usado pelo código:** `https://auth.mercadolivre.com.br/authorization`, o autorizador do Brasil. A troca de token usa `https://api.mercadolibre.com/oauth/token`, feita pelo servidor, e não passa pelo navegador.

## 4. CSP duplicada

No código, cada resposta envia **uma** CSP: uma no `painel.php` e outra no callback. O `.htaccess` atual da produção não adiciona nenhuma. Para confirmar que a HostGator não injeta uma segunda política, faça este teste no próprio servidor:

- No Edge, abra F12 → **Rede** → clique em `painel.php` → **Cabeçalhos de resposta**.
- Deve existir **apenas uma** linha `content-security-policy`, com `form-action 'self'`.
- Se aparecer uma segunda política, o navegador aplica as duas, e vale a mais restrita. Nesse caso, anote o conteúdo e me envie, **sem cookies**.

## 5. Fontes externas (assunto separado)

O painel **não carrega nenhuma fonte externa**: não há Google Fonts nem `@font-face`, e o CSS usa só fontes do sistema. Mensagens como "Refused to load the font…" no console não têm relação com o botão travado. Elas vêm de uma destas fontes:

- **Extensões do navegador** que injetam fontes na página e são bloqueadas pela regra `default-src 'self'`. É o comportamento esperado; não libere.
- **Páginas do próprio Mercado Livre**, que têm CSP própria. Isso é independente do GMAX.

Nenhuma alteração foi feita por causa disso.

## 6. Instalação

1. **Backup:** compacte `painel.php`, `gmax-assets/painel.js` e `gmax-lib/bootstrap.php` em `gmax-secrets/gmax-arquivos-3.1.1-AAAAMMDD.zip`.
2. **Envio:** extraia `GMAX_Ofertas_3.1.2_incremental_arquivos.zip` em `/home1/gust1922/gmaxcanais.com.br/gmax-auto/` e confirme a substituição dos 3 arquivos.
3. **Recarregue sem cache:** no Edge, use Ctrl+F5 em `painel.php?p=integracoes`. Assim o navegador busca o `painel.js` novo.
4. **Teste:** clique em **Conectar conta do Mercado Livre**. Deve aparecer a tela de login ou de autorização do Mercado Livre, sem erro de CSP no console (F12).
5. **Versão:** o rodapé ou a tela Sistema deve mostrar **3.1.2**.

## 7. Rollback

Extraia o backup do passo 1. Nada mais precisa ser desfeito: não há banco, credenciais ou Cron envolvidos.
