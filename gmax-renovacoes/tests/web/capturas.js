/*
 * Abre a aba Renovações num navegador real (Chromium/Playwright), percorre as abas em perfil de
 * celular (iPhone 13: 390x844, DPR 2, toque) e desktop (1280x900), salva capturas e verifica:
 *  - erros de console / exceções JS / violações de CSP;
 *  - rolagem horizontal em 390px (scrollWidth <= largura);
 *  - diálogos inesperados (alert de XSS);
 *  - respostas HTTP >= 400 durante o uso.
 * Uso: node tests/web/capturas.js <baseUrl> <pastaSaida>
 * Saída: uma linha JSON com o relatório (última linha do stdout).
 */
'use strict';
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const base = process.argv[2];
const outDir = process.argv[3];
if (!base || !outDir) {
  console.error('uso: node capturas.js <baseUrl> <pastaSaida>');
  process.exit(2);
}
fs.mkdirSync(outDir, { recursive: true });

const PERFIS = {
  celular: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' },
  desktop: { viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 },
};
const ABAS = [
  { nome: 'vencimentos', tab: 'visao', pronto: '#gr-hoje .gr-item, #gr-hoje .gr-empty' },
  { nome: 'historico', tab: 'historico', pronto: '#gr-hist .gr-item, #gr-hist .gr-empty' },
  { nome: 'autorizacoes', tab: 'clientes', pronto: '#gr-cli .gr-item, #gr-cli .gr-empty' },
  { nome: 'configuracoes', tab: 'config', pronto: '#gr-cfg fieldset' },
];

(async () => {
  const relatorio = { perfis: {}, erros: [] };
  const browser = await chromium.launch();
  try {
    for (const [nomePerfil, opts] of Object.entries(PERFIS)) {
      const ctx = await browser.newContext({ ...opts, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo', colorScheme: 'dark' });
      const page = await ctx.newPage();
      const r = { console: [], pageErrors: [], dialogs: [], http: [], abas: {} };
      relatorio.perfis[nomePerfil] = r;
      page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') { r.console.push(m.type() + ': ' + m.text()); } });
      page.on('pageerror', (e) => r.pageErrors.push(String(e)));
      page.on('dialog', async (d) => {
        if (d.type() === 'alert') { r.dialogs.push(d.type() + ': ' + d.message()); }
        await d.accept();
      });
      page.on('response', (resp) => { if (resp.status() >= 400) { r.http.push(resp.status() + ' ' + resp.url()); } });
      // Violações de CSP também chegam como evento no documento.
      await page.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (e) => {
          console.error('CSP violada: ' + e.violatedDirective + ' ' + (e.blockedURI || '') + ' ' + (e.sourceFile || '') + ':' + (e.lineNumber || ''));
        });
      });

      await page.goto(base + '/login.php');
      await page.goto(base + '/painel/renovacoes.php', { waitUntil: 'networkidle' });

      for (const aba of ABAS) {
        const api = { visao: 'painel', historico: 'historico', clientes: 'clientes', config: 'config' }[aba.tab];
        await Promise.all([
          page.waitForResponse((x) => x.url().includes('acao=' + api)),
          page.click('.gr-tabs button[data-tab="' + aba.tab + '"]'),
        ]);
        await page.waitForSelector(aba.pronto, { timeout: 10000 });
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(aba.tab === 'config' ? 900 : 200); // prévias da config têm debounce de 350 ms
        const medida = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          clientWidth: document.documentElement.clientWidth,
          largos: Array.from(document.querySelectorAll('body *'))
            .filter((el) => { const b = el.getBoundingClientRect(); return b.width > 0 && b.right > document.documentElement.clientWidth + 1; })
            .slice(0, 5).map((el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.split(' ').join('.') : '') + ' right=' + Math.round(el.getBoundingClientRect().right)),
          texto: document.querySelector('#tab-' + document.querySelector('.gr-tabs .active').dataset.tab).innerText.slice(0, 4000),
        }));
        r.abas[aba.nome] = medida;
        await page.screenshot({ path: path.join(outDir, nomePerfil + '-' + aba.nome + '.png'), fullPage: true });
      }

      if (nomePerfil === 'celular') {
        // Detalhe de uma mensagem do histórico (diálogo).
        await Promise.all([
          page.waitForResponse((x) => x.url().includes('acao=historico')),
          page.click('.gr-tabs button[data-tab="historico"]'),
        ]);
        await page.waitForTimeout(200);
        if (await page.$('#gr-hist button[data-msg]')) {
          await page.click('#gr-hist button[data-msg]');
          await page.waitForSelector('#gr-dialog[open]');
          await page.waitForTimeout(200);
          r.abas.historico_detalhe = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth, dialogWidth: document.querySelector('#gr-dialog').getBoundingClientRect().width }));
          await page.screenshot({ path: path.join(outDir, 'celular-historico-detalhe.png') });
          await page.click('#gr-dialog form button');
        }
        // Prévia expandida de um grupo nos Vencimentos.
        await Promise.all([
          page.waitForResponse((x) => x.url().includes('acao=painel')),
          page.click('.gr-tabs button[data-tab="visao"]'),
        ]);
        await page.waitForTimeout(300);
        const det = await page.$('#gr-proximos details');
        if (det) {
          await det.evaluate((d) => { d.open = true; });
          await page.waitForTimeout(150);
          r.abas.vencimentos_previa = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));
          const item = await det.evaluateHandle((d) => d.closest('.gr-item'));
          await item.asElement().screenshot({ path: path.join(outDir, 'celular-vencimentos-previa.png') });
        }
        // Botão "Executar agora" (POST com CSRF feito pelo próprio JS da página).
        await page.check('#gr-run-ignore');
        const [resp] = await Promise.all([
          page.waitForResponse((x) => x.url().includes('acao=executar_agora')),
          page.click('#gr-run'),
        ]);
        r.executarAgora = { status: resp.status(), corpo: (await resp.text()).slice(0, 500) };
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);
        r.executarAgora.saida = await page.textContent('#gr-run-out');
        r.executarAgora.toast = await page.textContent('#gr-toast');
      }
      await ctx.close();
    }
  } catch (e) {
    relatorio.erros.push(String(e && e.stack || e));
  } finally {
    await browser.close();
  }
  console.log(JSON.stringify(relatorio));
})();
