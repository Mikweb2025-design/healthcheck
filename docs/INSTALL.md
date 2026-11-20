# Installation and administration

## Requirements

- Nextcloud 30 or newer (tested on Nextcloud 35, also in the All-in-One container)
- PHP 8.1+ with the `zip` and `xmlreader` extensions (standard in Nextcloud images)
- Background jobs via cron (recommended) so imports run every 15 minutes
- Optional: an n8n instance for the weekly AI report, an OpenAI-compatible endpoint for the AI features

## Install from a release

```bash
cd /var/www/html/custom_apps            # or your apps path
tar xzf healthcheck-<version>.tar.gz
chown -R www-data:www-data healthcheck
sudo -u www-data php occ app:enable healthcheck
```

For Nextcloud All-in-One, copy the archive into the `nextcloud-aio-nextcloud` container first, or use
`bin/deploy.sh user@host [container]` from a checkout. After an update run `occ upgrade` if Nextcloud asks for it
(the deploy script does this automatically).

## Build from source

```bash
bin/pack.sh     # runs the PHP and JS tests, then writes dist/healthcheck-<version>.tar.gz and .zip
```

## First steps for each user

1. Create the folder `Health/Import` (the app also offers an upload button under **Data**).
2. Export from the iPhone: **Health → profile picture → Export All Health Data → Save to Files → Nextcloud →
   `Health/Import`**. The export contains the full history.
3. Open the app. The import runs within 15 minutes (or press **Import now**). Afterwards the zip is moved to
   `Health/Import/done/` and only the latest one is kept.
4. Optional: install the web app on the home screen (Safari → Share → Add to Home Screen). The home-screen app has its
   own storage, so sign in once more there.

## Administration settings

Settings → Administration → **Health Check** (en/de/it). Everything below can also be set there; no `occ` needed.

| Setting | What it does |
| --- | --- |
| Access → groups | Limit the app to groups. Nothing ticked = every user. (Nextcloud's own "enable for specific groups" in Apps works as well.) |
| AI | Master switch, provider preset, endpoint, model, API key (write-only, stored as a sensitive value), **Test connection** |
| AI features | Separate switches for the chat and for the check-up / explanations / doctor questions |
| Limits | Requests per user per hour and day, maximum answer length, timeout |
| Weekly report | **An automation** (the user's n8n webhook, see below) or **this server** with the AI above, Monday morning in the user's time zone |
| Integrations | Allow the daily phone push; allow a user webhook |
| Defaults | App language when the Nextcloud language is not supported; doctor report language |
| Usage | Number of users and AI requests in the last 24 h (counts only) |

Only aggregates (daily, weekly, monthly statistics) are sent to the AI, never single records. Choose a provider that
matches your privacy requirements (for example one hosted in your jurisdiction, or a local model such as Ollama).
A local endpoint needs `'allow_local_remote_servers' => true` in `config.php`.

Equivalent `occ` commands (the admin page is easier):

```bash
occ config:app:set healthcheck ai_url   --value=https://<provider>/v1/chat/completions
occ config:app:set healthcheck ai_model --value=<model>
occ config:app:set healthcheck ai_api_key --sensitive --value='Bearer <key>'
```

## Weekly AI report with n8n

See the "n8n setup" section of the [README](../README.md#n8n-setup). The workflow template
`n8n/healthcheck-weekly.json` contains placeholders only.

## Daily push from the iPhone

See [SHORTCUT.md](SHORTCUT.md).

## Uninstall / delete data

Each user can press **Delete all my data** (days, workouts, imports, AI reports, chats and the calendar; files in
`/Health` stay). Disabling and removing the app keeps the database tables until you remove them; deleting a Nextcloud
user deletes their data.
