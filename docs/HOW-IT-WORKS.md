# How tradequote works

Read this before an interview. It explains the project in plain words, in the order a request travels through the code.

## The idea

A buyer wants prices for some products. A vendor sells those products. The buyer asks for a quote, the vendor puts prices on it, and the buyer says yes or no. This is the flow behind most B2B ordering systems.

Two kinds of users: **vendor** and **buyer**. The kind is saved in `users.role`.

## Where things live

| Folder / file | What it does |
|---|---|
| `routes/api.php` | All URLs. Groups show which role may use which URL. |
| `app/Http/Controllers/` | One controller per area: Auth, Product, ProductImport, Quote. |
| `app/Http/Requests/` | Validation rules, one class per form. |
| `app/Policies/` | "Is this record yours?" checks. |
| `app/Http/Middleware/EnsureRole.php` | "Is this the right kind of user for this URL?" check. |
| `app/Jobs/ImportProductsJob.php` | Reads a CSV file in the background. |
| `app/Enums/` | `Role` and `QuoteStatus`, so we don't pass loose strings around. |
| `app/Notifications/` | The two emails (queued). |
| `tests/Feature/` | Tests that call the real URLs. |

## Follow one request: a vendor edits a product

1. `PUT /api/products/5` arrives.
2. Sanctum checks the token and finds the user (`auth:sanctum`).
3. `EnsureRole` checks the user is a vendor. A buyer gets 403 here.
4. Laravel loads Product 5 from the URL (route model binding).
5. `UpdateProductRequest` validates the input. SKU must stay unique *for that vendor only*.
6. The controller calls `authorize('update', $product)`. `ProductPolicy` checks that the product's `vendor_id` is the user's vendor. Another vendor gets 403.
7. The product is updated and returned through `ProductResource`.

Two checks, two jobs: **middleware = type of user, policy = ownership.** Say that in the interview.

## The CSV import

1. Vendor uploads a file to `POST /api/products/import`.
2. The controller saves the file, creates a `product_imports` row (status `pending`) and puts `ImportProductsJob` on the queue. It answers **202 Accepted** at once.
3. A worker (`php artisan queue:work`) runs the job. It reads the file one line at a time, validates each row and saves good rows with `updateOrCreate` (same SKU = update, new SKU = insert).
4. Bad rows are not saved. We store their line number and messages (max 100) in `errors`.
5. The vendor polls `GET /api/product-imports/{id}` and sees `completed`, how many rows worked and which lines failed.

Why a queue? A big file would make the request time out. Why row by row? One bad row shouldn't throw away the other 4,999.

## The quote flow

```
requested -> quoted -> accepted
   |           |-> rejected (buyer says no)
   |-> declined (vendor says no)
   '-> cancelled (buyer gives up; allowed while requested or quoted)
```

Each move is its own URL (`/respond`, `/accept`, ...). Each one checks (a) who you are and (b) what status the quote is in now. Wrong status gives **409 Conflict**. There is no "set any status" URL, on purpose.

Details worth knowing:
- Quote items copy the product's name, SKU and unit when the quote is created. If the vendor renames a product later, old quotes stay the same.
- When the vendor responds, the total is `sum(quantity x unit_price)`, calculated on the server.
- A quote can't be accepted after `valid_until`.
- All the writes in "create quote" and "respond" are inside `DB::transaction`, so we never end up with half a quote.

## Questions you may get

**Why Sanctum and not Passport?** Sanctum is enough for simple API tokens. Passport is full OAuth2, which we don't need.

**How do you stop vendor A editing vendor B's product?** The policy compares `vendor_id`. There is a test for it, and I checked that the test fails if I remove the `authorize` line.

**What if the import job crashes halfway?** The `failed()` method marks the import as failed with the error message. Rows already saved stay saved, and uploading the file again is safe because rows are upserted by SKU.

**How would you scale the import?** Split the file into chunks and dispatch one job per chunk, or use a batch (`Bus::batch`). Then add a progress counter.

**Why an enum for status?** A typo like `'acepted'` becomes impossible, and the allowed values live in one place.

**What would you add next?** Order creation from an accepted quote, PDF export of the quote, rate limiting on imports, and an index on `quotes.status` if the table gets big.

## Run it and try it yourself

See the README. Then, in this order, try: register a vendor, import `docs/sample-products.csv`, register a buyer, request a quote, respond as the vendor, accept as the buyer. Do it once with `curl` and once through the tests (`php artisan test --filter=QuoteTest`) and read each test name. The names describe the rules.
