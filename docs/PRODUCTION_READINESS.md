# Production readiness review — 31 October 2026

Decision: local checks pass; deployment verification and provider status clarification remain open. No chargeable provider initiation was performed during this review.

## Verified locally

- Isolated PHP workflow suite: authentication, invalid/expired OTP, one/multiple/all return selections, sequential execution, independent IDs, bounded retries/timeouts, isolation, persistence, ZIP validation and retention.
- HTTP fixture suite: CSRF, duplicate prevention, refresh recovery, individual/bulk downloads, yearly expansion, download acknowledgement and independent janitor cleanup.
- Audit/Excel suite: encrypted keys, masked default responses, key rotation attribution, restricted reveal, filtered exports beyond the 500-row display cap, literal formula-like cells and cleanup.
- PHP syntax: all 72 application/container PHP files. JavaScript syntax: portal, admin and popup scripts.
- Composer locked dependency audit: no known security advisories reported.
- Local live app: API configuration ready, worker online and janitor online. This is service readiness, not a new end-to-end paid provider test.
- Secret/config/database paths return 404; unauthenticated admin activity returns 401.
- Excluded production environment has live mode, correct HTTPS domain, HTTPS enforcement, secure cookies, 32-byte encryption key and an admin password hash. Secret values were not printed.

## Change made during review

Container web health now requires HTTP 200 and the application's JSON healthy response. An HTTPS redirect alone cannot count as healthy. The probe sends the proxy HTTPS indication to internal Apache, consistent with its reverse-proxy configuration.

## Required before public launch

1. Build and run the production Compose file on the VPS. Docker is unavailable on this local machine; Linux image build, extension installation, volume permissions, tmpfs capacity and all three container health probes remain unverified in containers.
2. Configure DNS and Coolify TLS for gstreturns.primeserve.in. Expose only the web service through the HTTPS proxy; keep Apache inaccessible directly. Set GST_TRUSTED_PROXIES to the exact trusted proxy IPs/CIDRs; the excluded local production file intentionally leaves this blank. Verify originating IPs cannot be spoofed.
3. Obtain verified provider mappings for pending, failed and no-data job statuses. Only completed status 100 is documented. Unknown statuses currently produce bounded monitoring errors and may require manual resumption; they are not fabricated as success or failure.
4. Perform an explicitly authorized single live end-to-end return on the deployed host, including signed ZIP fetch, Excel export, cross-session denial and expiry after browser download acknowledgement. Verify secure cookies, admin logout/session expiry and headers through the actual proxy.
5. Keep the encryption key stable and store it securely outside database backups. Exclude temporary ZIP storage from snapshots/backups and purge expired metadata before serving a restored backup.

See DEPLOYMENT.md for installation and retention details. The existing Bulk GST Search production application has not been changed.
