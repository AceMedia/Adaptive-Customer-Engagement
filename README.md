# Adaptive Customer Engagement

Adaptive Customer Engagement is a native WordPress plugin I am building to track first-party lead signals, route phone numbers by source and page context, and give me a practical admin view of how visits turn into conversations.

## What is in this first release

- WordPress plugin scaffold with activation, deactivation, uninstall, capabilities, and custom schema management
- Custom tables for sessions, events, companies, phone numbers, calls, and enrichment cache
- Public REST tracking endpoint for pageviews, click-to-call events, and downloads
- Optional native form submission tracking without storing field values
- Public phone-number resolution endpoint for frontend placeholders
- React-based wp-admin screens for dashboard, sessions, companies, WooCommerce interest, calls, phone numbers, settings, privacy, enrichment, Amazon Connect, and AI placeholders
- Live enrichment provider support for **ipregistry** and **ipinfo**, with cached lookups, company linking, and an admin test lookup tool
- Working company reporting screens and dashboard hot-company summaries for enriched traffic
- Paginated session and company filters for confidence, source/provider, search terms, and date range
- Saved reporting segments for reusing common session, company, calls, and WooCommerce filters
- Dashboard shortcuts into saved reporting segments across sessions, companies, calls, and WooCommerce views
- CSV exports for the current filtered session and company views
- Expanded lead and company priority scoring with visible breakdowns in the admin reporting views
- Calls screen with call-intent summaries, top call-driving pages, and stored-call reporting foundations
- Calls screen filters and CSV exports for stored-call investigation and reuse outside WordPress
- WooCommerce-aware tracking for product and category pages, including repeat-interest counts carried in event metadata
- Setup-focused admin pages for tracking, privacy, enrichment, Amazon Connect, and AI configuration, with guidance links for getting the keys and identifiers needed later
- Refreshed WordPress-style admin shell with branded header treatment, clearer page introductions, and a side navigation layer across the plugin sub-pages
- Local sample-data seeding controls for generating roughly three months of UK business and council activity inside the plugin tables for UI preview work
- Frontend tracker script for session cookies, pageview capture, number replacement, and call/download event capture
- Privacy-aware defaults with hashed IP support, raw IP expiry, basic bot filtering, and a manual privacy purge action

## What is intentionally deferred

This repository does **not** yet implement:

- Amazon Connect sync, imports, or matching
- AI chat, handoff, or site tools
- CRM exports or automated export workflows

The database shape and settings surface are ready for those next phases.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Node.js 18+ for asset builds
- npm 9+

## Local development

1. Install dependencies:

   ```bash
   npm install
   ```

2. Build the admin and frontend assets:

   ```bash
   npm run build
   ```

3. Copy or symlink the plugin into `wp-content/plugins/`.

4. Activate **Adaptive Customer Engagement** in wp-admin.

## Frontend placeholders

I can drop these into theme templates, blocks, or rendered content:

```html
<span data-ace-phone="default">Loading…</span>
<a href="#" data-ace-phone-link="default">Call us</a>
```

The frontend script resolves the most appropriate phone number for the current path and source context, then updates the text and `tel:` link.

## REST namespace

All current routes live under:

```text
adaptive-customer-engagement/v1
```

### Public routes

- `POST /track`
- `GET /number/resolve`

### Admin routes

- `GET /admin/dashboard`
- `GET /admin/sessions`
- `GET /admin/sessions/{id}`
- `GET /admin/companies`
- `GET /admin/companies/{id}`
- `GET /admin/commerce`
- `GET /admin/calls`
- `GET /admin/numbers`
- `POST /admin/numbers`
- `PATCH /admin/numbers/{id}`
- `DELETE /admin/numbers/{id}`
- `GET /admin/settings`
- `POST /admin/settings`
- `GET /admin/reporting-segments`
- `POST /admin/reporting-segments`
- `DELETE /admin/reporting-segments/{id}`
- `POST /admin/privacy/purge`
- `POST /admin/enrichment/test`
- `GET /admin/sample-data`
- `POST /admin/sample-data`
- `DELETE /admin/sample-data`

## Data model

The plugin creates these tables with the WordPress table prefix:

- `ace_sessions`
- `ace_events`
- `ace_companies`
- `ace_numbers`
- `ace_calls`
- `ace_enrichment_cache`

## Reporting downloads

The sessions and companies screens can now export the **current filtered view** as a CSV download, so I can pass the data into follow-up sales or reporting work without rebuilding it by hand elsewhere.

The calls screen can now export the **current filtered stored-call view** as a CSV too, which makes it much easier to analyse call outcomes or hand them into follow-up work.

## WooCommerce reporting

The WooCommerce screen surfaces **repeat product and category interest**, plus the sessions and companies showing those repeat patterns, while session and company detail views now summarise the strongest commerce interest signals attached to that record. I can now also filter that view by search/date and export the current products, categories, sessions, or companies into CSV files.

## Setup and connection guidance

The admin settings screens now cover the built-in tracking and privacy controls more fully, and the enrichment, Amazon Connect, and AI pages include setup guidance plus external links for where to get the relevant API keys, instance IDs, access keys, and provider docs before I start the live hookup work.

The **Enrichment** page is the place where I connect the implemented provider support today. That page already includes the provider selector, API key field, cache controls, bot/private-IP options, and a live test lookup tool for the supported providers.

## Sample data

The dashboard can now seed and remove a **local-only demo dataset**. This creates roughly three months of realistic-looking UK council and business activity across sessions, companies, events, WooCommerce interest, calls, and tracking numbers so I can inspect the reporting UI before switching on live integrations.

## Privacy approach

- first-party session and visitor IDs
- short-lived raw IP retention
- stable hashing for reporting and matching
- no browser fingerprinting
- referrer query strings stripped before storage
- logged-in admins ignored by default
- basic crawler and monitor filtering

## Current roadmap

The next sensible build steps are:

1. broaden reporting polish around calls, scoring, ranking, and follow-up workflows
2. deeper enrichment provider support and reporting polish
3. Amazon Connect import, matching, and number sync
4. AI-assisted lead capture and safe content tools

## Author

Built by Shane Rounce of [AceMedia.ninja](https://acemedia.ninja/).
