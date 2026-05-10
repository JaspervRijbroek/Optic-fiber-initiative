# Iniciativa Fibra Óptica

A Spanish-language sign-up form that lets residents register their interest in getting optic-fiber broadband. The project is built with **Symfony 7** (PHP), stores data in **SQLite** via Doctrine ORM, and sends confirmation emails via **Amazon SES** using Symfony Messenger's async message queue with a rate limit of 150 emails per 24 hours.

## Features

- 🇪🇸 Fully in Spanish
- Fields: **Nombre**, **Correo electrónico**, and either **Referencia Catastral** or **GPS coordinates**
- Duplicate cadastral-reference detection — each cadastral reference can only register once
- Confirmation email dispatched asynchronously via Symfony Messenger → Amazon SES
- Rate limiting: max **150 SES emails per 24 hours** (enforced in the message handler)
- Unsubscribe link in every confirmation email (GDPR-compliant removal)
- Password-protected CSV export endpoint (`/api/export?token=…`)
- Optional Cloudflare Turnstile bot-protection on registration

---

## Project structure

```
├── public/
│   ├── index.php            # Symfony front controller
│   ├── index.html           # Registration form (static)
│   └── info.html            # Information page (static)
├── src/
│   ├── Controller/
│   │   ├── RegistrationController.php   # POST /api/register
│   │   ├── UnsubscribeController.php    # GET  /api/unsubscribe
│   │   └── ExportController.php         # GET  /api/export
│   ├── Entity/
│   │   └── Registration.php
│   ├── Repository/
│   │   └── RegistrationRepository.php
│   ├── Message/
│   │   └── SendConfirmationEmail.php
│   └── MessageHandler/
│       └── SendConfirmationEmailHandler.php
├── templates/
│   ├── email/confirmation.html.twig
│   └── unsubscribe/page.html.twig
├── migrations/              # Doctrine migrations
├── config/
│   └── packages/
│       ├── doctrine.yaml    # SQLite config
│       ├── messenger.yaml   # Async transport routing
│       └── rate_limiter.yaml # 150 emails / 24 h
├── .env                     # Default environment variables
└── README.md
```

---

## Requirements

- PHP 8.2+
- Composer
- SQLite3 extension (usually bundled with PHP)
- An [Amazon SES](https://aws.amazon.com/ses/) account with a verified sender

---

## Installation

```bash
# 1. Install PHP dependencies
composer install

# 2. Copy and configure environment variables
cp .env .env.local
# Edit .env.local with your real values (see below)

# 3. Create the SQLite database and run migrations
php bin/console doctrine:migrations:migrate --no-interaction

# 4. Set up the Messenger transport tables
php bin/console messenger:setup-transports
```

---

## Environment variables

Copy `.env` to `.env.local` and set the following:

| Variable | Description |
|----------|-------------|
| `APP_SECRET` | Random 32-byte hex string — `openssl rand -hex 32` |
| `DATABASE_URL` | SQLite path, e.g. `sqlite:///%kernel.project_dir%/var/data_prod.db` |
| `MAILER_DSN` | Amazon SES DSN, e.g. `ses+smtp://ACCESS_KEY:SECRET_KEY@default?region=eu-west-1` |
| `FROM_EMAIL` | Verified SES sender address, e.g. `noreply@fibra-torrent.es` |
| `FROM_NAME` | Sender display name, e.g. `Fibra Óptica Torrent` |
| `SITE_URL` | Public URL, e.g. `https://fibra-torrent.es` |
| `EXPORT_SECRET` | Secret token to protect `/api/export` — `openssl rand -hex 32` |
| `TURNSTILE_SECRET_KEY` | (Optional) Cloudflare Turnstile secret key for bot protection |

---

## Running the message queue consumer

After registrations are saved, a `SendConfirmationEmail` message is dispatched to the `async` Doctrine transport. Start the consumer to process the queue:

```bash
php bin/console messenger:consume async --time-limit=3600
```

In production, manage this with **Supervisor** or **systemd** so it restarts automatically.

---

## Exporting registrations

```
GET /api/export?token=<EXPORT_SECRET>
```

Returns a UTF-8 CSV (BOM-prefixed for Excel compatibility):

| ID | Nombre | Email | Referencia Catastral | Latitud | Longitud | Fecha de Registro |

---

## Local development

```bash
# Start a local web server
php -S localhost:8000 -t public

# In another terminal, process queued messages
php bin/console messenger:consume async -vv
```

Visit `http://localhost:8000` for the sign-up form.

---

## What is a CRU?

The **CRU** (Código de Referencia Único de Punto de Suministro) is a unique reference code printed on every Spanish electricity or gas bill. It uniquely identifies the supply point (i.e. the address), making it the ideal key to measure per-address interest without collecting sensitive personal data such as a full postal address.
