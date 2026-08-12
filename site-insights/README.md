# Site Insights — self-hosted analytics for WordPress & WooCommerce

A WordPress plugin that gives you Google-Analytics-style answers without Google Analytics:

| Question | Where to see it |
| --- | --- |
| What is my most viewed article? | **Insights → Most viewed articles & pages** |
| What is my most viewed WooCommerce product? | **Insights → Most viewed products** |
| How long do people spend reading? | **Avg. time on page** card + per-article **Avg. read time** |
| Where does my traffic come from? | **Traffic channels** + **Top referrers** |
| Where do visitors go afterwards? | **Where visitors go next** (internal) + **Outbound clicks** (external) |

All data stays in your own MySQL database. No external service, no tracking cookies.

## How it works

```
Browser                          WordPress
───────                          ─────────
tracker.js ── POST /view ──────► records pageview (post, path, referrer, hashed visitor)
   │              ◄── id + HMAC token
   ├─ counts engaged time (only while the tab is visible)
   ├─ tracks max scroll depth
   ├─ captures the link used to leave the page
   └─ sendBeacon POST /engage ─► updates engaged seconds / scroll / exit destination
```

- **Storage:** one custom table `wp_si_views`, created on activation with `dbDelta`.
- **Uniques:** visitors are identified by `md5(daily_salt + IP + user agent)`; the salt rotates every day, so visitors cannot be linked across days and no IP is ever stored.
- **Integrity:** engagement updates require an HMAC token issued when the view was recorded, so rows cannot be tampered with by third parties.
- **Bots** are filtered by user-agent, `navigator.webdriver` browsers are ignored.
- **Retention:** a daily WP-Cron job deletes rows older than the configured retention window (default 180 days).

## Installation

1. Copy the `site-insights/` folder into `wp-content/plugins/` of your WordPress site
   (or zip it — `zip -r site-insights.zip site-insights` — and upload it via
   *Plugins → Add New → Upload Plugin*).
2. Activate **Site Insights** on the Plugins screen.
3. Open the new **Insights** menu in wp-admin. Data starts accumulating from the first
   front-end visit after activation.
4. Review **Insights → Settings**: exclude logged-in users (on by default), honor
   Do Not Track (on by default), and data retention.

WooCommerce is optional — if it is active, products (`product` post type) automatically
get their own "Most viewed products" panel.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Pretty permalinks (for the REST API) — the default on most sites.

## File layout

```
site-insights/
├── site-insights.php            # bootstrap, settings defaults, hooks
├── uninstall.php                # drops table + options on plugin deletion
├── readme.txt                   # WordPress.org-style readme
├── includes/
│   ├── class-si-schema.php      # table creation, upgrades, retention cron
│   ├── class-si-tracker.php     # REST endpoints /view and /engage, script enqueue
│   ├── class-si-stats.php       # aggregation queries for the dashboard
│   └── class-si-admin.php       # dashboard + settings pages
└── assets/
    ├── js/tracker.js            # front-end tracker (vanilla JS, ~3 KB)
    └── css/admin.css            # dashboard styles
```

## Ideas for later versions

- Add-to-cart / purchase conversion tracking for WooCommerce.
- UTM campaign parameter breakdown.
- CSV export and email reports.
- Country breakdown via a local GeoIP database.
