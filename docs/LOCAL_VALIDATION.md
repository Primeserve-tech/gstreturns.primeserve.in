# Current local validation

Live local origin: http://127.0.0.1:8086
Future domain (not deployed): https://gstreturns.primeserve.in

- Authenticated API documentation inspected for OTP/connect/disconnect, all eight returns and Job Status.
- Documented initiation jobId, completed jobStatus=100 and signed download URL configured. No account/profile API key copied.
- Live mode, contract readiness, worker heartbeat and janitor heartbeat confirmed through local state endpoints. No production provider API calls were made.
- Original upload.primeserve.in footer inspected and matched, including logo, contacts, Admin Login and legal links.
- Admin sign-in verified through HTTP and browser. IP/status tables, filters and mode labels verified. Production mode shows zero calls until the user tests real credentials; no dummy live records were inserted.
- Isolated PHP workflow suite passed, including all-eight sequential initiation, encryption, independent IDs, polling bounds, downloads, ownership, secure deletion, fixed expiry and separate audit retention.
- HTTP fixture workflow and admin/retention suites passed. Independent janitor deletion was verified by expiring a fixture window and temporary file, then observing deletion without any frontend cleanup request. Content-free admin logs and the separate GST connection remained intact, matching the user's clarification that ZIP data is what expires.
- PHP and JavaScript syntax checks passed. The existing dependency audit found no known advisories.
- Desktop local live portal and admin screens reviewed. Final footer and admin screenshots saved under docs/screenshots.
- Real OTP/return/download calls await the user's own key and GST credentials entered in the live local form. Undocumented pending/failure status enums and five period encodings are handled explicitly, without invented API specifications.
- Docker and Coolify execution deferred. Docker is unavailable on this machine; prepared configuration has not been run on a VPS.

## Latest monthly/yearly and retention correction

- Removed repeated per-API period inputs. One shared selection generates MMYYYY, including April 2026 = 042026.
- Added Monthly and Yearly preferences. Browser preview verified eight returns × twelve periods = 96 requests for FY 2025-26 without submitting it.
- Moved the 600-second deletion timer from OTP to browser acknowledgement of the first complete ZIP response, independently per return. Replays and repeated downloads do not extend expiry.
- Preserved live connection/job/audit records in the v3 store, without repeating provider initiations. Five four-digit-period HTTP 400 rejections are shown as Failed; three accepted jobs remain available for existing-job monitoring.
- Provider errors now include sanitized message detail where available. Unknown status enums remain bounded and do not cause new return initiation.

## Download-host correction

A user-completed production job returned a signed URL on octa-gst-cache.s3.ap-south-1.amazonaws.com, while the documentation example used gst-cache.s3.ap-south-1.amazonaws.com. The original exact-host check rejected the actual host before fetching the ZIP. The verified second exact host is now allowed; HTTPS, public-address/DNS pinning, no redirects, no forwarded API key and ZIP integrity checks remain enabled. A real existing completed job's ZIP was retrieved and integrity-checked successfully (30,047 bytes), without any new return initiation. No signed URL was persisted or printed.

Browser tests in the isolated fixture app confirmed both Download ZIP and Download All emit real browser download events and save valid ZIP files. Nested ZIP contents were inspected for integrity. Download progress/success/error messages now appear inside the Download Centre rather than only above the authentication form. Host-policy regression checks accept both verified hosts and reject HTTP, lookalike hosts, user-info URLs and nonstandard ports.

## Error popup and live-banner update

User-facing request, validation, new job/polling failure and admin errors now use a shared dismissible alert dialog. The dialog supports OK, Close and Escape, traps focus using the native dialog element, escapes messages with textContent and suppresses repeated automatic polling alerts for the same error. Existing historical job failures do not flood the page on first load. The yellow live API mode/charges banner is removed; fixture-mode labelling remains explicit.

Browser verification confirmed the live banner is hidden, missing required fields open the popup, Escape dismisses it, and a locally rejected invalid GSTIN opens the same popup from the HTTP error path. These checks did not call production Primeserve APIs. Screenshot: docs/screenshots/error-popup.jpg.

## Admin key attribution and Excel export

Added encrypted per-call key attribution, masked API Key Used column, admin-only Show/Hide with automatic re-masking, and filtered XLSX export. The default export masks keys; admins can explicitly include full keys. Existing historical rows are associated only with a retained connection valid at the recorded call time; otherwise the key is marked Not captured. A database trigger captures origin key references for existing long-running workers without replaying API calls. Keys are deduplicated, encrypted and removed when their final audit record is deleted.

Isolated tests verified encryption, key rotation attribution, legacy worker snapshots, masked JSON/default exports, full-key filtered exports, formula-like log values as literal cells, temporary-file cleanup and exporting all 504 test records despite the table's 500-row limit. Actual local HTTP tests verified admin authentication, authorized reveal, unauthorized denial and masked XLSX delivery without calling provider APIs.
