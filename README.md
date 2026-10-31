# PrimeServe GST Return Download Portal

Local live API portal: **http://127.0.0.1:8086/**

Admin: **http://127.0.0.1:8086/admin**

Future deployment domain: **https://gstreturns.primeserve.in/**. Nothing has been deployed to the VPS or existing upload portal.

## Start locally

```powershell
cd 'C:\GET GST TAXPAYER API\portal'
.\Start-Local.ps1 -Port 8086
```

The task-local PHP runtime and Composer dependencies are already installed. The app runs on PHP 8.3 / CodeIgniter 4 with SQLite, a sequential worker and a separate retention janitor. The current local `.env` enables **live** Primeserve endpoints. Enter your own API key, GST username and GSTIN, request OTP, and enter the real OTP manually. Test OTP `123456` is valid only on the separate fixture test server; it does not authenticate a live GST account.

No API key is configured in environment variables, copied from the documentation account, or hardcoded. No production API calls were made by the agent. Clicking live authentication/return actions will call the real provider and may incur charges under your subscription.

Admin credentials are configured privately. The password is stored as a one-way hash, with the local runtime credential record additionally encrypted using libsodium. Credentials are never prefilled or displayed by the admin screen. Previous sessions are invalidated when credentials change. No plaintext password file is retained. Admin sessions expire after 30 minutes and sign-in attempts are rate limited by IP.

The admin portal records originating client IP, operation, return type, method/endpoint, HTTP response status, outcome, duration and time. It stores the API key used as an encrypted audit reference, masked by default with an admin-only reveal. OTPs, ZIP contents and signed URL parameters are not recorded. Admins can download filtered XLSX logs and optionally include full keys in that private export.

## Return data retention

Each return gets a fixed **600-second deletion timer only after its first successful ZIP download**, acknowledged by the browser after the response bytes arrive. OTP, connect, initiation and processing do not start that timer. Repeat downloads do not extend it. Download All acknowledges all ZIPs included in the completed archive. Each downloaded record expires independently; undownloaded records are not deleted when a different return's timer ends. The backend enforces expiry and a separate janitor removes expired records without browser traffic. An independent 24-hour safeguard cleans abandoned, undownloaded workflows; it is not the 10-minute download timer.

ZIP contents are fetched on demand and temporary files are deleted immediately after use. Crash-leftover temporary ZIP files carry the original expiry in their filename and are deleted by an independent janitor, which runs every second even without browser traffic or an idle worker. Database deletions use SQLite secure deletion without a persistent WAL. Production Compose places temporary ZIP bytes on a shared RAM-backed tmpfs volume rather than persistent storage.

As clarified by the user, admin IP/status records are separate from ZIP data. Their default retention is **30 days**, configurable with `GST_AUDIT_RETENTION_DAYS`. Encrypted connection credentials may remain for the requested 30-day connection period and are removed on disconnect or local expiry. OTPs are never stored. Clearing cookies loses access to that browser's encrypted connection and history.

The portal cannot delete ZIPs already saved to the user's device or objects held by the upstream Primeserve/S3 service. It does not have a documented upstream object-deletion API. Do not back up temporary ZIP storage. Restore of persistent metadata from a backup must be followed by cleanup before serving requests.

## Verified APIs

The authenticated documentation has been inspected for OTP, connect, disconnect, all eight returns and Job Status. Connect always sends `KeepAliveDays: 30`. Initiation returns `jobId`; documented completion is `jobStatus: 100` with a signed ZIP `url`. Downloads refresh Job Status, validate and fetch that URL on the backend, and never forward the API key to storage.

Choose **Monthly** or **Yearly / Financial year** once for the selected returns. Monthly mode generates one six-digit MMYYYY period per return; yearly mode expands April through March into sequential monthly requests, excluding future periods. The preview shows the request count before submission: eight returns for a completed financial year means 96 requests. No repeated per-API period inputs are shown. The MMYYYY formatter follows explicit GSTR-6/6A/9 documentation, the GSTR-1 sample filename and observed user-started requests: 042026 received job IDs, while manually entered 0426 was rejected. Pending/failure/no-data status enums are not documented; unknown statuses receive bounded retries and then pause monitoring instead of being misclassified or causing new paid requests.

See [the verified contract and remaining gaps](docs/API_CONTRACT.md). The tracked `config/primeserve.documented.json` is loaded by default; optional `config/contract.json` can override it with further verified details. Neither contains secrets.

## Test results

- PHP workflow tests passed for one, multiple and all eight returns, strict sequencing, independent job IDs, encryption, timeouts, retries, ZIP integrity, ownership and persistence.
- Expiry tests confirmed removal of return records and temporary ZIP files while retaining content-free admin logs.
- Real HTTP fixture tests passed for authentication errors, CSRF, duplicate prevention, downloads, admin login/filters/IP capture and independent janitor deletion without browser requests.
- No chargeable API test was performed. Live mode is configured and waiting for your user-entered credentials.
- Docker/Coolify files are prepared; Docker is unavailable on this Windows host and has not been executed here.

```powershell
..\.runtime\php\php.exe tests\workflow.php
# Existing fixture server only; never run these against live mode:
$env:GST_TEST_BASE='http://127.0.0.1:8085'
python tests\http_workflow.py
python tests\admin_retention.py
```

The older fixture preview remains on port 8085, isolated from the new live-mode database on port 8086. Its data is synthetic. The local start script reuses existing processes and starts any missing current worker/janitor. Current commands are `php spark returns:process-v3` and `php spark returns:purge-v3`. Long-running PHP processes do not hot reload. Existing connections, jobs and audit records were migrated to the v3 database without initiating or repeating provider requests.

## Deploy later

Use the standalone `docker-compose.production.yml` in Coolify, set the web service domain to `https://gstreturns.primeserve.in`, and route to web port 80. `.env.production.example` lists required variables. A local generated `.env.production` exists with independent encryption and hashed admin secrets, but it must not be committed or included in source archives. Supply secrets through Coolify or an excluded environment file. Configure exact trusted proxy IPs/CIDRs for accurate client-IP reporting. See [deployment](docs/DEPLOYMENT.md) and [testing](docs/TESTING.md).
