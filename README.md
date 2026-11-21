# WP Visitor Stats

Privacy-friendly visitor statistics for WordPress — stored in your own database, no cookies, no external service, no raw IP addresses.

## What it tracks

- **Views** per page per day
- **Unique visitors** per page per day, counted from a one-way SHA-256 hash of (date | path | IP | user-agent). The raw IP is never stored, and the hashes are deleted by the daily cleanup once they pass the retention window.

## What it skips

- Logged-in users (toggleable), bots (common bot user-agents), feeds, admin pages, AJAX, cron, trackbacks
- Any URL containing one of your excluded patterns (defaults: `/wp-admin/`, `/wp-login.php`, `/wp-cron.php`)

## Viewing stats

**Tools → Visitor Stats** — totals, a daily-views bar chart and a top-20 pages table for the last 7, 30 or 90 days.

## Settings

**Settings → Visitor Stats**

- **Keep data for (days)** — 7–365 (default 90). Older rows and hashes are pruned by a daily WP-Cron job.
- **Skip logged-in users** — your own visits are not counted.
- **Excluded URL patterns** — one substring per line.

## Filters

- `wvs_client_ip` — override the IP used in the uniqueness hash (e.g. trust `X-Forwarded-For` behind a proxy you control).

## License

MIT — see [LICENSE](LICENSE).
