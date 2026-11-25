# Apartment Maintenance and Complaint Management System

ITT626 Back-End Technology project. Laravel 12 JSON API under `/api/v1` and a React 19 + TypeScript SPA served from the same origin (see `design-document.md`, `GLOSSARY.md` and `docs/adr/`).

## Requirements

PHP 8.4 (with `pdo_mysql`), Composer, MariaDB 11.8, Node.js current LTS (`nvm use` reads `.nvmrc`; Vite 7 will not run on old Node), and [Mailpit](https://mailpit.axllent.org/) for local email.

## Setup

```bash
# create the databases once (as a MariaDB admin)
mysql -uroot -p -e "CREATE DATABASE itt626; CREATE DATABASE itt626_test;
  CREATE USER 'itt626'@'localhost' IDENTIFIED BY 'itt626';
  GRANT ALL ON itt626.* TO 'itt626'@'localhost'; GRANT ALL ON itt626_test.* TO 'itt626'@'localhost';"

composer setup   # install, .env, key, migrate + reference seed, build the SPA
composer demo    # local only: one account per role
composer dev     # server, queue worker, logs and Vite
```

Run `mailpit` in another terminal to catch outgoing email; its inbox is at <http://127.0.0.1:8025>. Password links are sent through the queue, so `composer dev` (which runs the queue worker) must be running too. Don't switch `MAIL_MAILER` to `log`: it would write password links into `storage/logs` in plain text.

Open <http://127.0.0.1:8000>. Demo accounts (password `demo-password`): `resident@demo.test`, `manager@demo.test`, `technician@demo.test`, `administrator@demo.test`.

`php artisan migrate:fresh --seed` rebuilds the database with reference data only (the Property and the four roles). It creates no accounts and no passwords.

## Tests

```bash
composer test          # Pest feature tests against the itt626_test database
npm run typecheck      # TypeScript
```

## API conventions

- Same-origin session cookies via Sanctum. Load the SPA (or `GET /sanctum/csrf-cookie`) first, then send `X-XSRF-TOKEN` on state-changing requests.
- Errors: `{"message": "...", "errors": {...}}` with 401, 403, 404, 409, 422 (`"Validation failed"`), 419 (CSRF) and 429.
- Deny by default: every signed-in route declares its allowed roles with the `role:` middleware, and a test fails if one is missing.
