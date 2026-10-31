# Deployment for gstreturns.primeserve.in

Deployment is deferred at the user's request. The current live-mode application runs only on localhost port 8086. The existing upload.primeserve.in production application has not been modified.

## Coolify configuration

1. Create a separate Docker Compose application with base directory `portal` and Compose file `docker-compose.production.yml`. This is a standalone file; do not merge it with the development Compose file.
2. Set the web service domain to `https://gstreturns.primeserve.in` and internal HTTP port 80. Create the required DNS record to the VPS. Do not publish worker or janitor ports.
3. Supply `.env.production` from the excluded local secret file or Coolify environment settings. Use `GST_MODE=live`, the HTTPS base URL, secure cookies, a 32-byte base64 encryption key, admin username/password hash and audit retention. Do not put a provider API key in deployment secrets: it is entered by each user.
4. Set `GST_TRUSTED_PROXIES` to the exact trusted Coolify proxy IPs/CIDRs. It is intentionally blank by default. Do not trust arbitrary forwarded headers or all public/private ranges. Apache must only be reachable through the trusted HTTPS proxy because it accepts that proxy's HTTPS indication.
5. Keep all three services healthy: `web`, `worker` and `janitor`. Worker command: `php spark returns:process-v3`; janitor: `php spark returns:purge-v3`. The janitor is separate so a long-running upstream request does not delay expiry cleanup.
6. Share `returns-data` on a local single-host filesystem. SQLite and file locks are not designed for multi-host scaling or a network filesystem. Share `returns-temp`, a Linux RAM-backed tmpfs volume, between all three containers. It contains no persistent backups of ZIP bytes and is limited to 256 MiB.
7. Use HTTPS at Coolify, verify secure HttpOnly SameSite cookies and CSRF, and verify no secret files or writable directories are under the public document root.

The documented contract ships as `config/primeserve.documented.json`. Optional local `config/contract.json` takes precedence. Both web and worker must use the same verified contract. Rebuild/restart workers after changes.

## Retention

Each downloaded return record expires 600 seconds after the first browser-confirmed successful download. OTP and processing do not start the download timer. Repeated downloads do not extend it, and undownloaded records remain independent (with a separate 24-hour abandoned-work safeguard). All read/download paths enforce expiry, the janitor deletes expired windows/jobs/files every second, and SQLite uses secure deletion with DELETE journals. A stopped or failed host cannot execute a cleanup process; on restart constructors and the janitor remove expired records before serving them. Backups must exclude temporary ZIP volumes, and restored metadata must be purged before serving requests. SSD/host snapshots, upstream Primeserve/S3 data and user-downloaded ZIPs are outside the application's deletion authority.

Admin records retain only IP, operation, return type, path without parameters/object identifiers, HTTP status, outcome, duration, mode and timestamps. Default audit retention is 30 days, configurable via `GST_AUDIT_RETENTION_DAYS`. API keys used are encrypted in a deduplicated audit-key store, masked in default responses and available only to authenticated admins through CSRF-protected reveal/export actions. Keys are deleted when their last retained audit record expires. OTPs, signed URLs and ZIP contents are excluded. Connection credentials are encrypted and retained independently for the requested 30-day period or until disconnect. Keep the encryption key stable and securely backed up separately from the database.

## Before public testing

Docker is unavailable locally, so run a build and validate the services on the VPS. Confirm DNS/TLS, proxy IP interpretation, janitor/worker health, correct volume permissions, admin authentication, idle expiry, cross-user download denial and a single authorized live return request before wider use. The docs specify completed status 100 but do not enumerate pending/failure/no-data statuses. Unknown values intentionally trigger bounded monitoring errors; extend the contract only after obtaining verified mappings. Monthly periods are formatted centrally as MMYYYY. Yearly mode expands a financial year into monthly calls, with a visible count and no future periods. The download acknowledgement route must remain reachable and uncached: it starts each return's 600-second retention timer only after the browser receives its ZIP.
