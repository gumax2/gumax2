/* GMAX Ofertas 3.0 — comportamento progressivo; o painel funciona sem JavaScript. */
(function () {
  'use strict';

  // Confirmação e estado de carregamento em formulários.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
    if (form.dataset.busy === '1') { ev.preventDefault(); return; }
    form.dataset.busy = '1';
    var btn = form.querySelector('button[type=submit],button:not([type])');
    if (btn) { btn.setAttribute('aria-busy', 'true'); btn.disabled = true; }
  });
  // Ao voltar pelo histórico, reabilita os botões.
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[data-busy]').forEach(function (f) {
      delete f.dataset.busy;
      f.querySelectorAll('button[aria-busy]').forEach(function (b) { b.removeAttribute('aria-busy'); b.disabled = false; });
    });
  });

  // Página intermediária do OAuth: navegação por GET (não é envio de formulário).
  var autonav = document.querySelector('a[data-autonav]');
  if (autonav) { window.location.replace(autonav.href); }

  // Menu: gaveta no celular (abaixo do cabeçalho), fecha com Esc, ao tocar num link ou fora dele.
  var top = document.querySelector('.top');
  function setNavTop() {
    if (top) document.documentElement.style.setProperty('--nav-top', top.getBoundingClientRect().height + 'px');
  }
  setNavTop();
  window.addEventListener('resize', setNavTop);
  document.querySelectorAll('[data-toggle]').forEach(function (btn) {
    var el = document.querySelector(btn.getAttribute('data-toggle'));
    if (!el) return;
    function set(open) {
      el.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.body.classList.toggle('nav-open', open);
      // Com a gaveta aberta (celular/tablet), o conteúdo atrás fica inerte para teclado e leitores de tela.
      var mainEl = document.getElementById('conteudo');
      if (mainEl) { if (open && window.innerWidth < 1024) mainEl.setAttribute('inert', ''); else mainEl.removeAttribute('inert'); }
      if (open) { setNavTop(); var first = el.querySelector('a'); if (first) first.focus(); }
    }
    btn.addEventListener('click', function () { set(!el.classList.contains('open')); });
    el.addEventListener('click', function (ev) { if (ev.target.closest('a')) set(false); });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && el.classList.contains('open')) { set(false); btn.focus(); }
    });
  });

  // Tabelas viram cartões no celular: cada célula recebe o título da coluna (sem alterar o HTML do servidor).
  document.querySelectorAll('table.table:not(.keep-table)').forEach(function (t) {
    var heads = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    if (!heads.length) return;
    // Papéis explícitos: display:block (cartões) faz alguns leitores de tela perderem a semântica de tabela.
    t.setAttribute('role', 'table');
    t.querySelectorAll('tr').forEach(function (tr) { tr.setAttribute('role', 'row'); });
    t.querySelectorAll('th').forEach(function (th) { th.setAttribute('role', 'columnheader'); });
    t.querySelectorAll('td').forEach(function (td) { td.setAttribute('role', 'cell'); });
    t.querySelectorAll('tbody tr').forEach(function (tr) {
      var col = 0;
      Array.prototype.forEach.call(tr.children, function (td) {
        if (!td.hasAttribute('data-label') && heads[col] && !td.hasAttribute('colspan')) td.setAttribute('data-label', heads[col]);
        col += parseInt(td.getAttribute('colspan') || '1', 10);
      });
    });
  });

  // Filtros: abertos no computador; recolhidos no celular quando nenhum filtro está ativo.
  // Ao girar/redimensionar para ≥ 768 px (onde o título some), reabre para não ficarem inacessíveis.
  if (window.matchMedia) {
    var small = window.matchMedia('(max-width: 767px)');
    if (small.matches) document.querySelectorAll('details.filters-box[data-collapse-mobile]').forEach(function (d) { d.open = false; });
    var reopen = function (e) { if (!e.matches) document.querySelectorAll('details.filters-box').forEach(function (d) { d.open = true; }); };
    if (small.addEventListener) small.addEventListener('change', reopen); else if (small.addListener) small.addListener(reopen);
  }

  // Áreas com rolagem horizontal própria alcançáveis pelo teclado.
  document.querySelectorAll('.table-wrap').forEach(function (w) {
    if (w.scrollWidth > w.clientWidth + 1) {
      w.setAttribute('tabindex', '0'); w.setAttribute('role', 'region');
      var h = w.closest('section, .card'); h = h && h.querySelector('h2');
      w.setAttribute('aria-label', h ? h.textContent.trim() : 'Tabela');
    }
  });

  // Conectividade: avisa quando o aparelho fica sem rede e evita envio de formulário perdido.
  var net = document.querySelector('[data-net-status]');
  function netState() {
    if (!net) return;
    net.textContent = navigator.onLine === false ? 'Sem conexão com a internet: as ações não serão enviadas até a rede voltar.' : '';
  }
  window.addEventListener('online', netState);
  window.addEventListener('offline', netState);
  netState();
  document.addEventListener('submit', function (ev) {
    if (navigator.onLine === false) {
      ev.preventDefault(); ev.stopImmediatePropagation(); netState();
    }
  }, true);

  // Arquivo escolhido (iPhone/Android): mostra nome e tamanho e avisa antes do envio se passar de 5 MB.
  document.querySelectorAll('input[type=file]').forEach(function (inp) {
    var out = document.createElement('p');
    out.className = 'small muted'; out.setAttribute('aria-live', 'polite');
    inp.insertAdjacentElement('afterend', out);
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      if (!f) { out.textContent = ''; return; }
      var kb = Math.max(1, Math.round(f.size / 1024));
      var big = f.size > 5 * 1024 * 1024;
      out.textContent = 'Selecionado: ' + f.name + ' (' + (kb >= 1024 ? (kb / 1024).toFixed(1) + ' MB' : kb + ' KB') + ')' + (big ? ' — acima de 5 MB: o servidor vai recusar.' : '');
      out.className = 'small ' + (big ? 'err' : 'muted');
    });
  });

  // Lista de ofertas: guarda e restaura a posição da rolagem ao voltar (somente nesta aba).
  // "Voltar aos resultados" aponta para o cartão (#p123); no desktop o cartão fica oculto e
  // a linha equivalente da tabela é #t123: rola até ela e a destaca.
  var hashM = /^#p(\d+)$/.exec(location.hash);
  var hashEl = hashM && document.getElementById('p' + hashM[1]);
  var rowEl = hashM && document.getElementById('t' + hashM[1]);
  if (hashEl && hashEl.offsetParent === null && rowEl) {
    rowEl.classList.add('row-target');
    rowEl.scrollIntoView({ block: 'center' });
  }
  var listKey = document.querySelector('[data-scroll-key]');
  if (listKey) {
    var key = 'gmax-scroll:' + listKey.getAttribute('data-scroll-key');
    try {
      var y = sessionStorage.getItem(key);
      if (y && !location.hash) window.scrollTo(0, parseInt(y, 10) || 0);
    } catch (e) { /* armazenamento indisponível: segue sem restaurar */ }
    window.addEventListener('pagehide', function () {
      try { sessionStorage.setItem(key, String(window.scrollY || 0)); } catch (e) { /* ignora */ }
    });
  }

  // Selecionar todos.
  document.querySelectorAll('[data-checkall]').forEach(function (box) {
    box.addEventListener('change', function () {
      var name = box.getAttribute('data-checkall');
      box.form.querySelectorAll('input[type=checkbox][name="' + name + '"]').forEach(function (c) { c.checked = box.checked; });
    });
  });

  // Copiar texto.
  function copy(text, button) {
    function done(ok) {
      var label = button.textContent;
      button.textContent = ok ? 'Copiado!' : 'Selecione e copie manualmente';
      setTimeout(function () { button.textContent = label; }, 1600);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
      return;
    }
    var t = document.createElement('textarea');
    t.value = text; t.setAttribute('readonly', ''); t.className = 'offscreen';
    t.style.position = 'fixed'; t.style.left = '-9999px';
    document.body.appendChild(t); t.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    t.remove(); done(ok);
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-copy],[data-copy-text]');
    if (!b) return;
    var text = b.hasAttribute('data-copy-text') ? b.getAttribute('data-copy-text') : (document.querySelector(b.getAttribute('data-copy')) || {}).textContent;
    if (text) copy(text, b);
  });

  // Desconto calculado em tempo real no formulário (mesma regra do servidor: arredonda para baixo).
  function cents(v) {
    v = (v || '').replace(/R\$|\s/g, '');
    if (!v) return null;
    if (v.indexOf(',') >= 0) v = v.replace(/\./g, '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(v)) v = v.replace(/\./g, '');
    if (!/^\d+(\.\d{1,2})?$/.test(v)) return NaN;
    var p = v.split('.');
    return parseInt(p[0], 10) * 100 + parseInt(((p[1] || '') + '00').slice(0, 2), 10);
  }
  function brl(c) { return 'R$ ' + (c / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  document.querySelectorAll('form[data-discount]').forEach(function (form) {
    var o = form.querySelector('[data-price=orig]'), p = form.querySelector('[data-price=promo]'), out = form.querySelector('[data-discount-output]');
    function update() {
      var a = cents(o.value), b = cents(p.value);
      if (Number.isNaN(a) || Number.isNaN(b)) { out.textContent = 'Valor inválido: use 199,90'; return; }
      if (a === null || b === null) { out.textContent = b ? 'Sem preço original: a mensagem mostrará apenas o preço "Por".' : ''; return; }
      if (a < b) { out.textContent = 'Preço original menor que o promocional.'; return; }
      if (a === b) { out.textContent = 'Sem desconto.'; return; }
      var save = a - b;
      out.textContent = Math.floor(save * 100 / a) + '% OFF · Economize ' + brl(save);
    }
    o.addEventListener('input', update); p.addEventListener('input', update); update();
  });
})();
