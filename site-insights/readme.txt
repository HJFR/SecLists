=== Site Insights ===
Contributors: hugorodrigues
Tags: analytics, statistics, woocommerce, privacy, stats
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-friendly, self-hosted analytics for WordPress and WooCommerce. No external service, no cookies for tracking, data stays in your database.

== Description ==

Site Insights answers the questions you would normally open Google Analytics for, directly inside wp-admin:

* **Most viewed articles and pages** — with views, unique visitors and average read time per article.
* **Most viewed WooCommerce products** — automatically detected when WooCommerce is active.
* **Average time on page** — real engaged time, counted only while the browser tab is visible.
* **Referrers** — which sites send you traffic, grouped by channel (search / social / direct / other).
* **Where visitors go** — the next internal page they navigate to, and the external domains they leave through.

Privacy by design:

* All data is stored in your own database; nothing is sent to third parties.
* Visitors are counted with a hash that rotates daily, so they cannot be tracked across days.
* No tracking cookies (a per-tab session id lives in sessionStorage only).
* Optional "Do Not Track" support and configurable data retention with automatic cleanup.
* Bots, crawlers and (optionally) logged-in users are excluded.

== Installation ==

1. Upload the `site-insights` folder to `/wp-content/plugins/`.
2. Activate the plugin through the *Plugins* screen.
3. Statistics appear under the new *Insights* menu; options under *Insights → Settings*.

== Frequently Asked Questions ==

= Does it slow down my site? =

The tracker is a ~3 KB vanilla JavaScript file loaded in the footer. Reporting queries run only inside wp-admin.

= Do I need a cookie banner for this plugin? =

Site Insights sets no cookies and stores no personal data in the browser beyond a random per-tab id. Consult your own legal advice, but it is designed along the same lines as privacy-first tools like Plausible or Fathom.

== Changelog ==

= 1.0.0 =
* Initial release: pageview tracking, engaged time, scroll depth, referrer classification, exit tracking, WooCommerce product stats, dashboard, settings, retention cleanup.
