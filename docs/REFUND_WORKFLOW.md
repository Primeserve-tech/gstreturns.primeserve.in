# Manual wallet refunds (local implementation)

Customer: My Account → Request a Refund. Submit amount, UPI ID and valid reason, acknowledge the separate mandatory email. Then personally email support@primeserve.in with the request ID, registered email, name, company, original payment UTR, amount, destination and reason. The portal never sends refund emails automatically.

Admin: Payments → Refund Requests — Review. Match the support email with the portal request, verify original payment and unused paid credits, select approval/rejection and final amount, record the reason. Approval requires confirmation that the mandatory support email was received. Funds requested are reserved immediately. Partial approval/rejection releases unapproved funds.

Admin then manually transfers the approved amount through the business bank/UPI system. Payments → Refunds — Record Manual Transfer requires the UTR and explicit success confirmation. Recording payment does not deduct twice. The portal does not initiate a transfer. Customer sees status, decision and reference in My Account. Footer Refund Policy explains both steps.

Eligibility conservatively allocates all prepaid charged/reserved/review API usage against paid-recharge credits first, subtracts open/paid refunds and caps at available balance. Bonuses and Admin-granted credits do not create refund eligibility. One open refund per customer. Billing mode changes are blocked until open refunds are resolved. No automatic expiry of money holds.

Migration customer-v6.sql creates refund_requests and an index, preserving existing customers, prices and balances. Destination is encrypted using the existing server encryption key; financial audit entries exclude it. Existing authentication and CSRF protect request/review/payment endpoints. Section Excel supports recent 50 and full Reports supports date/client selection with existing split exports; destination is excluded from Excel.

Validation: tests/refunds.php uses isolated fixtures, no real email or transfer. Tests/customer_http.php covers authorization/CSRF and public policy. Never mark a production transfer paid before independently verifying it succeeded. Do not deploy independently of the other pending local changes. Retain financial records and encryption key; never drop this table as rollback while money is held. For rollback disable new refund submissions and reconcile all pending/approved refunds before reverting application code.

## Admin wallet correction
Payments → Reduce Wallet Balance / Correct Wrong Credit. Select customer, positive amount, correction reason and confirm reduction. Prepaid available balance only, no negative balance and no change to bank payment records. Ledger admin-debit and audit admin-wallet-debit preserve the correction. Duplicate request IDs cannot deduct twice. Customer is notified once. Corrections conservatively reduce refund eligibility. Tests: tests/wallet_corrections.php and tests/run_customer_http.php. No migration required.
