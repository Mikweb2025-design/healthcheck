# Changelog

## 0.4.5

- Warning on the overview when the phone pushed with a wrong or old token (rejected with 401) after the last good push.

## 0.4.4

- Warning on the overview when the shortcut stopped before heart rate (Apple Health had no heart rate for the last 2 days, so heart rate, sleep and resting heart rate were not sent).
- Shortcut: sleep and resting heart rate are read before heart rate, with a wider window (3/7/3 days), so a late watch sync no longer blocks them.
- Setup guide: automations at 07:00 and 23:00 (Time of Day) instead of the alarm.

## 0.4.3

- "Latest data" uses the newest day stored (it showed the day of the last request of a push, one day behind).

## 0.4.2

- Charts and cards reload when you return to the app (after 2 minutes), so a push or import shows up without closing the app.

## 0.4.1

- Data tab: "Latest data" now shows the newest day from the zip export *or* the daily push (it showed only the export).
- Daily push: a run (one request per type) is counted as one, with the number of samples per type (steps, kcal, HR, sleep…).

## 0.4.0 – 2026-11-20

* **Tips for this week** on "Today": up to three plain tips computed from your own data, without AI (rule-based,
  neutral, no diagnoses): enough sleep versus your goal, steadiness of bedtime, weekend shift ("social jet lag"),
  steps versus goal, and load versus recovery (a heavy week with a resting heart rate above your normal suggests an easy
  day). Together with three numbers: sleep rhythm (±min), weekend shift and load.

## 0.3.1 – 2026-11-20

* Shortcut no longer stops on an empty type ("No measurements found … Blood Oxygen"): blood oxygen is not read any more
  (it comes with the weekly export) and every type is POSTed right after it was read, most reliable types first, so an
  empty type can never block the data before it. A notification confirms a complete run.

## 0.3.0 – 2026-11-20

* **Daily check-in**: one tap for energy (1–5) with an optional short note on "Today"; last 14 days as bars, 7-day
  average; after about ten check-ins with sleep data a neutral line shows whether your energy differs after nights with
  at least 7 h of sleep (a pattern in your own data, never a diagnosis). Stored per user, deleted with "Delete all my data"
  and with the account.
* **Take my data with me**: download everything the app stored (days, workouts, weight, check-ins, AI reports) as JSON, or
  the daily table as CSV, from Data
* New app icons: three rings around a heart with a pulse line; sharper navigation icon (also for the dashboard widget)

## 0.2.2 – 2026-11-20

* OpenCode as a provider through a small bridge (`bridge/`): an OpenAI-compatible service on the Docker host that runs
  `opencode run`, so the OpenCode models that only answer inside OpenCode (free Zen models) can be used from the
  Nextcloud container. Bearer token, listens only on the Docker gateway, only `opencode/*` models, every call runs in an
  empty directory with a config that denies edit, bash and web fetch. Replaces the direct OpenCode Zen preset of 0.2.1.

## 0.2.1 – 2026-11-20

* Claude (Anthropic API) as an AI provider in the administration page, using the native Messages API
  (`x-api-key`, system prompt as its own field, no `temperature`, which newer Claude models reject). It needs an API key
  from the Claude Console: a Claude.ai / Claude Code subscription login cannot be used by other applications.
  Any other endpoint keeps the OpenAI-compatible format.
* OpenCode Zen as a provider preset (OpenAI-compatible, needs a Zen API key; the free models only answer inside
  OpenCode itself, so they cannot be used here)

## 0.2.0 – 2026-11-20

* **Administration settings** (Settings → Administration → Health Check), also in German and Italian:
  AI on/off, provider presets (IONOS, OpenAI, Mistral, OpenRouter, Ollama, custom OpenAI-compatible endpoint), model,
  write-only API key stored as a sensitive value, "Test connection", separate switches for the chat and for the
  check-up / explanations / doctor questions, requests per user per hour and day, maximum answer length and timeout
* Who writes the weekly report: an automation (n8n, as before) or this server with the configured AI (Monday morning,
  user time, no automation needed)
* Access: limit the app to groups (empty = every user); switches for the phone push and the user webhook; defaults for
  the app language (when the Nextcloud language is not supported) and the doctor report language; usage counters
  (users, AI requests in 24 h; counts only, never health data)
* User interface hides the AI buttons, the webhook and the push card when the administrator switched them off
* Ollama and other keyless endpoints work (no Authorization header without a key)
* Packaging now includes `templates/`

## 0.1.5 – 2026-11-20

* Weekly n8n report: goal comparisons are computed in code (`goal_check` in `/api/automation/summary` with verdicts
  met / close / below / above and, for a target weight, over/under target) and put into the prompt as ready-made status
  text in the report language; the raw goals are no longer passed to the model
