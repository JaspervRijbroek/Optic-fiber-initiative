# Iniciativa Fibra Óptica

A simple Spanish-language sign-up form that lets residents register their interest in getting optic-fiber broadband. All registrations are stored in a Cloudflare D1 (SQLite) database and can be exported as a password-protected CSV file to share with the installation company.

## Features

- 🇪🇸 Fully in Spanish
- Fields: **Nombre**, **Correo / Teléfono**, **CRU** (unique identifier per connection point)
- Duplicate CRU detection — each address can only register once
- Thank-you confirmation screen after successful sign-up
- Password-protected CSV export endpoint (`/api/export?token=…`)
- Zero build step — plain HTML + Vanilla JS; deploys directly to **Cloudflare Pages**

---

## Project structure

```
├── index.html               # Sign-up form (Spanish)
├── functions/
│   └── api/
│       ├── register.js      # POST /api/register — save a registration
│       └── export.js        # GET  /api/export   — download CSV (token-protected)
├── schema.sql               # D1 database schema (run once)
├── wrangler.toml            # Cloudflare Pages / D1 configuration
└── README.md
```

---

## Deployment (Cloudflare Pages)

### 1. Prerequisites

- A free [Cloudflare account](https://dash.cloudflare.com/)
- [Wrangler CLI](https://developers.cloudflare.com/workers/wrangler/install-and-update/) installed and logged in (`wrangler login`)

### 2. Create the D1 database

```bash
wrangler d1 create fibra-optica-db
```

Copy the `database_id` from the output and paste it into `wrangler.toml`:

```toml
[[d1_databases]]
binding       = "DB"
database_name = "fibra-optica-db"
database_id   = "PASTE-YOUR-ID-HERE"
```

### 3. Run the schema

```bash
# Against the remote (production) database:
wrangler d1 execute fibra-optica-db --remote --file=schema.sql
```

### 4. Deploy to Cloudflare Pages

Connect this repository to a new **Cloudflare Pages** project in the dashboard, or deploy via CLI:

```bash
wrangler pages deploy . --project-name fibra-optica-iniciativa
```

### 5. Set the export secret

In the Cloudflare Pages dashboard:

> **Settings → Environment variables → Production → Add variable**

| Name | Value |
|------|-------|
| `EXPORT_SECRET` | A long random string (e.g. `openssl rand -hex 32`) |

> ⚠️ Keep this secret private. Anyone with it can download all registrations.

---

## Exporting registrations

Send a GET request to:

```
https://<your-domain>/api/export?token=<EXPORT_SECRET>
```

The response is a UTF-8 CSV file (with BOM for Excel compatibility) with columns:

| ID | Nombre | Contacto | CRU | Fecha de Registro |
|----|--------|----------|-----|-------------------|

You can open it directly in Microsoft Excel or Google Sheets.

---

## Local development

```bash
# Install Wrangler (if not already installed)
npm install -g wrangler

# Initialise a local D1 database and run the schema
wrangler d1 execute fibra-optica-db --local --file=schema.sql

# Start the local dev server (includes Pages Functions)
wrangler pages dev . --d1 DB=fibra-optica-db
```

The site is then available at `http://localhost:8788`.

To test the export locally, set a temporary secret:

```bash
EXPORT_SECRET=test123 wrangler pages dev . --d1 DB=fibra-optica-db
# then visit: http://localhost:8788/api/export?token=test123
```

---

## What is a CRU?

The **CRU** (Código de Referencia Único de Punto de Suministro) is a unique reference code printed on every Spanish electricity or gas bill. It uniquely identifies the supply point (i.e. the address), making it the ideal key to measure per-address interest without collecting sensitive personal data such as a full postal address.

