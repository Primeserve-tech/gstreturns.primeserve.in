CREATE TABLE refund_requests (
 id TEXT PRIMARY KEY, customer_id TEXT NOT NULL REFERENCES customers(id), request_key TEXT NOT NULL UNIQUE,
 requested INTEGER NOT NULL CHECK(requested>0), approved INTEGER NOT NULL DEFAULT 0 CHECK(approved>=0 AND approved<=requested),
 destination TEXT NOT NULL, reason TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','paid')),
 email_received INTEGER NOT NULL DEFAULT 0, review_note TEXT NOT NULL DEFAULT '', payment_reference TEXT UNIQUE,
 created INTEGER NOT NULL, reviewed INTEGER, paid INTEGER
);
CREATE INDEX refunds_customer ON refund_requests(customer_id,created);
INSERT INTO customer_migrations VALUES(6,strftime('%s','now'));
