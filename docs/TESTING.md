# Testing and error handling

Run `php tests/workflow.php` with the installed PHP runtime. It creates a temporary fixture-only database and performs no HTTP calls to Primeserve. The suite verifies encryption, OTP success/invalid/expired, month mapping, documented GSTR-9 MMYYYY behavior, one/three/eight selections, strict sequencing, job mapping, completion, ZIP bytes, cross-user denial, partial failures, bounded polling, interrupted initiation, persistence, audit capture, secure deletion, ZIP/job expiry, separate audit retention, proxy IP handling, live contract readiness and shared six-digit periods.

`tests/http_workflow.py` and `tests/admin_retention.py` require the separate local fixture server and fixture worker/janitor. Set `GST_TEST_BASE=http://127.0.0.1:8085`. Both suites verify fixture mode before exercising workflows; the expiry suite modifies only the fixture database. They cover the actual HTTP routes, CSRF, invalid GSTIN, auth/OTP errors, duplicate clicks, user isolation, refresh, completion, ZIPs, admin authentication and filters, safe logs, originating IP, and independent janitor expiry with no browser cleanup request.

Fixture-only worker commands are `php spark returns:process-v3 --fixture` and `php spark returns:purge-v3 --fixture`. Default commands follow `.env` and are live locally. Never use synthetic credentials against live endpoints.

## Error policy

- Missing key/identity, invalid GSTIN, no selected returns or invalid year/month preferences: rejected before provider initiation.
- OTP/connect/disconnect: success requires documented `status_cd` string `1`; failures remain unverified and use safe messages.
- Initiation requires the documented scalar `jobId`. An ambiguous failure or interrupted initiation is marked Review required and is never automatically resubmitted.
- Completed status is documented numeric `100`. Other enum values must be documented before mapping them to processing, failure or no-data. Unknown statuses use bounded error retries and pause monitoring; Resume checks uses the same existing job.
- API status requests use a configurable initial 10-second interval, bounded exponential backoff and four consecutive errors by default. The job monitoring deadline cannot exceed the return-data expiry window.
- Data expiry prevents all return downloads and removes associated jobs. It does not revoke the provider's 30-day GST connection or delete the content-free admin audit.
- ZIP downloads refresh Job Status for a fresh URL, validate its allowlisted HTTPS/public destination, do not follow redirects, do not forward the API key, validate content type/ZIP structure and preserve original bytes.
- Combined archives fail visibly if any requested item fails, and preserve each inner ZIP unchanged. Per-response limit 40 MiB; combined input limit 100 MiB.
- Proxy headers from untrusted peers are ignored. Admin logins are authenticated, CSRF protected, and IP rate limited. Content is escaped on both admin and user screens.

## Local verification and limitations

The fixture, HTTP, admin and independent-expiry tests passed, as did PHP/JavaScript syntax checks. Live readiness was checked without making upstream requests. The authenticated source documents were read; all production OTP/return/download testing still requires the user's own key and credentials entered into the form. Pending/failure enums and unspecified period encodings remain explicit contract gaps rather than assumptions. Docker builds and VPS deployment are deferred at the user's request.

## Monthly / yearly and post-download regression checks

The updated PHP suite checks six-digit period generation, April-to-March yearly expansion, a single monthly period, safe provider-message redaction, no deletion timer at OTP/initiation/completion, 600 seconds from first receipt acknowledgement, no extension on receipt replay, owner-bound acknowledgements and independent expiry.

The HTTP suite checks the actual X-Download-Receipt header and acknowledgement endpoint, validates each downloaded row's expiry minus downloaded time equals 600, and verifies a two-return completed-year selection creates 24 distinct return/period jobs. It then expires only those downloaded fixture rows and verifies the independent janitor deletes them while leaving the 24 undownloaded yearly rows and admin audit intact. No new production return request is sent.
