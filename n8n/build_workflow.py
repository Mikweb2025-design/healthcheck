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
const L = { de: 'German', it: 'Italian', en: 'English' }[s.lang] || 'English';
const m = s.summary.metrics;
const r1 = (v, d) => v === null || v === undefined ? null : Math.round(v * Math.pow(10, d)) / Math.pow(10, d);
const lines = [];
const add = (key, label, unit, dec, scale) => {
  const x = m[key];
  if (!x || !x.period) return;
  const sc = scale || 1;
  const cur = r1(x.period.avg * sc, dec), base = x.baseline ? r1(x.baseline.avg * sc, dec) : null;
  lines.push(`${label}: last 7 days avg ${cur} ${unit} (n=${x.period.n} days, min ${r1(x.period.min * sc, dec)}, max ${r1(x.period.max * sc, dec)})` +
    (base === null ? '' : `; previous 28 days avg ${base} ${unit} (n=${x.baseline.n}); change ${r1((x.period.avg - x.baseline.avg) * sc, dec)} ${unit} (${x.delta_pct}%)`));
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
const trend = s.summary.weeks.map(x => `${x.to}: RHR ${r1(x.resting_hr, 1)}, sleep ${r1(x.sleep_total, 0)} min, deep ${r1(x.sleep_deep, 0)} min, steps ${r1(x.steps, 0)}, SpO2 ${x.spo2_avg === null ? null : r1(x.spo2_avg * 100, 1)}%`).join('\n');
const rings = s.summary.rings;
const facts = {
  period: s.summary.period, data_status: { last_day: s.data_status.lastImport && s.data_status.lastImport.lastDay, age_days: s.data_status.ageDays },
  scores_0_100: { sleep: rings.sleep.score, recovery: rings.recovery.score, movement: rings.movement.score },
  neutral_hints_from_rules: s.summary.warnings, goals: s.goals,
  this_week_progress: { steps_avg: s.progress.steps.avg, sleep_avg_min: s.progress.sleep.avg, workouts_done: s.progress.workouts.done, targets: s.progress.targets },
  next_week: s.next_week, previous_plan_evaluation: s.previous_plan ? s.previous_plan.evaluation : null,
  records_all_time: s.week_infographic.records, streaks: s.week_infographic.streaks, consistency: s.week_infographic.consistency,
  earlier_weekly_summaries: s.memory,
};
const days = [];
for (let i = 0; i < 7; i++) { const d = new Date(s.next_week.start + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + i); days.push(d.toISOString().slice(0, 10)); }
const system = `You are a careful health coach writing a weekly report from smartwatch AGGREGATES for one adult. Write in ${L}.
Hard rules: use ONLY the numbers given; do not invent values. No diagnoses, no disease names as conclusions, no medication, supplement or dosage advice.
Only safe, general lifestyle tips (sleep routine, light activity, recovery, hydration, daylight). If a value deviates persistently or strikingly, recommend seeing a doctor.
Durations: write minutes as "6 h 41 min". SpO2 in percent. Plain text, no markdown, no emojis.
Return ONLY a JSON object with exactly these keys:
{"summary": "4-6 sentences: how the week went vs the personal baseline, mention 3-5 concrete numbers; refer to earlier weeks if given; evaluate last week's plan if an evaluation is given",
 "tips": ["3 concrete, safe tips: one about sleep, one about movement, one about recovery; each max 2 sentences and tied to a number"],
 "doctor": "One neutral paragraph in clinical language for a physician: period, data source (consumer smartwatch, self-recorded), mean values with ranges and n, notable changes vs baseline, no interpretation beyond the data, no diagnosis",
 "plan": {"days": [{"date": "YYYY-MM-DD", "kind": "train|easy|rest", "note": "max 12 words"}], "bedtime": "HH:MM", "focus": "one sentence"}}
The plan covers exactly these dates: ${days.join(', ')}. Choose about ${s.next_week.targets.workouts} train days; if recovery is low or resting heart rate is elevated, start with rest/easy days. The bedtime should allow the sleep target of ${s.next_week.targets.sleep_min} minutes.`;
const user = `Metrics (7 days vs previous 28 days):\n${lines.join('\n')}\n\nWeekly trend (13 weeks):\n${trend}\n\nFacts JSON:\n${JSON.stringify(facts)}`;
return [{ json: { messages: [{ role: 'system', content: system }, { role: 'user', content: user }], summary: s, days } }];
"""

REPORT = r"""
// Parses the model answer, keeps only safe fields, writes Markdown + HTML for /Health/Reports.
const prev = $('Prompt bauen').first().json;
const s = prev.summary;
const resp = $input.first().json;
const content = String((((resp.choices || [])[0] || {}).message || {}).content || '');
const model = String(resp.model || '__AI_MODEL__');
let j = null;
const match = content.replace(/```json|```/g, '').match(/\{[\s\S]*\}/);
try { j = match ? JSON.parse(match[0]) : null; } catch (e) { j = null; }
if (!j || typeof j.summary !== 'string' || !j.summary.trim()) { throw new Error('AI answer was not valid JSON'); }
const clean = (x, n) => String(x || '').replace(/[<>]/g, '').trim().slice(0, n);
const tips = (Array.isArray(j.tips) ? j.tips : []).slice(0, 3).map(t => clean(typeof t === 'string' ? t : (t && t.text), 400)).filter(Boolean);
const plan = j.plan && Array.isArray(j.plan.days) ? {
  days: j.plan.days.filter(d => d && prev.days.includes(d.date) && ['train', 'easy', 'rest'].includes(String(d.kind).toLowerCase()))
    .map(d => ({ date: d.date, kind: String(d.kind).toLowerCase(), note: clean(d.note, 120) })),
  bedtime: /^\d{1,2}:\d{2}$/.test(String(j.plan.bedtime || '')) ? j.plan.bedtime : null, focus: clean(j.plan.focus, 250) } : null;
const p = s.summary.period, lang = s.lang;
const T = {
  de: ['Wochenbericht', 'Zeitraum', 'Zusammenfassung', 'Tipps', 'Plan für die nächste Woche', 'Für das ärztliche Gespräch', 'Kennzahlen', 'Wert', '7 Tage', '28 Tage davor', 'Kein medizinischer Rat. Werte einer Verbraucher-Smartwatch; bei anhaltenden Auffälligkeiten ärztlich abklären lassen.', 'Schlafenszeit', 'Training', 'locker', 'Ruhetag', 'Modell'],
  it: ['Report settimanale', 'Periodo', 'Riepilogo', 'Consigli', 'Piano per la prossima settimana', 'Per il medico', 'Valori chiave', 'Valore', '7 giorni', '28 giorni prima', 'Non è un consiglio medico. Valori di uno smartwatch di consumo; per anomalie persistenti rivolgersi a un medico.', 'Ora di dormire', 'allenamento', 'leggero', 'riposo', 'Modello'],
  en: ['Weekly report', 'Period', 'Summary', 'Tips', 'Plan for next week', 'For the doctor', 'Key figures', 'Value', '7 days', '28 days before', 'Not medical advice. Values from a consumer smartwatch; see a doctor about persistent anomalies.', 'Bedtime', 'training', 'easy', 'rest day', 'Model'],
}[lang] || null;
const L = T || { 0: 'Weekly report' };
const m = s.summary.metrics;
const hm = v => v === null || v === undefined ? '–' : Math.floor(v / 60) + ' h ' + String(Math.round(v % 60)).padStart(2, '0') + ' min';
const f = (v, d) => v === null || v === undefined ? '–' : Number(v).toFixed(d);
const rows = [
  ['Resting HR (bpm)', m.resting_hr, v => f(v, 1)], ['SpO2 (%)', m.spo2_avg, v => f(v * 100, 1)], ['Sleep', m.sleep_total, hm], ['Deep sleep', m.sleep_deep, hm],
  ['Steps/day', m.steps, v => f(v, 0)], ['Active kcal/day', m.active_kcal, v => f(v, 0)], ['Weight (kg)', m.weight, v => f(v, 1)], ['VO2max', m.vo2max, v => f(v, 1)],
].filter(r => r[1] && r[1].period).map(r => [r[0], r[2](r[1].period.avg), r[1].baseline ? r[2](r[1].baseline.avg) : '–', r[1].delta_pct === null ? '–' : (r[1].delta_pct > 0 ? '+' : '') + r[1].delta_pct + ' %']);
const kind = k => ({ train: L[12], easy: L[13], rest: L[14] })[k] || k;
let md = `# ${L[0]} ${p.to}\n\n${L[1]}: ${p.from} – ${p.to} (${p.days_with_data}/${p.days})\n\n## ${L[2]}\n\n${clean(j.summary, 3000)}\n\n## ${L[6]}\n\n| | ${L[8]} | ${L[9]} | Δ |\n|---|---|---|---|\n`;
md += rows.map(r => `| ${r.join(' | ')} |`).join('\n') + `\n\nWorkouts: ${s.summary.workouts.count} · ${s.summary.workouts.minutes} min · ${s.summary.workouts.distance_km} km\n\n## ${L[3]}\n\n` + tips.map((t, i) => `${i + 1}. ${t}`).join('\n');
if (plan) { md += `\n\n## ${L[4]}\n\n` + plan.days.map(d => `- ${d.date}: ${kind(d.kind)}${d.note ? ' – ' + d.note : ''}`).join('\n') + (plan.bedtime ? `\n- ${L[11]}: ${plan.bedtime}` : '') + (plan.focus ? `\n\n${plan.focus}` : ''); }
md += `\n\n## ${L[5]}\n\n${clean(j.doctor, 3000)}\n\n---\n${L[10]} ${L[15]}: ${model}\n`;
const esc = x => String(x).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const rg = s.summary.rings;
const tile = (label, val, color) => `<div class="t" style="--c:${color}"><div class="l">${esc(label)}</div><div class="v">${esc(val)}</div></div>`;
const html = `<!DOCTYPE html><html lang="${lang}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${esc(L[0] + ' ' + p.to)}</title>
<style>body{font:15px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:820px;margin:24px auto;padding:0 16px;color:#14181d}h1{margin:0}h2{margin-top:28px}.g{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:16px 0}
.t{border-radius:14px;padding:12px;background:color-mix(in srgb,var(--c) 12%,#fff);border:1px solid color-mix(in srgb,var(--c) 35%,#ddd)}.l{font-size:12px;color:#555}.v{font-size:24px;font-weight:800;color:var(--c)}
table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #ddd;padding:6px;text-align:left}small{color:#666}@media(prefers-color-scheme:dark){body{background:#0f1419;color:#e8edf2}.t{background:#182028}}</style></head><body>
<h1>${esc(L[0])}</h1><p>${esc(L[1])}: ${esc(p.from)} – ${esc(p.to)} · ${p.days_with_data}/${p.days}</p>
<div class="g">${tile('Sleep score', rg.sleep.score === null ? '–' : rg.sleep.score, '#7c6cf2')}${tile('Recovery', rg.recovery.score === null ? '–' : rg.recovery.score, '#2bb673')}${tile('Movement', rg.movement.score === null ? '–' : rg.movement.score, '#f2994a')}
${tile('Ø Sleep', m.sleep_total && m.sleep_total.period ? hm(m.sleep_total.period.avg) : '–', '#7c6cf2')}${tile('Ø Resting HR', m.resting_hr && m.resting_hr.period ? f(m.resting_hr.period.avg, 1) + ' bpm' : '–', '#e5484d')}${tile('Ø Steps', m.steps && m.steps.period ? f(m.steps.period.avg, 0) : '–', '#f2994a')}
${tile('Workouts', s.summary.workouts.count + ' · ' + s.summary.workouts.minutes + ' min', '#22c55e')}${tile('Consistency', s.week_infographic.consistency.score === null ? '–' : s.week_infographic.consistency.score + ' %', '#14b8a6')}</div>
<h2>${esc(L[2])}</h2><p>${esc(clean(j.summary, 3000))}</p><h2>${esc(L[6])}</h2><table><tr><th></th><th>${esc(L[8])}</th><th>${esc(L[9])}</th><th>Δ</th></tr>${rows.map(r => '<tr>' + r.map(c => '<td>' + esc(c) + '</td>').join('') + '</tr>').join('')}</table>
<h2>${esc(L[3])}</h2><ol>${tips.map(t => '<li>' + esc(t) + '</li>').join('')}</ol>
${plan ? `<h2>${esc(L[4])}</h2><ul>${plan.days.map(d => '<li><b>' + esc(d.date) + '</b> ' + esc(kind(d.kind)) + (d.note ? ' – ' + esc(d.note) : '') + '</li>').join('')}${plan.bedtime ? '<li>' + esc(L[11]) + ': ' + esc(plan.bedtime) + '</li>' : ''}</ul>${plan.focus ? '<p>' + esc(plan.focus) + '</p>' : ''}` : ''}
<h2>${esc(L[5])}</h2><p>${esc(clean(j.doctor, 3000))}</p><p><small>${esc(L[10])} ${esc(L[15])}: ${esc(model)}</small></p><p><small><a href="__NC_URL__/apps/healthcheck/infographic">Infographic</a></small></p></body></html>`;
const fileBase = 'Wochenbericht-' + p.to;
return [{ json: { fileBase, md, html, report: { summary: clean(j.summary, 3000), tips, doctor: clean(j.doctor, 3000), plan, model, period_from: p.from, period_to: p.to,
  report_path: '/Health/Reports/' + fileBase + '.html' } } }];
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
    http("Markdown speichern", [1540, 100], "PUT", "=__NC_URL__/remote.php/dav/files/__HC_USER__/Health/Reports/{{ $json.fileBase }}.md",
         {"sendBody": True, "contentType": "raw", "rawContentType": "text/markdown; charset=utf-8", "body": "={{ $json.md }}",
          "options": {"timeout": 60000, "response": {"response": {"responseFormat": "text"}}}}),
    http("HTML speichern", [1760, 100], "PUT", "=__NC_URL__/remote.php/dav/files/__HC_USER__/Health/Reports/{{ $('Bericht bauen').first().json.fileBase }}.html",
         {"sendBody": True, "contentType": "raw", "rawContentType": "text/html; charset=utf-8", "body": "={{ $('Bericht bauen').first().json.html }}",
          "options": {"timeout": 60000, "response": {"response": {"responseFormat": "text"}}}}),
    http("An App melden", [1980, 100], "POST", "__NC_URL__/apps/healthcheck/api/automation/report",
         {"sendBody": True, "specifyBody": "json", "jsonBody": "={{ JSON.stringify($('Bericht bauen').first().json.report) }}"}),
]
conn = lambda a, b: {a: {"main": [[{"node": b, "type": "main", "index": 0}]]}}
connections = {}
for a, b in [("Montag 07:00", "Aggregate holen"), ("Webhook (App)", "HMAC"), ("HMAC", "Signatur prüfen"), ("Signatur prüfen", "Aggregate holen"),
             ("Aggregate holen", "Prompt bauen"), ("Prompt bauen", "KI (IONOS)"), ("KI (IONOS)", "Bericht bauen"), ("Bericht bauen", "Markdown speichern"),
             ("Markdown speichern", "HTML speichern"), ("HTML speichern", "An App melden")]:
    connections.update(conn(a, b))

wf = {"id": "__WF_ID__", "name": "Health Check – Wochenbericht", "nodes": nodes, "connections": connections, "active": False,
      "settings": {"executionOrder": "v1", "timezone": "Europe/Berlin", "saveDataErrorExecution": "all", "saveDataSuccessExecution": "none"},
      "pinData": {}, "tags": []}
out = Path(__file__).with_name("healthcheck-weekly.json")
out.write_text(json.dumps(wf, ensure_ascii=False, indent=1) + "\n")
print(out)
