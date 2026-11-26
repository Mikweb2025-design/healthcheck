# Daily push from the iPhone (Apple Shortcuts)

A web app cannot read HealthKit, so the daily push uses Apple Shortcuts, which ships with iOS and costs nothing.

## Set up once

1. In the app open **Data → Automatic push** and press **Create token**. Copy it: it is shown only once
   (the server stores only its SHA-256 hash). You can rotate or revoke it at any time.
2. Tap **Install shortcut** (or download `shortcuts/Health-Check-Invio.shortcut` from this repository). On import it asks
   for two things: the address of your Nextcloud and the token.
3. Run the shortcut once by hand and allow access to Health.
4. Shortcuts → **Automation** → **+** → **Time of Day** → 07:00, **Daily** → **Run Immediately** → choose the shortcut.
5. Repeat with **Time of Day** 23:00, so the full day and the night arrive.

iOS does not release Health data while the phone is locked: a run with the phone locked may find nothing. The next run
(or a manual tap) catches up, duplicates are recognised.

## What is sent

The last 48 hours of steps, active calories, walking + running distance and floors, plus sleep analysis (all stages) and
heart rate (3 days) and resting heart rate (7 days), as JSON to `POST /apps/healthcheck/api/ingest` with the header `X-HC-Token`.

Find Health Samples stops the whole shortcut with an alert ("No measurements found") when a type has no sample in the
period. So the shortcut sends **each type right after reading it**, most reliable types first: a missing type never blocks
the ones before it. Sparse types (blood oxygen, weight, body fat, VO₂max, HRV, cycling, workouts) are not read by the
shortcut; they come from the weekly export, and the weight also from the manual weigh-in in the app.

The server accepts the formats Shortcuts really produces, including localized dates and sleep stage names
(English, German, Italian) and decimal commas. Repeated pushes are idempotent, and the weekly export fills gaps and
corrects values. Manual weigh-ins are never overwritten.

Payload: flat JSON with `<type>_values|_starts|_ends|_units|_sources` (newline separated), or
`samples: [{type, value, unit, start, end, source}]`.

## Build it by hand

The app contains a step-by-step guide (**Data → Automatic push**) with the exact actions, in case the signed file does
not import on your iOS version. To regenerate and sign the file on a Mac: `python3 shortcuts/build_shortcut.py`.
