# Adaptive Customer Engagement

Adaptive Customer Engagement is a native WordPress plugin for tracking first-party lead signals, routing phone numbers by source and page context, and giving WordPress teams a practical operational view of how visits turn into conversations.

## What is in this first release

### Tracking and data foundation

- WordPress plugin scaffold with activation, deactivation, uninstall, capabilities, and custom schema management
- Custom tables for sessions, events, companies, phone numbers, calls, enrichment cache, and saved reporting segments
- Public REST tracking endpoint for pageviews, click-to-call events, downloads, and optional native form submissions without storing field values
- Frontend tracker script for session cookies, pageview capture, number replacement, and call/download event capture
- Public phone-number resolution endpoint for frontend placeholders
- Privacy-aware defaults with hashed IP support, raw IP expiry, basic bot filtering, admin-user exclusion, private/internal IP exclusion, and a manual privacy purge action

### Admin reporting and drilldowns

- React-based wp-admin app with dashboard, sessions, companies, WooCommerce interest, calls, numbers, tracking, privacy, enrichment, Amazon Connect, and AI screens
- Dashboard metrics for tracked sessions, engaged visits, call intent, commerce interest, and top pages
- Rich single-record drilldowns for sessions, companies, calls, and numbers, with charts, linked related data, and route-based takeover views
- Inline matched call context inside session and company detail views so call activity can be reviewed without leaving the record
- Expanded lead and company priority scoring with visible factor breakdowns in the reporting views
- Working company reporting screens and dashboard hot-company summaries for enriched traffic
- Paginated session, company, calls, and WooCommerce filters for search terms, status, confidence, provider/source, matched state, repeat-interest, and date range
- Screen re-fetch behaviour tuned so seeded or newly changed data appears properly when returning to a report

### Exports, segments, and commerce reporting

- Saved reporting segments for reusing common session, company, calls, and WooCommerce filters
- Dashboard shortcuts that deep-link straight into saved reporting segments across sessions, companies, calls, and WooCommerce views
- CSV exports for the current filtered sessions, companies, calls, and WooCommerce reporting views
- WooCommerce-aware tracking for product and category pages, including repeat-interest counts carried in event metadata
- WooCommerce reporting for top products, top categories, and the sessions and companies showing repeat buying-intent patterns

### Numbers, setup, and sample data

- Setup-focused admin pages for tracking, privacy, enrichment, Amazon Connect, AI, and phone-number management
- Amazon Connect setup fields for region, instance ID, S3 export bucket/prefix, flow log group, credentials strategy, and default contact flow selection
- OpenAI-backed company and product assistant controls for API key, model, prompts, chatbot name, launcher copy, live site-context grounding, and frontend visibility rules
- A setup-style phone-numbers screen for adding, editing, routing, and reviewing tracked numbers, including default-number handling and Amazon Connect identifiers
- Live Amazon Connect number visibility inside the phone-number setup screen, so already claimed instance numbers can be reviewed alongside local routing rules
- Amazon Connect number search and claim actions in wp-admin, with newly claimed numbers loaded straight into a local routing-rule draft
- Sample/demo numbers kept visible for reporting and setup work without participating in live number resolution
- Guided setup content and external links for enrichment providers, Amazon Connect, and future AI configuration
- Local sample-data seeding controls for generating realistic recent UK business and council activity across sessions, companies, events, WooCommerce interest, calls, pages, and tracking numbers for UI preview work

### Enrichment already in place

- Live enrichment provider support for **ipregistry** and **ipinfo**
- Cached enrichment lookups, company linking, enrichment storage on sessions, and an admin test lookup tool
- Reporting surfaces that reuse enrichment data for company views and prioritisation

## What is intentionally deferred

This repository does **not** yet implement:

- Amazon Connect call imports, call matching, outbound callbacks, or dialler workflows
- CRM exports or automated export workflows
- Order-level revenue attribution or ecommerce conversion stitching beyond interest reporting

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

These placeholders can be used in theme templates, blocks, or rendered content:

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
- `POST /ai/chat/respond`

### Admin routes

- `GET /admin/dashboard`
- `GET /admin/sessions`
- `GET /admin/sessions/{id}`
- `GET /admin/companies`
- `GET /admin/companies/{id}`
- `GET /admin/commerce`
- `GET /admin/calls`
- `GET /admin/calls/{id}`
- `GET /admin/chats`
- `GET /admin/chats/{id}`
- `POST /admin/chats/{id}/reply`
- `POST /admin/chats/{id}/status`
- `POST /admin/chats/{id}/workflow`
- `GET /admin/chats/{id}/suggestions`
- `GET /admin/numbers`
- `GET /admin/numbers/{id}`
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
- `GET /admin/connect-readiness`
- `GET /admin/connect/resources`
- `POST /admin/connect/phone-numbers/search`
- `POST /admin/connect/phone-numbers/claim`
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

