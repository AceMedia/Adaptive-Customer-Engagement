# IP enrichment and privacy

I use IP enrichment to add country and network context and, where the provider gives enough evidence, a likely organisation. An IP match is a hint. A company declared in a form or order is confirmed; a later visitor using the same address is only likely and the hint expires after 30 days.

The plugin accepts the visitor address from validated `REMOTE_ADDR`. Apache or another trusted proxy layer must resolve forwarding headers. I do not trust browser-supplied Cloudflare or X-Forwarded-For headers directly. A host with its own trusted resolver can use the `ace_client_ip` filter. The filtered value must still be a valid IPv4 or IPv6 address.

Provider HTTP failures, transport failures and malformed responses do not assign companies or update sessions. They are cached for 15 minutes by default, with `ace_enrichment_error_ttl` accepting 60 to 3600 seconds. A Retry-After hint can extend the cache period up to one hour. Successful cache and company JSON retain derived classification diagnostics only, without provider IP fields or reverse-DNS hostnames. This does not alter the separate session raw-IP retention setting.

For existing provider payloads, I use this CLI maintenance tool in batches. It skips ordinary plugin bootstrap, performs a dry run by default and prints counts only:

```bash
php8.4 /usr/local/bin/wp eval-file assets/plugins/Adaptive-Customer-Engagement/bin/ace-scrub-enrichment-payloads.php blog=1 limit=500 cache-after-id=0 company-after-id=0 --url=https://iegemea.pi --skip-plugins --skip-themes
```

The output gives a `last_id` for each table. Repeat with `cache-after-id` and `company-after-id` set to their respective `last_id` values until both tables report fewer than the limit. For a write run, take a scoped database backup first, then add both `apply backup-confirmed`. The tool changes only provider-owned JSON in `ace_enrichment_cache.response` and `ace_companies.source_payload`. It leaves confidence, company IDs, expiry and other columns as they were. I do not run it against production without a separate reviewed backup and approval.

The `ace_ip_memory_max_age_days` filter defaults to 30, accepts 0 to disable recall, and caps recall at 90 days. This changes future matching only. Historic confirmed sessions cannot all be safely reclassified because their stored confidence does not say whether the source was a direct declaration or an old IP memory match.
