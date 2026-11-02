# Certificate and challan API review

Reviewed the existing portal on 2 November 2026. Existing OTP credentials are encrypted through ReturnStore, HTTP calls are audited through ReturnGateway, and download ownership/retention use ReturnsPortal and ReturnStore. Existing return request routes and download handling should remain intact.

Source inspected in the browser:
https://www.postman.com/primeserve45-7863096/prime-serve-s-workspace/collection/2je1ndu/gst-get-certificate-get-challan-details

## Verified request definitions

All three requests use POST, Content-Type: application/json and Apikey headers. Never copy the sample key from the public collection into the application.

| Service | Path under https://api.primeserve.in | JSON fields |
|---|---|---|
| Certificate | /api/spark/prime/gst/regcert | gstin, userName, password |
| Challan yearly | /api/spark/prime/gst/paymentchallansfy | gstin, year, userName, password |
| Challan monthly | /api/spark/prime/gst/paymentchallans | gstin, Period, userName, password |

Monthly example: Period="042026". Yearly example: year="2026". The collection does not explain whether year denotes a calendar year or the starting year of a financial year. Certificate and challan examples explicitly require GST passwords; the current OTP connection stores no GST password. OTP cannot be assumed to replace the documented password.

## Missing details

No saved response examples or response descriptions are visible for these requests. Missing: success indicators, disabled-access/authentication/expiry error codes, record paths/columns, no-data representation, synchronous versus job responses, certificate file encoding or URL field, MIME type, download hosts and link authentication/expiry rules. An exported collection without response examples will not resolve these omissions by itself.

Request redacted success, empty, and error responses for each service (or provider response documentation), certificate file/URL format and download rules, and the yearly year convention. Do not send customer passwords, OTPs, API keys, or active signed download URLs as examples.

## Implementation sequence after verification

1. Add Select GST Service above the existing content. Keep the return form, routes and worker unchanged; toggle new service sections using existing design components.
2. Implement separate audited backend service requests with the verified POST fields. Reuse a retained API key/GST identity where available. Password stays request-only and is not persisted or logged. Do not initiate OTP for a password-based request unless provider documentation requires it.
3. Map actual provider status/error codes centrally. Display the requested disabled-API-access message only for the verified condition. Fail closed for unknown response structures.
4. Render only actual returned challan fields, with safe CSV/XLSX export. Validate certificate file signatures and documented URL hosts, proxy downloads through authorized backend routes, and keep existing retention rules.
5. Use isolated transport fixtures derived from the verified response contracts for positive/negative service and download tests. Run existing return workflow, HTTP, audit/Excel and responsive browser checks. No chargeable production calls without explicit authorization.

No new service routes or production API calls have been added while response specifications are missing. The previous local error-message edit remains preserved. Production was not changed by this review.


## Local live-request update

The three POST request paths and documented request fields are now enabled locally through /api/documents, using the existing verified base URL and backend HTTPS request/audit handling. No production deployment was performed. Requests require GST password; API key can fall back to the existing encrypted connection. No new OTP request is initiated. Passwords and response payloads are not persisted for these services; existing encrypted audit-key retention still applies and is disclosed in the new common privacy notice. CSV cells guard spreadsheet formulas; JSON keeps provider structure except credential echoes are redacted. The year label is Calendar Year at the user's request.

No response schema, business-status mapping, certificate encoding, or download URL host has been invented. The UI shows actual JSON field paths and values (up to 5,000 fields), offers JSON and challan CSV exports, and downloads direct PDF/ZIP bodies recognized by signatures. A successful HTTP response is labelled Provider response, not GST success. Explicit error fields show a popup. Unsupported HTML is rejected, and API-access-disabled wording is mapped from actual provider error text. URL-only certificate responses remain visible data: backend fetching is not enabled until the file URL contract and allowed hosts are verified. Certificate JSON/base64 is not assumed to be a PDF. Direct PDF preview is not yet implemented.

The requested absolute no-storage privacy statement was not published because existing GST Returns credentials and API-key audit records are retained. Instead the shared notice accurately describes passwords/response handling, encrypted connections, default audit retention, 10-minute downloaded-return expiry and the abandoned-work safeguard. No DPDP compliance certification is claimed.

Validation: PHP/JavaScript syntax, isolated document response tests, existing return workflow suite and local HTTP invalid-request checks passed. No chargeable live requests were made; successful provider authentication, business status/error code mapping and URL-only file retrieval still require redacted provider examples or an explicitly authorized live test.

## Download format choices (local)

Returns and monthly/yearly challan forms now offer JSON and Excel, with the original provider file/ZIP retained as an option. Return exports operate only on completed, authorized jobs and keep the existing download acknowledgement/retention behavior. Download All packages the selected per-return exports in a combined ZIP. One JSON file is delivered as JSON; multiple JSON files are packaged unchanged in a ZIP. Excel uses source filename, JSON field path, value type and value; it is not a GST Portal report layout. Limits reject oversized exports instead of silently truncating them.

PDF is offered only after an original PDF is detected in a provider response/archive. PDF bytes are preserved; no GST Portal-style PDF is synthesized. URL-only PDFs still depend on a verified download URL contract. No chargeable requests were used to test conversion. Isolated checks cover original JSON/PDF/ZIP preservation, valid XLSX XML, formula-safe cells, identifiers/large integers, multi-file JSON archives, unavailable PDFs and temporary-file removal. Existing workflow checks passed. Production is unchanged.

## Subsequent local changes

At the user's request, the GST Return format selector was removed and its buttons restored to original ZIP / Download All Completed ZIPs. Export helpers remain available to the existing challan workflow. Service selection now has four explicit choices: returns, monthly challan, yearly challan and certificate. Customer accounts and billing are additive and gated; see CUSTOMERS_BILLING.md for implementation, tests, secure Zoho configuration and pending release checks. No production deployment has been performed.
