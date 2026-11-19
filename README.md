# Health Check (Nextcloud app)

**Health Check** (DE *Gesundheits-Check*) turns the weekly Apple Health export of an iPhone into a private health
dashboard inside Nextcloud: three rings (sleep, recovery, movement), trends back to the first recorded day, neutral early
hints, a printable doctor report, an infographic, goals with a weekly plan, an "ask your data" chat and a weekly AI report
via n8n. Any watch that writes into Apple Health works (tested with a Huawei watch via Huawei Health → Apple Health).

> Not a medical device and not medical advice. All hints compare a person only with their own recent past.

## How it works

```
iPhone Health app ──Export.zip──▶ Nextcloud /Health/Import ──(job every 15 min)──▶ streaming import ──▶ hc_day / hc_workout
                                                                                       │
            app UI / dashboard widget / calendar "Sport & Gesundheit" ◀────────────────┤
                                                                                       ▼
                      n8n "Health Check – Wochenbericht" ◀── signed webhook after import, or Monday 07:00
                        │  GET  /api/automation/summary   (aggregates only)
                        │  AI (OpenAI-compatible endpoint) → summary, 3 tips, doctor paragraph, plan for next week
                        │  PUT  /Health/Reports/Wochenbericht-<date>.md/.html  (WebDAV)
                        └▶ POST /api/automation/report  → stored, shown as "AI report", notification
```

* **Import**: `Export.zip` is opened with `ZipArchive`/`zip://` and `apple_health_export/Export.xml` (often 0.5–1 GB) is
  read with `XMLReader`, one element at a time. Unused record types are skipped after reading one attribute.
  `export_cda.xml`, workout routes and clinical records are ignored. Typical numbers for a 800 MB export with ~1.9 million
  records: ~15 s, ~40 MB peak memory.
* **Daily rows** per user: steps, distance, cycling distance, floors, active energy, heart rate min/avg/max, resting heart
  rate, SpO₂ avg/min, HRV (if present), VO₂max, weight, BMI, body fat, sleep total/deep/REM/core/awake, sleep start/end.
* **Several sources**: each metric is kept per source and per day; the most preferred source with data wins
  (current Huawei account > older Huawei account > other watches > other apps > test sources > phone). Steps of the watch
  and of the phone are therefore never added up; the phone is used only on days without watch data.
* **Sleep**: a segment belongs to the night that ends on the wake-up day (segment start + 6 h, local time). Overlapping
  segments are merged, only one source per night is used, nights under 30 minutes are ignored.
* **Idempotent**: a re-import replaces the days covered by the export and the workouts from its first workout on. Calendar
  events have stable IDs (source + start) and unchanged events are not rewritten.
* After a successful import the zip is moved to `/Health/Import/done/` (only the latest one is kept).

### Scores (shown in the app when tapping a ring)

| Ring | Formula |
|---|---|
| Sleep | 60 % duration (target 7.5 h) + 25 % deep-sleep share (target 15 %) + 15 % REM share (target 20 %); shares scaled by night length; > 60 min awake −10 |
| Recovery | 70 % resting heart rate vs. own 28-day median (−10 points per bpm above) + 30 % SpO₂ (92 % → 0, 97 % → 100); HRV only if the watch provides it |
| Movement | 60 % steps (target 8,000) + 40 % active energy (target 400 kcal), last complete day |

### Early hints (neutral, never diagnoses)

Resting HR ≥ baseline + 5 bpm on the last 2 measured days · night SpO₂ of the last 3 nights ≥ 1.5 points below the
baseline or below 93 % · deep-sleep share < 70 % of usual · average sleep < 6 h over ≥ 4 nights · steps < 50 % of usual ·
weight change ≥ 3 % in ~30 days. Data older than 8 days → banner + Nextcloud notification "please upload a new export".

### Agent

* Goals (sleep, steps, active energy, workouts per week, optional weight) with **adaptive weekly targets**
  (from the last 4 weeks, at most +10 % / +15 min / +1 workout per week, never above the goal), streaks.
* The weekly AI run writes a **plan for the next week** (train / easy / rest per day, bedtime). The next run receives a
  deterministic evaluation of that plan (workouts and sleep start from the data) and comments on it.
* **Nudges** after each import (no AI): "rest day recommended" when the resting HR is elevated or recovery < 50, streak
  notifications every 7 days. Each at most once per data day.
