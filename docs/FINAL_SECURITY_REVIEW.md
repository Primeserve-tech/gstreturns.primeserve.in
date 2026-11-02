# Local pre-production review

Result: local checks passed; production readiness remains conditional on a staging Docker build and deployed HTTPS checks. This is a focused code/test review, not an independent penetration test or a guarantee against all vulnerabilities.

## Changes from the review
- Customer-mode Admin API activity disables full historical-key reveal and full-key Excel exports. Customer/prepaid/Zoho secrets are not returned by account/admin state endpoints.
- Production Docker installs libxml2, XMLWriter and DOM required by the QR dependency.
- Coolify worker receives server-only Zoho configuration for queued payment notifications and low-balance reminders.
- Coolify customer accounts default enabled; paid API verification remains fail-closed by default.
- Apache explicitly denies dotfiles. PHP exception argument logging is disabled. Public document root remains /public, excluding application source, databases, .env and Git metadata.

## Local evidence
- Customer foundation, OTP limits/replay, billing reservations, concurrent balance enforcement, payment idempotence, exports, mail transport validation, document response validation, returns workflow and calendar tests passed using isolated fixtures.
- HTTP tests passed: unauthenticated access blocked; customer-to-admin access blocked; CSRF enforced; session expiry/logout enforced by existing account tests; full-key reveal disabled in customer mode; private server paths did not expose files; owner-scoped statements; existing billing history preserved.
- Configured environment secret values were not found in tracked or public files; .env is not tracked. Public JS has no credential storage in localStorage/sessionStorage or console logging.
- Composer production dependency audit: no known advisories reported.
- Browser viewport checks: 390x844 phone and 768x1024 tablet for customer GST services and Admin customer management, no document-wide horizontal overflow. Tables retain their own horizontal scroll. Login desktop fit was previously verified at 1366x650. These are browser viewport checks, not real-device/Safari certification.

## Required on staging before live
1. Build and start Docker images; Docker is unavailable on this local machine, so the container build has not been executed.
2. Configure production secrets in Coolify only; preserve stable encryption key and existing persistent data. Install the prepaid key through Admin without exporting it.
3. Verify HTTPS redirect, Secure/HttpOnly/SameSite session cookies, security headers, private paths returning no files, and correct trusted proxy IPs on the actual deployment. Do not expose Apache directly to untrusted clients.
4. Verify real worker and janitor health, email delivery, account authorization and download isolation on staging. No chargeable production GST API requests were made for this review.
5. Back up the database securely before migration. Do not publish writable data, .env, logs, test fixtures or secrets.

## Browser Inspect limitation
A browser owner can inspect values they themselves enter (passwords, OTPs, postpaid API keys, or a new key submitted by an authorized Admin). HTTPS protects transport; client-side masking cannot hide input from that browser owner. Backend prepaid and Zoho credentials must never be returned to the client. API responses and JS can be inspected, so backend authorization is the security boundary.