* The prompt forbids comparing values with goals and mentioning technical names; correlation names and hint texts are
  translated in the workflow (de/it/en) before they reach the model

## 0.1.4 – 2026-11-20
- AI check-up: goal comparisons are now computed in code (`goal_check`: goal, period value, verdict met/close/below).
  The model no longer receives raw goals (such as `sleep_min: 450`), so it cannot compare them wrongly or mention field names.
- Shortcut: Health types use the iOS picker format; only daily types are sent (an empty type stops Find Health Samples).
- Public repository, English docs, screenshots from a synthetic demo dataset.

## 0.1.3 – 2026-11-20

* Daily push from the iPhone via Apple Shortcuts: `POST /api/ingest` with a personal ingest token (`X-HC-Token` or
  Bearer; only its sha256 is stored; create / rotate / revoke in Data → Settings, shown once), public endpoint with brute
  force protection, 60 requests per hour, 3 MB limit, samples at most 30 days old
* Tolerant parser for what Shortcuts produces: flat newline-separated columns, sample lists or `;` lines; localized
  type names and sleep stages (it/de/en), ISO and localized dates, decimal commas and thousands dots, units glued to values
* Raw samples are stored deduplicated (idempotent) and the touched days are re-aggregated with the same aggregator as
  the zip import; days from a zip are only completed, days from the phone are replaced; zip imports still correct
  everything; manual weigh-ins still win; workouts are added unless one starts within 2 minutes
* Signed shortcut "Health Check – Invio" (generated by `shortcuts/build_shortcut.py`, signed with `shortcuts sign
  --mode anyone`), downloadable in the app; step-by-step manual build and the morning automation in the app (it/de/en)
* Data status shows the last automatic push; the "data too old" reminder uses the newest day of both channels

## 0.1.2 – 2026-11-20

* Weight as a first-class metric: card on "Today" (current value, change 7/30/90 days, BMI with WHO category, body fat,
  rate per week, ETA to the target weight, chart with 7-day moving average), in trends, agent, doctor report, infographic
  and weekly n8n report
* Manual weigh-ins ("weight today", optional body fat), stored separately, never overwritten by imports, deletable;
  weigh-in reminder after 7 days when a target weight is set
* Import ignores implausible scale values (≤ 20 kg, > 300 kg, BMI outside 10–80), converts lb/g/st
* Charts: smooth lines with 7-day moving average, personal normal range (mean ± 1 SD) band, anomaly marks, workout
  markers, tap/hover tooltips with exact values, sleep window chart (bedtime → wake-up with stage shares), weekday bars,
  nicer empty states, narrower drawing canvas on phones (verified at 375 px)
* AI functions (aggregates only, rate limited, stored): check-up for 30/90/365 days (good / watch / 3 actions / when
  to see a doctor), "Why?" explanation for each early hint, questions for the doctor in the report language
* Correlations computed in code (Pearson r with n, minimum 20 pairs; weekly workout minutes vs weight change; weekday
  means); the AI only narrates them
* Weekly n8n report: weight section and the computed correlations of the last 90 days
* Deploy script runs the Nextcloud update step right after replacing the app

## 0.1.1 – 2026-11-20

* Own language for the doctor report (`report_lang`, setting next to the app language); language switch IT/DE/EN on the
  report page for printing
* Weekly n8n report: summary, tips and plan in the app language, doctor paragraph in the doctor report language; extra
  files `Arztbericht-YYYY-WW.<lang>.md/.html`; durations always as "h min" (formatted before the prompt, safety net after)
* Calendar titles follow the app language: events whose text changed are rewritten (same UIDs, no duplicates)

## 0.1.0 – 2026-11-19

First version.

* Streaming import of Apple Health exports (Export.zip / Export.xml) with XMLReader, background job every 15 minutes,
  "import now" and upload in the app, archive of the last zip, import log with duration and peak memory
* Daily rows with source preference (watch over phone), sleep stages per wake-up day, workouts with deduplication
* Rings (sleep, recovery, movement) with transparent formulas, early hints against the 28-day baseline, data status with
  reminder after 8 days
* Trends (7/30/90/365 days, all), doctor report (30/90 days, print/PDF), infographic (week/month/year, heatmap, records,
  streaks, consistency, PNG export)
* Agent: goals, adaptive weekly targets, streaks, weekly plan from the AI with evaluation, rule based nudges
* Ask your data (aggregates only, rate limited)
* n8n workflow "Health Check – Wochenbericht" (weekly and after import, HMAC-signed webhook), report files in /Health/Reports
* Personal calendar "Sport & Gesundheit" with one event per workout, optional daily summary events
* Dashboard widget, notifications, PWA (manifest, icons, service worker for static files only), de/en/it