* **Ask your data**: questions are answered by the configured model from monthly/weekly/yearly aggregates and the last
  4 weekly summaries (never single records). 10 questions per hour, 40 per day per user.

## iPhone: weekly export

1. Open **Health** → tap the profile picture (top right).
2. **Export All Health Data** → **Export** (1–3 minutes).
3. Share sheet → **Save to Files** → Nextcloud → `Health/Import` (or upload it in the app under *Data*).
4. Done – the import runs within 15 minutes, a notification tells you the result.

Home-screen app: open `https://<cloud>/apps/healthcheck/` in Safari → **Share → Add to Home Screen**. The home-screen app
has its own storage, so sign in to Nextcloud there once.

## Installation

```bash
bin/pack.sh                                  # tests + dist/healthcheck-<version>.tar.gz
tar xzf dist/healthcheck-*.tar.gz -C /var/www/html/custom_apps/
occ app:enable healthcheck
```

`bin/deploy.sh user@host [container]` does the same for a Nextcloud AIO container over ssh.

### Chat endpoint (optional, admin)

```bash
occ config:app:set healthcheck ai_url   --value=https://<openai-compatible>/v1/chat/completions
occ config:app:set healthcheck ai_model --value=<model>
occ config:app:set healthcheck ai_api_key --sensitive --value='Bearer <key>'
```

## n8n setup

1. In the app: *Data → Settings* → set the webhook URL (`https://<n8n>/webhook/<path>`) and press **New secret**.
2. In n8n create two credentials: *HTTP Basic Auth* with the Nextcloud user and an **app password** of that user
   (`occ user:auth-tokens:add --name="n8n Health Check" <user> </dev/null`), and *HTTP Header Auth*
   (`Authorization: Bearer <key>`) for the AI endpoint.
3. Fill the placeholders in `n8n/healthcheck-weekly.json` (`__NC_URL__`, `__HC_USER__`, `__HC_HOOK__`, `__HC_SECRET__`,
   `__HC_CRED_ID__`, `__AI_CRED_ID__`, `__AI_URL__`, `__AI_MODEL__`, `__WF_ID__`; `n8n/build_workflow.py` regenerates
   the template), then `n8n import:workflow` and `n8n publish:workflow`.
4. Webhook signature: `X-HC-Signature: sha256=HMAC_SHA256(secret, "<ts>.<event>.<user>.<nonce>.<payload>")`, checked in
   n8n with the Crypto node, max. 5 minutes old.

The workflow runs every Monday at 07:00 (Europe/Berlin) and after every import. Successful executions are not stored in n8n
(only failed ones), so no health aggregates stay in the n8n database.

## API (logged-in user or app password of the same user)

| Method | Path | |
|---|---|---|
| GET | `/api/overview` | rings, hints, latest days, week vs baseline, progress, AI report, settings |
| GET | `/api/series?from&to` | daily rows and workouts |
| GET | `/api/report?days=30\|90` | doctor report data |
| GET | `/api/infographic?period=week\|month\|year` | infographic data |
| POST | `/api/import` | import now |
| GET/PUT | `/api/settings`, POST `/api/settings/secret` | settings, new webhook secret |
| POST | `/api/calendar/sync`, `/api/calendar/remove` | calendar |
| GET/POST | `/api/chat` | ask your data |
| POST | `/api/ai/request` | trigger the automation now |
| DELETE | `/api/data` | delete all own data (and the calendar) |
| GET | `/api/automation/summary` | aggregates for the automation |
| POST | `/api/automation/report` | result of the automation |

## Privacy

* Every query is filtered by the logged-in user; there is no admin view of other users' data.
* The AI only receives aggregates; the chat is rate limited; answers must quote numbers from the context.
* The calendar "Sport & Gesundheit" is a personal calendar of the user and not shared.
* *Delete all my data* removes days, workouts, imports, AI reports, chats and the calendar. Files in `/Health` stay.
* Retention is unlimited by default. Deleting a Nextcloud user deletes their data.

## Tests

```bash
php tests/run.php            # parser (synthetic fixture), aggregation, scores, hints, agent, infographic, chat, ICS
node tests/frontend/run.js   # i18n completeness, formatting, aggregation, service worker, no innerHTML
```

The fixture `tests/fixtures/export_small.xml` is synthetic. Never commit a real export.

## License

AGPL-3.0-or-later
