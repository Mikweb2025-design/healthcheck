/* Health Check – views of the app: main (today, trends, agent, ask, more), doctor report and infographic. */
(function (root) {
  'use strict';
  var HC = root.HC, h = HC.h, C = HC.charts, doc = root.document;
  var t = function (k, p) { return HC.i18n.t(k, p); };
  HC.t = t;
  var COL = { sleep: '#7c6cf2', recovery: '#2bb673', movement: '#f2994a', heart: '#e5484d', spo2: '#3ba7e0', weight: '#c084fc', fat: '#f472b6',
    vo2: '#14b8a6', deep: '#4338ca', core: '#8b7cf6', rem: '#4fb3ff', awake: '#f2c94c', steps: '#f2994a', kcal: '#ef6c3a', workout: '#22c55e' };
  var HEAT = ['#d9dee5', '#b7e4c7', '#74c69d', '#2d9d63', '#1b6b40'];
  var HEAT_DARK = ['#2a3038', '#1f4d36', '#2d7a52', '#3fae73', '#7ee0a6'];
  HC.shortMonth = function (iso) { return t('months').split(',')[parseInt(iso.slice(5, 7), 10) - 1]; };
  function dark() { try { return root.matchMedia('(prefers-color-scheme: dark)').matches; } catch (e) { return false; } }

  function card(title, body, opts) {
    opts = opts || {};
    return h('section', { class: 'hc-card ' + (opts.cls || ''), id: opts.id || null }, [title ? h('h2', { text: title }) : null].concat(body));
  }
  function stat(label, value, sub, color) {
    return h('div', { class: 'hc-stat' }, [h('div', { class: 'hc-stat-l', text: label }), h('div', { class: 'hc-stat-v', style: color ? 'color:' + color : null, text: value }), sub ? h('div', { class: 'hc-stat-s', text: sub }) : null]);
  }
  function btn(label, onclick, cls) { return h('button', { type: 'button', class: 'hc-btn ' + (cls || ''), onclick: onclick, text: label }); }
  function disclaimer() { return h('p', { class: 'hc-disclaimer', text: t('disclaimer') }); }
  function toast(msg, bad) {
    var el = h('div', { class: 'hc-toast' + (bad ? ' hc-toast-bad' : ''), role: 'status', text: msg });
    doc.body.appendChild(el);
    root.setTimeout(function () { el.classList.add('hc-toast-out'); }, 3200);
    root.setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 3800);
  }
  function errText(e) { return t('err_' + (e && e.code)) !== 'err_' + (e && e.code) ? t('err_' + e.code) : t('err_generic') + ' (' + ((e && e.code) || '?') + ')'; }
  function deltaText(cur, base, dec, unit, invert) {
    if (cur === null || cur === undefined || base === null || base === undefined) { return ''; }
    var d = cur - base;
    var cls = Math.abs(d) < Math.pow(10, -(dec || 0)) ? 'eq' : ((d > 0) !== !!invert ? 'up' : 'down');
    return { text: HC.signed(d, dec, unit), cls: cls };
  }
  function delta(cur, base, dec, unit, invert) {
    var d = deltaText(cur, base, dec, unit, invert);
    return d ? h('span', { class: 'hc-delta hc-delta-' + d.cls, text: d.text }) : null;
  }
  function warningText(w) {
    var p = Object.assign({}, w.params);
    if (w.key === 'sleep_short') { p.value = HC.hm(p.value); }
    var dec = w.key === 'spo2_drop' || w.key === 'weight_change' ? 1 : 0;
    ['value', 'base', 'min', 'pct'].forEach(function (k) { if (typeof p[k] === 'number') { p[k] = HC.num(p[k], k === 'pct' ? 1 : dec); } else if (p[k] === null) { p[k] = '–'; } });
    if (p.since) { p.since = HC.day(p.since); }
    return t('warn_' + w.key, p);
  }

  /* ======================================================= main page ======================================================= */
  var state = { ov: null, tab: 'today', range: 30 };
  var TABS = ['today', 'trends', 'agent', 'ask', 'more'];

  function mainPage(rootEl) {
    var header = h('header', { class: 'hc-head' }, [
      h('a', { class: 'hc-back', href: rootEl.getAttribute('data-home'), title: 'Nextcloud', text: '‹' }),
      h('img', { class: 'hc-logo', src: rootEl.getAttribute('data-icon').replace('app.svg', 'icon-192.png'), alt: '' }),
      h('div', { class: 'hc-title', text: t('app') })
    ]);
    var nav = h('nav', { class: 'hc-tabs', role: 'tablist' });
    TABS.forEach(function (k) {
      nav.appendChild(h('a', { href: '#' + k, role: 'tab', 'data-tab': k, class: 'hc-tab', text: t('tab_' + k) }));
    });
    var main = h('main', { class: 'hc-main', id: 'hc-main' });
    HC.clear(rootEl).appendChild(header);
    rootEl.appendChild(nav);
    rootEl.appendChild(main);
    root.addEventListener('hashchange', route);
    load().then(route);
  }

  function load() {
    return HC.api('GET', '/api/overview').then(function (ov) { state.ov = ov; return ov; }, function (e) {
      HC.clear(doc.getElementById('hc-main')).appendChild(card(t('err_title'), [h('p', { text: errText(e) })]));
      throw e;
    });
  }
  function route() {
    var hash = (root.location.hash || '#today').slice(1);
    var tab = TABS.indexOf(hash) >= 0 ? hash : (hash === 'ai' || hash === 'hints' ? 'today' : 'today');
    state.tab = tab;
    Array.prototype.forEach.call(doc.querySelectorAll('.hc-tab'), function (a) { a.setAttribute('aria-selected', a.getAttribute('data-tab') === tab ? 'true' : 'false'); });
    var main = HC.clear(doc.getElementById('hc-main'));
    if (!state.ov) { return; }
    ({ today: viewToday, trends: viewTrends, agent: viewAgent, ask: viewAsk, more: viewMore })[tab](main);
    if (hash === 'ai' || hash === 'hints') { var el = doc.getElementById('hc-' + hash); if (el) { el.scrollIntoView(); } }
  }

  function statusBanner(st) {
    if (st.running) { return h('div', { class: 'hc-banner', text: t('import_running') }); }
    if (!st.lastImport) {
      return h('div', { class: 'hc-banner hc-banner-info' }, [h('strong', { text: t('first_title') }), h('p', { text: t('first_text') }), btn(t('go_more'), function () { root.location.hash = '#more'; })]);
    }
    if (st.stale) {
      return h('div', { class: 'hc-banner hc-banner-warn' }, [h('strong', { text: t('stale_title', { days: st.ageDays }) }), h('p', { text: t('stale_text') }), btn(t('upload'), function () { root.location.hash = '#more'; })]);
    }
    if (st.pending && st.pending.length) { return h('div', { class: 'hc-banner', text: t('pending', { n: st.pending.length }) }); }
    return null;
  }

  function viewToday(main) {
    var ov = state.ov, st = ov.status;
    var b = statusBanner(st); if (b) { main.appendChild(b); }
    if (!ov.hasData) { main.appendChild(installHint()); return; }
    var rings = h('div', { class: 'hc-rings' });
    [['sleep', COL.sleep], ['recovery', COL.recovery], ['movement', COL.movement]].forEach(function (k) {
      var r = ov.rings[k[0]];
      var cell = h('button', { type: 'button', class: 'hc-ring-cell', 'aria-label': t('ring_' + k[0]) + ' ' + (r.score === null ? '–' : r.score) }, [C.ring(r.score, k[1], 120), h('div', { class: 'hc-ring-label', text: t('ring_' + k[0]) }), h('div', { class: 'hc-ring-day', text: r.day ? HC.shortDay(r.day) : '' })]);
      cell.addEventListener('click', function () { explain.textContent = ''; explain.appendChild(ringParts(k[0], r)); });
      rings.appendChild(cell);
    });
    var explain = h('div', { class: 'hc-explain' }, [h('p', { class: 'hc-muted', text: t('ring_tap') })]);
    main.appendChild(card(t('today_title'), [rings, explain]));

    // early warning
    var ws = ov.warnings || [];
    main.appendChild(card(t('hints_title'), ws.length ? ws.map(hintItem).concat([h('p', { class: 'hc-muted', text: t('hints_doctor') }), disclaimer()]) : [h('p', { text: t('hints_none') }), disclaimer()], { id: 'hc-hints', cls: ws.length ? 'hc-card-hint' : '' }));
    var coachEl = coachCard(ov.coach); if (coachEl) { main.appendChild(coachEl); }
    main.appendChild(weightCard(ov));
    main.appendChild(checkinCard());

    // last night + vitals
    var lastSleep = null, lastVitals = null;
    ov.latest.forEach(function (r) { if (r.sleep_total !== null) { lastSleep = r; } if (r.resting_hr !== null) { lastVitals = r; } });
    var wk = ov.week ? ov.week.metrics : {};
    var base = function (m) { return wk[m] && wk[m].baseline ? wk[m].baseline.avg : null; };
    var vit = h('div', { class: 'hc-grid4' }, [
      stat(t('m_resting_hr'), lastVitals ? HC.num(lastVitals.resting_hr, 0) + ' bpm' : '–', lastVitals ? t('vs_base', { v: HC.num(base('resting_hr'), 1) }) : '', COL.heart),
      stat(t('m_spo2'), lastVitals && lastVitals.spo2_avg !== null ? HC.pct(lastVitals.spo2_avg) : '–', lastVitals && lastVitals.spo2_min !== null ? t('min_v', { v: HC.pct(lastVitals.spo2_min, 0) }) : '', COL.spo2),
      stat(t('m_steps'), ov.rings.movement.day ? HC.num(findRow(ov.rings.movement.day).steps, 0) : '–', ov.rings.movement.day ? HC.shortDay(ov.rings.movement.day) : '', COL.steps),
      stat(t('m_kcal'), ov.rings.movement.day ? HC.num(findRow(ov.rings.movement.day).active_kcal, 0) + ' kcal' : '–', '', COL.kcal)
    ]);
    var sleepBox = lastSleep ? sleepBar(lastSleep) : h('p', { text: '–' });
    main.appendChild(card(t('night_title', { day: lastSleep ? HC.day(lastSleep.day) : '' }), [sleepBox, vit]));

    // week vs baseline
    if (ov.week) { main.appendChild(card(t('week_title'), [weekTable(ov.week), h('p', { class: 'hc-muted', text: t('week_note', { from: HC.day(ov.week.period.from), to: HC.day(ov.week.period.to), n: ov.week.period.days_with_data }) })])); }

    main.appendChild(aiCard(ov.ai));

    if (ov.recentWorkouts.length) {
      main.appendChild(card(t('workouts_title'), [h('ul', { class: 'hc-list' }, ov.recentWorkouts.map(workoutItem))]));
    }
    main.appendChild(h('div', { class: 'hc-links' }, [
      h('a', { class: 'hc-btn hc-btn-primary', href: HC.rootEl().getAttribute('data-base') + '/infographic', text: t('open_info') }),
      h('a', { class: 'hc-btn', href: HC.rootEl().getAttribute('data-base') + '/report', text: t('open_report') })
    ]));
    main.appendChild(installHint());
  }
  /* one early hint with an on-demand AI explanation ("why?") */
  /* the administrator can switch AI features and integrations off; unknown = on, the server decides */
  function feat(k, def) { var se = state && state.ov && state.ov.settings; return se && se[k] !== undefined ? se[k] : def; }
  function featIntegration(k) { var se = state && state.ov && state.ov.settings; return !(se && se.features && se.features[k] === false); }

  function hintItem(w) {
    var out = h('div', { class: 'hc-ai-out' });
    var b = h('button', { type: 'button', class: 'hc-btn hc-btn-small', text: t('why') });
    if (!feat('aiTasksAvailable', true)) { b.hidden = true; }
    b.addEventListener('click', function () {
      b.disabled = true; HC.clear(out).appendChild(h('p', { class: 'hc-muted', text: t('ask_thinking') }));
      HC.api('POST', '/api/ai/task/explain', { key: w.key }).then(function (r) {
        var x = r.result;
        HC.clear(out).appendChild(h('div', { class: 'hc-ai-box' }, [x.what ? h('p', { text: x.what }) : null,
          x.possible.length ? h('h4', { text: t('why_possible') }) : null, x.possible.length ? h('ul', {}, x.possible.map(function (s) { return h('li', { text: s }); })) : null,
          x.do.length ? h('h4', { text: t('why_do') }) : null, x.do.length ? h('ul', {}, x.do.map(function (s) { return h('li', { text: s }); })) : null,
          x.doctor ? h('p', { class: 'hc-doctor-note', text: '🩺 ' + x.doctor }) : null, h('p', { class: 'hc-muted hc-small', text: r.model + ' · ' + t('ai_note') })]));
      }, function (e) { HC.clear(out).appendChild(h('p', { class: 'hc-a-err', text: errText(e) })); b.disabled = false; });
    });
    return h('div', { class: 'hc-hint' }, [h('span', { class: 'hc-hint-dot' }), h('div', { class: 'hc-hint-body' }, [h('p', { text: warningText(w) }), b, out])]);
  }

  /* ---------- weight card with quick entry ---------- */
  var BMI_COL = { under: '#3ba7e0', normal: '#2bb673', over: '#f2994a', obese: '#e5484d' };
  /* daily check-in: one tap for energy 1-5, optional note, neutral link to sleep */
  function checkinCard() {
    var body = h('div', {}), msg = h('span', { class: 'hc-muted hc-small' });
    var c = card(t('ci_title'), [body]);
    function draw(r) {
      HC.clear(body);
      var cur = r.todayEntry ? r.todayEntry.energy : 0;
      var faces = ['😴', '😕', '😐', '🙂', '😄'];
      var row = h('div', { class: 'hc-row', role: 'group', 'aria-label': t('ci_title') }, faces.map(function (f, i) {
        var b = h('button', { type: 'button', class: 'hc-btn' + (cur === i + 1 ? ' hc-btn-primary' : ''), title: t('ci_e' + (i + 1)), 'aria-label': (i + 1) + ' ' + t('ci_e' + (i + 1)), 'aria-pressed': cur === i + 1 ? 'true' : 'false', text: f + ' ' + (i + 1) });
        b.addEventListener('click', function () {
          HC.api('POST', '/api/checkins', { energy: i + 1, note: note.value }).then(function (x) { draw(x); msg.textContent = t('ci_saved'); }, function (e) { toast(errText(e), true); });
        });
        return b;
      }));
      var note = h('input', { type: 'text', class: 'hc-input', maxlength: '200', placeholder: t('ci_note'), value: r.todayEntry && r.todayEntry.note ? r.todayEntry.note : '' });
      var dots = h('div', { class: 'hc-row hc-small', 'aria-hidden': 'true' }, r.entries.slice(-14).map(function (e) { return h('span', { title: HC.day(e.day) + ': ' + e.energy + '/5', text: ['·', '▁', '▂', '▄', '▆', '█'][e.energy] }); }));
      body.appendChild(h('p', { class: 'hc-muted', text: t('ci_hint') }));
      body.appendChild(row); body.appendChild(note);
      var info = [];
      if (r.todayEntry) { info.push(t('ci_today', { v: r.todayEntry.energy })); }
      if (r.avg7 !== null && r.avg7 !== undefined) { info.push(t('ci_avg', { v: HC.num(r.avg7, 1) })); }
      body.appendChild(h('p', { class: 'hc-small' }, [info.join(' · ') + ' ', msg]));
      if (r.entries.length > 1) { body.appendChild(dots); }
      body.appendChild(h('p', { class: 'hc-muted hc-small', text: r.link ? t('ci_link', { h: Math.round(r.link.threshold_min / 60) + ' h', a: HC.num(r.link.enough, 1), b: HC.num(r.link.short, 1), n: r.link.n }) : t('ci_wait') }));
      if (r.todayEntry) {
        var del = h('button', { type: 'button', class: 'hc-btn hc-btn-small', text: t('ci_remove') });
        del.addEventListener('click', function () { HC.api('DELETE', '/api/checkins/' + r.today).then(draw, function (e) { toast(errText(e), true); }); });
        body.appendChild(del);
      }
    }
    HC.api('GET', '/api/checkins').then(draw, function () { c.hidden = true; });
    return c;
  }

  /* rule-based weekly tips and rhythm numbers (no AI) */
  function coachCard(co) {
    if (!co || !co.tips || !co.tips.length) { return null; }
    var fmt = function (k, p) {
      var q = {}; Object.keys(p || {}).forEach(function (x) { q[x] = p[x]; });
      if (q.avg !== undefined && (k === 'tip_sleep_short')) { q.avg = HC.hm(q.avg); q.goal = HC.hm(q.goal); }
      if (q.avg !== undefined && k === 'tip_move') { q.avg = HC.num(q.avg, 0); q.goal = HC.num(q.goal, 0); }
      return t(k, q);
    };
    var body = [h('p', { class: 'hc-muted hc-small', text: t('coach_hint') }), h('ul', { class: 'hc-tips' }, co.tips.map(function (x) { return h('li', { text: fmt(x.key, x.params) }); }))];
    var stats = [];
    if (co.regularity) { stats.push(stat(t('coach_reg'), t('coach_reg_v', { m: co.regularity.bed_sd }), t('coach_reg_s', { t: co.regularity.bed_avg }), COL.sleep)); }
    if (co.jetlag) { stats.push(stat(t('coach_jet'), t('coach_jet_v', { s: co.jetlag.diff_min > 0 ? '+' : '−', m: Math.abs(co.jetlag.diff_min) }), t('coach_jet_s'), COL.sleep)); }
    if (co.load) { stats.push(stat(t('coach_load'), t('coach_load_' + co.load.status), t('coach_load_s', { k: co.load.kcal7, b: co.load.kcal_base }), COL.kcal)); }
    if (stats.length) { body.push(h('div', { class: 'hc-grid4' }, stats)); }
    return card(t('coach_title'), body);
  }

  function weightCard(ov) {
    var w = ov.weight, g = ov.settings.goals, body = [];
    if (w && w.has) {
      if (w.reminder) { body.push(h('div', { class: 'hc-banner hc-banner-warn', text: t('w_reminder', { days: w.age_days, day: HC.day(w.last.day) }) })); }
      var chg = function (d) { var c = w.change[d]; return c ? HC.signed(c.kg, 1, 'kg') : '–'; };
      body.push(h('div', { class: 'hc-weight-top' }, [
        h('div', {}, [h('div', { class: 'hc-big', style: 'color:' + COL.weight, text: HC.num(w.last.kg, 1) + ' kg' }), h('div', { class: 'hc-muted', text: HC.day(w.last.day) + (w.last.manual ? ' · ' + t('w_manual') : '') })]),
        h('div', { class: 'hc-chips-row' }, [7, 30, 90].map(function (d) { var c = w.change[d]; return h('span', { class: 'hc-pill' + (c && c.kg < 0 ? ' hc-pill-down' : c && c.kg > 0 ? ' hc-pill-up' : '') }, [h('small', { text: t('w_change_d', { n: d }) }), h('b', { text: chg(d) })]); }))
      ]));
      var meta = [];
      if (w.bmi) { meta.push(h('span', { class: 'hc-pill', style: 'border-color:' + BMI_COL[w.bmi.category] }, [h('small', { text: 'BMI' }), h('b', { text: HC.num(w.bmi.value, 1) + ' · ' + t('bmi_' + w.bmi.category) })])); }
      if (w.bodyfat) { meta.push(h('span', { class: 'hc-pill' }, [h('small', { text: t('m_bodyfat') }), h('b', { text: HC.num(w.bodyfat.pct, 1) + ' %' })])); }
      if (w.rate_kg_week !== null) { meta.push(h('span', { class: 'hc-pill' }, [h('small', { text: t('w_rate') }), h('b', { text: HC.signed(w.rate_kg_week, 2, 'kg/' + t('u_week')) })])); }
      body.push(h('div', { class: 'hc-chips-row' }, meta));
      if (g.weight) {
        var e = w.eta;
        body.push(h('p', { class: 'hc-eta', text: !e ? t('w_goal_only', { goal: HC.num(g.weight, 1) }) : e.reached ? t('w_goal_reached', { goal: HC.num(g.weight, 1) })
          : e.days ? t('w_eta', { goal: HC.num(g.weight, 1), date: HC.day(e.date), weeks: Math.round(e.days / 7) }) : t('w_eta_away', { goal: HC.num(g.weight, 1) }) }));
      }
      var pts = (ov.weightTrend || []).map(function (p) { return { x: p.day, y: p.weight }; });
      body.push(C.line(pts, { color: COL.weight, ma: pts.length >= 10 ? 7 : 0, goal: g.weight, goalLabel: t('target'), fmt: function (v) { return HC.num(v, 1); }, unit: 'kg', empty: t('no_data'), dots: true }));
      body.push(h('p', { class: 'hc-muted hc-small', text: t('w_chart_note', { n: w.count }) }));
    } else {
      body.push(h('p', { text: t('w_none') }));
    }
    // quick entry
    var today = ov.status.today;
    var f = h('form', { class: 'hc-weight-form' }, [
      h('label', { class: 'hc-field' }, [h('span', { text: t('w_today') + ' (kg)' }), h('input', { name: 'weight', type: 'number', step: '0.1', min: 21, max: 300, inputmode: 'decimal', required: true, placeholder: w && w.has ? HC.num(w.last.kg, 1) : '' })]),
      h('label', { class: 'hc-field' }, [h('span', { text: t('m_bodyfat') + ' % (' + t('optional') + ')' }), h('input', { name: 'bodyfat', type: 'number', step: '0.1', min: 2, max: 70, inputmode: 'decimal' })]),
      h('label', { class: 'hc-field' }, [h('span', { text: t('date') }), h('input', { name: 'day', type: 'date', value: today, max: today })]),
      h('button', { type: 'submit', class: 'hc-btn hc-btn-primary', text: t('save') })
    ]);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      HC.api('POST', '/api/weights', { weight: f.elements.weight.value, bodyfat: f.elements.bodyfat.value, day: f.elements.day.value })
        .then(function () { toast(t('saved')); return load(); }).then(route, function (er) { toast(errText(er), true); });
    });
    body.push(f);
    if (ov.weightEntries && ov.weightEntries.length) {
      body.push(h('details', { class: 'hc-how' }, [h('summary', { text: t('w_entries', { n: ov.weightEntries.length }) }), h('ul', { class: 'hc-list' }, ov.weightEntries.map(function (en) {
        return h('li', {}, [h('div', { class: 'hc-grow', text: HC.day(en.day) + ' · ' + HC.num(en.weight, 1) + ' kg' + (en.bodyfat !== null ? ' · ' + HC.num(en.bodyfat, 1) + ' %' : '') }),
          btn(t('delete'), function () { if (root.confirm(t('w_delete_confirm'))) { HC.api('DELETE', '/api/weights/' + en.id).then(function () { return load(); }).then(route); } }, 'hc-btn-small hc-btn-danger')]);
      }))]));
    }
    return card(t('w_title'), body, { id: 'hc-weight' });
  }

  function findRow(day) { var r = null; state.ov.latest.forEach(function (x) { if (x.day === day) { r = x; } }); return r || {}; }

  function ringParts(kind, r) {
    if (r.score === null) { return h('p', { text: t('ring_none_' + kind) }); }
    var rows = r.parts.map(function (p) {
      var v = p.key === 'duration' ? HC.hm(p.value) : (p.key === 'deep' || p.key === 'rem' || p.key === 'spo2') ? HC.pct(p.value, 0) : p.key === 'awake' ? HC.hm(p.value) : HC.num(p.value, p.key === 'rhr' ? 0 : 0);
      var tg = p.key === 'duration' ? HC.hm(p.target) : (p.key === 'deep' || p.key === 'rem' || p.key === 'spo2') ? HC.pct(p.target, 0) : p.key === 'awake' ? HC.hm(p.target) : HC.num(p.target, p.key === 'rhr' ? 1 : 0);
      return h('tr', {}, [h('td', { text: t('part_' + p.key) }), h('td', { text: v }), h('td', { text: (p.key === 'rhr' ? t('baseline') : t('target')) + ' ' + tg }), h('td', { class: 'hc-num', text: p.points + (p.weight ? ' × ' + p.weight + ' %' : '') })]);
    });
    return h('div', {}, [h('h3', { text: t('ring_' + kind) + ': ' + r.score + '/100 · ' + HC.day(r.day) }), h('table', { class: 'hc-table' }, [h('tbody', {}, rows)]), h('p', { class: 'hc-muted', text: t('ring_how_' + kind) })]);
  }

  function sleepBar(r) {
    var tot = r.sleep_total || 1, parts = [['deep', r.sleep_deep], ['core', r.sleep_core], ['rem', r.sleep_rem]];
    var bar = h('div', { class: 'hc-stagebar' });
    parts.forEach(function (p) { if (p[1]) { bar.appendChild(h('span', { style: 'width:' + (p[1] / tot * 100).toFixed(1) + '%;background:' + COL[p[0]], title: t('stage_' + p[0]) })); } });
    var legend = h('div', { class: 'hc-legend' }, parts.filter(function (p) { return p[1] !== null; }).map(function (p) {
      return h('span', {}, [h('i', { style: 'background:' + COL[p[0]] }), t('stage_' + p[0]) + ' ' + HC.hm(p[1]) + ' (' + HC.num(p[1] / tot * 100, 0) + ' %)']);
    }).concat(r.sleep_awake ? [h('span', {}, [h('i', { style: 'background:' + COL.awake }), t('stage_awake') + ' ' + HC.hm(r.sleep_awake)])] : []));
    return h('div', { class: 'hc-sleep' }, [
      h('div', { class: 'hc-sleep-top' }, [h('span', { class: 'hc-big', style: 'color:' + COL.sleep, text: HC.hm(r.sleep_total) }), h('span', { class: 'hc-muted', text: r.sleep_start ? HC.clock(r.sleep_start) + ' – ' + HC.clock(r.sleep_end) : '' })]),
      bar, legend]);
  }

  var WEEK_ROWS = [['sleep_total', 'm_sleep', 'min', 0], ['sleep_deep', 'm_deep', 'min', 0], ['resting_hr', 'm_resting_hr', 'bpm', 1, true], ['spo2_avg', 'm_spo2', 'pct', 1],
    ['hrv', 'm_hrv', 'ms', 0], ['steps', 'm_steps', '', 0], ['active_kcal', 'm_kcal', 'kcal', 0], ['weight', 'm_weight', 'kg', 1, true], ['vo2max', 'm_vo2', '', 1]];
  function fmtMetric(v, unit, dec) {
    if (v === null || v === undefined) { return '–'; }
    if (unit === 'min') { return HC.hm(v); }
    if (unit === 'pct') { return HC.pct(v, dec); }
    return HC.num(v, dec) + (unit ? ' ' + unit : '');
  }
  function weekTable(sum) {
    var body = WEEK_ROWS.filter(function (r) { return sum.metrics[r[0]] && sum.metrics[r[0]].period; }).map(function (r) {
      var m = sum.metrics[r[0]], cur = m.period.avg, b = m.baseline ? m.baseline.avg : null;
      var d = r[2] === 'min' ? delta(cur, b, 0, 'min', r[4]) : r[2] === 'pct' ? delta(cur === null ? null : cur * 100, b === null ? null : b * 100, 1, '%', r[4]) : delta(cur, b, r[3], r[2], r[4]);
      return h('tr', {}, [h('td', { text: t(r[1]) }), h('td', { class: 'hc-num', text: fmtMetric(cur, r[2], r[3]) }), h('td', { class: 'hc-num hc-muted', text: fmtMetric(b, r[2], r[3]) }), h('td', { class: 'hc-num' }, [d])]);
    });
    return h('table', { class: 'hc-table' }, [h('thead', {}, [h('tr', {}, [h('th', { text: '' }), h('th', { text: t('col_7d') }), h('th', { text: t('col_28d') }), h('th', { text: 'Δ' })])]), h('tbody', {}, body)]);
  }
  function workoutItem(w) {
    var emoji = { Running: '🏃', Walking: '🚶', Hiking: '🥾', Cycling: '🚴', Swimming: '🏊' }[w.activity] || '🏅';
    var parts = [HC.day(w.day), HC.hm(w.duration_min, true)];
    if (w.distance_m) { parts.push(HC.num(w.distance_m / 1000, 1) + ' km'); }
    if (w.kcal) { parts.push(w.kcal + ' kcal'); }
    return h('li', {}, [h('span', { class: 'hc-wo-e', text: emoji }), h('div', {}, [h('strong', { text: t('act_' + w.activity) !== 'act_' + w.activity ? t('act_' + w.activity) : w.activity }), h('div', { class: 'hc-muted', text: parts.join(' · ') })])]);
  }

  function aiCard(ai) {
    var body = [];
    if (!ai) {
      body.push(h('p', { text: t('ai_none') }));
    } else {
      body.push(h('p', { class: 'hc-muted', text: t('ai_meta', { date: HC.dateTime(ai.createdAt), model: ai.model || '?', to: HC.day(ai.to) }) }));
      body.push(h('p', { class: 'hc-ai-text', text: ai.summary }));
      if (ai.tips && ai.tips.length) { body.push(h('h3', { text: t('ai_tips') })); body.push(h('ol', { class: 'hc-tips' }, ai.tips.map(function (x) { return h('li', { text: x }); }))); }
      if (ai.plan) { body.push(planBox(ai.plan, ai.planEvaluation)); }
    }
    var b = btn(t('ai_request'), function () {
      b.disabled = true;
      HC.api('POST', '/api/ai/request').then(function () { toast(t('ai_requested')); pollReport(ai ? ai.id : 0); }, function (e) { b.disabled = false; toast(errText(e), true); });
    });
    body.push(h('div', { class: 'hc-row' }, [b]));
    body.push(disclaimer());
    return card(t('ai_title'), body, { id: 'hc-ai' });
  }
  function pollReport(lastId) {
    var n = 0;
    var tick = function () {
      HC.api('GET', '/api/ai/reports').then(function (r) {
        if (r.reports.length && r.reports[0].id !== lastId) { load().then(route); toast(t('ai_arrived')); return; }
        if (++n < 24) { root.setTimeout(tick, 5000); }
      });
    };
    root.setTimeout(tick, 5000);
  }
  function planBox(plan, ev) {
    var evByDay = {};
    ((ev && ev.details) || []).forEach(function (d) { evByDay[d.date] = d; });
    var list = h('ul', { class: 'hc-plan' }, plan.days.map(function (d) {
      var e = evByDay[d.date], mark = !e || e.ok === null ? '·' : e.ok ? '✓' : '✗';
      return h('li', { class: 'hc-plan-' + d.kind }, [h('span', { class: 'hc-plan-mark', text: mark }), h('strong', { text: HC.weekday(d.date) + ' ' + HC.shortDay(d.date) + ' – ' + t('plan_' + d.kind) }), d.note ? h('span', { class: 'hc-muted', text: ' ' + d.note }) : null]);
    }));
    var out = [h('h3', { text: t('plan_title') }), plan.focus ? h('p', { text: plan.focus }) : null, list];
    if (plan.bedtime) { out.push(h('p', { text: t('plan_bedtime', { t: plan.bedtime }) + (ev && ev.bedtime ? ' · ' + t('plan_bed_eval', { ok: ev.bedtime.on_time, n: ev.bedtime.nights }) : '') })); }
    if (ev && ev.adherence_pct !== null) { out.push(h('p', { class: 'hc-muted', text: t('plan_eval', { pct: ev.adherence_pct, met: ev.days_met, n: ev.days_evaluated }) })); }
    return h('div', { class: 'hc-planbox' }, out);
  }
  HC.weekday = function (iso) { return t('daynames').split(',')[(new Date(iso + 'T12:00:00Z').getUTCDay() + 6) % 7]; };

  /* ------------------------------------------------------- trends ------------------------------------------------------- */
  var RANGES = [7, 30, 90, 365, 0];
  function viewTrends(main) {
    var sel = h('div', { class: 'hc-seg', role: 'group' });
    RANGES.forEach(function (d) {
      sel.appendChild(h('button', { type: 'button', class: 'hc-seg-b', 'aria-pressed': state.range === d ? 'true' : 'false', text: d ? t('range_d', { n: d }) : t('range_all'),
        onclick: function () { state.range = d; route(); } }));
    });
    main.appendChild(sel);
    var box = h('div', { class: 'hc-trends' }, [h('p', { class: 'hc-muted', text: t('loading') })]);
    main.appendChild(box);
    var to = state.ov.range ? state.ov.range.to : null;
    var from = state.range && to ? HC.addDays(to, -(state.range - 1)) : null;
    HC.api('GET', '/api/series', undefined, { from: from, to: to }).then(function (s) { renderTrends(HC.clear(box), s, state.range || 99999); }, function (e) { HC.clear(box).appendChild(h('p', { text: errText(e) })); });
  }
  function renderTrends(box, s, days) {
    var rows = s.days, span = rows.length ? Math.round((Date.parse(rows[rows.length - 1].day) - Date.parse(rows[0].day)) / 864e5) + 1 : 0;
    var bucket = HC.bucketFor(Math.min(days, span));
    var note = bucket === 'day' ? '' : t('bucket_' + bucket);
    var chart = function (title, el, sub) { box.appendChild(card(title, [el, sub ? h('p', { class: 'hc-muted', text: sub }) : null], { cls: 'hc-chart' })); };
    var daily = bucket === 'day';
    var wdays = s.workouts.map(function (w) { return w.day; });
    var lineOpts = function (color, fmt, unit, extra) { return Object.assign({ color: color, band: 'auto', ma: daily ? 7 : 0, anomalies: daily, empty: t('no_data'), fmt: fmt, unit: unit }, extra || {}); };
    var rhr = HC.series(rows, 'resting_hr', bucket);
    var st = C.meanSd(rhr.map(function (p) { return p.y; }));
    chart(t('m_resting_hr') + ' (bpm)', C.line(rhr, lineOpts(COL.heart, function (v) { return HC.num(v, 0); }, 'bpm', { markers: wdays, markerLabel: t('m_workouts') })),
      (note ? note + ' · ' : '') + (st && rhr.length >= 10 ? t('band_sd', { lo: HC.num(st.mean - st.sd, 1), hi: HC.num(st.mean + st.sd, 1), n: st.n }) : '') + (daily ? ' · ' + t('ma_note') + ' · ' + t('marker_note') : ''));
    chart(t('c_sleepwin'), C.sleepWindow(rows.slice(-Math.min(rows.length, 120)), { colors: { deep: COL.deep, core: COL.core, rem: COL.rem, awake: COL.awake }, empty: t('no_data') }), t('sleepwin_note'));
    // sleep stages
    var st = {};
    rows.forEach(function (r) { if (r.sleep_total !== null) { var k = bucket === 'week' ? HC.mondayOf(r.day) : bucket === 'month' ? r.day.slice(0, 7) + '-01' : r.day; (st[k] = st[k] || []).push(r); } });
    var sp = Object.keys(st).sort().map(function (k) {
      var g = st[k], a = function (c) { return HC.avg(g.map(function (r) { return r[c]; })) || 0; };
      var deep = a('sleep_deep'), rem = a('sleep_rem'), core = a('sleep_core'), tot = a('sleep_total');
      var rest = Math.max(0, tot - deep - rem - core);
      return { x: k, parts: [deep / 60, core / 60, rem / 60, rest / 60] };
    });
    chart(t('c_sleep'), C.bars(sp, { colors: [COL.deep, COL.core, COL.rem, '#9aa3ad'], names: [t('stage_deep'), t('stage_core'), t('stage_rem'), t('stage_other')], target: (state.ov.settings.goals.sleep_min || 450) / 60, targetLabel: t('target'), empty: t('no_data'), fmt: function (v) { return HC.hm(v * 60); } }),
      [t('stage_deep'), t('stage_core'), t('stage_rem'), t('stage_other')].join(' · ') + (note ? ' · ' + note : ''));
    chart(t('m_spo2') + ' (%)', C.line(HC.series(rows, 'spo2_avg', bucket, 100), lineOpts(COL.spo2, function (v) { return HC.num(v, 1); }, '%')), note);
    var stepsGoal = state.ov.settings.goals.steps;
    chart(t('m_steps'), C.bars(HC.series(rows, 'steps', bucket), { color: COL.steps, target: stepsGoal, targetLabel: t('target'), empty: t('no_data') }), note);
    chart(t('m_kcal') + ' (kcal)', C.bars(HC.series(rows, 'active_kcal', bucket), { color: COL.kcal, target: state.ov.settings.goals.active_kcal, targetLabel: t('target'), empty: t('no_data') }), note);
    var wt = HC.series(rows, 'weight', bucket);
    chart(t('m_weight') + ' (kg)', C.line(wt, { color: COL.weight, ma: daily && wt.length >= 10 ? 7 : 0, goal: state.ov.settings.goals.weight, goalLabel: t('target'), dots: true, fmt: function (v) { return HC.num(v, 1); }, unit: 'kg', empty: t('w_none_period') }), note);
    var bf = HC.series(rows, 'bodyfat', bucket, 100);
    if (bf.length) { chart(t('m_bodyfat') + ' (%)', C.line(bf, { color: COL.fat, dots: true, fmt: function (v) { return HC.num(v, 1); }, unit: '%' }), note); }
    var vo = HC.series(rows, 'vo2max', bucket === 'day' ? 'week' : bucket);
    if (vo.length) { chart(t('m_vo2') + ' (ml/kg/min)', C.line(vo, { color: COL.vo2, dots: true, fmt: function (v) { return HC.num(v, 1); } }), t('vo2_note')); }
    var hrv = HC.series(rows, 'hrv', bucket);
    if (hrv.length) { chart(t('m_hrv') + ' (ms)', C.line(hrv, lineOpts(COL.recovery, function (v) { return HC.num(v, 0); }, 'ms')), ''); }
    // workouts: minutes per week (or month)
    var wb = bucket === 'month' ? 'month' : 'week', acc = {};
    s.workouts.forEach(function (w) { var k = wb === 'week' ? HC.mondayOf(w.day) : w.day.slice(0, 7) + '-01'; acc[k] = (acc[k] || 0) + w.duration_min; });
    var wp = Object.keys(acc).sort().map(function (k) { return { x: k, y: acc[k] }; });
    chart(t('c_workouts_' + wb), C.bars(wp, { color: COL.workout, empty: t('no_workouts'), fmt: function (v) { return HC.hm(v); } }), t('workouts_count', { n: s.workouts.length }));
  }

  /* ------------------------------------------------------- agent ------------------------------------------------------- */
  function viewAgent(main) {
    var ov = state.ov, g = ov.settings.goals, p = ov.progress;
    main.appendChild(h('p', { class: 'hc-lead', text: t('agent_lead') }));
    if (p) {
      var tg = p.targets, rows = [
        [t('m_steps'), p.steps.avg, tg.steps, HC.num(p.steps.avg, 0) + ' / ' + HC.num(tg.steps, 0), t('days_met', { n: p.steps.days_met, d: p.days_in_week }), COL.steps],
        [t('m_sleep'), p.sleep.avg, tg.sleep_min, HC.hm(p.sleep.avg) + ' / ' + HC.hm(tg.sleep_min), t('days_met', { n: p.sleep.days_met, d: p.days_in_week }), COL.sleep],
        [t('m_kcal'), p.active_kcal.avg, tg.active_kcal, HC.num(p.active_kcal.avg, 0) + ' / ' + HC.num(tg.active_kcal, 0) + ' kcal', t('days_met', { n: p.active_kcal.days_met, d: p.days_in_week }), COL.kcal],
        [t('m_workouts'), p.workouts.done, p.workouts.target, p.workouts.done + ' / ' + p.workouts.target, '', COL.workout]
      ];
      main.appendChild(card(t('week_targets', { from: HC.day(p.week_start) }), rows.map(function (r) {
        return h('div', { class: 'hc-goal' }, [h('div', { class: 'hc-goal-top' }, [h('strong', { text: r[0] }), h('span', { text: r[3] })]), C.progress(r[1] || 0, r[2], r[5]), r[4] ? h('div', { class: 'hc-muted', text: r[4] }) : null]);
      }).concat([p.weight.goal ? h('p', { text: t('weight_goal', { last: HC.num(p.weight.last, 1), goal: HC.num(p.weight.goal, 1) }) + (ov.weight && ov.weight.eta && ov.weight.eta.days ? ' · ' + t('w_eta_short', { date: HC.day(ov.weight.eta.date) }) : '') }) : null,
        h('p', { class: 'hc-muted', text: t('adaptive_note', { steps: HC.num(tg.basis.steps, 0), sleep: HC.hm(tg.basis.sleep_min) }) })])));
    }
    main.appendChild(checkupCard());
    main.appendChild(corrCard(ov.correlations));
    var s = ov.streaks;
    main.appendChild(card(t('streaks_title'), [h('div', { class: 'hc-grid4' }, [
      stat(t('streak_steps'), s.steps.current + ' ' + t('u_days'), t('best_v', { v: s.steps.best }), COL.steps),
      stat(t('streak_sleep'), s.sleep.current + ' ' + t('u_days'), t('best_v', { v: s.sleep.best }), COL.sleep)])]));
    if (ov.ai && ov.ai.plan) { main.appendChild(card(t('plan_card'), [planBox(ov.ai.plan, ov.ai.planEvaluation), disclaimer()])); } else { main.appendChild(card(t('plan_card'), [h('p', { text: t('plan_none') })])); }
    // goals form
    var f = h('form', { class: 'hc-form' });
    var field = function (name, label, val, attrs) {
      return h('label', { class: 'hc-field' }, [h('span', { text: label }), h('input', Object.assign({ name: name, type: 'number', value: val === null ? '' : val, inputmode: 'decimal' }, attrs || {}))]);
    };
    f.appendChild(field('sleep_h', t('goal_sleep'), (g.sleep_min / 60).toFixed(2).replace(/0$/, ''), { step: '0.25', min: 4, max: 12 }));
    f.appendChild(field('steps', t('goal_steps'), g.steps, { step: 500, min: 1000, max: 40000 }));
    f.appendChild(field('active_kcal', t('goal_kcal'), g.active_kcal, { step: 50, min: 50, max: 3000 }));
    f.appendChild(field('workouts_week', t('goal_workouts'), g.workouts_week, { step: 1, min: 0, max: 14 }));
    f.appendChild(field('weight', t('goal_weight'), g.weight, { step: 0.1, min: 30, max: 250 }));
    f.appendChild(h('button', { type: 'submit', class: 'hc-btn hc-btn-primary', text: t('save') }));
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = function (n) { return f.elements[n].value; };
      HC.api('PUT', '/api/settings', { goals: { sleep_min: Math.round(parseFloat(v('sleep_h')) * 60), steps: v('steps'), active_kcal: v('active_kcal'), workouts_week: v('workouts_week'), weight: v('weight') || null } })
        .then(function () { toast(t('saved')); return load(); }).then(route, function (er) { toast(errText(er), true); });
    });
    main.appendChild(card(t('goals_title'), [f]));
  }

  function checkupCard() {
    var latest = state.ov.aiLatest && state.ov.aiLatest.checkup;
    var out = h('div', { class: 'hc-ai-out' });
    var sel = h('select', { class: 'hc-select' }, [30, 90, 365].map(function (d) { return h('option', { value: d, text: t('range_d', { n: d }) }); }));
    var b = btn(t('checkup_run'), function () {
      b.disabled = true; HC.clear(out).appendChild(h('p', { class: 'hc-muted', text: t('ask_thinking') }));
      HC.api('POST', '/api/ai/task/checkup', { days: sel.value }).then(function (r) { renderCheckup(out, r); }, function (e) { HC.clear(out).appendChild(h('p', { class: 'hc-a-err', text: errText(e) })); }).then(function () { b.disabled = false; });
    }, 'hc-btn-primary');
    if (!feat('aiTasksAvailable', true)) { b.hidden = true; sel.hidden = true; }
    if (latest && latest.result) { renderCheckup(out, latest); }
    return card(t('checkup_title'), [h('p', { class: 'hc-muted', text: t('checkup_intro') }), h('div', { class: 'hc-row' }, [sel, b]), out, disclaimer()], { id: 'hc-checkup' });
  }
  function renderCheckup(out, r) {
    var x = r.result, sec = function (title, cls, items) { return items && items.length ? h('div', { class: 'hc-cu ' + cls }, [h('h4', { text: title }), h('ul', {}, items.map(function (s) { return h('li', { text: s }); }))]) : null; };
    var parts = String(r.request || '').split(':');
    HC.clear(out).appendChild(h('div', { class: 'hc-cu-grid' }, [sec('✓ ' + t('cu_good'), 'hc-cu-good', x.good), sec('👀 ' + t('cu_watch'), 'hc-cu-watch', x.watch), sec('→ ' + t('cu_actions'), 'hc-cu-act', x.actions),
      x.doctor ? h('div', { class: 'hc-cu hc-cu-doc' }, [h('h4', { text: '🩺 ' + t('cu_doctor') }), h('p', { text: x.doctor })]) : null]));
    out.appendChild(h('p', { class: 'hc-muted hc-small', text: (parts[1] ? t('range_d', { n: parts[1] }) + ' · ' : '') + HC.dateTime(r.at) + ' · ' + r.model }));
  }
  function corrCard(c) {
    if (!c) { return h('span'); }
    var items = c.pairs.map(function (p) {
      var dir = p.r > 0 ? 'pos' : 'neg';
      var bar = h('div', { class: 'hc-corr-bar' }, [h('span', { class: 'hc-corr-fill hc-corr-' + dir, style: 'width:' + Math.round(Math.abs(p.r) * 100) + '%' })]);
      return h('li', { class: 'hc-corr' }, [h('div', { class: 'hc-corr-top' }, [h('strong', { text: t('corr_' + p.key) }), h('span', { class: 'hc-muted', text: 'r = ' + HC.num(p.r, 2) + ' · n = ' + p.n })]), bar,
        h('div', { class: 'hc-muted hc-small', text: t('strength_' + p.strength) + (p.strength !== 'none' ? ' · ' + t('corr_dir_' + dir) : '') })]);
    });
    var wd = c.weekday || [];
    var names = t('daynames').split(',');
    var body = [h('p', { class: 'hc-muted', text: t('corr_intro', { from: HC.day(c.from), to: HC.day(c.to) }) })];
    body.push(items.length ? h('ul', { class: 'hc-list hc-corr-list' }, items) : h('p', { text: t('corr_none') }));
    if (wd.length) {
      body.push(h('h3', { text: t('weekday_title') }));
      body.push(C.hbars(wd.map(function (d) { return { label: names[d.weekday], v: d.steps, color: COL.steps }; }), function (v) { return HC.num(v, 0) + ' ' + t('u_steps'); }));
      body.push(C.hbars(wd.map(function (d) { return { label: names[d.weekday], v: d.sleep_total, color: COL.sleep }; }), function (v) { return HC.hm(v); }));
    }
    body.push(h('p', { class: 'hc-muted hc-small', text: t('corr_note') }));
    return card(t('corr_title'), body);
  }

  /* ------------------------------------------------------- ask ------------------------------------------------------- */
  function viewAsk(main) {
    var list = h('div', { class: 'hc-chat' });
    var input = h('textarea', { rows: 2, maxlength: 500, placeholder: t('ask_ph'), class: 'hc-input' });
    var send = h('button', { type: 'submit', class: 'hc-btn hc-btn-primary', text: t('ask_send') });
    var form = h('form', { class: 'hc-askform' }, [input, send]);
    var examples = h('div', { class: 'hc-chips' }, ['ask_ex1', 'ask_ex2', 'ask_ex3'].map(function (k) { return h('button', { type: 'button', class: 'hc-chip', text: t(k), onclick: function () { input.value = t(k); input.focus(); } }); }));
    main.appendChild(card(t('ask_title'), [h('p', { class: 'hc-muted', text: t('ask_intro') }), examples, form, list, disclaimer()]));
    var bubble = function (q, a, meta, err) {
      return h('div', { class: 'hc-qa' }, [h('div', { class: 'hc-q', text: q }), h('div', { class: 'hc-a' + (err ? ' hc-a-err' : ''), text: a }), meta ? h('div', { class: 'hc-muted hc-small', text: meta }) : null]);
    };
    HC.api('GET', '/api/chat').then(function (r) {
      if (!r.available) { form.replaceWith(h('p', { class: 'hc-banner', text: t('ask_unavailable') })); }
      r.chats.filter(function (c) { return c.status === 'ok'; }).forEach(function (c) { list.appendChild(bubble(c.question, c.answer, HC.dateTime(c.at) + ' · ' + c.model)); });
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var q = input.value.trim();
      if (q.length < 3) { return; }
      send.disabled = true;
      var pending = bubble(q, t('ask_thinking'));
      list.insertBefore(pending, list.firstChild);
      HC.api('POST', '/api/chat', { question: q }).then(function (r) {
        list.replaceChild(bubble(r.question, r.answer, HC.dateTime(r.at) + ' · ' + r.model), pending); input.value = '';
      }, function (er) { list.replaceChild(bubble(q, errText(er), '', true), pending); }).then(function () { send.disabled = false; });
    });
  }

  /* ------------------------------------------------------- more ------------------------------------------------------- */
  function viewMore(main) {
    var ov = state.ov, st = ov.status, se = ov.settings, rootEl = HC.rootEl();
    // data status
    var li = st.lastImport;
    var sBody = [h('div', { class: 'hc-grid4' }, [
      stat(t('st_last_day'), li ? HC.day(li.lastDay) : '–', st.ageDays !== null ? t('age_days', { n: st.ageDays }) : '', st.stale ? COL.heart : COL.recovery),
      stat(t('st_last_import'), li ? HC.dateTime(li.finishedAt) : '–', li ? t('import_meta', { s: HC.num(li.durationMs / 1000, 1), mb: li.peakMb }) : ''),
      stat(t('st_days'), ov.range ? HC.num(ov.range.days, 0) : '–', ov.range ? HC.day(ov.range.from) + ' – ' + HC.day(ov.range.to) : ''),
      stat(t('st_pending'), String(st.pending.length), st.pending.length ? st.pending[0].name : ''),
      stat(t('st_push'), st.push && st.push.lastAt ? HC.dateTime(st.push.lastAt) : '–', st.push && st.push.lastAt ? t('push_meta', { n: st.push.samples, day: HC.day(st.push.lastDay) }) : (st.push && st.push.tokenSet ? t('push_waiting') : t('push_off')))
    ])];
    if (st.stale) { sBody.unshift(h('div', { class: 'hc-banner hc-banner-warn', text: t('stale_title', { days: st.ageDays }) + ' ' + t('stale_text') })); }
    var prog = h('div', { class: 'hc-muted' });
    var file = h('input', { type: 'file', accept: '.zip,application/zip', class: 'hc-file', id: 'hc-file' });
    var importBtn = btn(t('import_now'), function () {
      importBtn.disabled = true; prog.textContent = t('import_running');
      HC.api('POST', '/api/import').then(function (r) {
        prog.textContent = r.status === 'ok' ? t('import_ok', { days: r.days, w: r.workouts, s: HC.num(r.duration_ms / 1000, 1) }) : t('import_' + r.status) + (r.message ? ' (' + r.message + ')' : '');
        return load().then(route);
      }, function (e) { prog.textContent = errText(e) + ' – ' + t('import_bg'); }).then(function () { importBtn.disabled = false; });
    }, 'hc-btn-primary');
    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      if (!f) { return; }
      prog.textContent = t('uploading', { p: 0 });
      HC.upload(f, function (p) { prog.textContent = t('uploading', { p: Math.round(p * 100) }); }).then(function () { prog.textContent = t('uploaded'); importBtn.click(); }, function (e) { prog.textContent = errText(e); });
    });
    sBody.push(h('div', { class: 'hc-row' }, [h('label', { class: 'hc-btn', for: 'hc-file', text: t('upload') }), file, importBtn, h('a', { class: 'hc-btn', href: rootEl.getAttribute('data-files'), text: t('open_folder') })]));
    sBody.push(prog);
    sBody.push(h('details', { class: 'hc-how' }, [h('summary', { text: t('how_title') }), h('ol', {}, [1, 2, 3, 4, 5].map(function (i) { return h('li', { text: t('how_' + i) }); }))]));
    main.appendChild(card(t('status_title'), sBody));
    HC.api('GET', '/api/status').then(function (s) {
      if (!s.imports.length) { return; }
      main.insertBefore(card(t('imports_title'), [h('table', { class: 'hc-table hc-small' }, [h('tbody', {}, s.imports.map(function (i) {
        return h('tr', {}, [h('td', { text: HC.dateTime(i.startedAt) }), h('td', { text: t('st_' + i.status) }), h('td', { text: i.days !== null ? t('import_row', { d: i.days, w: i.workouts }) : (i.message || '') }), h('td', { class: 'hc-num', text: i.durationMs !== null ? HC.num(i.durationMs / 1000, 1) + ' s' : '' })]);
      }))])]), main.children[1] || null);
    });
    if (featIntegration('ingest')) { main.appendChild(pushCard(st)); }
    var exBase = HC.rootEl().getAttribute('data-base');
    main.appendChild(card(t('export_title'), [h('p', { class: 'hc-muted', text: t('export_hint') }), h('div', { class: 'hc-row' }, [
      h('a', { class: 'hc-btn', href: exBase + '/api/export?format=json', download: '', text: t('export_json') }),
      h('a', { class: 'hc-btn', href: exBase + '/api/export?format=csv', download: '', text: t('export_csv') })])]));
    main.appendChild(installHint(true));
    // settings
    var f = h('form', { class: 'hc-form' });
    var check = function (name, label, on) { return h('label', { class: 'hc-check' }, [h('input', { type: 'checkbox', name: name, checked: on ? true : null }), h('span', { text: label })]); };
    var langSel = h('select', { name: 'lang' }, [['', t('lang_auto')], ['de', 'Deutsch'], ['en', 'English'], ['it', 'Italiano']].map(function (o) { return h('option', { value: o[0], selected: se.lang === o[0] ? true : null, text: o[1] }); }));
    f.appendChild(h('label', { class: 'hc-field' }, [h('span', { text: t('set_lang') }), langSel]));
    var repSel = h('select', { name: 'reportLang' }, [['', t('lang_same')], ['de', 'Deutsch'], ['en', 'English'], ['it', 'Italiano']].map(function (o) { return h('option', { value: o[0], selected: se.reportLang === o[0] ? true : null, text: o[1] }); }));
    f.appendChild(h('label', { class: 'hc-field' }, [h('span', { text: t('set_report_lang') }), repSel]));
    f.appendChild(check('notifyImport', t('set_notify_import'), se.notifyImport));
    f.appendChild(check('nudges', t('set_nudges'), se.nudges));
    f.appendChild(check('calendarSync', t('set_cal'), se.calendarSync));
    f.appendChild(check('calendarDaily', t('set_cal_daily'), se.calendarDaily));
    var hookField = h('label', { class: 'hc-field' }, [h('span', { text: t('set_webhook') }), h('input', { name: 'webhookUrl', type: 'url', value: se.webhookUrl, placeholder: 'https://…/webhook/…' })]);
    f.appendChild(hookField);
    var secretOut = h('code', { class: 'hc-secret' });
    var hookRow = h('div', { class: 'hc-row' }, [h('span', { class: 'hc-muted', text: se.webhookSecretSet ? t('secret_set') : t('secret_unset') }),
      btn(t('secret_new'), function () { if (!root.confirm(t('secret_confirm'))) { return; } HC.api('POST', '/api/settings/secret').then(function (r) { secretOut.textContent = r.secret; toast(t('secret_copy')); }); }), secretOut]);
    f.appendChild(hookRow);
    if (!featIntegration('webhooks')) { hookField.hidden = true; hookRow.hidden = true; }
    f.appendChild(h('button', { type: 'submit', class: 'hc-btn hc-btn-primary', text: t('save') }));
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      HC.api('PUT', '/api/settings', { lang: langSel.value, reportLang: repSel.value, notifyImport: f.elements.notifyImport.checked, nudges: f.elements.nudges.checked, calendarSync: f.elements.calendarSync.checked,
        calendarDaily: f.elements.calendarDaily.checked, webhookUrl: f.elements.webhookUrl.value.trim() })
        .then(function () { toast(t('saved')); root.setTimeout(function () { root.location.reload(); }, 600); }, function (er) { toast(errText(er), true); });
    });
    var cal = ov.calendar;
    main.appendChild(card(t('settings_title'), [f]));
    main.appendChild(card(t('cal_title'), [h('p', { text: cal ? t('cal_info', { name: cal.name, n: cal.events }) : t('cal_none') }),
      h('div', { class: 'hc-row' }, [btn(t('cal_sync'), function () { HC.api('POST', '/api/calendar/sync').then(function (r) { toast(t('cal_synced', { c: r.created, u: r.updated, d: r.deleted })); load().then(route); }, function (e) { toast(errText(e), true); }); }),
        btn(t('cal_remove'), function () { if (root.confirm(t('cal_remove_confirm'))) { HC.api('POST', '/api/calendar/remove').then(function () { toast(t('cal_removed')); load().then(route); }); } }, 'hc-btn-danger')])]));
    main.appendChild(card(t('privacy_title'), [h('p', { text: t('privacy_text') }),
      btn(t('delete_all'), function () {
        if (!root.confirm(t('delete_confirm'))) { return; }
        HC.api('DELETE', '/api/data').then(function () { toast(t('deleted')); load().then(route); }, function (e) { toast(errText(e), true); });
      }, 'hc-btn-danger'), disclaimer()]));
  }

  /* daily push from the iPhone via Apple Shortcuts */
  function pushCard(st) {
    var p = st.push || {}, base = HC.rootEl().getAttribute('data-base');
    var url = root.location.origin + base + '/api/ingest';
    var tokOut = h('div', { class: 'hc-token-out' });
    var copyBtn = function (text) { return btn(t('copy'), function () { try { root.navigator.clipboard.writeText(text).then(function () { toast(t('copied')); }); } catch (e) { toast(text); } }, 'hc-btn-small'); };
    var mk = btn(p.tokenSet ? t('push_rotate') : t('push_create'), function () {
      if (p.tokenSet && !root.confirm(t('push_rotate_confirm'))) { return; }
      HC.api('POST', '/api/ingest/token').then(function (r) {
        HC.clear(tokOut).appendChild(h('div', { class: 'hc-banner hc-banner-info' }, [h('strong', { text: t('push_token_once') }), h('code', { class: 'hc-secret', text: r.token }), copyBtn(r.token)]));
        p.tokenSet = true;
      }, function (e) { toast(errText(e), true); });
    }, 'hc-btn-primary');
    var rv = btn(t('push_revoke'), function () {
      if (!root.confirm(t('push_revoke_confirm'))) { return; }
      HC.api('DELETE', '/api/ingest/token').then(function () { toast(t('push_revoked')); return load(); }).then(route);
    }, 'hc-btn-danger');
    var steps = function (prefix, n) { var a = []; for (var i = 1; i <= n; i++) { a.push(h('li', { text: t(prefix + i) })); } return h('ol', {}, a); };
    return card(t('push_title'), [
      h('p', { class: 'hc-muted', text: t('push_intro') }),
      h('p', {}, [h('strong', { text: t('push_last') + ': ' }), p.lastAt ? HC.dateTime(p.lastAt) + ' · ' + t('push_meta', { n: p.samples, day: HC.day(p.lastDay) }) : '–']),
      h('div', { class: 'hc-row' }, [h('span', { class: 'hc-muted', text: p.tokenSet ? t('push_token_set') : t('push_token_none') }), mk, p.tokenSet ? rv : null]),
      tokOut,
      h('div', { class: 'hc-row' }, [h('span', { class: 'hc-muted', text: t('push_url') }), h('code', { class: 'hc-secret', text: url }), copyBtn(url)]),
      h('div', { class: 'hc-row' }, [h('a', { class: 'hc-btn hc-btn-primary', href: base + '/shortcut', text: t('push_install') })]),
      h('details', { class: 'hc-how' }, [h('summary', { text: t('push_how_install') }), steps('push_i', 5)]),
      h('details', { class: 'hc-how' }, [h('summary', { text: t('push_how_auto') }), steps('push_a', 6), h('p', { class: 'hc-muted', text: t('push_locked') })]),
      h('details', { class: 'hc-how' }, [h('summary', { text: t('push_how_manual') }), steps('push_m', 9), h('p', { class: 'hc-muted', text: t('push_keys') })])
    ], { id: 'hc-push' });
  }

  function installHint(always) {
    if (HC.standalone()) { return h('span'); }
    var p = HC.detect(root.navigator.userAgent, root.navigator.platform, root.navigator.maxTouchPoints);
    if (!always && HC.store('get', 'hc.hintClosed') === '1') { return h('span'); }
    var text = p.ios ? (p.safari ? t('install_ios') : t('install_ios_other')) : p.android ? t('install_android') : t('install_desktop');
    var box = card(t('install_title'), [h('p', { text: text }), h('p', { class: 'hc-muted', text: t('install_note') })], { cls: 'hc-install' });
    if (!always) { box.appendChild(btn(t('close'), function () { HC.store('set', 'hc.hintClosed', '1'); box.remove(); })); }
    return box;
  }

  /* ======================================================= report ======================================================= */
  function reportPage(rootEl) {
    var days = parseInt((root.location.search.match(/days=(\d+)/) || [])[1] || '30', 10);
    // the doctor report has its own language (setting "report language"), switchable here for printing
    var rl = (root.location.search.match(/lang=(de|en|it)/) || [])[1] || rootEl.getAttribute('data-report-lang') || HC.i18n.lang();
    HC.i18n.setLang(rl);
    doc.documentElement.setAttribute('lang', rl);
    var go = function (d, l) { root.location.search = '?days=' + d + '&lang=' + l; };
    var langs = h('div', { class: 'hc-seg' }, ['it', 'de', 'en'].map(function (l) { return h('button', { type: 'button', class: 'hc-seg-b', 'aria-pressed': l === rl ? 'true' : 'false', text: l.toUpperCase(), onclick: function () { go(days, l); } }); }));
    var bar = h('div', { class: 'hc-noprint hc-row hc-reportbar' }, [
      h('a', { class: 'hc-btn', href: rootEl.getAttribute('data-base') + '/', text: '‹ ' + t('app') }),
      h('select', { onchange: function (e) { go(e.target.value, rl); } }, [30, 90].map(function (d) { return h('option', { value: d, selected: d === days ? true : null, text: t('range_d', { n: d }) }); })),
      langs,
      btn(t('print'), function () { root.print(); }, 'hc-btn-primary')
    ]);
    var page = h('article', { class: 'hc-report' }, [h('p', { text: t('loading') })]);
    HC.clear(rootEl).appendChild(bar); rootEl.appendChild(page);
    HC.api('GET', '/api/report', undefined, { days: days }).then(function (r) { renderReport(HC.clear(page), r); }, function (e) { HC.clear(page).appendChild(h('p', { text: errText(e) })); });
  }
  function renderReport(page, r) {
    var s = r.summary, m = s.metrics;
    page.appendChild(h('header', { class: 'hc-rep-head' }, [h('h1', { text: t('rep_title') }), h('p', { text: t('rep_period', { from: HC.day(s.period.from), to: HC.day(s.period.to), n: s.period.days_with_data, d: s.period.days }) }),
      h('p', { class: 'hc-muted', text: t('rep_source', { date: r.status.lastImport ? HC.dateTime(r.status.lastImport.finishedAt) : '–' }) })]));
    var rows = [['resting_hr', 'm_resting_hr', 'bpm', 1], ['hr_avg', 'm_hr_avg', 'bpm', 0], ['spo2_avg', 'm_spo2', 'pct', 1], ['hrv', 'm_hrv', 'ms', 0], ['sleep_total', 'm_sleep', 'min', 0],
      ['sleep_deep', 'm_deep', 'min', 0], ['sleep_rem', 'm_rem', 'min', 0], ['steps', 'm_steps', '', 0], ['active_kcal', 'm_kcal', 'kcal', 0], ['weight', 'm_weight', 'kg', 1], ['bodyfat', 'm_bodyfat', 'pct', 1], ['vo2max', 'm_vo2', '', 1]];
    var body = rows.filter(function (x) { return m[x[0]] && m[x[0]].period; }).map(function (x) {
      var p = m[x[0]].period, b = m[x[0]].baseline;
      return h('tr', {}, [h('td', { text: t(x[1]) }), h('td', { class: 'hc-num', text: fmtMetric(p.avg, x[2], x[3]) }), h('td', { class: 'hc-num', text: fmtMetric(p.min, x[2], x[3]) + ' – ' + fmtMetric(p.max, x[2], x[3]) }),
        h('td', { class: 'hc-num', text: String(p.n) }), h('td', { class: 'hc-num', text: b ? fmtMetric(b.avg, x[2], x[3]) : '–' }), h('td', { class: 'hc-num', text: m[x[0]].delta_pct === null ? '–' : HC.signed(m[x[0]].delta_pct, 1, '%') })]);
    });
    page.appendChild(h('section', {}, [h('h2', { text: t('rep_values') }), h('table', { class: 'hc-table hc-rep-table' }, [h('thead', {}, [h('tr', {}, ['', t('rep_avg'), t('rep_range'), 'n', t('rep_base'), 'Δ'].map(function (x) { return h('th', { text: x }); }))]), h('tbody', {}, body)]),
      h('p', { class: 'hc-muted hc-small', text: t('rep_base_note', { from: HC.day(s.baseline.from), to: HC.day(s.baseline.to) }) })]));
    var charts = h('section', { class: 'hc-rep-charts' }, [h('h2', { text: t('rep_charts') })]);
    var g = function (title, el) { charts.appendChild(h('figure', {}, [h('figcaption', { text: title }), el])); };
    g(t('m_resting_hr') + ' (bpm)', C.line(HC.series(r.days, 'resting_hr', 'day'), { color: COL.heart, empty: t('no_data') }));
    g(t('c_sleep'), C.bars(r.days.filter(function (d) { return d.sleep_total !== null; }).map(function (d) { return { x: d.day, parts: [(d.sleep_deep || 0) / 60, (d.sleep_core || 0) / 60, (d.sleep_rem || 0) / 60, Math.max(0, d.sleep_total - (d.sleep_deep || 0) - (d.sleep_core || 0) - (d.sleep_rem || 0)) / 60] }; }),
      { colors: [COL.deep, COL.core, COL.rem, '#9aa3ad'], fmt: function (v) { return HC.hm(v * 60); }, empty: t('no_data') }));
    g(t('m_spo2') + ' (%)', C.line(HC.series(r.days, 'spo2_avg', 'day', 100), { color: COL.spo2, fmt: function (v) { return HC.num(v, 1); }, empty: t('no_data') }));
    g(t('m_steps'), C.bars(HC.series(r.days, 'steps', 'day'), { color: COL.steps, empty: t('no_data') }));
    var wt = HC.series(r.days, 'weight', 'day');
    if (wt.length) { g(t('m_weight') + ' (kg)', C.line(wt, { color: COL.weight, fmt: function (v) { return HC.num(v, 1); } })); }
    page.appendChild(charts);
    var W = r.weight;
    if (W && W.has) {
      var wrows = [[t('w_current'), HC.num(W.last.kg, 1) + ' kg (' + HC.day(W.last.day) + ')'], [t('w_change_d', { n: 30 }), W.change[30] ? HC.signed(W.change[30].kg, 1, 'kg') : '–'],
        [t('w_change_d', { n: 90 }), W.change[90] ? HC.signed(W.change[90].kg, 1, 'kg') : '–'], ['BMI', W.bmi ? HC.num(W.bmi.value, 1) + ' (' + t('bmi_' + W.bmi.category) + ', ' + HC.day(W.bmi.day) + ')' : '–'],
        [t('m_bodyfat'), W.bodyfat ? HC.num(W.bodyfat.pct, 1) + ' % (' + HC.day(W.bodyfat.day) + ')' : '–'], [t('w_rate'), W.rate_kg_week === null ? '–' : HC.signed(W.rate_kg_week, 2, 'kg/' + t('u_week')) + ' (n=' + W.rate_n + ')']];
      page.appendChild(h('section', { class: 'hc-rep-weight' }, [h('h2', { text: t('w_title') }), h('table', { class: 'hc-table hc-small' }, [h('tbody', {}, wrows.map(function (x) { return h('tr', {}, [h('td', { text: x[0] }), h('td', { class: 'hc-num', text: x[1] })]); }))]),
        C.line((r.weightTrend || []).map(function (p) { return { x: p.day, y: p.weight }; }), { color: COL.weight, dots: true, ma: (r.weightTrend || []).length >= 10 ? 7 : 0, fmt: function (v) { return HC.num(v, 1); }, unit: 'kg', empty: t('no_data') }),
        h('p', { class: 'hc-muted hc-small', text: t('w_rep_note') })]));
    }
    page.appendChild(h('section', {}, [h('h2', { text: t('rep_changes') })].concat(s.warnings.length ? [h('ul', {}, s.warnings.map(function (w) { return h('li', { text: warningText(w) }); }))] : [h('p', { text: t('hints_none') })])));
    if (r.workouts.length) {
      page.appendChild(h('section', {}, [h('h2', { text: t('rep_workouts', { n: r.workouts.length }) }), h('table', { class: 'hc-table hc-small' }, [h('tbody', {}, r.workouts.map(function (w) {
        return h('tr', {}, [h('td', { text: HC.day(w.day) }), h('td', { text: t('act_' + w.activity) !== 'act_' + w.activity ? t('act_' + w.activity) : w.activity }), h('td', { class: 'hc-num', text: HC.hm(w.duration_min, true) }),
          h('td', { class: 'hc-num', text: w.distance_m ? HC.num(w.distance_m / 1000, 1) + ' km' : '' }), h('td', { class: 'hc-num', text: w.hr_avg ? HC.num(w.hr_avg, 0) + ' bpm' : '' })]);
      }))])]));
    }
    page.appendChild(h('section', { class: 'hc-rep-ai' }, [h('h2', { text: t('rep_ai') }), r.ai && r.ai.doctor ? h('p', { lang: r.ai.doctorLang || null, text: r.ai.doctor }) : h('p', { class: 'hc-muted', text: t('rep_ai_none') }),
      r.ai && r.ai.doctorLang && r.ai.doctorLang !== HC.i18n.lang() ? h('p', { class: 'hc-muted hc-small hc-noprint', text: t('rep_ai_lang', { l: r.ai.doctorLang.toUpperCase() }) }) : null,
      r.ai ? h('p', { class: 'hc-muted hc-small', text: t('ai_meta', { date: HC.dateTime(r.ai.createdAt), model: r.ai.model || '?', to: HC.day(r.ai.to) }) }) : null]));
    var qBox = h('div', {});
    var renderQ = function (x) {
      HC.clear(qBox);
      if (!x || !x.result) { return; }
      if (x.result.intro) { qBox.appendChild(h('p', { text: x.result.intro })); }
      qBox.appendChild(h('ol', { class: 'hc-questions' }, x.result.questions.map(function (q) { return h('li', { text: q }); })));
      qBox.appendChild(h('p', { class: 'hc-muted hc-small', text: HC.dateTime(x.at) + ' · ' + x.model + ' · ' + t('ai_note') }));
    };
    var qb = btn(t('dq_make'), function () {
      qb.disabled = true; HC.clear(qBox).appendChild(h('p', { class: 'hc-muted', text: t('ask_thinking') }));
      HC.api('POST', '/api/ai/task/doctorq', { days: r.summary.period.days, lang: HC.i18n.lang() }).then(renderQ, function (e) { HC.clear(qBox).appendChild(h('p', { text: errText(e) })); }).then(function () { qb.disabled = false; });
    }, 'hc-noprint');
    if (!feat('aiTasksAvailable', true)) { qb.hidden = true; }
    renderQ(r.doctorQuestions);
    page.appendChild(h('section', { class: 'hc-rep-q' }, [h('h2', { text: t('dq_title') }), qBox, qb]));
    page.appendChild(h('footer', { class: 'hc-rep-foot' }, [h('p', { text: t('rep_disclaimer') }), h('p', { class: 'hc-small', text: t('rep_method') })]));
  }

  /* ======================================================= infographic ======================================================= */
  function infoPage(rootEl) {
    var period = (root.location.search.match(/period=(week|month|year)/) || [])[1] || 'week';
    var bar = h('div', { class: 'hc-noprint hc-row hc-reportbar' }, [h('a', { class: 'hc-btn', href: rootEl.getAttribute('data-base') + '/', text: '‹ ' + t('app') })]);
    var seg = h('div', { class: 'hc-seg' }, ['week', 'month', 'year'].map(function (p) { return h('button', { type: 'button', class: 'hc-seg-b', 'aria-pressed': p === period ? 'true' : 'false', text: t('per_' + p), onclick: function () { root.location.search = '?period=' + p; } }); }));
    bar.appendChild(seg);
    var page = h('article', { class: 'hc-info' }, [h('p', { text: t('loading') })]);
    HC.clear(rootEl).appendChild(bar); rootEl.appendChild(page);
    HC.api('GET', '/api/infographic', undefined, { period: period }).then(function (d) {
      renderInfo(HC.clear(page), d);
      bar.appendChild(btn(t('share_png'), function () { exportPng(d); }, 'hc-btn-primary'));
      bar.appendChild(btn(t('print'), function () { root.print(); }));
    }, function (e) { HC.clear(page).appendChild(h('p', { text: errText(e) })); });
  }
  function tile(label, value, color, cmp) {
    return h('div', { class: 'hc-tile', style: '--c:' + color }, [h('div', { class: 'hc-tile-l', text: label }), h('div', { class: 'hc-tile-v', text: value })].concat(cmp || []));
  }
  function cmpLine(label, cur, other, dec, unit, invert, fmt) {
    if (other === null || other === undefined || cur === null || cur === undefined) { return h('div', { class: 'hc-tile-c hc-muted', text: label + ': –' }); }
    var d = deltaText(cur, other, dec, unit, invert);
    return h('div', { class: 'hc-tile-c' }, [h('span', { class: 'hc-muted', text: label + ' ' + (fmt ? fmt(other) : HC.num(other, dec)) + ' ' }), h('span', { class: 'hc-delta hc-delta-' + d.cls, text: d.text })]);
  }
  function renderInfo(page, d) {
    var c = d.current, p = d.previous, b = d.baseline, P = d.period, lt = d.latest || {};
    page.appendChild(h('header', { class: 'hc-info-head' }, [h('h1', { text: t('info_title_' + P.kind) }), h('p', { text: t('rep_period', { from: HC.day(P.from), to: HC.day(P.to), n: c.days_with_data, d: P.days }) })]));
    var both = function (cur, key, dec, unit, invert, fmt) {
      var out = [cmpLine(t('cmp_prev'), cur, p[key], dec, unit, invert, fmt)];
      if (P.kind !== 'year') { out.push(cmpLine(t('cmp_base'), cur, b[key], dec, unit, invert, fmt)); } // for a year the baseline is the previous year
      return out;
    };
    var hmF = function (v) { return HC.hm(v); };
    var tiles = h('div', { class: 'hc-tiles' }, [
      tile(t('m_sleep'), HC.hm(c.sleep_avg), COL.sleep, both(c.sleep_avg, 'sleep_avg', 0, 'min', false, hmF).concat([h('div', { class: 'hc-tile-n', text: t('n_nights', { n: c.sleep_n }) })])),
      tile(t('m_resting_hr'), c.resting_hr === null ? '–' : HC.num(c.resting_hr, 1) + ' bpm', COL.heart, both(c.resting_hr, 'resting_hr', 1, 'bpm', true)),
      tile(t('m_spo2'), c.spo2 === null ? '–' : HC.num(c.spo2, 1) + ' %', COL.spo2, both(c.spo2, 'spo2', 1, '%')),
      tile(t('m_steps_avg'), HC.num(c.steps_avg, 0), COL.steps, both(c.steps_avg, 'steps_avg', 0, '').concat([h('div', { class: 'hc-tile-n', text: t('total_v', { v: HC.num(c.steps_total, 0) }) + ' · ' + HC.num(c.distance_km, 1) + ' km' })])),
      tile(t('m_kcal'), c.kcal_avg === null ? '–' : HC.num(c.kcal_avg, 0) + ' kcal', COL.kcal, both(c.kcal_avg, 'kcal_avg', 0, 'kcal')),
      tile(t('m_workouts'), String(c.workouts), COL.workout, [cmpLine(t('cmp_prev'), c.workouts, p.workouts, 0, ''), h('div', { class: 'hc-tile-n', text: HC.num(c.workout_hours, 1) + ' h' })]),
      tile(t('m_vo2'), c.vo2max !== null ? HC.num(c.vo2max, 1) : lt.vo2max ? HC.num(lt.vo2max.value, 1) : '–', COL.vo2,
        c.vo2max !== null ? [cmpLine(t('cmp_base'), c.vo2max, b.vo2max, 1, '')] : [h('div', { class: 'hc-tile-n', text: lt.vo2max ? t('as_of', { day: HC.day(lt.vo2max.day) }) : t('no_data') })]),
      tile(t('m_weight'), c.weight !== null ? HC.num(c.weight, 1) + ' kg' : lt.weight ? HC.num(lt.weight.value, 1) + ' kg' : '–', COL.weight,
        c.weight !== null ? [cmpLine(t('cmp_base'), c.weight, b.weight, 1, 'kg', true), c.bodyfat !== null ? h('div', { class: 'hc-tile-n', text: t('m_bodyfat') + ' ' + HC.num(c.bodyfat, 1) + ' %' }) : null]
          : [h('div', { class: 'hc-tile-n', text: lt.weight ? t('as_of', { day: HC.day(lt.weight.day) }) + (lt.bodyfat ? ' · ' + t('m_bodyfat') + ' ' + HC.num(lt.bodyfat.value, 1) + ' %' : '') : t('no_data') })])
    ]);
    page.appendChild(tiles);
    // sleep stages donut + consistency ring
    var share = [{ v: c.deep_share, color: COL.deep, k: 'deep' }, { v: c.core_share, color: COL.core, k: 'core' }, { v: c.rem_share, color: COL.rem, k: 'rem' }];
    var dn = share.some(function (x) { return x.v; }) ? C.donut(share, 150, HC.hm(c.sleep_avg)) : h('p', { text: t('no_data') });
    var leg = h('div', { class: 'hc-legend' }, share.filter(function (x) { return x.v !== null; }).map(function (x) { return h('span', {}, [h('i', { style: 'background:' + x.color }), t('stage_' + x.k) + ' ' + HC.num(x.v * 100, 0) + ' %']); }));
    var cons = d.consistency;
    page.appendChild(h('div', { class: 'hc-info-row' }, [
      card(t('info_stages'), [dn, leg, h('p', { class: 'hc-muted hc-small', text: t('info_stages_base', { deep: b.deep_share === null ? '–' : HC.num(b.deep_share * 100, 0), rem: b.rem_share === null ? '–' : HC.num(b.rem_share * 100, 0) }) })]),
      card(t('info_consistency'), [C.ring(cons.score, COL.recovery, 150, cons.days ? cons.days_met + '/' + cons.days : ''), h('p', { class: 'hc-muted hc-small', text: t('info_consistency_note') })])
    ]));
    // sports
    if (d.sports.length) {
      page.appendChild(card(t('info_sports'), [h('table', { class: 'hc-table' }, [h('thead', {}, [h('tr', {}, ['', t('col_count'), 'h', 'km', 'km/h', 'kcal'].map(function (x) { return h('th', { text: x }); }))]),
        h('tbody', {}, d.sports.map(function (s) { return h('tr', {}, [h('td', { text: t('act_' + s.activity) !== 'act_' + s.activity ? t('act_' + s.activity) : s.activity }), h('td', { class: 'hc-num', text: s.count }), h('td', { class: 'hc-num', text: HC.num(s.hours, 1) }), h('td', { class: 'hc-num', text: s.km ? HC.num(s.km, 1) : '–' }), h('td', { class: 'hc-num', text: s.kmh === null ? '–' : HC.num(s.kmh, 1) }), h('td', { class: 'hc-num', text: HC.num(s.kcal, 0) })]); }))])]));
    }
    // heatmap
    page.appendChild(card(t('info_heat', { n: d.heatmap.length }), [h('div', { class: 'hc-heat-wrap' }, [C.heatmap(d.heatmap, dark() ? HEAT_DARK : HEAT, t('daynames_short').split(','))]),
      h('div', { class: 'hc-legend' }, [t('less')].concat((dark() ? HEAT_DARK : HEAT).map(function (col) { return h('i', { style: 'background:' + col }); })).concat([t('more_l')])), h('p', { class: 'hc-muted hc-small', text: t('heat_note') })]));
    // records + streaks
    var R = d.records, rec = [];
    var add = function (label, val, dayv) { if (val) { rec.push(h('li', {}, [h('strong', { text: val }), h('span', { class: 'hc-muted', text: ' ' + label + (dayv ? ' · ' + HC.day(dayv) : '') })])); } };
    if (R.longest_ride) { add(t('rec_ride'), HC.num(R.longest_ride.km, 1) + ' km', R.longest_ride.day); }
    if (R.longest_run) { add(t('rec_run'), HC.num(R.longest_run.km, 1) + ' km', R.longest_run.day); }
    if (R.longest_workout) { add(t('rec_workout'), HC.hm(R.longest_workout.minutes, true), R.longest_workout.day); }
    if (R.best_week_steps) { add(t('rec_week'), HC.num(R.best_week_steps.steps, 0) + ' ' + t('u_steps'), R.best_week_steps.week_start); }
    if (R.most_steps) { add(t('rec_steps'), HC.num(R.most_steps.steps, 0) + ' ' + t('u_steps'), R.most_steps.day); }
    if (R.best_sleep) { add(t('rec_sleep'), HC.hm(R.best_sleep.minutes), R.best_sleep.day); }
    if (R.most_deep) { add(t('rec_deep'), HC.hm(R.most_deep.minutes), R.most_deep.day); }
    if (R.lowest_rhr) { add(t('rec_rhr'), HC.num(R.lowest_rhr.bpm, 0) + ' bpm', R.lowest_rhr.day); }
    var S = d.streaks;
    page.appendChild(h('div', { class: 'hc-info-row' }, [card(t('info_records'), [h('ul', { class: 'hc-records' }, rec)]),
      card(t('streaks_title'), [h('div', { class: 'hc-grid4' }, [stat(t('streak_steps'), S.steps.current + ' ' + t('u_days'), t('best_v', { v: S.steps.best }), COL.steps), stat(t('streak_sleep'), S.sleep.current + ' ' + t('u_days'), t('best_v', { v: S.sleep.best }), COL.sleep)]),
        h('p', { class: 'hc-muted hc-small', text: t('streak_note', { steps: HC.num(d.goals.steps, 0), sleep: HC.hm(d.goals.sleep_min) }) })])]));
    page.appendChild(h('p', { class: 'hc-disclaimer', text: t('info_method') + ' ' + t('disclaimer') }));
  }

  /* shareable PNG: a self-contained SVG card with numbers only (no name, no account), rasterised on a canvas */
  function exportPng(d) {
    var c = d.current, W = 1080, H = 1350, S = C.s, dk = dark();
    var bg = dk ? '#0f1419' : '#f6f7f9', fg = dk ? '#e8edf2' : '#14181d', mu = dk ? '#94a0ad' : '#5d6873';
    var svgEl = S('svg', { xmlns: 'http://www.w3.org/2000/svg', width: W, height: H, viewBox: '0 0 ' + W + ' ' + H });
    svgEl.appendChild(S('rect', { width: W, height: H, fill: bg }));
    var txt = function (x, y, size, color, str, weight, anchor) { svgEl.appendChild(S('text', { x: x, y: y, 'font-family': 'Helvetica, Arial, sans-serif', 'font-size': size, 'font-weight': weight || 400, fill: color, 'text-anchor': anchor || 'start', text: str })); };
    txt(70, 120, 56, fg, t('info_title_' + d.period.kind), 700);
    txt(70, 175, 30, mu, HC.day(d.period.from) + ' – ' + HC.day(d.period.to) + ' · ' + t('n_days', { n: c.days_with_data }));
    var cells = [[t('m_sleep'), HC.hm(c.sleep_avg), COL.sleep], [t('m_resting_hr'), c.resting_hr === null ? '–' : HC.num(c.resting_hr, 0) + ' bpm', COL.heart], [t('m_spo2'), c.spo2 === null ? '–' : HC.num(c.spo2, 1) + ' %', COL.spo2],
      [t('m_steps_avg'), HC.num(c.steps_avg, 0), COL.steps], [t('m_kcal'), c.kcal_avg === null ? '–' : HC.num(c.kcal_avg, 0) + ' kcal', COL.kcal], [t('m_workouts'), c.workouts + ' · ' + HC.num(c.workout_hours, 1) + ' h', COL.workout],
      [t('m_vo2'), c.vo2max === null ? '–' : HC.num(c.vo2max, 1), COL.vo2], [t('info_consistency'), d.consistency.score === null ? '–' : d.consistency.score + ' %', COL.recovery]];
    cells.forEach(function (x, i) {
      var cx = 70 + (i % 2) * 480, cy = 240 + Math.floor(i / 2) * 230;
      svgEl.appendChild(S('rect', { x: cx, y: cy, width: 450, height: 200, rx: 28, fill: x[2], 'fill-opacity': dk ? 0.16 : 0.12 }));
      txt(cx + 32, cy + 60, 30, mu, x[0]);
      txt(cx + 32, cy + 140, 64, x[2], x[1], 700);
    });
    txt(70, H - 80, 26, mu, t('png_footer'));
    var data = new root.XMLSerializer().serializeToString(svgEl);
    var img = new root.Image();
    var url = root.URL.createObjectURL(new root.Blob([data], { type: 'image/svg+xml' }));
    img.onload = function () {
      var cv = doc.createElement('canvas'); cv.width = W; cv.height = H;
      cv.getContext('2d').drawImage(img, 0, 0);
      root.URL.revokeObjectURL(url);
      cv.toBlob(function (blob) {
        var a = doc.createElement('a'); a.href = root.URL.createObjectURL(blob); a.download = 'health-' + d.period.kind + '-' + d.period.to + '.png';
        doc.body.appendChild(a); a.click(); a.remove();
      }, 'image/png');
    };
    img.src = url;
  }

  /* ======================================================= boot ======================================================= */
  function boot() {
    var el = HC.rootEl();
    if (!el) { return; }
    HC.i18n.setLang(el.getAttribute('data-lang') || 'en');
    var page = el.getAttribute('data-page');
    if (page === 'report') { reportPage(el); } else if (page === 'infographic') { infoPage(el); } else { mainPage(el); }
    try {
      if (root.navigator.serviceWorker && el.getAttribute('data-sw')) { root.navigator.serviceWorker.register(el.getAttribute('data-sw'), { scope: el.getAttribute('data-base') + '/' }).catch(function () {}); }
    } catch (e) { /* ignore */ }
  }
  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})(self);
