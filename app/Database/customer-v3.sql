ALTER TABLE recharge_requests ADD COLUMN payee_upi TEXT NOT NULL DEFAULT '';
ALTER TABLE recharge_requests ADD COLUMN payee_name TEXT NOT NULL DEFAULT '';
INSERT OR IGNORE INTO customer_settings(name,value) VALUES('payment_notification_email','finance@primeserve.in');
CREATE TABLE payment_notifications(id TEXT PRIMARY KEY,recharge_id TEXT NOT NULL REFERENCES recharge_requests(id),kind TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'queued',created INTEGER NOT NULL,updated INTEGER NOT NULL,UNIQUE(recharge_id,kind));
INSERT INTO customer_migrations VALUES(3,strftime('%s','now'));
