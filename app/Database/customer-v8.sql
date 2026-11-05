ALTER TABLE customer_prices ADD COLUMN pricing_mode TEXT NOT NULL DEFAULT 'standard' CHECK(pricing_mode IN ('standard','special'));
-- Preserve explicit Admin assignments, including special rates equal to a default.
UPDATE customer_prices SET pricing_mode='special' WHERE EXISTS (
 SELECT 1 FROM customer_audit a WHERE a.action='client-price-changed' AND a.subject=customer_prices.customer_id AND json_extract(a.detail,'$.service')=customer_prices.service
) OR net != COALESCE((SELECT CAST(value AS INTEGER) FROM customer_settings WHERE name='price_'||customer_prices.service),net);
INSERT INTO customer_migrations VALUES(8,strftime('%s','now'));
