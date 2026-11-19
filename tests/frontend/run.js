/* Node tests for the pure frontend logic: node tests/frontend/run.js */
'use strict';
const fs = require('fs'), path = require('path'), assert = require('assert');
global.self = global;
const i18n = require('../../js/hc-i18n.js'), core = require('../../js/hc-core.js');
let n = 0, failed = 0;
function test(name, fn) { n++; try { fn(); } catch (e) { failed++; console.log('FAIL ' + name + '\n  ' + (e && e.message)); } }
const JS = path.join(__dirname, '../../js');
const app = fs.readFileSync(path.join(JS, 'hc-app.js'), 'utf8') + fs.readFileSync(path.join(JS, 'hc-charts.js'), 'utf8');

test('i18n: de/en/it have the same keys', () => {
  const k = (l) => Object.keys(i18n.dict[l]).sort();
  assert.deepStrictEqual(k('de'), k('en')); assert.deepStrictEqual(k('it'), k('en'));
});
test('i18n: placeholders consistent, no empty texts', () => {
  Object.keys(i18n.dict.en).forEach((key) => {
    const ph = (s) => (s.match(/\{\w+\}/g) || []).sort().join();
    ['de', 'it'].forEach((l) => { assert.ok(i18n.dict[l][key], l + ':' + key); assert.strictEqual(ph(i18n.dict[l][key]), ph(i18n.dict.en[key]), l + ' placeholders ' + key); });
  });
});
test('i18n: every literal key used in the code exists', () => {
  const used = new Set(); const re = /\bt\('([a-z0-9_]+)'(?!\s*\+)/g; let m;
  while ((m = re.exec(app))) { used.add(m[1]); }
  const missing = [...used].filter((k) => i18n.dict.en[k] === undefined);
  assert.deepStrictEqual(missing, []);
});
test('i18n: dynamic key families complete', () => {
  const fam = { tab_: ['today', 'trends', 'agent', 'ask', 'more'], ring_: ['sleep', 'recovery', 'movement'], ring_how_: ['sleep', 'recovery', 'movement'], ring_none_: ['sleep', 'recovery', 'movement'],
    part_: ['duration', 'deep', 'rem', 'awake', 'rhr', 'spo2', 'hrv', 'steps', 'kcal'], warn_: ['rhr_up', 'spo2_drop', 'deep_drop', 'sleep_short', 'steps_drop', 'weight_change'],
    stage_: ['deep', 'core', 'rem', 'awake', 'other'], plan_: ['train', 'easy', 'rest'], per_: ['week', 'month', 'year'], info_title_: ['week', 'month', 'year'],
    bucket_: ['week', 'month'], c_workouts_: ['week', 'month'], st_: ['ok', 'error', 'running'], how_: [1, 2, 3, 4, 5],
    import_: ['nothing', 'skipped_failed', 'running', 'error'], err_: ['offline', 'rate_limited', 'ai_not_configured', 'ai_empty', 'not_configured', 'request_failed', 'validation', 'server_error'] };
  Object.keys(fam).forEach((p) => fam[p].forEach((s) => assert.ok(i18n.dict.en[p + s], p + s)));
  ['months', 'daynames'].forEach((k) => ['de', 'en', 'it'].forEach((l) => assert.strictEqual(i18n.dict[l][k].split(',').length, k === 'months' ? 12 : 7)));
});
test('i18n: placeholders and fallback', () => {
  i18n.setLang('de'); assert.strictEqual(i18n.t('range_d', { n: 30 }), '30 Tage'); assert.strictEqual(i18n.t('nope'), 'nope');
  i18n.setLang('fr'); assert.strictEqual(i18n.lang(), 'en');
});
test('format: numbers, durations, dates', () => {
  i18n.setLang('de');
  assert.strictEqual(core.num(9420, 0), '9.420'); assert.strictEqual(core.num(32.45, 1), '32,5'); assert.strictEqual(core.num(null), '–');
  assert.strictEqual(core.hm(432), '7 h 12'); assert.strictEqual(core.hm(45), '45 min'); assert.strictEqual(core.hm(65, true), '1 h 05 min');
  assert.strictEqual(core.pct(0.9745), '97,5 %'); assert.strictEqual(core.day('2026-11-18'), '18.11.2026');
  assert.strictEqual(core.signed(-3.2, 1, 'bpm'), '−3,2 bpm'); assert.strictEqual(core.signed(0, 0), '±0');
  i18n.setLang('en'); assert.strictEqual(core.num(9420.5, 1), '9,420.5'); assert.strictEqual(core.day('2026-11-18'), '2026-11-18');
});
test('aggregation: weekly / monthly averages', () => {
  const rows = [{ day: '2026-11-16', steps: 1000 }, { day: '2026-11-17', steps: 3000 }, { day: '2026-11-23', steps: 5000 }, { day: '2026-11-24', steps: null }];
  assert.deepStrictEqual(core.series(rows, 'steps', 'week'), [{ x: '2026-11-16', y: 2000, n: 2 }, { x: '2026-11-23', y: 5000, n: 1 }]);
  assert.deepStrictEqual(core.series(rows, 'steps', 'month').map((p) => p.y), [3000]);
  assert.strictEqual(core.series([{ day: '2026-01-01', spo2_avg: 0.97 }], 'spo2_avg', 'day', 100)[0].y, 97);
  assert.strictEqual(core.mondayOf('2026-11-22'), '2026-11-16'); assert.strictEqual(core.bucketFor(90), 'day'); assert.strictEqual(core.bucketFor(365), 'week'); assert.strictEqual(core.bucketFor(4000), 'month');
});
test('platform detection for the install hint', () => {
  assert.strictEqual(core.detect('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit Version/18.0 Mobile/15E148 Safari/604.1', 'iPhone', 5).safari, true);
  assert.strictEqual(core.detect('Mozilla/5.0 (iPhone) CriOS/120 Safari/604.1', 'iPhone', 5).safari, false);
  assert.strictEqual(core.detect('Mozilla/5.0 (Macintosh)', 'MacIntel', 5).ios, true);
});
test('security: no innerHTML / eval / inline handlers in app code', () => {
  fs.readdirSync(JS).filter((f) => f.endsWith('.js')).forEach((f) => {
    const s = fs.readFileSync(path.join(JS, f), 'utf8');
    assert.ok(!/innerHTML|outerHTML|insertAdjacentHTML|eval\(|new Function/.test(s), f);
  });
});
test('service worker never caches pages or API', () => {
  const sw = fs.readFileSync(path.join(JS, 'hc-sw.js'), 'utf8');
  assert.ok(/req\.mode === 'navigate'/.test(sw) && /\/api\//.test(sw));
  const re = /\/healthcheck\/(js|css|img)\/[^?#]+\.(js|css|png|svg)$/;
  assert.ok(re.test('/apps/healthcheck/js/hc-app.js')); assert.ok(!re.test('/apps/healthcheck/api/overview')); assert.ok(!re.test('/apps/healthcheck/'));
});
console.log((failed ? 'FAILED' : 'OK') + ': ' + n + ' tests, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
