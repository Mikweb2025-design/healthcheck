# Changelog

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
