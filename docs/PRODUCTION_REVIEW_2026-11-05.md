# Production readiness review - 5 November 2026

Scope: existing gstreturns.primeserve.in application only. No Bulk GST Upload folders changed.

## Checks completed
- Customer, HTTP authorization/CSRF, OTP attempt/replay/rate-limit, wallet concurrency/idempotency, GST charge/tax, paid credit/recharge, refund, access blocking, standard/special pricing and privacy consent fixture suites pass.
- Original return workflow, document formats, data export, API audit export and Zoho response validation suites pass without live provider calls.
- Split Excel boundaries include 100,000 and 100,001 rows, complete row continuity, temp cleanup, client filtering and inclusive IST dates.
- Migration test upgrades a populated v4 fixture database through v9 twice, preserving customers, hashes, balances, ledger and encrypted prepaid key. Standard/special legacy classification preserved; no consent fabricated. SQLite integrity and foreign keys pass.
- PHP and frontend JavaScript syntax pass; Composer manifest validates; dependency audit reports no advisories. Docker engine is unavailable locally, so a local container build was not executed.
- Browser checks on isolated fixture accounts at 390px mobile and 768px tablet: customer account/QR/consent/refund and Admin pricing/payment forms fit without page-level overflow; no customer console warnings/errors observed. Wide tables scroll in their own containers.
- Recent tables remain capped at 50, but separate actionable selectors include all pending recharges/open refunds/held usage. Selected refund details identify amount, destination and customer reason.
- Fixture browser redirects pinned to the isolated 8087 origin so the local 8086 runtime cannot be targeted accidentally.

## Release contents
Migrations v5-v9, usage GSTIN context, Admin credits/debits, manual refunds, block/unblock emails and reasons, standard/special prices, one-time consent and withdrawal/re-consent, split reports, recent-list limits, policies and separate PDF manuals.

Temporary authoring files, diagnostics, runtime data, local environment/secrets and screenshots are excluded from Git. Manuals are versioned outside the public web root and excluded from Docker build context. No API endpoints or production secrets are changed by this release.

## Backup and deployment
Coolify GST RETURNS WEBSITE advanced configuration currently displays Deploy on push (webhooks). A main-branch push may deploy automatically. Existing deployment is commit f5ab6f6.
Pre-release backup created on existing web container at /var/www/html/writable/backups/accounts-pre-release-20261105-014435.sqlite; SQLite integrity=ok, directory 0700, file 0600. It preserves account/financial/configuration records while removing copied GST connections, windows/jobs, temporary customer sessions, pending registration payloads and password-reset tokens. No backup data was downloaded or printed. Original live database was not modified.
Preserve existing Coolify secrets and persistent writable volume. Existing users will see a one-time privacy consent screen on their next GST service visit; no Admin approval is required.

## Limits and post-deployment checks
No chargeable production GST requests, real payments/refunds, or real customer blocking were performed. Fresh live email inbox delivery and external GST/provider availability are not certified by fixture tests. Verify them with an authorized customer before claiming end-to-end live service success.
After rollout check login/register, policies and protected paths, worker/janitor health, loaded migration version, account/QR and notifications. Do not automatically repeat a chargeable request after a timeout.

## Rollback
Keep additive migration columns/tables. Prefer a forward fix or a compatible release that respects refund holds, pricing mode and consent. Do not roll straight back to old code with positional customer_prices INSERT or without consent enforcement. If database restore is necessary, stop writes and workers in a controlled maintenance window, reconcile every transaction since backup, restore required ownership/permissions and encryption key, then verify accounts and balances before reopening. A pre-release backup cannot contain later transactions; never overwrite them blindly.