## Reporting downloads and saved views

The sessions, companies, calls, and WooCommerce screens can all export the **current filtered view** as CSV so the exact reporting slice can be handed into follow-up sales, reporting, or investigation work.

Saved reporting segments preserve useful filter combinations, and the dashboard can surface shortcuts straight back into those saved views.

## Detail views and data visualisation

The admin now leans much more heavily on **single-item takeover views** for sessions, companies, calls, and numbers. Those views include summary cards, charts, related activity tables, and cross-links into the other reporting surfaces so a top-level trend can be followed into the underlying record without losing context.

## WooCommerce reporting

The WooCommerce screen surfaces **repeat product and category interest**, plus the sessions and companies showing those repeat patterns. Session and company detail views also summarise the strongest commerce interest signals attached to that record.

## Website assistant

The frontend assistant is now positioned around **the company, products, and services** rather than generic website help.

It currently supports:

- a configurable chatbot name, opening message, and input placeholder
- a compact single-line composer that expands as needed up to five lines
- OpenAI-backed replies grounded in live WordPress and WooCommerce content
- product-aware comparison answers, including size and capacity ranking where the catalogue provides those signals
- inline linked titles in assistant replies when relevant sources are mentioned
- a lead source card with thumbnail, short summary, price display, and variation counts where available
- product actions for viewing a product, viewing options, or adding simple products straight to the basket without leaving the page, including mini-cart opening where the site basket supports it
- a collapsed list of extra options with cleaner spacing for longer product-result sets
- persistent chat state across page changes for the same visitor, including reopening the chat after product navigation
- live context post-type detection with a selectable allowlist in the AI settings screen
- explicit end-chat handling for visitors, plus consistent auto-scroll when restored or newly updated
- live team availability status based on admins actively watching the plugin admin area, plus follow-up contact capture when nobody is online
- human-handover detection when a visitor asks for an agent, with admin alert links that open the chat in a new tab
- named chat messages with avatars for the bot and live agents, using the site icon for the bot avatar where available
- handover-aware chat state so the team can take over a live conversation and stop the AI replying over the top
- a two-way admin chats detail screen where staff can take over, reply to the customer, return the thread to the AI assistant, or end the conversation
- a chatroom-style admin operator console with a sticky transcript, bottom reply composer, auto-refreshing queue/detail state, and AI-suggested human replies
- stored chat conversations and transcripts in the plugin reporting surfaces
- commercial workflow handling on chats, including owner assignment, priority, outcome, follow-up dates, internal notes, and linked WooCommerce buying-signal summaries for the matched session or company

## Setup and connection guidance

The setup screens now cover tracking, privacy, enrichment, AI, Amazon Connect, and phone numbers more clearly, with consistent introductions, section spacing, and guidance links for the relevant API keys, instance IDs, phone number identifiers, and provider documentation.

The **Amazon Connect** page also acts as a pre-flight screen for later testing, so the region, instance ID, S3 export bucket/prefix, flow log group, credentials mode, and default contact flow can be stored in one place while the remaining readiness gaps are checked.

The **AI agent** page now runs the company and product assistant directly from WordPress using OpenAI. That means the model, prompts, chatbot name, frontend launcher copy, source-link behaviour, and live site-context grounding can all be managed without relying on Amazon Connect chat-flow wiring.

The **Enrichment** page is where the implemented provider support is connected today. That page already includes the provider selector, API key field, cache controls, bot/private-IP options, and a live test lookup tool for the supported providers.

The **Phone numbers** page is now treated as a proper setup surface rather than just a list, so defaults, routing rules, display labels, source context, Amazon Connect identifiers, live claimed Connect numbers, and new-number claiming can all be managed in one place.

## Sample data

The dashboard can seed and remove a **local-only demo dataset**. This fills the plugin with realistic recent UK business and council activity across sessions, companies, events, top pages, WooCommerce interest, calls, and tracking numbers so the reporting UI can be reviewed before live integrations are enabled, while keeping those demo numbers out of live frontend resolution.

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
3. Amazon Connect call import, call matching, assistant creation, and deeper number synchronisation
4. AI-assisted lead capture, richer website-assistant controls, and safe content tools

## Author

Built by Shane Rounce of [AceMedia.ninja](https://acemedia.ninja/).
