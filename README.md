# PHP Uptime Monitor

A dependency-free PHP uptime monitor for your websites and client sites. Checks each URL, alerts you **only when status changes** (no alert spam), and keeps a check log. Runs from cron every few minutes.

## Why

UptimeRobot-style services are fine, but this lives on your own VPS, checks unlimited sites for free, and can verify page *content* (keyword match) — catching the "server is up but WordPress shows a white screen" case that plain ping monitors miss.

## Setup

1. Copy the example config and edit it:

   ```bash
   cp config.json.example config.json
   ```

2. Set your sites, alert email, and optional Slack/Discord webhook URL in `config.json`. The `keyword` field is optional — when set, the site only counts as UP if the response body contains that text.

3. Run from cron (every 5 minutes):

   ```bash
   */5 * * * * /usr/bin/php /opt/uptime-monitor/monitor.php >> /dev/null 2>&1
   ```

## How it works

- Each site is fetched with a timeout (default 15s). UP = HTTP 2xx/3xx **and** the keyword (if set) is present in the body.
- State is stored in `state.json`. Alerts fire only on transitions: UP → DOWN and DOWN → UP (recovery).
- Every check is appended to `monitor.log` with status, HTTP code, and response time in ms.
- First run records the baseline silently — no false "DOWN" alert on setup.

## Config reference

| Key | What it does |
|---|---|
| `sites[].name` | Friendly label used in alerts |
| `sites[].url` | Full URL to check |
| `sites[].keyword` | Optional text that must appear in the page (e.g. `wp-content`) |
| `check_timeout` | Seconds before a check counts as failed |
| `alert_email` | Where DOWN/RECOVERED emails go (uses PHP `mail()`) |
| `slack_webhook` | Incoming webhook URL — works with Slack and Discord |
| `state_file` / `log_file` | File names for state and log (relative to the script dir) |

## Notes

- Email alerts need a working MTA on the server (`sendmail`/Postfix). If in doubt, use the webhook channel.
- To stop monitoring a site, remove it from `config.json` (its old state entry is simply ignored).

MIT licensed.
