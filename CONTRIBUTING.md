# Contributing

I want this repository to stay clear, privacy-conscious, and easy to carry forward.

## Ground rules

- Please write in **British English**.
- Keep documentation and issue text in **my point of view** where that fits the context.
- For GitHub issues and issue comments, include an **AI summary** at the end in a Markdown block quote.
- Keep the product name as **Adaptive Customer Engagement**.

## Code expectations

- Follow native WordPress conventions.
- Sanitize input early and escape output late.
- Use REST routes rather than new admin-ajax flows.
- Keep anonymous tracking light enough to work well on cached pages.
- Do not introduce fingerprinting or unnecessary third-party marketing scripts.

## Workflow

1. Install dependencies with `npm install`.
2. Build assets with `npm run build`.
3. Check PHP syntax with `find . -name '*.php' -print0 | xargs -0 -n1 php -l`.
4. Keep changes scoped to the problem being solved.

## Roadmap discipline

If a feature is only partly designed, I would rather capture it in an issue than merge vague or speculative code.
