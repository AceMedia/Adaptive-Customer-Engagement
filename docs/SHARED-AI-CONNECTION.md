# Shared Ace AI connection

**Settings → Ace AI connection** is shared by Ace SEO and Adaptive Customer Engagement. On multisite, **Network Admin → Settings → Ace AI connection** supplies the default for every site. A site administrator can inherit it, use their own connection, or switch AI off. These settings belong to the site/network, not the WordPress administrator who saved them; scheduled jobs use the same selection.

## Connect a ChatGPT subscription on a self-hosted installation

This is the open-source self-hosted route documented by OpenAI. It is not a direct WordPress OAuth callback, and no account is connected automatically.

1. Open the connection settings at the intended scope. For IEG, use **Network Admin**, then test the first AI feature on the dev subsite.
2. Download the sign-in helper to the computer running your browser. It requires Node.js 20 or newer. Expand **Set up on a self-hosted server** for the command containing this installation's host ID.
3. Run that command in a private folder, open the one-time link it prints, and approve the requested ChatGPT plan permission. The helper listens on `127.0.0.1` on that computer; running it on a different server will not receive the browser callback.
4. Import the resulting protected credential file in settings and confirm the site/network scope. Never paste credentials into chat or tickets. Remove the transfer file after import. The `.registration` companion contains the issued client/account mapping and can be kept privately for later sign-in; pass it as the optional third helper argument to reuse that registration.
5. Choose a text model from the connected account's catalogue. No inference request is made by import or model selection. Run the first text feature only when ready to use the authorised account's allowance.

The helper validates state, PKCE, issued client registration, signed account identity, nonce and granted plan scopes. Imported identity is verified again against OpenAI's signing keys. Tokens are encrypted in the selected scope's single connection record. The VM/site owns later refreshes; do not run another runtime against the copied refresh token.

**Availability:** eligibility, consent and actual requests still depend on the user's OpenAI account. This integration does not establish commercial partner approval or guarantee that any particular shared-site workload is permitted. OpenAI's [overview](https://developers.openai.com/siwc/token-sharing-open-source) distinguishes open-source/self-hosted tools from paid/remotely hosted apps. A direct website callback requires the [registered partner route](https://developers.openai.com/siwc/request-client-id). We do not borrow Codex credentials or invent a registered client ID.

## Scope and permissions

| Selection | Behaviour |
| --- | --- |
| Network connection | Sites without an override inherit it; only network administrators may change it. |
| Site's own connection | Replaces inheritance for every participating Ace plugin on that site. |
| Switch AI off | Stops managed AI access; no network or old plugin-key fallback. |
| Missing, expired or unreadable override | Requires repair or a successful refresh; never silently charges another account. |
| Installation not yet configured | Preserves existing explicit plugin settings until shared management is saved. |

Subscription access currently supports **text through the streaming Responses API only**. It does not grant image generation, audio, web search or Decisions access. An explicitly selected API-key connection is separately billed and offers independent text/image/audio permissions. Existing plugin feature switches still apply.

## Refresh and disconnection

Both plugins resolve the same connection on each request, including after `switch_to_blog()`. A database lease serialises refreshes by site/network; compare-and-swap protects reconnects or disconnects from an older worker overwriting their changes. Access and rotating refresh tokens update together. A revoked grant requires reconnection. A transient transport error does not silently switch billing or destroy the refresh token.

**Disconnect ChatGPT at this scope** attempts to revoke the renewable session, clears local tokens and switches that scope off. If remote revocation cannot be confirmed, the screen says so and directs the administrator to ChatGPT settings. Merely switching to inheritance/off clears that scope's local connection; use the explicit disconnect action first to request remote revocation. Retain the helper's private registration companion to reuse the same issued client later.

Credentials are authenticated-encrypted using the WordPress authentication salt; site options are not autoloaded. Moving a database without its salts requires reconnecting. Status output contains the account label and chosen model but never tokens. Removing one consumer does not delete the shared record needed by another. Explicit disconnection is the cleanup mechanism.

Both plugins bundle identical `includes/shared-ai` files. A single early `plugins_loaded` hook chooses the highest registered bundle version deterministically. Consumers remain independently installable. Keep bundle files identical when releasing and review compatibility before changing this shared contract.

## Verification and limits

Local verification includes scope/encryption/capability checks, signed identity rejection cases, state/PKCE callback checks, interrupted/failed stream handling, token rotation/concurrency, cross-plugin transport checks, real WordPress multisite inheritance and real database refresh-lock tests with provider HTTP mocked. Browser checks cover settings save, required file/scope consent and mobile layout. Temporary local fixture settings and the test login session were restored/removed.

No successful real user OAuth consent or live inference is implied by those tests. The first real account sign-in and permitted text request remain an explicit acceptance step. Responses are bounded to 2 MiB and accepted only after a terminal `response.completed` event; an incomplete or failed stream is not returned as a successful partial answer.

Official references: [registration and sign-in](https://developers.openai.com/siwc/token-sharing-open-source/sign-in), [self-hosted VMs](https://developers.openai.com/siwc/token-sharing-open-source/self-hosted-vms), [sessions and refresh](https://developers.openai.com/siwc/token-sharing-open-source/profiles-and-sessions), [models and inference](https://developers.openai.com/siwc/token-sharing-open-source/models-and-inference).
