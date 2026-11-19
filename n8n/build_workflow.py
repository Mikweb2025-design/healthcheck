#!/usr/bin/env python3
"""Generates n8n/healthcheck-weekly.json (placeholders, no secrets).
Placeholders: __NC_URL__ (https://cloud.example.org), __HC_USER__ (Nextcloud user), __HC_HOOK__ (webhook path),
__HC_SECRET__ (HMAC secret, same as in the app), __HC_CRED_ID__ (n8n httpBasicAuth credential: user + app password),
__AI_CRED_ID__ (n8n httpHeaderAuth credential "Authorization: Bearer ..."), __AI_URL__, __AI_MODEL__."""
import json
from pathlib import Path

VERIFY = r"""
// Accept only requests signed by the app: HMAC-SHA256(secret, ts.event.user.nonce.payload), max. 5 minutes old.
const it = $input.first().json;
const b = it.body || {};
const sig = String((it.headers || {})['x-hc-signature'] || '');
const fresh = Math.abs(Date.now() / 1000 - Number(b.ts || 0)) < 300;
if (!fresh || sig !== 'sha256=' + it.sig || b.user !== '__HC_USER__' || !['import', 'manual'].includes(b.event)) {
  return [];
}
return [{ json: { trigger: b.event } }];
"""

PROMPT = r"""
// Builds the prompt from aggregates only. Deltas are computed here, the model must only phrase them.
const s = $input.first().json;
const LN = { de: 'German', it: 'Italian', en: 'English' };
const L = LN[s.lang] || 'English';
const LR = LN[s.report_lang || s.lang] || L;
const hm = v => v === null || v === undefined ? null : Math.floor(Math.round(v) / 60) + ' h ' + String(Math.round(v) % 60).padStart(2, '0') + ' min';
const m = s.summary.metrics;
const r1 = (v, d) => v === null || v === undefined ? null : Math.round(v * Math.pow(10, d)) / Math.pow(10, d);
const lines = [];
const add = (key, label, unit, dec, scale) => {
  const x = m[key];
  if (!x || !x.period) return;
  const sc = scale || 1;
  // durations are passed already formatted as "h min" so the model never has to convert minutes
  const fmt = unit === 'min' ? (v => hm(v)) : (v => r1(v * sc, dec) + ' ' + unit);
  const base = x.baseline ? x.baseline.avg : null;
  const diff = base === null ? null : x.period.avg - base;
  lines.push(`${label}: last 7 days avg ${fmt(x.period.avg)} (n=${x.period.n} days, min ${fmt(x.period.min)}, max ${fmt(x.period.max)})` +
    (base === null ? '' : `; previous 28 days avg ${fmt(base)} (n=${x.baseline.n}); change ${unit === 'min' ? (diff < 0 ? '-' : '+') + hm(Math.abs(diff)) : fmt(diff)} (${x.delta_pct}%)`));
};
add('resting_hr', 'Resting heart rate', 'bpm', 1);
add('hr_avg', 'Average heart rate', 'bpm', 0);
add('spo2_avg', 'Night SpO2', '%', 1, 100);
add('hrv', 'HRV SDNN', 'ms', 0);
add('sleep_total', 'Sleep per night', 'min', 0);
add('sleep_deep', 'Deep sleep', 'min', 0);
add('sleep_rem', 'REM sleep', 'min', 0);
add('steps', 'Steps per day', 'steps', 0);
add('active_kcal', 'Active energy per day', 'kcal', 0);
add('weight', 'Weight', 'kg', 1);
add('bodyfat', 'Body fat', '%', 1, 100);
add('vo2max', 'VO2max (watch estimate)', 'ml/kg/min', 1);
const w = s.summary.workouts;
lines.push(`Workouts last 7 days: ${w.count} (${w.minutes} min, ${w.distance_km} km, ${w.kcal} kcal) by type ${JSON.stringify(w.by_activity)}`);
const trend = s.summary.weeks.map(x => `${x.to}: RHR ${r1(x.resting_hr, 1)}, sleep ${hm(x.sleep_total)}, deep ${hm(x.sleep_deep)}, steps ${r1(x.steps, 0)}, SpO2 ${x.spo2_avg === null ? null : r1(x.spo2_avg * 100, 1)}%`).join('\n');
const rings = s.summary.rings;
const facts = {
  period: s.summary.period, data_status: { last_day: s.data_status.lastImport && s.data_status.lastImport.lastDay, age_days: s.data_status.ageDays },
  scores_0_100: { sleep: rings.sleep.score, recovery: rings.recovery.score, movement: rings.movement.score },
  neutral_hints_from_rules: s.summary.warnings, goals: s.goals,
  this_week_progress: { steps_avg: s.progress.steps.avg, sleep_avg: hm(s.progress.sleep.avg), workouts_done: s.progress.workouts.done, targets: s.progress.targets },
  next_week: { start: s.next_week.start, end: s.next_week.end, targets: { steps: s.next_week.targets.steps, sleep: hm(s.next_week.targets.sleep_min),
    active_kcal: s.next_week.targets.active_kcal, workouts: s.next_week.targets.workouts } }, previous_plan_evaluation: s.previous_plan ? s.previous_plan.evaluation : null,
  records_all_time: s.week_infographic.records, streaks: s.week_infographic.streaks, consistency: s.week_infographic.consistency,
  earlier_weekly_summaries: s.memory,
};
const days = [];
for (let i = 0; i < 7; i++) { const d = new Date(s.next_week.start + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + i); days.push(d.toISOString().slice(0, 10)); }
const system = `You are a careful health coach writing a weekly report from smartwatch AGGREGATES for one adult. Write "summary", "tips" and the plan notes in ${L}; write "doctor" in ${LR}.
Hard rules: use ONLY the numbers given; do not invent values. No diagnoses, no disease names as conclusions, no medication, supplement or dosage advice.
Only safe, general lifestyle tips (sleep routine, light activity, recovery, hydration, daylight). If a value deviates persistently or strikingly, recommend seeing a doctor.
Durations: always write them exactly as given, e.g. "6 h 41 min" - never as a number of minutes. SpO2 in percent. Plain text, no markdown, no emojis.
Return ONLY a JSON object with exactly these keys:
{"summary": "4-6 sentences: how the week went vs the personal baseline, mention 3-5 concrete numbers; refer to earlier weeks if given; evaluate last week's plan if an evaluation is given",
 "tips": ["3 concrete, safe tips: one about sleep, one about movement, one about recovery; each max 2 sentences and tied to a number"],
 "doctor": "One neutral paragraph in clinical language for a physician: period, data source (consumer smartwatch, self-recorded), mean values with ranges and n, notable changes vs baseline, no interpretation beyond the data, no diagnosis",
 "plan": {"days": [{"date": "YYYY-MM-DD", "kind": "train|easy|rest", "note": "max 12 words"}], "bedtime": "HH:MM", "focus": "one sentence"}}
The plan covers exactly these dates: ${days.join(', ')}. Choose about ${s.next_week.targets.workouts} train days; if recovery is low or resting heart rate is elevated, start with rest/easy days. The bedtime should allow the sleep target of ${hm(s.next_week.targets.sleep_min)}.`;
const user = `Metrics (7 days vs previous 28 days):\n${lines.join('\n')}\n\nWeekly trend (13 weeks):\n${trend}\n\nFacts JSON:\n${JSON.stringify(facts)}`;
return [{ json: { messages: [{ role: 'system', content: system }, { role: 'user', content: user }], summary: s, days } }];
"""

