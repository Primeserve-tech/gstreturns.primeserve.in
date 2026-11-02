ALTER TABLE billing_usage ADD COLUMN supplier_state TEXT NOT NULL DEFAULT '';
ALTER TABLE billing_usage ADD COLUMN customer_state TEXT NOT NULL DEFAULT '';
ALTER TABLE billing_usage ADD COLUMN tax_kind TEXT NOT NULL DEFAULT 'unallocated';
ALTER TABLE billing_usage ADD COLUMN cgst INTEGER NOT NULL DEFAULT 0;
ALTER TABLE billing_usage ADD COLUMN sgst INTEGER NOT NULL DEFAULT 0;
ALTER TABLE billing_usage ADD COLUMN igst INTEGER NOT NULL DEFAULT 0;
INSERT INTO customer_migrations VALUES(2,strftime('%s','now'));
