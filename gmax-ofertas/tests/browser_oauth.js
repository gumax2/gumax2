'use strict';
/*
 * Teste de navegador (Chromium/Playwright) do botão "Conectar conta do Mercado Livre".
 * Os hosts do Mercado Livre são simulados por um servidor HTTPS local (--host-resolver-rules):
 * nenhuma requisição sai da máquina. Chamado por tests/http_test.php.
 * Uso: node tests/browser_oauth.js <base_url> <senha>
 * Saída: uma linha JSON por verificação {nome, ok, erro}.
 */
const https = require('https');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const [base, password] = process.argv.slice(2);
const results = [];
const check = (nome, ok, erro = '') => results.push({ nome, ok: !!ok, erro: ok ? '' : String(erro) });

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'gmax-ml-'));
execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', `${dir}/k.pem`, '-out', `${dir}/c.pem`, '-days', '1', '-subj', '/CN=ml',
  '-addext', 'subjectAltName=DNS:auth.mercadolivre.com.br,DNS:www.mercadolivre.com.br'], { stdio: 'ignore' });

// Cenário do autorizador: 'deslogado' manda para o login em outro host (caso do Edge);
// 'logado' devolve direto ao callback com code e o mesmo state recebido.
let scenario = 'deslogado';
let lastAuthQuery = null;
const ml = https.createServer({ key: fs.readFileSync(`${dir}/k.pem`), cert: fs.readFileSync(`${dir}/c.pem`) }, (req, res) => {
  const u = new URL(req.url, `https://${req.headers.host}`);
  if (req.headers.host.startsWith('auth.') && u.pathname === '/authorization') {
    lastAuthQuery = u.searchParams;
    const to = scenario === 'deslogado'
      ? 'https://www.mercadolivre.com.br/jms/mlb/lgz/login?go=' + encodeURIComponent(u.href)
      : `${base}/ml-oauth-callback.php?code=TG-abcdefgh123&state=${encodeURIComponent(u.searchParams.get('state'))}`;
    res.writeHead(302, { Location: to });
    return res.end();
  }
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end('<title>Login Mercado Livre (simulado)</title><p>login</p>');
});

async function connect(page) {
  await page.goto(`${base}/painel.php?p=integracoes`);
  await Promise.all([
    page.waitForURL(/mercadolivre\.com\.br|ml-oauth-callback|p=integracoes/, { timeout: 8000 }).catch(() => {}),
    page.click('button:has-text("Conectar conta do Mercado Livre")'),
  ]);
  await page.waitForLoadState('load').catch(() => {});
  await page.waitForTimeout(800);
}

(async () => {
  await new Promise((r) => ml.listen(0, '127.0.0.1', r));
  const port = ml.address().port;
  const browser = await chromium.launch({
    args: ['--no-proxy-server', `--host-resolver-rules=MAP auth.mercadolivre.com.br:443 127.0.0.1:${port}, MAP www.mercadolivre.com.br:443 127.0.0.1:${port}`],
  });
  try {
    for (const js of [true, false]) {
      const tag = js ? 'com JavaScript' : 'sem JavaScript (meta refresh)';
      const ctx = await browser.newContext({ ignoreHTTPSErrors: true, javaScriptEnabled: js });
      const page = await ctx.newPage();
      const csp = [];
      page.on('console', (m) => { if (/Content Security Policy/i.test(m.text())) csp.push(m.text()); });

      await page.goto(`${base}/painel.php`);
      await page.fill('#password', password);
      await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

      scenario = 'deslogado';
      lastAuthQuery = null;
      await connect(page);
      check(`${tag}: navegador sem login no ML chega à tela de login do ML`, page.url().startsWith('https://www.mercadolivre.com.br/jms/mlb/lgz/login'), page.url());
      check(`${tag}: nenhuma violação de CSP`, csp.length === 0, csp.join(' | '));
      check(`${tag}: autorização com PKCE S256 e state`, lastAuthQuery && lastAuthQuery.get('code_challenge_method') === 'S256'
        && /^[A-Za-z0-9_-]{43}$/.test(lastAuthQuery.get('code_challenge') || '') && (lastAuthQuery.get('state') || '').length >= 40, lastAuthQuery && lastAuthQuery.toString());

      scenario = 'logado';
      await connect(page);
      const body = await page.textContent('body');
      check(`${tag}: retorno ao callback mantém a sessão (state aceito; troca de token sem rede no teste)`,
        page.url().includes('painel.php?p=integracoes') && body.includes('não concluída') && !body.includes('State inválido') && !body.includes('Senha de administrador'),
        page.url() + ' ' + body.slice(0, 300));
      check(`${tag}: nenhuma violação de CSP no retorno`, csp.length === 0, csp.join(' | '));

      // state de uso único: repetir o mesmo retorno é recusado.
      const st = lastAuthQuery.get('state');
      await page.goto(`${base}/ml-oauth-callback.php?code=TG-abcdefgh123&state=${encodeURIComponent(st)}`);
      check(`${tag}: state reutilizado é recusado`, (await page.textContent('body')).includes('State inválido'), page.url());
      await ctx.close();
    }
  } catch (e) {
    check('execução do teste de navegador', false, e && e.stack);
  } finally {
    await browser.close();
    ml.close();
    fs.rmSync(dir, { recursive: true, force: true });
  }
  for (const r of results) console.log(JSON.stringify(r));
})();