REPORT = r"""
// Parses the model answer, keeps only safe fields, formats every duration as "h min" and builds the files:
// weekly report (app language) and Arztbericht (doctor report language) as Markdown + HTML.
const prev = $('Prompt bauen').first().json;
const s = prev.summary;
const resp = $input.first().json;
const content = String((((resp.choices || [])[0] || {}).message || {}).content || '');
const model = String(resp.model || '__AI_MODEL__');
let j = null;
const match = content.replace(/```json|```/g, '').match(/\{[\s\S]*\}/);
try { j = match ? JSON.parse(match[0]) : null; } catch (e) { j = null; }
if (!j || typeof j.summary !== 'string' || !j.summary.trim()) { throw new Error('AI answer was not valid JSON'); }
const hm = v => v === null || v === undefined ? '–' : Math.floor(Math.round(v) / 60) + ' h ' + String(Math.round(v) % 60).padStart(2, '0') + ' min';
// safety net: "342 minutes" / "342 min" / "342 Minuten" / "342 minuti" (>= 60) -> "5 h 42 min"
const fixMin = t => String(t).replace(/(\d{2,4})(?:[.,]\d+)?\s*(?:minutes|minute|minuti|minuto|Minuten|Minute|mins|min)\b\.?/g, (m0, n) => +n >= 60 ? hm(+n) : m0);
const clean = (x, n) => fixMin(String(x || '').replace(/[<>]/g, '').trim()).slice(0, n);
const tips = (Array.isArray(j.tips) ? j.tips : []).slice(0, 3).map(t => clean(typeof t === 'string' ? t : (t && t.text), 400)).filter(Boolean);
const plan = j.plan && Array.isArray(j.plan.days) ? {
  days: j.plan.days.filter(d => d && prev.days.includes(d.date) && ['train', 'easy', 'rest'].includes(String(d.kind).toLowerCase()))
    .map(d => ({ date: d.date, kind: String(d.kind).toLowerCase(), note: clean(d.note, 120) })),
  bedtime: /^\d{1,2}:\d{2}$/.test(String(j.plan.bedtime || '')) ? j.plan.bedtime : null, focus: clean(j.plan.focus, 250) } : null;
const summaryText = clean(j.summary, 3000), doctorText = clean(j.doctor, 3000);
const p = s.summary.period, lang = s.lang, rlang = s.report_lang || s.lang;
const TX = {
  de: { week: 'Wochenbericht', doc: 'Arztbericht', kw: 'KW', period: 'Zeitraum', days: 'Tage mit Daten', sum: 'Zusammenfassung', tips: 'Tipps', plan: 'Plan für die nächste Woche',
    docpar: 'Zusammenfassung für das ärztliche Gespräch', keys: 'Kennzahlen (Mittelwerte)', d7: '7 Tage', d28: '28 Tage davor', disc: 'Kein medizinischer Rat. Selbst erhobene Werte einer Verbraucher-Smartwatch, keine Diagnose; bei anhaltenden Auffälligkeiten ärztlich abklären lassen.',
    bed: 'Schlafenszeit', train: 'Training', easy: 'locker', rest: 'Ruhetag', model: 'Modell', wo: 'Trainings', src: 'Quelle: Apple-Health-Export (Smartwatch)',
    m: ['Ruhepuls (bpm)', 'SpO₂ (%)', 'Schlaf', 'Tiefschlaf', 'Schritte/Tag', 'Aktive kcal/Tag', 'Gewicht (kg)', 'VO₂max'], dec: ',' },
  it: { week: 'Report settimanale', doc: 'Report per il medico', kw: 'sett.', period: 'Periodo', days: 'giorni con dati', sum: 'Riepilogo', tips: 'Consigli', plan: 'Piano per la prossima settimana',
    docpar: 'Riepilogo per il medico', keys: 'Valori chiave (medie)', d7: '7 giorni', d28: '28 giorni prima', disc: 'Non è un consiglio medico. Valori rilevati da uno smartwatch di consumo, nessuna diagnosi; per anomalie persistenti rivolgersi a un medico.',
    bed: 'Ora di dormire', train: 'allenamento', easy: 'leggero', rest: 'riposo', model: 'Modello', wo: 'Allenamenti', src: 'Fonte: esportazione Apple Salute (smartwatch)',
    m: ['FC a riposo (bpm)', 'SpO₂ (%)', 'Sonno', 'Sonno profondo', 'Passi/giorno', 'kcal attive/giorno', 'Peso (kg)', 'VO₂max'], dec: ',' },
  en: { week: 'Weekly report', doc: 'Doctor report', kw: 'week', period: 'Period', days: 'days with data', sum: 'Summary', tips: 'Tips', plan: 'Plan for next week',
    docpar: 'Summary for the physician', keys: 'Key figures (means)', d7: '7 days', d28: '28 days before', disc: 'Not medical advice. Self-recorded values of a consumer smartwatch, no diagnosis; see a doctor about persistent anomalies.',
    bed: 'Bedtime', train: 'training', easy: 'easy', rest: 'rest day', model: 'Model', wo: 'Workouts', src: 'Source: Apple Health export (smartwatch)',
    m: ['Resting HR (bpm)', 'SpO₂ (%)', 'Sleep', 'Deep sleep', 'Steps/day', 'Active kcal/day', 'Weight (kg)', 'VO₂max'], dec: '.' },
};
const T = TX[lang] || TX.en, R = TX[rlang] || TX.en;
const dmy = (iso, l) => l === 'en' ? iso : iso.slice(8, 10) + '.' + iso.slice(5, 7) + '.' + iso.slice(0, 4);
const m = s.summary.metrics;
const num = (v, d, X) => v === null || v === undefined ? '–' : Number(v).toFixed(d).replace('.', X.dec);
const rowsFor = X => [[0, m.resting_hr, v => num(v, 1, X)], [1, m.spo2_avg, v => num(v * 100, 1, X)], [2, m.sleep_total, hm], [3, m.sleep_deep, hm],
  [4, m.steps, v => num(v, 0, X)], [5, m.active_kcal, v => num(v, 0, X)], [6, m.weight, v => num(v, 1, X)], [7, m.vo2max, v => num(v, 1, X)]]
  .filter(r => r[1] && r[1].period).map(r => [X.m[r[0]], r[2](r[1].period.avg), r[1].baseline ? r[2](r[1].baseline.avg) : '–', r[1].delta_pct === null ? '–' : (r[1].delta_pct > 0 ? '+' : '') + num(r[1].delta_pct, 1, X) + ' %']);
const kind = k => T[k] || k;
const esc = x => String(x).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const table = X => `<table><tr><th></th><th>${esc(X.d7)}</th><th>${esc(X.d28)}</th><th>Δ</th></tr>${rowsFor(X).map(r => '<tr>' + r.map(c => '<td>' + esc(c) + '</td>').join('') + '</tr>').join('')}</table>`;
const mdTable = X => `| | ${X.d7} | ${X.d28} | Δ |\n|---|---|---|---|\n` + rowsFor(X).map(r => `| ${r.join(' | ')} |`).join('\n');
const css = 'body{font:15px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:820px;margin:24px auto;padding:0 16px;color:#14181d}h1{margin:0}h2{margin-top:26px}.g{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:16px 0}.t{border-radius:14px;padding:12px;background:color-mix(in srgb,var(--c) 12%,#fff);border:1px solid color-mix(in srgb,var(--c) 35%,#ddd)}.l{font-size:12px;color:#555}.v{font-size:24px;font-weight:800;color:var(--c)}table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #ddd;padding:6px;text-align:left}small{color:#666}@media print{body{margin:0}}';
const page = (l, title, body) => `<!DOCTYPE html><html lang="${l}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${esc(title)}</title><style>${css}</style></head><body>${body}</body></html>`;
const rg = s.summary.rings, w = s.summary.workouts;
const tile = (label, val, color) => `<div class="t" style="--c:${color}"><div class="l">${esc(label)}</div><div class="v">${esc(val)}</div></div>`;
const per = (X, l) => `${X.period}: ${dmy(p.from, l)} – ${dmy(p.to, l)} · ${p.days_with_data}/${p.days} ${X.days}`;
// ISO week of the period end
const d0 = new Date(p.to + 'T12:00:00Z'); const dn = (d0.getUTCDay() + 6) % 7; d0.setUTCDate(d0.getUTCDate() - dn + 3);
const fy = d0.getUTCFullYear(); const f4 = new Date(Date.UTC(fy, 0, 4)); const wk = 1 + Math.round(((d0 - f4) / 864e5 - 3 + ((f4.getUTCDay() + 6) % 7)) / 7);
const isoWeek = fy + '-' + String(wk).padStart(2, '0');
// weekly report (app language)
let md = `# ${T.week} ${dmy(p.to, lang)}\n\n${per(T, lang)}\n\n## ${T.sum}\n\n${summaryText}\n\n## ${T.keys}\n\n${mdTable(T)}\n\n${T.wo}: ${w.count} · ${hm(w.minutes)} · ${num(w.distance_km, 1, T)} km\n\n## ${T.tips}\n\n` + tips.map((t, i) => `${i + 1}. ${t}`).join('\n');
if (plan) { md += `\n\n## ${T.plan}\n\n` + plan.days.map(d => `- ${dmy(d.date, lang)}: ${kind(d.kind)}${d.note ? ' – ' + d.note : ''}`).join('\n') + (plan.bedtime ? `\n- ${T.bed}: ${plan.bedtime}` : '') + (plan.focus ? `\n\n${plan.focus}` : ''); }
md += `\n\n---\n${T.disc} ${T.model}: ${model}\n`;
const html = page(lang, T.week + ' ' + p.to, `<h1>${esc(T.week)}</h1><p>${esc(per(T, lang))}</p>
<div class="g">${tile('Sonno / Schlaf', rg.sleep.score === null ? '–' : rg.sleep.score, '#7c6cf2')}${tile('Recupero / Erholung', rg.recovery.score === null ? '–' : rg.recovery.score, '#2bb673')}${tile('Movimento / Bewegung', rg.movement.score === null ? '–' : rg.movement.score, '#f2994a')}
${tile(T.m[2], m.sleep_total && m.sleep_total.period ? hm(m.sleep_total.period.avg) : '–', '#7c6cf2')}${tile(T.m[0], m.resting_hr && m.resting_hr.period ? num(m.resting_hr.period.avg, 1, T) : '–', '#e5484d')}${tile(T.m[4], m.steps && m.steps.period ? num(m.steps.period.avg, 0, T) : '–', '#f2994a')}
${tile(T.wo, w.count + ' · ' + hm(w.minutes), '#22c55e')}</div>
<h2>${esc(T.sum)}</h2><p>${esc(summaryText)}</p><h2>${esc(T.keys)}</h2>${table(T)}<h2>${esc(T.tips)}</h2><ol>${tips.map(t => '<li>' + esc(t) + '</li>').join('')}</ol>
${plan ? `<h2>${esc(T.plan)}</h2><ul>${plan.days.map(d => '<li><b>' + esc(dmy(d.date, lang)) + '</b> ' + esc(kind(d.kind)) + (d.note ? ' – ' + esc(d.note) : '') + '</li>').join('')}${plan.bedtime ? '<li>' + esc(T.bed) + ': ' + esc(plan.bedtime) + '</li>' : ''}</ul>${plan.focus ? '<p>' + esc(plan.focus) + '</p>' : ''}` : ''}
<p><small>${esc(T.disc)} ${esc(T.model)}: ${esc(model)}</small></p><p><small><a href="__NC_URL__/apps/healthcheck/infographic">Infografik</a> · <a href="__NC_URL__/apps/healthcheck/report">${esc(R.doc)}</a></small></p>`);
// doctor report (report language)
const dmd = `# ${R.doc} ${R.kw} ${isoWeek}\n\n${per(R, rlang)}\n\n${R.src}\n\n## ${R.keys}\n\n${mdTable(R)}\n\n${R.wo}: ${w.count} · ${hm(w.minutes)}\n\n## ${R.docpar}\n\n${doctorText}\n\n---\n${R.disc} ${R.model}: ${model}\n`;
const dhtml = page(rlang, R.doc + ' ' + isoWeek, `<h1>${esc(R.doc)} · ${esc(R.kw)} ${esc(isoWeek)}</h1><p>${esc(per(R, rlang))}<br><small>${esc(R.src)}</small></p>
<h2>${esc(R.keys)}</h2>${table(R)}<p>${esc(R.wo)}: ${w.count} · ${esc(hm(w.minutes))}</p><h2>${esc(R.docpar)}</h2><p>${esc(doctorText)}</p><p><small>${esc(R.disc)} ${esc(R.model)}: ${esc(model)}</small></p>`);
const base = 'Wochenbericht-' + p.to, dbase = 'Arztbericht-' + isoWeek + '.' + rlang;
const report = { summary: summaryText, tips, doctor: doctorText, doctor_lang: rlang, plan, model, period_from: p.from, period_to: p.to, report_path: '/Health/Reports/' + base + '.html' };
return [
  { json: { file: base + '.md', ctype: 'text/markdown; charset=utf-8', body: md, report } },
  { json: { file: base + '.html', ctype: 'text/html; charset=utf-8', body: html, report } },
  { json: { file: dbase + '.md', ctype: 'text/markdown; charset=utf-8', body: dmd, report } },
  { json: { file: dbase + '.html', ctype: 'text/html; charset=utf-8', body: dhtml, report } },
];
"""


