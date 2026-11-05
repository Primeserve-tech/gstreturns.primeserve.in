# Customer access controls

Local: Admin → Customers → Block / Unblock Customer. Choose customer, Block or Unblock, and record a reason. Save shows the existing success popup. Customer table Active = 0 means blocked; Active = 1 means enabled.

Block atomically sets enabled=0 and deletes all customer sessions, temporary API keys and session GST connections. Backend authorization rejects further customer requests immediately, including downloads. An already running request/transfer cannot be recalled. Browser session heartbeat checks every five seconds while active and on focus/reopening, returning revoked sessions to Login. Unblock requires a fresh login; it never revives old sessions. Financial records, prices and balances are preserved. Both changes are audited. Existing Account enabled checkbox is retained for backwards compatibility.

No migration required. Checks: tests/account_access.php, tests/run_customer_http.php; fixtures only, no live customer was blocked. Production unchanged.

Migration customer-v7.sql adds access_reason, backfilling existing blocked reasons from audit where available. Correct-password blocked logins show the reason; incorrect-password attempts retain a generic error. Block/unblock status emails include the reason and status. No-op repeated status changes send no duplicate email. Delivery failure is recorded in Email Delivery Acceptance Status and returned to Admin without undoing the block. Existing billing form cannot bypass this workflow. Tests use injected mail transports and never send real status emails.
