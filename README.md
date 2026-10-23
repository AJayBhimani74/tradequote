# tradequote

A small REST API for B2B quotations, built with Laravel 12 and Sanctum. Vendors keep a product catalogue (bulk-loaded from CSV by a queued job), buyers send them requests for quotation, vendors price the request, and the buyer accepts or rejects.

## What it does

- **Token auth** with two roles, `vendor` and `buyer`. Registering as a vendor creates a company profile.
- **Products**: vendors manage their own catalogue (SKU unique per vendor). Buyers browse active products with search and vendor filter.
- **CSV import**: `POST /api/products/import` returns `202` straight away and a queued job does the work. Rows are validated one by one, valid rows are upserted by SKU, bad rows are reported with their line number. Poll the import to see the result.
- **Quotes**: a small state machine, enforced in one controller and covered by tests.

```
requested ──respond──▶ quoted ──accept──▶ accepted
    │  │                  │
    │  └─decline─▶ declined   └─reject─▶ rejected
    └──────cancel / cancel────▶ cancelled
```

Invalid transitions return `409`. A quoted offer can't be accepted after its `valid_until` date. Item names and SKUs are copied onto the quote, so later catalogue edits don't change an existing quotation. The other party is notified by a queued mail notification.

## Endpoints

| Method | Path | Who |
|---|---|---|
| POST | `/api/register`, `/api/login` | anyone |
| GET | `/api/me`, POST `/api/logout` | signed in |
| GET | `/api/products?q=&vendor_id=`, `/api/products/{id}` | signed in |
| GET | `/api/my/products` | vendor |
| POST/PUT/DELETE | `/api/products`, `/api/products/{id}` | vendor (own products) |
| POST | `/api/products/import` (multipart `file`) | vendor |
| GET | `/api/product-imports/{id}` | vendor (own imports) |
| GET | `/api/quotes?status=`, `/api/quotes/{id}` | buyer / vendor involved |
| POST | `/api/quotes` | buyer |
| POST | `/api/quotes/{id}/accept`, `/reject`, `/cancel` | buyer |
| POST | `/api/quotes/{id}/respond`, `/decline` | vendor |

CSV format (see `docs/sample-products.csv`): header `sku,name,description,unit,price,stock`. Only `sku`, `name` and `price` are required.

## Running it

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed      # demo users, password is "password"
php artisan serve               # API on :8000
php artisan queue:work          # needed for CSV imports and mail
```

Seeded accounts: `buyer@example.com`, `vendor1@example.com`, `vendor2@example.com`.

```bash
TOKEN=$(curl -s -X POST localhost:8000/api/login -H 'Accept: application/json' \
  -d email=vendor1@example.com -d password=password | jq -r .token)

curl -s -X POST localhost:8000/api/products/import -H 'Accept: application/json' \
  -H "Authorization: Bearer $TOKEN" -F file=@docs/sample-products.csv
# {"id":1,"status":"pending"}   ...worker runs...   GET /api/product-imports/1
# {"status":"completed","total_rows":6,"imported_rows":4,"failed_rows":2,"errors":[{"row":5,...},{"row":6,...}]}
```

## Tests

```bash
php artisan test
```

Feature tests run on in-memory SQLite and cover auth, ownership rules, the import (including bad rows and cross-vendor SKUs) and every quote transition. CI runs Pint and the suite on PHP 8.2 and 8.3.
