# AGENTS.md

## Repository intent

This repository holds my WordPress plugin **Adaptive Customer Engagement**. The aim is to keep the plugin privacy-conscious, first-party, and practical for business lead attribution rather than turning it into a bloated marketing stack.

## Working expectations

- Write in **British English**.
- When drafting issues, comments, notes, or planning text, write from **my point of view**.
- Add an **AI summary** at the end of each GitHub issue or issue comment using a Markdown block quote.
- Keep naming, UI copy, and docs aligned with **Adaptive Customer Engagement** and **AceMedia.ninja**.
- Preserve the phased roadmap: data spine first, then enrichment, then call matching, then AI.

## Technical guardrails

- Use native WordPress patterns: hooks, capabilities, REST routes, `dbDelta()`, and prepared SQL.
- Sanitize input early and escape output late.
- Keep anonymous tracking lightweight and resilient on cached pages.
- Avoid third-party tracking pixels or fingerprinting logic.
- Prefer extension points and placeholders over half-built integrations.
- Treat Amazon Connect, enrichment providers, and AI tools as opt-in features behind settings and clear capability checks.

## Repo structure

- `adaptive-customer-engagement.php` bootstraps the plugin.
- `includes/` holds PHP classes.
- `assets/src/` holds source JS for admin and frontend behaviour.
- `assets/build/` holds compiled assets and is generated locally.

## Build and validation

- Run `npm install` before building assets.
- Run `npm run build` after JS changes.
- Lint PHP syntax with `php -l` when touching PHP files.
- Keep commits coherent and small enough to review.

## Documentation hygiene

- Keep `README.md` current when behaviour or setup changes.
- Keep the GitHub wiki at `https://github.com/AceMedia/Adaptive-Customer-Engagement/wiki` current when behaviour, setup, reporting screens, or roadmap position changes.
- Write wiki pages in British English from my point of view, as normal reference documentation, without AI summary block quotes.
- Do not maintain manual wiki sidebar page links unless there is a specific reason; rely on the native GitHub wiki navigation by default.
- Capture future implementation plans in GitHub issues rather than ad-hoc notes in the repo.
- Do not create speculative roadmap code without documenting the intended next step.
