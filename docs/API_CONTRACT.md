# Verified Primeserve portal contract

Inspected the authenticated documentation on 31 October 2026. No production API calls were made and no profile API key was copied.

Sources:

- [GST Get OTP & Connect API](https://app.primeserve.in/apis/group/ef4c3799-c38c-474e-9ef2-e99c6a9f08ff/a0dbc138-7580-44c2-9ee5-e8b39ad316ce)
- [GST GET PRIME API](https://app.primeserve.in/apis/group/bc021aef-45f4-480a-bdc6-f0dc3b60bcc4/1873f18d-85ab-4812-813d-17725c59db8e)

All requests use `Apikey`, supplied by the user at runtime. Base URL: `https://api.primeserve.in`.

| Operation | Method / path | Request / response |
|---|---|---|
| Request OTP | POST `/api/spark/core/gst/auth/requestotp` | JSON `userName`, `gstin`; success `status_cd: "1"` |
| Connect | POST `/api/spark/core/gst/auth/connect` | JSON `userName`, `gstin`, `Otp`, `KeepAliveDays`; success `status_cd: "1"`, `data.expiry` |
| Disconnect | POST `/api/spark/core/gst/auth/disconnect` | JSON `userName`, `gstin`; success `status_cd: "1"` |
| GSTR-1 | GET `/api/spark/prime/gst/gstr1` | Query `gstin`, `period={{month}}`; response `jobId` |
| GSTR-1A | GET `/api/spark/prime/gst/gstr1a` | Same documented placeholder `{{month}}`; response `jobId` |
| GSTR-2A | GET `/api/spark/prime/gst/gstr2a` | Same documented placeholder `{{month}}`; response `jobId` |
| GSTR-2B | GET `/api/spark/prime/gst/gstr2b` | Same documented placeholder `{{month}}`; response `jobId` |
| GSTR-3B | GET `/api/spark/prime/gst/gstr3b` | Same documented placeholder `{{month}}`; response `jobId` |
| GSTR-6 | GET `/api/spark/prime/gst/gstr6` | Query `gstin`, `period={{MMYYYY}}`; response `jobId` |
| GSTR-6A | GET `/api/spark/prime/gst/gstr6a` | Query `gstin`, `period={{MMYYYY}}`; response `jobId` |
| GSTR-9 | GET `/api/spark/prime/gst/gstr9` | Query `gstin`, `period={{MMYYYY}}`; response `jobId` |
| Job Status | GET `/api/spark/system/jobstatus` | Query `jobid`; documented completed response `jobStatus: 100`, `url` |

The supplied Connect example uses `KeepAliveDays: 0`; this application deliberately sends **30** as explicitly required by the user. The documentation does not define the units of `data.expiry`, so the application does not infer them. A provider session may expire before the application's maximum local credential lifetime.

## Downloads

There is no separate ZIP API in the inspected group. The completed Job Status response provides a signed HTTPS ZIP URL on `gst-cache.s3.ap-south-1.amazonaws.com`. The sample URL has an `X-Amz-Expires=300` parameter; the application does not assume every URL has the same expiry. It re-requests Job Status before downloading to obtain the currently returned URL. Signed query strings and object paths are never persisted in logs.

The backend fetches the ZIP with no API key forwarded to S3, validates HTTPS/host/public IP/content type/ZIP structure, and preserves its bytes. Redirects are not followed. A different legitimate storage host must be verified and explicitly allowlisted before use. The actual authenticated completed-job response has now also verified `octa-gst-cache.s3.ap-south-1.amazonaws.com`; this exact host is included alongside the documented sample host.

## Gaps handled without invented specifications

- The `{{month}}` placeholders for five APIs do not explicitly name the encoding. The shared six-digit MMYYYY formatter is now used, based on explicit GSTR-6/6A/9 documentation, the GSTR-1 signed URL sample, and observed user-started requests: 042026 received job IDs, while 0426 was rejected. The rejected response body was not previously retained, so period formatting is the likely cause rather than a proven provider error message. Future HTTP errors include sanitized provider message detail when available.
- Yearly mode is an application-level expansion of a financial year into monthly calls. It does not invent an annual endpoint or annual period parameter. Future months are excluded, and the number of provider requests is shown before submission.
- Only completed `jobStatus: 100` is documented. Pending, failed and no-data enums are not invented. Unknown statuses trigger bounded status-check backoff and eventually pause checks. The existing job can be checked again without reinitiating a chargeable return.
- Only success examples are present for authentication. Upstream failures use safe generic error messages rather than speculative error-field parsing.
- Initiation success is a nonempty scalar `jobId`, as documented. No nonexistent `success` field is required.
- No automatic chargeable initiation retries are enabled.

The tracked configuration is `config/primeserve.documented.json`. An optional local `config/contract.json` overrides it. Neither contains API keys. Extend these mappings only after observing/documenting the real API behavior or receiving additional specifications.
