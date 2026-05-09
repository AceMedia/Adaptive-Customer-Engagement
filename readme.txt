=== Adaptive Customer Engagement ===
Contributors: shanerounce
Tags: lead tracking, analytics, attribution, phone tracking, b2b
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adaptive Customer Engagement gives me a privacy-conscious, first-party way to track lead signals, session journeys, and phone number routing inside WordPress.

== Description ==

This first release includes:

* custom database tables for sessions, events, numbers, calls, companies, and enrichment cache
* a public tracking endpoint for pageviews, click-to-call, and download events
* a public number-resolution endpoint for frontend placeholders
* wp-admin screens for dashboard, sessions, phone numbers, settings, privacy, enrichment, Amazon Connect, and AI placeholders
* privacy-aware defaults with hashed IP support and raw IP expiry

This release intentionally leaves Amazon Connect, live enrichment providers, and AI tooling as later phases.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it from source.
2. Run `npm install` and `npm run build` if you are installing directly from the repository source.
3. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= Does this plugin send data to third-party marketing platforms? =

No. The current release is built around first-party tracking and internal data storage.

= Does this plugin already include Amazon Connect integration? =

Not yet. The settings and schema are ready for it, but the live integration is a later phase.

== Changelog ==

= 0.1.0 =

* Initial scaffold with tracking, number routing, admin views, settings, and privacy foundations.
