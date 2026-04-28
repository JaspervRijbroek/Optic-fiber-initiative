-- Cloudflare D1 schema for the optic-fiber interest registration.
-- Run once to initialise a fresh database:
--   wrangler d1 execute fibra-optica-db --file=schema.sql
--
-- MIGRATION NOTE: if upgrading an existing database that used the old schema
-- (with a `contacto` column and no `unsubscribe_token`), run the following
-- statements manually instead of re-running this file:
--
--   ALTER TABLE registrations RENAME COLUMN contacto TO email;
--   ALTER TABLE registrations ADD COLUMN unsubscribe_token TEXT;
--   ALTER TABLE registrations ADD COLUMN unsubscribed_at TEXT;
--   UPDATE registrations SET unsubscribe_token = lower(hex(randomblob(24))) WHERE unsubscribe_token IS NULL;
--   CREATE UNIQUE INDEX IF NOT EXISTS idx_registrations_token ON registrations(unsubscribe_token);
--   -- Then enforce NOT NULL by recreating the table if required.

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
