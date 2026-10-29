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

  // Menu no celular.
  document.querySelectorAll('[data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var el = document.querySelector(btn.getAttribute('data-toggle'));
      if (!el) return;
      var open = el.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

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
