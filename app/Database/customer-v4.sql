CREATE TABLE customer_balance_reminders(customer_id TEXT PRIMARY KEY REFERENCES customers(id),status TEXT NOT NULL,updated INTEGER NOT NULL);
INSERT INTO customer_migrations VALUES(4,strftime('%s','now'));
