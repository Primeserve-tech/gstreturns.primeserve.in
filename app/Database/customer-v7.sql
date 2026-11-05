ALTER TABLE customers ADD COLUMN access_reason TEXT NOT NULL DEFAULT '';
UPDATE customers SET access_reason=COALESCE((SELECT json_extract(detail,'$.reason') FROM customer_audit WHERE subject=customers.id AND action='customer-blocked' ORDER BY created DESC,rowid DESC LIMIT 1),'Please contact support@primeserve.in for assistance.') WHERE enabled=0;
INSERT INTO customer_migrations VALUES(7,strftime('%s','now'));
