CREATE TABLE customer_consents(customer_id TEXT NOT NULL REFERENCES customers(id),purpose TEXT NOT NULL,version TEXT NOT NULL,wording TEXT NOT NULL,granted INTEGER NOT NULL,withdrawn INTEGER,PRIMARY KEY(customer_id,purpose));
CREATE TABLE consent_events(id TEXT PRIMARY KEY,customer_id TEXT NOT NULL REFERENCES customers(id),purpose TEXT NOT NULL,version TEXT NOT NULL,wording TEXT NOT NULL,action TEXT NOT NULL CHECK(action IN ('granted','withdrawn')),created INTEGER NOT NULL);
INSERT INTO customer_migrations VALUES(9,strftime('%s','now'));
