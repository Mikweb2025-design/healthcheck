# Daily push from the iPhone (Apple Shortcuts)

A web app cannot read HealthKit, so the daily push uses Apple Shortcuts, which ships with iOS and costs nothing.

## Set up once

1. In the app open **Data → Automatic push** and press **Create token**. Copy it: it is shown only once
   (the server stores only its SHA-256 hash). You can rotate or revoke it at any time.
2. Tap **Install shortcut** (or download `shortcuts/Health-Check-Invio.shortcut` from this repository). On import it asks
   for two things: the address of your Nextcloud and the token.
3. Run the shortcut once by hand and allow access to Health.
4. Shortcuts → **Automation** → **+** → **Alarm** → *When my alarm is stopped* → **Run Immediately** → choose the
   shortcut.

iOS does not release Health data while the phone is locked. The alarm trigger works well because the phone is usually
unlocked right after. If a run fails, add a second automation when you open an app you use every morning.

## What is sent

The last 48 hours of steps, active calories, walking + running distance, floors, heart rate, sleep analysis (all stages) and
resting heart rate, as JSON to `POST /apps/healthcheck/api/ingest` with the header `X-HC-Token`.

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
