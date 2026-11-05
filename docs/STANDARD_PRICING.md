# Standard and special client rates

Admin → Pricing → Standard Service Prices edits service prices excluding GST. Changes atomically update the default for new registrations and current rows marked standard. Existing special rates, permissions, and historical usage/charge snapshots remain unchanged.

Client Pricing shows pricing_mode and customer identity, with an Edit button that populates Customer API Prices & Access. Select Special fixed rate for an individually protected rate, or Standard rate to adopt the current global price and future changes. Mode is per customer per service. Disabled services remain disabled. Success popups confirm changes. Edits and affected-client counts are audited.

Migration customer-v8.sql adds pricing_mode. Existing explicitly Admin-edited rows are protected as special even when equal to the old default. Existing rates differing from the configured default are conservatively protected as special because legacy records do not reliably distinguish old default snapshots from bespoke rates. Admin can deliberately switch these back to standard. No legacy prices are changed by migration. New registrations use standard mode.

Checks: tests/standard_pricing.php and tests/run_customer_http.php use fixture accounts; no production prices are changed. Existing code must use explicit customer_prices insert columns after migration. No new secrets or environment variables. Keep the additive schema on rollback; reverting to an old build that uses positional INSERT would break registrations. Production deployment is a separate action.
