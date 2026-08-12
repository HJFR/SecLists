# Simple Site Analytics

A privacy-friendly, self-hosted analytics plugin for WordPress and WooCommerce.
All data stays in your own database — no external service, no cookies, no
consent banner needed for tracking itself.

## What it answers

- **Most viewed articles and pages** — views, unique visitors and average
  reading time per article.
- **Most viewed WooCommerce products** — same metrics, shown automatically when
  WooCommerce (or any `product` post type) is active.
- **Average time spent reading** — "engaged" time only: the timer runs while the
  tab is actually visible, so a tab left open in the background doesn't inflate
  numbers.
- **Referrers** — which external sites and search engines send you traffic.
- **Where visitors go** — top outbound destinations (external links clicked) and
  the most common internal navigation paths (page A → page B).

## Installation

1. Copy this folder to `wp-content/plugins/simple-site-analytics/` (or zip it
   and upload via *Plugins → Add New → Upload Plugin*).
2. Activate **Simple Site Analytics** in *Plugins*.
3. Open the new **Analytics** menu in wp-admin. Data starts collecting
   immediately; choose the last 7 / 30 / 90 days at the top of the page.

## How it works

A small (~2 KB, dependency-free) script runs on the public site and posts to
three REST endpoints (`/wp-json/ssa/v1/view`, `/duration`, `/event`):

- **Pageview** — one per page load, with the referrer. Works with full-page
  caching, since tracking happens in the browser rather than in PHP.
- **Engaged time** — accumulated while the tab is visible and flushed with
  `navigator.sendBeacon` when the visitor leaves (plus a periodic save every
  15 s). The server keeps the maximum reported value per view.
- **Outbound clicks** — a click on any link leaving your domain records the
  destination.

Two custom tables store the data: `wp_ssa_views` and `wp_ssa_events`.

### Who is *not* counted

- Logged-in users who can edit posts (admins, editors, authors).
- Known bots/crawlers (user-agent heuristic) and headless browsers.
- Previews and the customizer.

## Privacy

- **No cookies and no localStorage** — nothing is stored in the browser.
- Unique visitors are estimated from a salted hash of IP + user agent. The salt
  is an HMAC of the current date with a per-site secret, so hashes rotate daily
  and the raw IP address is never written to the database.
- Data never leaves your server. Deleting the plugin from the Plugins screen
  drops both tables and all options.

## Limitations (by design, to stay simple)

- Unique visitors are per-day approximations; returning visitors across days
  are not linked (that's what makes it cookie-less).
- Engaged time needs JavaScript; visitors with JS disabled are not counted.
- The collection endpoints are open (like any analytics beacon); junk data is
  limited by bot filtering, UUID validation, capped durations, and events being
  accepted only for recorded pageviews.

## Development

Plain PHP + vanilla JS, no build step. Code follows WordPress coding-standard
conventions; lint with `php -l` or `phpcs --standard=WordPress`.

## License

GPL-2.0-or-later.
