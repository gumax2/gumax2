'use strict';
/*
 * Testes de interface 3.1.5 (Chromium + Playwright, EMULAÇÃO de dispositivos).
 * Limitação: não há WebKit/Safari real nem aparelhos físicos neste ambiente; os perfis de
 * iPhone usam viewport, densidade, toque e user agent do Safari/Chrome iOS sobre o motor Chromium.
 * Chamado por tests/ui_test.php. Saída: uma linha JSON por verificação {grupo, nome, ok, erro}.
 */
const { chromium, devices } = require('playwright');
const C = JSON.parse(process.argv[2]);
const out = (grupo, nome, ok, erro = '') => console.log(JSON.stringify({ grupo, nome, ok: !!ok, erro: ok ? '' : String(erro).slice(0, 600) }));

const UA = {
  android: 'Mozilla/5.0 (Linux; Android 14; SM-A145M) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
  iosChrome: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.90 Mobile/15E148 Safari/604.1',
  edge: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0',
  chrome: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
};
const strip = (d) => { const { defaultBrowserType, ...rest } = d; return rest; };
const PROFILES = [
  { nome: 'Android pequeno · Chrome · 320 px', w: 320, mobile: true, opts: { viewport: { width: 320, height: 640 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, userAgent: UA.android } },
  { nome: 'iPhone SE · Safari · 375 px', w: 375, mobile: true, opts: strip(devices['iPhone SE']) },
  { nome: 'iPhone 13 · Safari · 390 px', w: 390, mobile: true, shots: 'iphone-390', opts: strip(devices['iPhone 13']) },
  { nome: 'iPhone 13 · Chrome iOS · 390 px', w: 390, mobile: true, opts: { ...strip(devices['iPhone 13']), userAgent: UA.iosChrome } },
  { nome: 'Android Pixel 7 · Chrome · 412 px', w: 412, mobile: true, shots: 'android-412', opts: strip(devices['Pixel 7']) },
  { nome: 'Tablet iPad Mini · 768 px', w: 768, mobile: false, shots: 'tablet-768', opts: strip(devices['iPad Mini']) },
  { nome: 'Notebook · Chrome · 1024 px', w: 1024, mobile: false, opts: { viewport: { width: 1024, height: 768 }, userAgent: UA.chrome } },
  { nome: 'Desktop · Edge · 1366 px', w: 1366, mobile: false, shots: 'desktop-1366', opts: { viewport: { width: 1366, height: 768 }, userAgent: UA.edge } },
  { nome: 'Monitor grande · Chrome · 1920 px', w: 1920, mobile: false, opts: { viewport: { width: 1920, height: 1080 }, userAgent: UA.chrome } },
];
const PAGES = ['painel', 'ofertas', `oferta&id=${C.ids.shopee[0]}`, `conferir&id=${C.ids.variacao}`, 'importar', 'links', 'ranking', 'publicacoes',
  'automacoes', 'execucoes', 'integracoes', 'relatorios', 'categorias', 'sistema', 'ofertas&marketplace=shopee&variation=1',
  'ranking&modo=prontas', 'ranking&marketplace=shopee&conferido=nunca&ordem=preco&link=com', 'candidatos', 'autolab', 'diagnostico'];
const STORE_HOSTS = /^https:\/\/(s\.shopee\.com\.br|shopee\.com\.br|meli\.la|[a-z]+\.mercadolivre\.com\.br|mercadolivre\.com\.br)\//;

async function newContext(browser, p) {
  const ctx = await browser.newContext({ ...p.opts, locale: 'pt-BR' });
  const opened = [];
  await ctx.route(STORE_HOSTS, (route) => { opened.push(route.request().url()); route.fulfill({ status: 200, contentType: 'text/html', body: '<title>Loja (simulada)</title>anúncio' }); });
  // Imagens do CDN oficial do Mercado Livre (candidatos): simuladas, sem rede.
  await ctx.route(/^https:\/\/[a-z0-9.-]*mlstatic\.com\//, (route) => route.fulfill({ status: 200, contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64') }));
  ctx.opened = opened;
  return ctx;
}
async function login(page) {
  await page.goto(`${C.base}/painel.php`);
  await page.fill('#password', C.password);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}
/** Elementos que ultrapassam a largura da janela fora de áreas com rolagem própria. */
async function overflow(page) {
  return page.evaluate(() => {
    const vw = window.innerWidth;
    const bad = [];
    if (document.documentElement.scrollWidth > vw + 1) {
      document.querySelectorAll('body *').forEach((el) => {
        const r = el.getBoundingClientRect();
        if (r.width && r.right > vw + 1 && !el.closest('.table-wrap') && getComputedStyle(el).position !== 'fixed') {
          bad.push((el.tagName + '.' + [...el.classList].join('.')).slice(0, 60) + ' (' + Math.round(r.right) + 'px)');
        }
      });
      return { page: document.documentElement.scrollWidth, vw, bad: bad.slice(0, 4) };
    }
    return null;
  });
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-proxy-server'] });
  try {
    for (const p of PROFILES) {
      const G = p.nome;
      const ctx = await newContext(browser, p);
      const page = await ctx.newPage();
      const consoleErr = [];
      page.on('console', (m) => { if (m.type() === 'error' || /Content Security Policy/i.test(m.text())) consoleErr.push(m.text()); });
      page.on('pageerror', (e) => consoleErr.push('JS: ' + e.message));
      await login(page);

      // 1. Todas as páginas: sem rolagem horizontal, sem erro de console/CSP, sem erro PHP, CSP presente.
      const over = [], php = [], noCsp = [];
      for (const pg of PAGES) {
        const resp = await page.goto(`${C.base}/painel.php?p=${pg}`);
        const csp = resp.headers()['content-security-policy'] || '';
        if (!csp.includes("form-action 'self'") || !csp.includes("script-src 'self'") || /unsafe-inline/.test(csp)) noCsp.push(pg);
        const o = await overflow(page);
        if (o) over.push(`${pg}: ${o.page}>${o.vw} ${o.bad.join(', ')}`);
        const body = await page.content();
        if (/(Warning|Notice|Deprecated|Fatal error|Uncaught)\b.*on line/i.test(body)) php.push(pg);
      }
      out(G, `${PAGES.length} páginas sem rolagem horizontal da página`, over.length === 0, over.join(' | '));
      out(G, 'Nenhum erro de PHP nas páginas', php.length === 0, php.join(', '));
      out(G, "CSP presente e restritiva em todas as páginas (form-action 'self', sem unsafe-inline)", noCsp.length === 0, noCsp.join(', '));

      // 2. Navegação: gaveta no celular, menu lateral no desktop.
      await page.goto(`${C.base}/painel.php?p=painel`);
      const toggleVisible = await page.isVisible('.nav-toggle');
      if (p.w < 1024) {
        const hiddenBefore = !(await page.isVisible('#mainnav'));
        await page.click('.nav-toggle');
        const openOk = (await page.isVisible('#mainnav')) && (await page.getAttribute('.nav-toggle', 'aria-expanded')) === 'true';
        const focusInNav = await page.evaluate(() => !!document.activeElement.closest('#mainnav') && document.getElementById('conteudo').hasAttribute('inert'));
        const navOver = await overflow(page);
        if (p.shots) await page.screenshot({ path: `${C.shots}/${p.shots}-menu.png` });
        await page.keyboard.press('Escape');
        const closed = !(await page.isVisible('#mainnav')) && (await page.getAttribute('.nav-toggle', 'aria-expanded')) === 'false';
        out(G, 'Menu móvel: recolhido, abre pelo botão (foco no menu), fecha com Esc', toggleVisible && hiddenBefore && openOk && focusInNav && closed && !navOver,
          JSON.stringify({ toggleVisible, hiddenBefore, openOk, focusInNav, closed, navOver }));
      } else {
        out(G, 'Menu lateral fixo visível; botão de menu oculto', !toggleVisible && (await page.isVisible('#mainnav')), `toggle=${toggleVisible}`);
      }
      await page.goto(`${C.base}/painel.php?p=painel`); // foco do zero (o Esc acima devolve o foco ao botão de menu)
      await page.keyboard.press('Tab');
      out(G, 'Teclado: primeiro Tab leva ao link "Pular para o conteúdo"', await page.evaluate(() => document.activeElement.classList.contains('skip')), await page.evaluate(() => document.activeElement.outerHTML.slice(0, 80)));
      const pauseA11y = await page.evaluate(() => [...document.querySelectorAll('.top-actions .pill span')].some((e) => e.offsetParent !== null && !e.closest('[aria-hidden=true]') && /pausad/i.test(e.textContent)));
      out(G, 'Estado de pausa dos envios visível e anunciado por leitores de tela', pauseA11y, await page.textContent('.top-actions'));

      // 3. Toque e zoom (celular): campos ≥ 16 px; botões ≥ 44 px (≥ 36 px nos pequenos).
      if (p.mobile) {
        await page.goto(`${C.base}/painel.php?p=conferir&id=${C.ids.ml}`);
        const sizes = await page.evaluate(() => {
          const vis = (e) => e.offsetParent !== null || getComputedStyle(e).position === 'fixed';
          const small = [...document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea')].filter(vis)
            .filter((e) => parseFloat(getComputedStyle(e).fontSize) < 16).map((e) => e.name || e.id);
          const tiny = [...document.querySelectorAll('.btn,button')].filter(vis).filter((e) => {
            const h = e.getBoundingClientRect().height; return h < (e.classList.contains('btn-sm') ? 36 : 44) - 0.5;
          }).map((e) => e.textContent.trim().slice(0, 30) + ':' + Math.round(e.getBoundingClientRect().height));
          return { small, tiny };
        });
        out(G, 'Campos com fonte ≥ 16 px (sem zoom involuntário no iPhone)', sizes.small.length === 0, sizes.small.join(', '));
        out(G, 'Botões com altura confortável para toque (≥ 44 px; pequenos ≥ 36 px)', sizes.tiny.length === 0, sizes.tiny.join(', '));
      }

      // 4. Ofertas: cartões no celular, tabela no desktop; filtros recolhíveis.
      await page.goto(`${C.base}/painel.php?p=ofertas`);
      const cards = await page.locator('.offer-card').count();
      const cardsVisible = await page.isVisible('.offer-cards');
      const tableVisible = await page.isVisible('#bulkform table');
      const filtersOpen = await page.evaluate(() => document.querySelector('details.filters-box').open);
      if (p.w < 768) {
        out(G, 'Ofertas: cartões visíveis, tabela oculta', cardsVisible && !tableVisible && cards >= 6, `cards=${cards} vis=${cardsVisible} tabela=${tableVisible}`);
        out(G, 'Filtros recolhidos quando nenhum filtro está ativo', !filtersOpen, `open=${filtersOpen}`);
        await page.goto(`${C.base}/painel.php?p=ofertas&marketplace=shopee`);
        const open2 = await page.evaluate(() => document.querySelector('details.filters-box').open);
        const n = await page.locator('.offer-card').count();
        const count = await page.textContent('.filter-count').catch(() => '');
        out(G, 'Filtro ativo: painel de filtros aberto, contador e resultados só da Shopee', open2 && count.trim() === '1' && n === 6 && (await page.locator('.offer-card .mp-mercadolivre').count()) === 0, `open=${open2} count=${count} n=${n}`);
      } else {
        out(G, 'Ofertas: tabela completa com seleção em lote visível, cartões ocultos', tableVisible && !cardsVisible && (await page.isVisible('select[name=op]')), `tabela=${tableVisible} cards=${cardsVisible}`);
        out(G, 'Filtros sempre abertos no computador', filtersOpen && (await page.isVisible('select[name=marketplace]')), `open=${filtersOpen}`);
      }
      if (p.shots) await page.screenshot({ path: `${C.shots}/${p.shots}-ofertas.png`, fullPage: true });

      // 4b. Ranking: abas de modo; cartões no celular, tabela compacta no computador; detalhes expansíveis.
      await page.goto(`${C.base}/painel.php?p=ranking`);
      const rk = await page.evaluate(() => ({
        cards: document.querySelectorAll('.rank-card').length, cardsVis: !!document.querySelector('.rank-cards') && document.querySelector('.rank-cards').offsetParent !== null,
        tableVis: !!document.querySelector('.rank-table') && document.querySelector('.rank-table').offsetParent !== null,
        tabs: document.querySelectorAll('.seg-tabs a').length, active: (document.querySelector('.seg-tabs a.active') || {}).textContent || '',
        pend: document.querySelectorAll('.rank-card .tag-warn, .rank-table .tag-warn').length,
        priceWrap: [...document.querySelectorAll('.rank-table .col-price strong')].some((e) => e.getClientRects().length > 1),
      }));
      out(G, p.w < 768 ? 'Ranking: cartões compactos com posição, pontuação, confiança e pendência' : 'Ranking: tabela compacta, preço sem quebra de linha',
        rk.tabs === 2 && /Potencial/.test(rk.active) && rk.pend > 0 && (p.w < 768 ? rk.cardsVis && !rk.tableVis && rk.cards >= 6 : rk.tableVis && !rk.cardsVis && !rk.priceWrap), JSON.stringify(rk));
      if (p.shots) await page.screenshot({ path: `${C.shots}/${p.shots}-ranking.png`, fullPage: true });
      await page.goto(`${C.base}/painel.php?p=ranking&modo=prontas`);
      const pr = await page.evaluate(() => ({ n: document.querySelectorAll('.rank-card, .rank-table tbody tr').length, empty: !!document.querySelector('.empty') }));
      out(G, 'Ranking "Prontas para divulgar": só produtos sem pendências (aqui nenhum, com explicação)', pr.empty, JSON.stringify(pr));
      await page.goto(`${C.base}/painel.php?p=integracoes`);
      const ig = await page.evaluate(() => ({ diag: !!document.querySelector('#ml-diagnostico table'), sup: !!document.querySelector('#ml-suporte'), txt: document.querySelector('#ml-diagnostico').textContent }));
      out(G, 'Integrações: diagnóstico do Mercado Livre com conclusões, situação dos recursos e solicitação ao suporte', ig.diag && ig.sup && /Restrição da plataforma/.test(ig.txt) && /PolicyAgent/.test(ig.txt) && !/APP_USR-/.test(ig.txt), ig.txt.slice(0, 200));
      if (p.shots) await page.locator('#ml-diagnostico').screenshot({ path: `${C.shots}/${p.shots}-diagnostico-ml.png` });

      // 4c. Candidatos do Mercado Livre: separados das ofertas, ID de catálogo identificado, imagem do CDN oficial.
      await page.goto(`${C.base}/painel.php?p=candidatos`);
      const cd = await page.evaluate(() => ({ n: document.querySelectorAll('.cand').length, aviso: /Candidatos não são ofertas/.test(document.body.textContent),
        idCat: /ID de catálogo/.test(document.body.textContent), img: document.querySelectorAll('.cand-img').length === 1 && [...document.querySelectorAll('.cand-img')].every((i) => /^https:\/\/[a-z0-9.-]*mlstatic\.com\//.test(i.src) && i.loading === 'lazy'), naoConf: /não conferido/.test(document.body.textContent) }));
      out(G, 'Candidatos ML: aviso de que não são ofertas, ID de catálogo, imagem oficial e preço marcado como não conferido', cd.n === 2 && cd.aviso && cd.idCat && cd.img && cd.naoConf, JSON.stringify(cd));
      if (p.shots) await page.screenshot({ path: `${C.shots}/${p.shots}-candidatos.png`, fullPage: true });

      // 4d. GMAX AutoLab: aviso de homologação, botão de pausa imediata, destino e histórico.
      await page.goto(`${C.base}/painel.php?p=autolab`);
      const al = await page.evaluate(() => ({ aviso: /Homologação, não publicação comercial/.test(document.body.textContent),
        pausa: [...document.querySelectorAll('button')].some((b) => /PAUSAR AUTOLAB AGORA/.test(b.textContent)), destino: /-1004443626419/.test(document.body.textContent),
        hist: /Oferta validada/.test(document.body.textContent) && /Candidato/.test(document.body.textContent), conf: /confirmado/.test(document.body.textContent) }));
      out(G, 'AutoLab: aviso de homologação, pausa imediata, destino fixo e histórico com tipo e resultado do Telegram', al.aviso && al.pausa && al.destino && al.hist && al.conf, JSON.stringify(al));
      if (p.shots) await page.screenshot({ path: `${C.shots}/${p.shots}-autolab.png`, fullPage: true });

      // 5. Busca global.
      if (p.w >= 640 || p.mobile) {
        await page.goto(`${C.base}/painel.php?p=painel`);
        await page.fill('#gq', 'Garrafa');
        await Promise.all([page.waitForNavigation(), page.press('#gq', 'Enter')]);
        const txt = await page.textContent('main');
        out(G, 'Busca global por nome leva às ofertas filtradas', page.url().includes('p=ofertas') && page.url().includes('q=Garrafa') && txt.includes('Garrafa') && !txt.includes('Fone Bluetooth'), page.url());
      }

      // 6. Botões das lojas: URL oficial, nova aba, sem referer; afiliado separado; ausentes sinalizados.
      await page.goto(`${C.base}/painel.php?p=oferta&id=${C.ids.shopee[0]}`);
      const store = page.locator('section .store-actions a.btn-store').first();
      const aff = page.locator('section .store-actions a.btn-affiliate').first();
      const sHref = await store.getAttribute('href'), aHref = await aff.getAttribute('href');
      const sRel = await store.getAttribute('rel'), sTarget = await store.getAttribute('target');
      const sTxt = await store.textContent(), aTxt = await aff.textContent();
      out(G, 'Conferir na loja: URL original oficial, nova aba, rel=noopener noreferrer', sHref === C.urls.shopee0 && sTarget === '_blank' && /noopener/.test(sRel) && /noreferrer/.test(sRel) && /Conferir na Shopee/.test(sTxt), JSON.stringify({ sHref, sTarget, sRel, sTxt }));
      out(G, 'Abrir link afiliado: link oficial distinto e visualmente diferenciado', aHref === C.urls.aff0 && aHref !== sHref && /link afiliado/i.test(aTxt)
        && (await aff.evaluate((e) => getComputedStyle(e).borderStyle)) === 'dashed' && (await store.evaluate((e) => getComputedStyle(e).borderStyle)) === 'solid', JSON.stringify({ aHref, aTxt }));
      await page.goto(`${C.base}/painel.php?p=oferta&id=${C.ids.ml}`);
      const mlNoUrl = (await page.locator('section .store-actions a.btn-store').count()) === 0 && (await page.textContent('section .store-actions')).includes('Sem URL do anúncio');
      await page.goto(`${C.base}/painel.php?p=oferta&id=${C.ids.semLink}`);
      const noAff = (await page.locator('section .store-actions a.btn-affiliate').count()) === 0 && (await page.textContent('section .store-actions')).includes('Sem link afiliado');
      const escaped = !(await page.content()).includes('<b>negrito</b>');
      out(G, 'Produtos sem URL do anúncio ou sem link afiliado: botões desativados, sem link inventado; nome escapado', mlNoUrl && noAff && escaped, JSON.stringify({ mlNoUrl, noAff, escaped }));

      // 7. Abrir o anúncio (popup) e voltar NÃO confirma preço.
      await page.goto(`${C.base}/painel.php?p=conferir&id=${C.ids.shopee[0]}`);
      const beforeTxt = await page.textContent('.check-flow .fresh');
      const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click('a.btn-store.btn-lg')]);
      await popup.waitForLoadState().catch(() => {});
      const popupUrl = popup.url();
      await popup.close();
      await page.reload();
      const afterTxt = await page.textContent('.check-flow .fresh');
      out(G, 'Abrir na loja abre o anúncio oficial em nova aba e, ao voltar, o preço continua não conferido', popupUrl === C.urls.shopee0 && /não conferido/.test(beforeTxt) && afterTxt === beforeTxt, JSON.stringify({ popupUrl, beforeTxt, afterTxt }));
      if (p.shots) await page.screenshot({ path: `${C.shots}/${p.shots}-conferir.png`, fullPage: true });

      // 8. Variação pendente sinalizada.
      await page.goto(`${C.base}/painel.php?p=conferir&id=${C.ids.variacao}`);
      out(G, 'Produto com variação pendente: alerta e opção de definir a variação', (await page.textContent('main')).includes('Variação pendente') && (await page.locator('input[name=variation_ok]').count()) === 1, '');

      // 9. Automações no celular: tabelas viram cartões com rótulos.
      await page.goto(`${C.base}/painel.php?p=automacoes`);
      const auto = await page.evaluate(() => {
        const t = document.querySelector('#historico table');
        return { thead: getComputedStyle(t.querySelector('thead')).display, labels: t.querySelectorAll('td[data-label]').length };
      });
      out(G, p.w < 768 ? 'Automações: histórico em cartões com rótulo em cada campo' : 'Automações: histórico em tabela completa', p.w < 768 ? auto.thead === 'none' && auto.labels > 5 : auto.thead !== 'none', JSON.stringify(auto));
      if (p.shots) await page.screenshot({ path: `${C.shots}/${p.shots}-automacoes.png`, fullPage: true });
      await page.goto(`${C.base}/painel.php?p=painel`);
      if (p.shots) await page.screenshot({ path: `${C.shots}/${p.shots}-painel.png`, fullPage: true });

      out(G, 'Nenhum erro de JavaScript ou violação de CSP no console', consoleErr.length === 0, consoleErr.join(' | '));
      await ctx.close();
    }

    // ---- Fluxos completos (uma vez cada) ----
    const G = 'Fluxos completos (iPhone 13 · emulado)';
    const ctx = await newContext(browser, PROFILES[2]);
    const page = await ctx.newPage();
    const errs = [];
    page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
    await login(page);
    // Conferência manual: sem confirmação o navegador não envia; com confirmação grava como manual.
    await page.goto(`${C.base}/painel.php?p=ofertas&marketplace=mercadolivre`);
    await page.goto(`${C.base}/painel.php?p=conferir&id=${C.ids.ml}`);
    const back = await page.getAttribute('.back-link', 'href');
    await page.fill('input[name=price_promo]', '99,90');
    await page.fill('input[name=price_original]', '149,90');
    await page.check('input[name=payment_condition][value=pix]');
    await page.check('input[name=availability][value=disponivel]');
    await page.fill('textarea[name=observacao]', 'Frete grátis');
    const live = await page.textContent('[data-calc-output]');
    await page.click('button:has-text("Salvar conferência")');
    await page.waitForTimeout(300);
    const notSent = page.url().includes(`p=conferir&id=${C.ids.ml}`) && !(await page.textContent('main')).includes('Conferência manual salva');
    out(G, 'Sem marcar "Conferi agora na loja" a conferência não é enviada', notSent, page.url());
    await page.check('input[name=confirmo]');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Salvar conferência")')]);
    const main = await page.textContent('main');
    out(G, 'Conferência salva como MANUAL (não como API), com desconto calculado ao vivo', main.includes('Conferência manual salva') && main.includes('não como validação pela API')
      && (await page.locator('.check-flow .src-manual').count()) >= 1 && (await page.locator('.check-flow .src-api').count()) === 0 && /33% OFF/.test(live), main.slice(0, 300) + ' | ' + live);
    out(G, '"Voltar aos resultados" mantém filtros e posição do produto', back.includes('p=ofertas') && back.includes('marketplace=mercadolivre') && back.endsWith(`#p${C.ids.ml}`), back);
    // Desktop: o "Voltar aos resultados" leva à linha do produto na tabela.
    const desk = await newContext(browser, PROFILES[7]);
    const dp = await desk.newPage();
    await login(dp);
    await dp.goto(`${C.base}/painel.php?p=ofertas`);
    await dp.goto(`${C.base}/painel.php?p=oferta&id=${C.ids.shopee[0]}`);
    await dp.click('.back-link');
    await dp.waitForLoadState();
    const rowInView = await dp.evaluate((id) => { const r = document.getElementById('t' + id); const b = r.getBoundingClientRect(); return r.classList.contains('row-target') && b.top >= 0 && b.bottom <= innerHeight; }, C.ids.shopee[0]);
    out(G, 'Desktop: "Voltar aos resultados" rola até a linha do produto na tabela', rowInView, '');
    await desk.close();
    // Calculadora: dois modos, valores preservados ao alternar, mesmos números do servidor.
    const G2 = 'Calculadora de preços (iPhone 13 · emulado)';
    await page.goto(`${C.base}/painel.php?p=conferir&id=${C.ids.calc}`);
    await page.fill('input[name=price_promo]', '99,90');
    await page.fill('input[name=price_original]', '149,90');
    const m1 = await page.textContent('[data-calc-output]');
    out(G2, 'Modo preço original: desconto e economia em tempo real', m1.includes('33% OFF · Economia de R$ 50,00'), m1);
    await page.check('input[name=price_mode][value=desconto]');
    const origHidden = !(await page.isVisible('input[name=price_original]')) && (await page.isVisible('input[name=desconto_pct]'));
    let parity = [];
    for (const [pctv, exp] of Object.entries(C.estimates)) {
      await page.fill('input[name=desconto_pct]', pctv);
      const t = await page.textContent('[data-calc-output]');
      if (!(t.includes('≈ ' + exp.original) && t.includes('entre ' + exp.min + ' e ' + exp.max) && t.includes('CALCULADO — não confirmado'))) parity.push(pctv + ': ' + t);
    }
    out(G2, 'Modo desconto: estimativa, faixa e economia iguais ao cálculo do servidor (35, 35,5 e 7%), marcada como CALCULADO', origHidden && parity.length === 0, `origHidden=${origHidden} ` + parity.join(' | '));
    await page.fill('input[name=desconto_pct]', '120');
    const bad = await page.textContent('[data-calc-output]');
    out(G2, 'Percentual inválido é sinalizado na hora', /Percentual inválido/.test(bad), bad);
    await page.check('input[name=price_mode][value=original]');
    const kept = (await page.inputValue('input[name=price_original]')) === '149,90' && (await page.inputValue('input[name=desconto_pct]')) === '120'
      && (await page.textContent('[data-calc-output]')).includes('33% OFF');
    out(G2, 'Alternar entre os modos não perde os valores digitados', kept, '');
    if (PROFILES[2].shots) { await page.check('input[name=price_mode][value=desconto]'); await page.fill('input[name=desconto_pct]', '35'); await page.locator('[data-calc]').screenshot({ path: `${C.shots}/iphone-390-calculadora.png` }); }
    await page.check('input[name=price_mode][value=desconto]');
    await page.fill('input[name=desconto_pct]', '35');
    await page.check('input[name=availability][value=disponivel]');
    await page.check('input[name=confirmo]');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Salvar conferência")')]);
    const saved = await page.textContent('main');
    out(G2, 'Salvar no modo desconto: Por conferido, "De" estimado registrado como CALCULADO (não confirmado)', saved.includes('ESTIMADO') && saved.includes(C.estimates['35'].original) && saved.includes('Calculado ≈'), saved.slice(0, 400));
    await page.goto(`${C.base}/painel.php?p=conferir&id=${C.ids.api}`);
    out(G, 'Preço validado pela API identificado como "API Shopee"', (await page.locator('.check-flow .src-api').count()) >= 1 && (await page.textContent('.check-flow')).includes('API oficial'), '');
    // Importação pelo celular: seleção de arquivo, retorno e prévia.
    await page.goto(`${C.base}/painel.php?p=importar`);
    await page.setInputFiles('input[type=file][name=arquivo]', C.fixture);
    const sel = await page.textContent('input[type=file] + p');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Ler arquivo")')]);
    const lote = await page.textContent('main');
    out(G, 'Importação: arquivo selecionado mostrado, prévia gerada sem gravar e duplicados reconhecidos', /Selecionado: shopee-5-ofertas\.csv/.test(sel) && page.url().includes('p=lote') && /sem alteração|Sem alteração|atualizar|já existe/i.test(lote), sel + ' | ' + page.url() + ' | ' + lote.slice(0, 200));
    // Arquivar exige confirmação (cancelar não arquiva).
    await page.goto(`${C.base}/painel.php?p=ofertas`);
    page.once('dialog', (d) => d.dismiss());
    await page.locator(`#p${C.ids.semLink} button:has-text("Arquivar")`).click();
    await page.waitForTimeout(300);
    out(G, 'Arquivar pede confirmação; ao cancelar nada muda', (await page.locator(`#p${C.ids.semLink}`).count()) === 1 && page.url().includes('p=ofertas'), page.url());
    // Copiar mensagem.
    await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: C.base });
    await page.locator(`#p${C.ids.shopee[0]} button:has-text("Copiar mensagem")`).click();
    await page.waitForTimeout(200);
    out(G, 'Copiar mensagem pelo cartão', /Copiado|Selecione/.test(await page.locator(`#p${C.ids.shopee[0]} [data-copy]`).textContent()), '');
    // Conectividade.
    await ctx.setOffline(true);
    await page.waitForTimeout(100);
    const offVis = await page.isVisible('[data-net-status]');
    await ctx.setOffline(false);
    await page.waitForTimeout(100);
    out(G, 'Indicador de conexão: aparece sem rede e some quando a rede volta', offVis && !(await page.isVisible('[data-net-status]')), `offline=${offVis}`);
    // PWA: manifesto e ícones válidos; nenhum service worker.
    const man = await page.request.get(`${C.base}/gmax-assets/manifest.json`);
    const mj = await man.json();
    let iconsOk = true;
    for (const i of mj.icons) iconsOk = iconsOk && (await page.request.get(`${C.base}/gmax-assets/${i.src}`)).status() === 200;
    const sw = await page.evaluate(async () => (navigator.serviceWorker ? (await navigator.serviceWorker.getRegistrations()).length : 0));
    out(G, 'PWA: manifesto válido (standalone, ícones 192/512/maskable), apple-touch-icon e nenhum service worker',
      man.status() === 200 && mj.display === 'standalone' && mj.icons.length === 3 && iconsOk && sw === 0
      && (await page.request.get(`${C.base}/gmax-assets/apple-touch-icon.png`)).status() === 200, JSON.stringify({ status: man.status(), sw, iconsOk }));
    out(G, 'Nenhum erro de console nos fluxos', errs.length === 0, errs.join(' | '));
    await ctx.close();
  } catch (e) {
    out('Execução', 'execução do teste de interface', false, e && e.stack);
  } finally {
    await browser.close();
  }
})();
