# Alteração no painel existente (somente `index.html`)

A única mudança em arquivo existente do painel é **acrescentar** ao `painel/index.html`:

1. no `<head>`, depois de `plans.css`:
   ```html
   <link rel="stylesheet" href="renovacoes-assets/menu.css?v=1">
   ```
2. no menu, logo antes de `</nav>` (depois do botão "Configurações"):
   ```html
   <a class="nav gr-menu-link" href="renovacoes.php"><span>⏰</span>Renovações</a>
   ```

Não são alterados: `api.php`, `assets/app.js` (continua `?v=vencimentos-3`), `style.css`, `plans.css`, `pwa.js`, `manifest.webmanifest`, `.htaccess`. Como o `app.js` não muda, não existe o risco de um `index.html` novo carregar um `app.js` antigo do cache. O link é um `<a>` sem `data-page`, então o controlador de abas do `app.js` não o captura. A página `renovacoes.php` fica na mesma pasta e no mesmo escopo do PWA (`/painel/`).

**Como aplicar (recomendado):** o comando confere o arquivo REAL do servidor, mostra a diferença, faz cópia de segurança e só então grava:
```bash
php ~/gmax-renovacoes/bin/renovacoes-admin.php menu verificar ~/public_html/painel/index.html   # só mostra
php ~/gmax-renovacoes/bin/renovacoes-admin.php menu aplicar   ~/public_html/painel/index.html   # grava + backup
php ~/gmax-renovacoes/bin/renovacoes-admin.php menu remover   ~/public_html/painel/index.html   # desfaz
```
Se o `index.html` de produção for diferente do esperado (o ponto de inserção não aparecer exatamente uma vez), o comando **não altera nada** e avisa.

`index.html.diff` é o diff unificado sobre a base de 15/10 (aplicável com `patch`), para revisão.