def basic():
    return {"httpBasicAuth": {"id": "__HC_CRED_ID__", "name": "Health Check · Nextcloud"}}


def http(name, pos, method, url, extra=None, creds=None):
    p = {"method": method, "url": url, "authentication": "genericCredentialType", "genericAuthType": "httpBasicAuth",
         "sendHeaders": True, "headerParameters": {"parameters": [{"name": "OCS-APIRequest", "value": "true"}]},
         "options": {"timeout": 60000}}
    p.update(extra or {})
    return {"parameters": p, "name": name, "type": "n8n-nodes-base.httpRequest", "typeVersion": 4.2, "position": pos, "credentials": creds or basic()}


nodes = [
    {"parameters": {"rule": {"interval": [{"field": "cronExpression", "expression": "0 7 * * 1"}]}}, "name": "Montag 07:00",
     "type": "n8n-nodes-base.scheduleTrigger", "typeVersion": 1.2, "position": [0, 0]},
    {"parameters": {"httpMethod": "POST", "path": "__HC_HOOK__", "responseMode": "onReceived", "options": {}}, "name": "Webhook (App)",
     "type": "n8n-nodes-base.webhook", "typeVersion": 2, "position": [0, 220], "webhookId": "__HC_HOOK__"},
    {"parameters": {"action": "hmac", "type": "SHA256", "value": "={{ $json.body.ts }}.{{ $json.body.event }}.{{ $json.body.user }}.{{ $json.body.nonce }}.{{ $json.body.payload }}",
                    "dataPropertyName": "sig", "secret": "__HC_SECRET__", "encoding": "hex"}, "name": "HMAC",
     "type": "n8n-nodes-base.crypto", "typeVersion": 1, "position": [220, 220]},
    {"parameters": {"jsCode": VERIFY.strip()}, "name": "Signatur prüfen", "type": "n8n-nodes-base.code", "typeVersion": 2, "position": [440, 220]},
    http("Aggregate holen", [660, 100], "GET", "__NC_URL__/apps/healthcheck/api/automation/summary"),
    {"parameters": {"jsCode": PROMPT.strip()}, "name": "Prompt bauen", "type": "n8n-nodes-base.code", "typeVersion": 2, "position": [880, 100]},
    {"parameters": {"method": "POST", "url": "__AI_URL__", "authentication": "genericCredentialType", "genericAuthType": "httpHeaderAuth",
                    "sendBody": True, "specifyBody": "json",
                    "jsonBody": "={{ JSON.stringify({ model: '__AI_MODEL__', messages: $json.messages, max_tokens: 6000, reasoning_effort: 'low', temperature: 0.3 }) }}",
                    "options": {"timeout": 180000}},
     "name": "KI (IONOS)", "type": "n8n-nodes-base.httpRequest", "typeVersion": 4.2, "position": [1100, 100],
     "credentials": {"httpHeaderAuth": {"id": "__AI_CRED_ID__", "name": "IONOS AI Model Hub"}}},
    {"parameters": {"jsCode": REPORT.strip()}, "name": "Bericht bauen", "type": "n8n-nodes-base.code", "typeVersion": 2, "position": [1320, 100]},
    http("Dateien speichern", [1540, 100], "PUT", "=__NC_URL__/remote.php/dav/files/__HC_USER__/Health/Reports/{{ $json.file }}",
         {"sendBody": True, "contentType": "raw", "rawContentType": "={{ $json.ctype }}", "body": "={{ $json.body }}",
          "options": {"timeout": 60000, "response": {"response": {"responseFormat": "text"}}}}),
    dict(http("An App melden", [1760, 100], "POST", "__NC_URL__/apps/healthcheck/api/automation/report",
         {"sendBody": True, "specifyBody": "json", "jsonBody": "={{ JSON.stringify($('Bericht bauen').first().json.report) }}"}), executeOnce=True),
]
conn = lambda a, b: {a: {"main": [[{"node": b, "type": "main", "index": 0}]]}}
connections = {}
for a, b in [("Montag 07:00", "Aggregate holen"), ("Webhook (App)", "HMAC"), ("HMAC", "Signatur prüfen"), ("Signatur prüfen", "Aggregate holen"),
             ("Aggregate holen", "Prompt bauen"), ("Prompt bauen", "KI (IONOS)"), ("KI (IONOS)", "Bericht bauen"), ("Bericht bauen", "Dateien speichern"),
             ("Dateien speichern", "An App melden")]:
    connections.update(conn(a, b))

wf = {"id": "__WF_ID__", "name": "Health Check – Wochenbericht", "nodes": nodes, "connections": connections, "active": False,
      "settings": {"executionOrder": "v1", "timezone": "Europe/Berlin", "saveDataErrorExecution": "all", "saveDataSuccessExecution": "none"},
      "pinData": {}, "tags": []}
out = Path(__file__).with_name("healthcheck-weekly.json")
out.write_text(json.dumps(wf, ensure_ascii=False, indent=1) + "\n")
print(out)
