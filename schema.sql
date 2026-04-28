-- Cloudflare D1 schema for the optic-fiber interest registration.
-- Run once to initialise the database:
--   wrangler d1 execute fibra-optica-db --file=schema.sql

CREATE TABLE IF NOT EXISTS registrations (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre            TEXT    NOT NULL,
    email             TEXT    NOT NULL,
    cru               TEXT    NOT NULL UNIQUE,
    unsubscribe_token TEXT    NOT NULL UNIQUE,
    unsubscribed_at   TEXT,
    created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_registrations_cru       ON registrations(cru);
CREATE INDEX IF NOT EXISTS idx_registrations_created_at ON registrations(created_at);
CREATE INDEX IF NOT EXISTS idx_registrations_token      ON registrations(unsubscribe_token);
