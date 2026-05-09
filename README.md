# Adaptive Customer Engagement

Adaptive Customer Engagement is a native WordPress plugin I am building to track first-party lead signals, route phone numbers by source and page context, and give me a practical admin view of how visits turn into conversations.

## What is in this first release

- WordPress plugin scaffold with activation, deactivation, uninstall, capabilities, and custom schema management
- Custom tables for sessions, events, companies, phone numbers, calls, and enrichment cache
- Public REST tracking endpoint for pageviews, click-to-call events, and downloads
- Optional native form submission tracking without storing field values
- Public phone-number resolution endpoint for frontend placeholders
- React-based wp-admin screens for dashboard, sessions, session detail timelines, phone numbers, settings, privacy, enrichment, Amazon Connect, and AI placeholders
- Frontend tracker script for session cookies, pageview capture, number replacement, and call/download event capture
- Privacy-aware defaults with hashed IP support, raw IP expiry, basic bot filtering, and a manual privacy purge action

## What is intentionally deferred

This repository does **not** yet implement:

- live IP enrichment providers
- Amazon Connect sync, imports, or matching
- AI chat, handoff, or site tools
- CRM exports or CSV workflows

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
- `GET /admin/numbers`
- `POST /admin/numbers`
- `PATCH /admin/numbers/{id}`
- `DELETE /admin/numbers/{id}`
- `GET /admin/settings`
- `POST /admin/settings`
- `POST /admin/privacy/purge`

## Data model

The plugin creates these tables with the WordPress table prefix:

- `ace_sessions`
- `ace_events`
- `ace_companies`
- `ace_numbers`
- `ace_calls`
- `ace_enrichment_cache`

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

1. real enrichment providers and company scoring
2. stronger admin reporting and exports
3. Amazon Connect import, matching, and number sync
4. AI-assisted lead capture and safe content tools

## Author

Built by Shane Rounce of [AceMedia.ninja](https://acemedia.ninja/).
