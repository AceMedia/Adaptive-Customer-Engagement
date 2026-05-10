=== Adaptive Customer Engagement ===
Contributors: shanerounce
Tags: lead tracking, analytics, attribution, phone tracking, b2b
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adaptive Customer Engagement provides a privacy-conscious, first-party way to track lead signals, session journeys, and phone number routing inside WordPress.

== Description ==

This first release includes:

* custom database tables for sessions, events, companies, phone numbers, calls, enrichment cache, and saved reporting segments
* a public tracking endpoint for pageviews, click-to-call, download, and optional native form-submission events
* a public number-resolution endpoint for frontend placeholders
* a React-powered wp-admin app with dashboard, sessions, companies, WooCommerce interest, calls, phone numbers, tracking, privacy, enrichment, Amazon Connect, and AI screens
* rich single-record drilldowns for sessions, companies, calls, and numbers, with linked related data and charts
* live enrichment provider support for ipregistry and ipinfo, with cached lookups, company linking, and an admin test lookup tool
* saved reporting segments and CSV exports across sessions, companies, calls, and WooCommerce reporting
* WooCommerce repeat-interest reporting for products, categories, sessions, and companies
* setup-focused admin pages for tracking, privacy, enrichment, Amazon Connect, AI, and phone-number management
* Amazon Connect setup fields for instance details, S3 export location, flow log group, credentials mode, contact flow IDs, hosted widget values, and test-chat setup
* live Amazon Connect phone-number visibility in the setup UI, plus search and claim actions for available numbers
* Amazon Connect call import from S3 exports, plus stored-call matching and import status reporting
* Amazon Q in Connect assistant visibility and site-assistant creation in the admin UI
* sample/demo numbers kept visible for admin preview work without taking over live number resolution
* sample-data seeding for realistic recent local demo activity
* privacy-aware defaults with hashed IP support, raw IP expiry, admin exclusion, and bot/private-IP filtering

This release is already geared around live first-party tracking, WooCommerce interest reporting, and Amazon Connect operations. Broader CRM export workflows, deeper outbound automation, and fuller order-level revenue attribution remain later phases.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it from source.
2. Run `npm install` and `npm run build` if you are installing directly from the repository source.
3. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= Does this plugin send data to third-party marketing platforms? =

No. The current release is built around first-party tracking and internal data storage.

= Does this plugin already include Amazon Connect integration? =

Yes. The plugin already includes Amazon Connect setup, number visibility and claiming, contact flow visibility and creation, Amazon Q in Connect assistant visibility and creation, S3 call import, stored call matching, and an admin-only frontend test-chat path for the hosted Connect widget.

= Does the frontend chat widget support secured hosted widgets? =

Yes. The plugin can now store the hosted widget values, parse the pasted widget snippet, and issue JWT tokens server-side when the Connect widget is configured with a security key.

= Does this support WooCommerce reporting already? =

Yes. It tracks product and category interest and surfaces repeat-interest reporting across products, categories, sessions, and companies.

== Changelog ==

= 0.1.0 =

* Initial public release with tracking, enrichment, calls, numbers, WooCommerce interest reporting, saved segments, exports, setup screens, sample data, and privacy foundations.
