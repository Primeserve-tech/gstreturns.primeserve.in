ALTER TABLE billing_usage ADD COLUMN gstin TEXT NOT NULL DEFAULT '';
ALTER TABLE billing_usage ADD COLUMN return_type TEXT NOT NULL DEFAULT '';
ALTER TABLE billing_usage ADD COLUMN period TEXT NOT NULL DEFAULT '';
UPDATE billing_usage SET
 gstin=COALESCE((SELECT j.gstin FROM jobs j WHERE billing_usage.request_key='return:'||j.id),''),
 return_type=COALESCE((SELECT j.return_type FROM jobs j WHERE billing_usage.request_key='return:'||j.id),''),
 period=COALESCE((SELECT j.period FROM jobs j WHERE billing_usage.request_key='return:'||j.id),'')
 WHERE service='returns';
INSERT INTO customer_migrations VALUES(5,strftime('%s','now'));
