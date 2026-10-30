/* GMAX Renovações — interface da aba Renovações (JavaScript puro, sem dependências). */
(function () {
  'use strict';

  var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var API = 'renovacoes-api.php';
  var state = { cfg: null, histPage: 0 };

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  // Todo dado vindo do servidor passa por esc() antes de ir para o HTML.
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  // "2026-10-30 09:06:11" → "30/10/2026 09:06"
  function dt(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/.exec(v || '');
    return m ? m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '') : (v || '');
  }
  var REM_LABELS = { pendente: 'aguardando', em_mensagem: 'na mensagem', cancelado: 'cancelado', ignorado: 'não enviado' };
  function badge(status, text) { return '<span class="gr-badge ' + esc(status) + '">' + esc(text || status) + '</span>'; }

  function toast(msg, bad) {
    var t = $('#gr-toast');
    t.textContent = msg;
    t.className = 'show' + (bad ? ' bad' : '');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.className = ''; }, 4500);
  }

  function api(action, data, method) {
    var opts = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    var url = API + '?acao=' + encodeURIComponent(action);
    if (method === 'POST') {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = CSRF;
      opts.body = JSON.stringify(data || {});
    } else if (data) {
      Object.keys(data).forEach(function (k) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); });
    }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return { erro: 'Resposta inválida do servidor.' }; }).then(function (j) {
        if (r.status === 401) { location.href = './'; }
        if (!r.ok) { throw new Error(j.erro || ('Erro ' + r.status)); }
        return j;
      });
    });
  }
  function post(action, data) { return api(action, data, 'POST'); }

  // ---------- Banners de estado ----------
  function renderBanners(st) {
    var h = '';
    if (!st.ativo) {
      h += '<div class="gr-banner off">Módulo desativado — nenhum lembrete é gerado.<small>Ative em Configurações quando estiver pronto.</small></div>';
    }
    if (st.modo_efetivo === 'simulacao') {
      h += '<div class="gr-banner sim">MODO SIMULAÇÃO — nenhuma mensagem real é enviada aos clientes.';
      if (st.bloqueios_envio_real && st.bloqueios_envio_real.length) {
        h += '<details><summary>O que falta para o envio real (' + st.bloqueios_envio_real.length + ')</summary><small>' + st.bloqueios_envio_real.map(esc).join('<br>') + '</small></details>';
      }
      h += '</div>';
    } else {
      h += '<div class="gr-banner real">ENVIO REAL ATIVO pela API oficial do WhatsApp.</div>';
    }
    if (st.pausa_global) {
      h += '<div class="gr-banner pause">PAUSA GLOBAL — nenhum envio será feito até você retomar.</div>';
    }
    $('#gr-banners').innerHTML = h;
    $('#gr-clock').textContent = 'Agora: ' + st.agora + ' (Brasília)';
  }

  // ---------- Vencimentos ----------
  function renderGroup(g) {
    var stage = g.etapa_hoje
      ? badge('aguardando', 'Hoje: ' + g.etapa_hoje.rotulo)
      : (g.proxima_etapa ? '<span class="gr-muted">Próximo aviso: ' + esc(g.proxima_etapa.rotulo) + ' (' + esc(g.proxima_etapa.data) + ')</span>' : '<span class="gr-muted">Sem avisos pendentes</span>');
    var when = g.dias === 0 ? 'vence hoje' : (g.dias > 0 ? 'vence em ' + g.dias + ' dia(s)' : 'venceu há ' + (-g.dias) + ' dia(s)');
    var items = g.itens.map(function (it) {
      var right = esc(it.valor);
      if (it.lembrete) {
        right += ' ' + badge(it.lembrete.status, it.lembrete.status_texto);
        if (it.lembrete.motivo) { right += ' <span class="gr-muted">' + esc(it.lembrete.motivo) + '</span>'; }
        if (it.lembrete.mensagem_id) { right += ' <button class="gr-btn gr-btn-sec gr-btn-sm" data-msg="' + it.lembrete.mensagem_id + '">Detalhes</button>'; }
      } else if (it.motivo) {
        right += ' ' + badge('ignorado', it.motivo_texto);
      }
      return '<li><span>' + esc(it.pacote) + (it.servico ? ' <span class="gr-muted">· ' + esc(it.servico) + '</span>' : '') + '</span><span>' + right + '</span></li>';
    }).join('');
    var consent = g.consentimento === 'concedido' ? badge('concedido', 'autorizado') : badge(g.consentimento, g.consentimento === 'revogado' ? 'autorização revogada' : 'sem autorização');
    var h = '<div class="gr-item">' +
      '<div class="gr-item-head"><strong>' + esc(g.cliente) + '</strong><span>' + esc(g.vencimento_br) + ' · ' + esc(when) + '</span></div>' +
      '<div class="gr-item-head"><span class="gr-muted">' + esc(g.telefone) + ' ' + consent + (g.pausado ? ' ' + badge('ignorado', 'pausado') : '') + '</span>' + stage + '</div>' +
      '<ul class="gr-pkgs">' + items + '</ul>' +
      (g.itens.length > 1 ? '<div class="gr-muted gr-mt6">Total do grupo: <b>' + esc(g.total) + '</b> (mesma data → uma única mensagem)</div>' : '');
    if (g.previa) {
      h += '<details><summary>Prévia da mensagem</summary><div class="gr-preview">' + esc(g.previa) + '</div>';
      if (g.link_manual && !g.ja_enviado) {
        h += '<div class="gr-actions"><a class="gr-btn gr-btn-sec gr-btn-sm" target="_blank" rel="noopener" href="' + esc(g.link_manual) + '">Abrir WhatsApp com mensagem pronta (envio MANUAL)</a>';
        if (g.etapa_hoje && !(g.itens[0].lembrete && g.itens[0].lembrete.mensagem_id)) {
          h += '<button class="gr-btn gr-btn-sec gr-btn-sm" data-manual="' + esc(JSON.stringify({ cliente_id: g.cliente_id, vencimento: g.vencimento, etapa: g.etapa_hoje.codigo })) + '">Registrar que enviei manualmente</button>';
        }
        h += '</div><div class="gr-muted">O botão só abre o WhatsApp com o texto pronto: você precisa tocar em Enviar. Não é envio automático. Registrar o envio manual evita que o automático mande o mesmo aviso.</div>';
      }
      h += '</details>';
    }
    return h + '</div>';
  }

  function loadDashboard() {
    return api('painel').then(function (d) {
      renderBanners(d.estado);
      var r = d.resumo;
      $('#gr-resumo').innerHTML =
        '<div class="gr-card"><span class="gr-muted">Vencem hoje</span><b>' + r.hoje + '</b></div>' +
        '<div class="gr-card"><span class="gr-muted">Próximos 7 dias</span><b>' + r.proximos + '</b></div>' +
        '<div class="gr-card"><span class="gr-muted">Vencidos (15 dias)</span><b>' + r.vencidos + '</b></div>' +
        '<div class="gr-card"><span class="gr-muted">Falhas/incertos hoje</span><b>' + r.falhas_hoje + '</b></div>' +
        (r.datas_invalidas ? '<div class="gr-card"><span class="gr-muted">Pacotes com vencimento inválido (sem aviso — corrija no painel)</span><b>' + r.datas_invalidas + '</b></div>' : '');
      ['hoje', 'proximos', 'vencidos'].forEach(function (k) {
        $('#gr-' + k).innerHTML = d[k].length ? d[k].map(renderGroup).join('') : '<div class="gr-empty">Nada aqui.</div>';
      });
      var last = d.estado.ultima_execucao;
      $('#gr-run-out').textContent = (state.runMsg ? state.runMsg + ' · ' : '') + (last ? 'Última execução (' + last.origem + '): ' + dt(last.iniciado_em) + (d.estado.ultimo_cron ? ' · último cron: ' + dt(d.estado.ultimo_cron) : ' · cron ainda não rodou') : 'O agendador ainda não rodou.');
      state.runMsg = '';
    }).catch(function (e) { toast(e.message, true); });
  }

  // ---------- Histórico ----------
  function renderMsg(m) {
    var err = m.erro_detalhe && (m.status === 'falhou' || m.status === 'incerto' || m.status === 'cancelado') ? '<div class="gr-muted">Motivo: ' + esc(m.erro_detalhe) + '</div>' : '';
    return '<div class="gr-item"><div class="gr-item-head"><strong>' + esc(m.cliente) + '</strong><span class="gr-badges">' +
      (m.modo === 'simulacao' ? badge('sim', 'SIMULAÇÃO') + ' ' : '') + badge(m.status, m.status_texto) + '</span></div>' +
      '<div class="gr-muted">' + esc(m.pacotes || '') + ' · venc. ' + esc(m.vencimento_br) + ' · etapa ' + esc(m.etapa) + ' · ' + esc(m.telefone) + '</div>' +
      '<div class="gr-muted">Criada ' + esc(dt(m.criado_em)) + (m.enviado_em ? (m.modo === 'simulacao' ? ' · simulada ' : ' · aceita ') + esc(dt(m.enviado_em)) : '') + (m.entregue_em ? ' · entregue ' + esc(dt(m.entregue_em)) : '') + (m.lido_em ? ' · lida ' + esc(dt(m.lido_em)) : '') + ' · tentativas ' + esc(m.tentativas) + '</div>' +
      err + '<div class="gr-actions"><button class="gr-btn gr-btn-sec gr-btn-sm" data-msg="' + m.id + '">Detalhes</button></div></div>';
  }

  function loadHistory(append) {
    if (!append) {
      state.histPage = 0;
      api('recebidas').then(function (d) {
        $('#gr-recv').innerHTML = d.itens.map(function (e) {
          return '<div class="gr-item"><span class="gr-muted">' + esc(dt(e.registrado_em)) + '</span> ' + badge(e.tipo === 'descadastro' ? 'revogado' : 'aceito', e.tipo === 'descadastro' ? 'descadastro' : 'recebida') + '<br>' + esc(e.detalhe) + '</div>';
        }).join('') || '<div class="gr-empty">Nenhuma resposta registrada.</div>';
      }).catch(function () {});
    }
    return api('historico', { status: $('#gr-hist-status').value, pagina: state.histPage }).then(function (d) {
      var sel = $('#gr-hist-status');
      if (sel.options.length === 1) {
        Object.keys(d.status_disponiveis).forEach(function (k) {
          var o = document.createElement('option'); o.value = k; o.textContent = d.status_disponiveis[k]; sel.appendChild(o);
        });
      }
      var h = d.itens.map(renderMsg).join('');
      if (append) { $('#gr-hist').insertAdjacentHTML('beforeend', h); } else { $('#gr-hist').innerHTML = h || '<div class="gr-empty">Nenhuma mensagem ainda.</div>'; }
      $('#gr-hist-more').style.display = d.itens.length === 50 ? '' : 'none';
    }).catch(function (e) { toast(e.message, true); });
  }

  function openMessage(id) {
    api('mensagem', { id: id }).then(function (m) {
      var h = '<h2>Mensagem #' + esc(m.id) + ' ' + badge(m.status, m.status_texto) + (m.modo === 'simulacao' ? ' ' + badge('sim', 'SIMULAÇÃO') : '') + '</h2>';
      h += '<div class="gr-muted">Para ' + esc(m.telefone) + ' · provedor ' + esc(m.provedor) + (m.modelo_nome ? ' · modelo ' + esc(m.modelo_nome) : '') + '</div>';
      if (m.erro_detalhe) { h += '<p class="gr-muted">Último erro: ' + esc(m.erro_codigo || '') + ' ' + esc(m.erro_detalhe) + '</p>'; }
      h += '<div class="gr-preview">' + esc(m.corpo) + '</div>';
      if (m.lembretes.length) {
        h += '<ul class="gr-pkgs">' + m.lembretes.map(function (l) { return '<li><span>' + esc(l.snap_pacote) + '</span><span>' + esc(l.valor) + ' ' + badge(l.status, REM_LABELS[l.status] || l.status) + ' <span class="gr-muted">' + esc(l.motivo_texto) + '</span></span></li>'; }).join('') + '</ul>';
      }
      h += '<ul class="gr-events">' + m.eventos.map(function (e) { return '<li><span class="gr-muted">' + esc(dt(e.registrado_em)) + (e.ocorrido_em ? ' (provedor: ' + esc(dt(e.ocorrido_em)) + ')' : '') + '</span><br>' + esc(e.detalhe) + '</li>'; }).join('') + '</ul>';
      var acts = '';
      if (m.status === 'incerto') {
        acts += '<button class="gr-btn gr-btn-sm" data-resolve="confirmar_enviado">Confirmei no WhatsApp: foi enviada</button>' +
          '<button class="gr-btn gr-btn-sec gr-btn-sm" data-resolve="marcar_falhou">Não foi enviada</button>';
      }
      if (m.status === 'incerto' || m.status === 'falhou') {
        acts += '<button class="gr-btn gr-btn-bad gr-btn-sm" data-resolve="reenviar">Reenviar (confira antes!)</button>';
      }
      if (m.status === 'aguardando') { acts += '<button class="gr-btn gr-btn-sec gr-btn-sm" data-resolve="cancelar">Cancelar envio</button>'; }
      if (['aguardando', 'incerto', 'falhou'].indexOf(m.status) >= 0) { acts += '<button class="gr-btn gr-btn-sec gr-btn-sm" data-resolve="registrar_manual">Registrar envio manual</button>'; }
      if (acts) { h += '<div class="gr-actions" data-msgid="' + esc(m.id) + '">' + acts + '</div>'; }
      $('#gr-dialog-body').innerHTML = h;
      $('#gr-dialog').showModal();
    }).catch(function (e) { toast(e.message, true); });
  }

  // ---------- Autorizações ----------
  function loadClients() {
    return api('clientes', { q: $('#gr-cli-q').value }).then(function (d) {
      $('#gr-cli').innerHTML = d.itens.map(function (c) {
        var cs = c.consentimento === 'concedido' ? badge('concedido', 'autorizado em ' + dt(c.consentimento_em)) : badge(c.consentimento, c.consentimento === 'revogado' ? 'revogado' : 'sem autorização');
        var warn = !c.telefone_valido ? ' ' + badge('falhou', 'telefone inválido') : (c.telefone_mudou ? ' ' + badge('incerto', 'telefone mudou — reautorizar') : '');
        var btns = c.consentimento === 'concedido'
          ? '<button class="gr-btn gr-btn-sec gr-btn-sm" data-consent="revogar" data-id="' + esc(c.id) + '">Revogar autorização</button>'
          : '<button class="gr-btn gr-btn-sm" data-consent="conceder" data-id="' + esc(c.id) + '"' + (c.telefone_valido ? '' : ' disabled') + '>Registrar autorização</button>';
        btns += '<button class="gr-btn gr-btn-sec gr-btn-sm" data-pause="' + (c.pausado ? 0 : 1) + '" data-id="' + esc(c.id) + '">' + (c.pausado ? 'Retomar lembretes' : 'Pausar lembretes') + '</button>';
        btns += '<button class="gr-btn gr-btn-sec gr-btn-sm" data-clihist="' + esc(c.id) + '">Registro</button>';
        return '<div class="gr-item"><div class="gr-item-head"><strong>' + esc(c.nome) + '</strong><span>' + cs + warn + (c.pausado ? ' ' + badge('ignorado', 'pausado') : '') + (c.ativo ? '' : ' ' + badge('cancelado', 'inativo no painel')) + '</span></div>' +
          '<div class="gr-muted">' + esc(c.telefone) + (c.consentimento_origem ? ' · origem: ' + esc(c.consentimento_origem) : '') + '</div>' +
          '<div class="gr-actions">' + btns + '</div></div>';
      }).join('') || '<div class="gr-empty">Nenhum cliente encontrado.</div>';
    }).catch(function (e) { toast(e.message, true); });
  }

  // ---------- Configurações ----------
  var STAGE_NAMES = { antes: 'Antes do vencimento', dia: 'No dia do vencimento', depois: 'Depois do vencimento' };

  function renderConfig(d) {
    state.cfg = d;
    var c = d.config, st = d.estado, et = {};
    d.etapas.forEach(function (e) { et[e.codigo] = e; });
    var creds = d.provedor.credenciais;
    var h = '';
    h += '<fieldset><legend>Funcionamento</legend>' +
      '<div class="gr-row">' +
      '<label class="gr-check"><input type="checkbox" name="ativo"' + (c.ativo ? ' checked' : '') + '> Módulo ativo (o agendador gera e envia)</label>' +
      '<label class="gr-check"><input type="checkbox" id="gr-pause"' + (c.pausa_global ? ' checked' : '') + '> Pausa global dos envios</label></div>' +
      '<div class="gr-row"><label>Modo<select name="modo"><option value="simulacao"' + (c.modo === 'simulacao' ? ' selected' : '') + '>Simulação (nenhum envio real)</option><option value="real"' + (c.modo === 'real' ? ' selected' : '') + '>Real (API oficial)</option></select></label>' +
      '<label>Gerar/enviar a partir de<input type="time" name="horario" value="' + esc(c.horario) + '"></label>' +
      '<label>Não enviar depois de<input type="time" name="janela_fim" value="' + esc(c.janela_fim) + '"></label>' +
      '<label>Tentativas (falha temporária)<input type="number" min="1" max="5" name="max_tentativas" value="' + esc(c.max_tentativas) + '"></label></div>' +
      '<p class="gr-muted">Fuso America/Sao_Paulo. Modo efetivo agora: <b>' + esc(st.modo_efetivo) + '</b>. Provedor no servidor: <b>' + esc(d.provedor.driver) + '</b> · credenciais: ' +
      Object.keys(creds).map(function (k) { return esc(k) + (creds[k] ? ' ✔' : ' ✘'); }).join(', ') + ' (valores nunca aparecem aqui).</p></fieldset>';

    h += '<fieldset><legend>Etapas</legend><div class="gr-row">' +
      '<label class="gr-check"><input type="checkbox" name="et_antes"' + (et.antes && et.antes.ativo ? ' checked' : '') + '> Antes: <input type="number" min="1" max="15" name="et_antes_dias" value="' + (et.antes ? -et.antes.dias : 3) + '" class="gr-w80"> dia(s)</label>' +
      '<label class="gr-check"><input type="checkbox" name="et_dia"' + (et.dia && et.dia.ativo ? ' checked' : '') + '> No dia do vencimento</label>' +
      '<label class="gr-check"><input type="checkbox" name="et_depois"' + (et.depois && et.depois.ativo ? ' checked' : '') + '> Depois: <input type="number" min="1" max="15" name="et_depois_dias" value="' + (et.depois ? et.depois.dias : 1) + '" class="gr-w80"> dia(s), se não renovado</label>' +
      '</div></fieldset>';

    h += '<fieldset><legend>Pagamento</legend><div class="gr-row">' +
      '<label>Chave Pix<input type="text" name="chave_pix" value="' + esc(c.chave_pix) + '"></label>' +
      '<label>Rodapé (descadastro)<input type="text" name="rodape" value="' + esc(c.rodape) + '"></label></div>' +
      '<label class="gr-field">Instruções de pagamento (use {chave_pix})<textarea name="instrucoes_pagamento" class="gr-ta-sm">' + esc(c.instrucoes_pagamento) + '</textarea></label>' +
      (c.pagamento_confirmado
        ? '<p>' + badge('concedido', 'Confirmado em ' + (c.pagamento_confirmado_em || '')) + '</p>'
        : '<p>' + badge('incerto', 'Aguardando sua confirmação') + ' <button type="button" class="gr-btn gr-btn-sm" id="gr-confirm-pix">Confirmo que a chave e as instruções estão corretas</button></p><p class="gr-muted">Salve antes de confirmar. Envio real fica bloqueado até a confirmação. Mudar a chave ou as instruções exige nova confirmação.</p>') +
      '</fieldset>';

    h += '<fieldset><legend>Textos das mensagens</legend><p class="gr-muted">Campos: ' + d.campos.map(function (f) { return '{' + esc(f) + '}'; }).join(' ') +
      '. Valores são o TOTAL do pacote/ciclo. Não use links, senhas ou códigos de acesso. Estes textos são propostas: o envio real usa os modelos aprovados pela Meta (o texto do modelo precisa corresponder).</p>';
    ['antes', 'dia', 'depois'].forEach(function (code) {
      ['individual', 'grupo'].forEach(function (v) {
        var id = 'tx_' + code + '_' + v;
        h += '<div class="gr-field gr-mb14"><b>' + STAGE_NAMES[code] + ' — ' + (v === 'grupo' ? 'vários pacotes na mesma data' : 'um pacote') + '</b>' +
          '<textarea name="' + id + '" data-stage="' + code + '" data-variant="' + v + '">' + esc(c.textos[code][v]) + '</textarea>' +
          '<div class="gr-preview" id="pv_' + id + '"></div><ul class="gr-errors" id="er_' + id + '"></ul></div>';
      });
    });
    h += '</fieldset>';

    h += '<fieldset><legend>Modelos aprovados na Meta (envio real)</legend><p class="gr-muted">Preencha com o nome exato de cada modelo depois da aprovação. Parâmetros, em ordem — um pacote: {{1}} nome, {{2}} pacote, {{3}} data, {{4}} valor, {{5}} instruções. Vários pacotes: {{1}} nome, {{2}} data, {{3}} lista de pacotes, {{4}} total, {{5}} instruções.</p><div class="gr-row">';
    ['antes', 'dia', 'depois'].forEach(function (code) {
      ['individual', 'grupo'].forEach(function (v) {
        h += '<label>' + STAGE_NAMES[code] + ' (' + v + ')<input type="text" name="md_' + code + '_' + v + '" value="' + esc(c.modelos[code][v]) + '" placeholder="ex.: gmax_lembrete_' + code + '"></label>';
      });
    });
    h += '</div><label class="gr-field">Idioma do modelo<input type="text" value="' + esc(c.modelo_idioma) + '" disabled></label></fieldset>';

    h += '<fieldset><legend>Teste com contato interno</legend>' +
      '<label class="gr-field">Números de teste autorizados (um por linha, com DDD)<textarea name="numeros_teste" class="gr-ta-sm">' + esc((c.numeros_teste || []).join('\n')) + '</textarea></label>' +
      '<label class="gr-check"><input type="checkbox" name="somente_numeros_teste"' + (c.somente_numeros_teste ? ' checked' : '') + '> No modo real, enviar SOMENTE para os números de teste (recomendado no início)</label>' +
      '<div class="gr-actions"><select id="gr-test-num">' + (c.numeros_teste || []).map(function (n) { return '<option>' + esc(n) + '</option>'; }).join('') + '</select>' +
      '<select id="gr-test-stage"><option value="antes">Antes</option><option value="dia">No dia</option><option value="depois">Depois</option></select>' +
      '<label class="gr-check"><input type="checkbox" id="gr-test-group"> agrupada</label>' +
      '<button type="button" class="gr-btn gr-btn-sec" id="gr-test-send">Enviar mensagem de teste</button></div>' +
      '<p class="gr-muted">Usa dados fictícios. Em simulação, nada sai do servidor.</p></fieldset>';

    h += '<div class="gr-actions"><button type="submit" class="gr-btn">Salvar configurações</button></div><ul class="gr-errors" id="gr-cfg-errors"></ul>';
    $('#gr-cfg').innerHTML = h;
    $all('#gr-cfg textarea[data-stage]').forEach(updatePreview);
  }

  var pvTimers = {};
  function updatePreview(ta) {
    clearTimeout(pvTimers[ta.name]);
    pvTimers[ta.name] = setTimeout(function () {
      var f = $('#gr-cfg');
      post('previa', {
        etapa: ta.dataset.stage, variante: ta.dataset.variant, texto: ta.value,
        instrucoes_pagamento: f.instrucoes_pagamento.value, chave_pix: f.chave_pix.value, rodape: f.rodape.value
      }).then(function (r) {
        $('#pv_' + ta.name).textContent = r.corpo;
        $('#er_' + ta.name).innerHTML = r.erros.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('');
      }).catch(function () {});
    }, 350);
  }

  function collectConfig() {
    var f = $('#gr-cfg');
    var data = {
      ativo: f.ativo.checked, modo: f.modo.value, horario: f.horario.value, janela_fim: f.janela_fim.value,
      max_tentativas: parseInt(f.max_tentativas.value, 10),
      etapas: [
        { codigo: 'antes', dias: -parseInt(f.et_antes_dias.value, 10), ativo: f.et_antes.checked },
        { codigo: 'dia', dias: 0, ativo: f.et_dia.checked },
        { codigo: 'depois', dias: parseInt(f.et_depois_dias.value, 10), ativo: f.et_depois.checked }
      ],
      chave_pix: f.chave_pix.value, rodape: f.rodape.value, instrucoes_pagamento: f.instrucoes_pagamento.value,
      numeros_teste: f.numeros_teste.value.split(/\n+/), somente_numeros_teste: f.somente_numeros_teste.checked,
      textos: {}, modelos: {}
    };
    ['antes', 'dia', 'depois'].forEach(function (code) {
      data.textos[code] = {}; data.modelos[code] = {};
      ['individual', 'grupo'].forEach(function (v) {
        data.textos[code][v] = f['tx_' + code + '_' + v].value;
        data.modelos[code][v] = f['md_' + code + '_' + v].value;
      });
    });
    return data;
  }

  function loadConfig() {
    return api('config').then(function (d) { renderConfig(d); renderBanners(d.estado); }).catch(function (e) { toast(e.message, true); });
  }

  // ---------- Eventos ----------
  $all('.gr-tabs button').forEach(function (b) {
    b.addEventListener('click', function () {
      $all('.gr-tabs button').forEach(function (x) { x.classList.toggle('active', x === b); });
      $all('.gr-tab').forEach(function (s) { s.classList.toggle('active', s.id === 'tab-' + b.dataset.tab); });
      try { localStorage.setItem('gr_tab', b.dataset.tab); } catch (e) { /* sem armazenamento */ }
      ({ visao: loadDashboard, historico: function () { loadHistory(false); }, clientes: loadClients, config: loadConfig })[b.dataset.tab]();
    });
  });

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('button');
    if (!t) { return; }
    if (t.dataset.msg) { openMessage(t.dataset.msg); }
    if (t.dataset.resolve) {
      var id = t.parentNode.dataset.msgid, dec = t.dataset.resolve;
      var ask = { reenviar: 'Reenviar pode duplicar a mensagem se ela já chegou ao cliente. Confirme no WhatsApp antes. Reenviar mesmo assim?', confirmar_enviado: 'Confirmar que a mensagem foi enviada?', marcar_falhou: 'Marcar como não enviada?', cancelar: 'Cancelar este envio?', registrar_manual: 'Registrar que você enviou manualmente?' }[dec];
      if (ask && !confirm(ask)) { return; }
      post('resolver', { id: parseInt(id, 10), decisao: dec }).then(function (r) {
        toast('Status: ' + r.status); $('#gr-dialog').close(); loadHistory(false);
      }).catch(function (e) { toast(e.message, true); });
    }
    if (t.dataset.manual) {
      if (!confirm('Registrar que você enviou este aviso manualmente? O envio automático deste aviso será evitado.')) { return; }
      post('registrar_manual', JSON.parse(t.dataset.manual)).then(function () { toast('Envio manual registrado.'); loadDashboard(); }).catch(function (e) { toast(e.message, true); });
    }
    if (t.dataset.consent) {
      var cid = t.dataset.id;
      if (t.dataset.consent === 'conceder') {
        var origem = prompt('Como o cliente autorizou receber lembretes? (ex.: "respondeu SIM no WhatsApp em 30/10/2026")');
        if (!origem) { return; }
        post('consentimento', { cliente_id: cid, acao: 'conceder', origem: origem }).then(function () { toast('Autorização registrada.'); loadClients(); }).catch(function (e) { toast(e.message, true); });
      } else {
        if (!confirm('Revogar a autorização deste cliente? Ele deixará de receber lembretes.')) { return; }
        post('consentimento', { cliente_id: cid, acao: 'revogar' }).then(function () { toast('Autorização revogada.'); loadClients(); }).catch(function (e) { toast(e.message, true); });
      }
    }
    if (t.dataset.pause !== undefined && t.dataset.id) {
      post('pausa_cliente', { cliente_id: t.dataset.id, pausado: t.dataset.pause === '1' }).then(function () { loadClients(); }).catch(function (e) { toast(e.message, true); });
    }
    if (t.dataset.clihist) {
      api('cliente_historico', { id: t.dataset.clihist }).then(function (d) {
        $('#gr-dialog-body').innerHTML = '<h2>Registro de autorização</h2><ul class="gr-events">' + (d.itens.map(function (i) {
          return '<li><b>' + esc(i.acao) + '</b> · ' + esc(dt(i.registrado_em)) + '<br><span class="gr-muted">' + esc(i.origem || '') + ' ' + esc(i.observacao || '') + ' ' + esc(i.telefone || '') + '</span></li>';
        }).join('') || '<li>Sem registros.</li>') + '</ul>';
        $('#gr-dialog').showModal();
      });
    }
    if (t.id === 'gr-confirm-pix') {
      if (!confirm('Confirma que a chave Pix e as instruções de pagamento salvas estão corretas?')) { return; }
      post('confirmar_pagamento', {}).then(function () { toast('Pagamento confirmado.'); loadConfig(); }).catch(function (e) { toast(e.message, true); });
    }
    if (t.id === 'gr-test-send') {
      var num = $('#gr-test-num').value;
      if (!num) { toast('Cadastre e salve um número de teste primeiro.', true); return; }
      post('enviar_teste', { numero: num, etapa: $('#gr-test-stage').value, grupo: $('#gr-test-group').checked }).then(function (r) {
        toast((r.real ? 'Teste REAL: ' : 'Teste SIMULADO: ') + r.status + (r.detalhe ? ' — ' + r.detalhe : ''), r.status === 'falhou');
      }).catch(function (e) { toast(e.message, true); });
    }
  });

  document.addEventListener('change', function (ev) {
    if (ev.target.id === 'gr-pause') {
      post('pausa_global', { pausado: ev.target.checked }).then(function () { toast(ev.target.checked ? 'Envios pausados.' : 'Envios retomados.'); loadConfig(); }).catch(function (e) { toast(e.message, true); });
    }
  });
  document.addEventListener('input', function (ev) {
    if (ev.target.matches && ev.target.matches('#gr-cfg textarea[data-stage]')) { updatePreview(ev.target); }
  });

  $('#gr-cfg').addEventListener('submit', function (ev) {
    ev.preventDefault();
    post('salvar_config', collectConfig()).then(function (r) {
      if (!r.ok) {
        $('#gr-cfg-errors').innerHTML = r.erros.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('');
        toast('Corrija os erros indicados.', true);
        return;
      }
      toast('Configurações salvas.'); loadConfig();
    }).catch(function (e) { toast(e.message, true); });
  });

  $('#gr-run').addEventListener('click', function () {
    var b = this; b.disabled = true;
    post('executar_agora', { ignorar_horario: $('#gr-run-ignore').checked }).then(function (r) {
      state.runMsg = !r.executado ? r.motivo
        : 'Resultado: ' + (r.mensagens_criadas || 0) + ' mensagem(ns) criada(s), ' + (r.aceitas || 0) + ' aceita(s), ' + (r.falhas || 0) + ' falha(s), ' + (r.incertas || 0) + ' incerta(s)' + (r.motivo ? ' — ' + r.motivo : '') + (r.modo === 'simulacao' ? ' [SIMULAÇÃO]' : '');
      return loadDashboard();
    }).catch(function (e) { toast(e.message, true); }).then(function () { b.disabled = false; });
  });
  $('#gr-hist-reload').addEventListener('click', function () { loadHistory(false); });
  $('#gr-hist-status').addEventListener('change', function () { loadHistory(false); });
  $('#gr-hist-more').addEventListener('click', function () { state.histPage++; loadHistory(true); });
  var qTimer;
  $('#gr-cli-q').addEventListener('input', function () { clearTimeout(qTimer); qTimer = setTimeout(loadClients, 300); });

  var start = 'visao';
  try { start = localStorage.getItem('gr_tab') || 'visao'; } catch (e) { /* ignore */ }
  var btn = $('.gr-tabs button[data-tab="' + start + '"]') || $('.gr-tabs button');
  btn.click();
})();
