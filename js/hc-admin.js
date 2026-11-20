/* SPDX-License-Identifier: AGPL-3.0-or-later
 * Administration settings of Health Check. Draws the form from /api/admin/settings; the API key is write-only. */
(function () {
  'use strict';
  var root = document.getElementById('hc-admin');
  if (!root) { return; }
  var L = ((document.documentElement.lang || 'en').slice(0, 2));
  if (['de', 'it'].indexOf(L) < 0) { L = 'en'; }
  var T = {
    en: { title: 'Health Check', intro: 'App-wide settings. Health data stays per user; administrators cannot read it here.', access: 'Access', groups: 'Limit to groups', groups_hint: 'Nobody ticked = every user of this Nextcloud can use the app. (Nextcloud’s own “enable for specific groups” in Apps works too.)',
      ai: 'AI', ai_on: 'Enable AI features', provider: 'Provider', url: 'Endpoint (OpenAI-compatible chat completions)', model: 'Model', key: 'API key', key_set: 'A key is saved. Leave empty to keep it.', key_none: 'No key saved', key_clear: 'Remove saved key', key_hint: 'Stored as a sensitive value. Not needed for a local model such as Ollama.',
      chat: 'Allow the “Ask your data” chat', tasks: 'Allow AI check-up, explanations and doctor questions', per_hour: 'Requests per user and hour', per_day: 'Requests per user and day', tokens: 'Maximum answer length (tokens)', timeout: 'Timeout (seconds)',
      test: 'Test connection', testing: 'Testing…', test_ok: 'Works', test_fail: 'Failed',
      reports: 'Weekly report', mode: 'Who writes the weekly report', m_n8n: 'An automation (n8n webhook of each user)', m_builtin: 'This server, with the AI above (Monday morning, no automation needed)',
      integ: 'Integrations', ingest: 'Allow the daily push from the phone (Shortcuts)', webhooks: 'Allow users to set a webhook to an automation',
      defaults: 'Defaults for users', dlang: 'App language when the Nextcloud language is not supported', rlang: 'Doctor report language (empty = same as the app)', same: 'same as the app',
      stats: 'Usage', users: 'Users of the app', calls: 'AI requests in the last 24 hours', save: 'Save', saved: 'Saved', err: 'Could not save: ', ai_ready: 'AI is ready', ai_off: 'AI is not usable yet (switched off, or endpoint, model or key missing)', local_hint: 'A local address needs allow_local_remote_servers in config.php.' },
    de: { title: 'Gesundheits-Check', intro: 'App-weite Einstellungen. Gesundheitsdaten bleiben pro Person; Administratoren sehen sie hier nicht.', access: 'Zugriff', groups: 'Auf Gruppen beschränken', groups_hint: 'Nichts angehakt = alle Nutzer dieser Nextcloud dürfen die App nutzen. (Auch „für bestimmte Gruppen aktivieren“ unter Apps funktioniert.)',
      ai: 'KI', ai_on: 'KI-Funktionen aktivieren', provider: 'Anbieter', url: 'Endpunkt (OpenAI-kompatibel, Chat Completions)', model: 'Modell', key: 'API-Schlüssel', key_set: 'Ein Schlüssel ist gespeichert. Leer lassen, um ihn zu behalten.', key_none: 'Kein Schlüssel gespeichert', key_clear: 'Gespeicherten Schlüssel entfernen', key_hint: 'Wird als sensibler Wert gespeichert. Für ein lokales Modell wie Ollama nicht nötig.',
      chat: 'Chat „Frag deine Daten“ erlauben', tasks: 'KI-Check-up, Erklärungen und Arztfragen erlauben', per_hour: 'Anfragen pro Person und Stunde', per_day: 'Anfragen pro Person und Tag', tokens: 'Maximale Antwortlänge (Tokens)', timeout: 'Zeitlimit (Sekunden)',
      test: 'Verbindung testen', testing: 'Teste…', test_ok: 'Funktioniert', test_fail: 'Fehlgeschlagen',
      reports: 'Wochenbericht', mode: 'Wer schreibt den Wochenbericht', m_n8n: 'Eine Automatisierung (n8n-Webhook jeder Person)', m_builtin: 'Dieser Server mit der KI oben (Montagmorgen, keine Automatisierung nötig)',
      integ: 'Integrationen', ingest: 'Täglichen Push vom Handy (Kurzbefehle) erlauben', webhooks: 'Webhook zu einer Automatisierung erlauben',
      defaults: 'Standardwerte für Nutzer', dlang: 'App-Sprache, wenn die Nextcloud-Sprache nicht unterstützt wird', rlang: 'Sprache des Arztberichts (leer = wie die App)', same: 'wie die App',
      stats: 'Nutzung', users: 'Nutzer der App', calls: 'KI-Anfragen in den letzten 24 Stunden', save: 'Speichern', saved: 'Gespeichert', err: 'Speichern fehlgeschlagen: ', ai_ready: 'KI ist bereit', ai_off: 'KI ist noch nicht nutzbar (aus, oder Endpunkt, Modell oder Schlüssel fehlt)', local_hint: 'Eine lokale Adresse braucht allow_local_remote_servers in der config.php.' },
    it: { title: 'Health Check', intro: 'Impostazioni valide per tutta l’app. I dati di salute restano per utente; gli amministratori qui non li vedono.', access: 'Accesso', groups: 'Limita a gruppi', groups_hint: 'Nessuno selezionato = tutti gli utenti di questo Nextcloud possono usare l’app. (Funziona anche «abilita per gruppi specifici» in App.)',
      ai: 'IA', ai_on: 'Attiva le funzioni IA', provider: 'Provider', url: 'Endpoint (compatibile OpenAI, chat completions)', model: 'Modello', key: 'Chiave API', key_set: 'Una chiave è salvata. Lascia vuoto per tenerla.', key_none: 'Nessuna chiave salvata', key_clear: 'Rimuovi la chiave salvata', key_hint: 'Salvata come valore sensibile. Non serve per un modello locale come Ollama.',
      chat: 'Consenti la chat «Chiedi ai tuoi dati»', tasks: 'Consenti check-up IA, spiegazioni e domande per il medico', per_hour: 'Richieste per utente e ora', per_day: 'Richieste per utente e giorno', tokens: 'Lunghezza massima della risposta (token)', timeout: 'Timeout (secondi)',
      test: 'Prova la connessione', testing: 'Provo…', test_ok: 'Funziona', test_fail: 'Non riuscito',
      reports: 'Report settimanale', mode: 'Chi scrive il report settimanale', m_n8n: 'Un’automazione (webhook n8n di ogni utente)', m_builtin: 'Questo server, con l’IA sopra (lunedì mattina, nessuna automazione necessaria)',
      integ: 'Integrazioni', ingest: 'Consenti l’invio giornaliero dal telefono (Comandi rapidi)', webhooks: 'Consenti agli utenti un webhook verso un’automazione',
      defaults: 'Valori predefiniti per gli utenti', dlang: 'Lingua dell’app se la lingua di Nextcloud non è supportata', rlang: 'Lingua del referto per il medico (vuoto = come l’app)', same: 'come l’app',
      stats: 'Utilizzo', users: 'Utenti dell’app', calls: 'Richieste IA nelle ultime 24 ore', save: 'Salva', saved: 'Salvato', err: 'Salvataggio non riuscito: ', ai_ready: 'L’IA è pronta', ai_off: 'L’IA non è ancora utilizzabile (spenta, oppure mancano endpoint, modello o chiave)', local_hint: 'Un indirizzo locale richiede allow_local_remote_servers in config.php.' }
  }[L];
  var PROV = { ionos: 'IONOS AI Model Hub', openai: 'OpenAI', anthropic: 'Claude (Anthropic API)', mistral: 'Mistral', opencode: 'OpenCode Zen (API key)', openrouter: 'OpenRouter', ollama: 'Ollama (local)', custom: 'Custom' };
  var LANGS = { de: 'Deutsch', en: 'English', it: 'Italiano' };

  function url(p) { return (window.OC && OC.generateUrl) ? OC.generateUrl('/apps/healthcheck' + p) : '/index.php/apps/healthcheck' + p; }
  function tok() { return (window.OC && OC.requestToken) || (document.head && document.head.getAttribute('data-requesttoken')) || ''; }
  function api(method, p, body) {
    return fetch(url(p), { method: method, headers: { 'Content-Type': 'application/json', requesttoken: tok(), 'OCS-APIREQUEST': 'true' }, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw j; } return j; }); });
  }
  function h(tag, attrs, kids) {
    var e = document.createElement(tag), k;
    for (k in (attrs || {})) { if (k === 'text') { e.textContent = attrs[k]; } else if (k === 'class') { e.className = attrs[k]; } else { e.setAttribute(k, attrs[k]); } }
    (kids || []).forEach(function (c) { if (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
    return e;
  }
  var uid = 0;
  function field(label, input, hint) { var id = 'hca' + (++uid); input.id = id; return h('div', { class: 'hc-adm-row' }, [h('label', { for: id, text: label }), input, hint ? h('p', { class: 'hc-adm-muted', text: hint }) : null]); }
  function check(label, on) { var i = h('input', { type: 'checkbox', class: 'checkbox' }); i.checked = !!on; var id = 'hca' + (++uid); i.id = id; return { el: h('div', { class: 'hc-adm-row hc-adm-check' }, [i, h('label', { for: id, text: label })]), input: i }; }
  function sel(opts, val) { var s = h('select'); Object.keys(opts).forEach(function (k) { var o = h('option', { value: k, text: opts[k] }); if (k === val) { o.selected = true; } s.appendChild(o); }); return s; }
  function num(v, lo, hi) { var i = h('input', { type: 'number', min: lo, max: hi }); i.value = v; return i; }
  function section(title, kids) { return h('div', { class: 'hc-adm-card' }, [h('h3', { text: title })].concat(kids)); }

  function draw(d) {
    var f = h('form', { class: 'hc-adm' });
    var status = h('p', { class: 'hc-adm-status ' + (d.ai_ready ? 'ok' : 'warn'), text: d.ai_ready ? T.ai_ready : T.ai_off });
    var gBox = h('div', { class: 'hc-adm-groups' });
    var gIn = {};
    d.groups.concat(d.allowed_groups.filter(function (g) { return d.groups.indexOf(g) < 0; })).forEach(function (g) {
      var c = check(g, d.allowed_groups.indexOf(g) >= 0); gIn[g] = c.input; gBox.appendChild(c.el);
    });
    var aiOn = check(T.ai_on, d.ai_enabled), chat = check(T.chat, d.ai_chat), tasks = check(T.tasks, d.ai_tasks);
    var prov = sel(PROV, d.ai_provider), urlI = h('input', { type: 'url' }), modelI = h('input', { type: 'text' }), keyI = h('input', { type: 'password', autocomplete: 'new-password' });
    urlI.value = d.ai_url; modelI.value = d.ai_model; keyI.placeholder = d.ai_key_set ? '••••••••' : '';
    var keyClear = check(T.key_clear, false);
    prov.addEventListener('change', function () { var p = d.providers[prov.value]; if (p && prov.value !== 'custom') { urlI.value = p.url; modelI.value = p.model; } });
    var perH = num(d.ai_per_hour, 1, 200), perD = num(d.ai_per_day, 1, 2000), tokI = num(d.ai_max_tokens, 200, 16000), toI = num(d.ai_timeout, 10, 600);
    var testOut = h('span', { class: 'hc-adm-muted' }), testBtn = h('button', { type: 'button', text: T.test });
    testBtn.addEventListener('click', function () {
      testBtn.disabled = true; testOut.textContent = T.testing; testOut.className = 'hc-adm-muted';
      api('POST', '/api/admin/test', { ai_url: urlI.value, ai_model: modelI.value, ai_api_key: keyI.value }).then(function (r) {
        testOut.textContent = r.ok ? T.test_ok + ' · ' + r.model + ' · ' + r.ms + ' ms' : T.test_fail + ': ' + r.error + ((/request_failed/.test(r.error) && /^https?:\/\/(localhost|127\.|10\.|192\.168\.|172\.)/.test(urlI.value)) ? ' (' + T.local_hint + ')' : '');
        testOut.className = r.ok ? 'hc-adm-ok' : 'hc-adm-bad';
      }, function (e) { testOut.textContent = T.test_fail + ': ' + (e && e.error || ''); testOut.className = 'hc-adm-bad'; }).then(function () { testBtn.disabled = false; });
    });
    var mode = sel({ n8n: T.m_n8n, builtin: T.m_builtin }, d.report_mode);
    var ingest = check(T.ingest, d.allow_ingest), hooks = check(T.webhooks, d.allow_webhooks);
    var dl = sel(LANGS, d.default_lang), rl = sel(Object.assign({ '': T.same }, LANGS), d.default_report_lang);
    var msg = h('span', { class: 'hc-adm-muted' }), save = h('button', { type: 'submit', class: 'primary', text: T.save });

    f.appendChild(section(T.access, [h('p', { class: 'hc-adm-muted', text: T.groups_hint }), h('strong', { text: T.groups }), gBox]));
    f.appendChild(section(T.ai, [status, aiOn.el, field(T.provider, prov), field(T.url, urlI), field(T.model, modelI),
      field(T.key, keyI, (d.ai_key_set ? T.key_set : T.key_none) + ' ' + T.key_hint), d.ai_key_set ? keyClear.el : null,
      chat.el, tasks.el, field(T.per_hour, perH), field(T.per_day, perD), field(T.tokens, tokI), field(T.timeout, toI), h('div', { class: 'hc-adm-row' }, [testBtn, ' ', testOut])]));
    f.appendChild(section(T.reports, [field(T.mode, mode)]));
    f.appendChild(section(T.integ, [ingest.el, hooks.el]));
    f.appendChild(section(T.defaults, [field(T.dlang, dl), field(T.rlang, rl)]));
    f.appendChild(section(T.stats, [h('p', { text: T.users + ': ' + d.stats.users }), h('p', { text: T.calls + ': ' + d.stats.ai_calls_24h })]));
    f.appendChild(h('div', { class: 'hc-adm-row' }, [save, ' ', msg]));
    f.addEventListener('submit', function (ev) {
      ev.preventDefault(); save.disabled = true; msg.textContent = '';
      var body = {
        ai_enabled: aiOn.input.checked, ai_chat: chat.input.checked, ai_tasks: tasks.input.checked, ai_provider: prov.value, ai_url: urlI.value, ai_model: modelI.value,
        ai_per_hour: perH.value, ai_per_day: perD.value, ai_max_tokens: tokI.value, ai_timeout: toI.value, report_mode: mode.value, allow_ingest: ingest.input.checked, allow_webhooks: hooks.input.checked,
        default_lang: dl.value, default_report_lang: rl.value, allowed_groups: Object.keys(gIn).filter(function (g) { return gIn[g].checked; })
      };
      if (keyI.value.trim() !== '') { body.ai_api_key = keyI.value.trim(); }
      if (keyClear.input.checked) { body.ai_api_key_clear = true; }
      api('PUT', '/api/admin/settings', body).then(function (r) { root.textContent = ''; draw(r); var m = root.querySelector('.hc-adm-status'); if (m) { m.insertAdjacentText('afterend', ''); } flash(T.saved); },
        function (e) { msg.textContent = T.err + ((e && e.fields) ? e.fields.join(', ') : (e && e.error) || ''); msg.className = 'hc-adm-bad'; save.disabled = false; });
    });
    root.appendChild(h('h2', { text: T.title })); root.appendChild(h('p', { class: 'hc-adm-muted', text: T.intro })); root.appendChild(f);
  }
  function flash(t) { var m = root.querySelector('.hc-adm .hc-adm-row:last-child span'); if (m) { m.textContent = t; m.className = 'hc-adm-ok'; } }

  api('GET', '/api/admin/settings').then(function (d) { root.textContent = ''; draw(d); }, function () { root.textContent = 'Health Check: could not load the settings.'; });
})();
