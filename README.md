# Inventory Starter

[![Tests](https://github.com/aldeebhasan/inventory-starter/actions/workflows/run-tests.yml/badge.svg)](https://github.com/aldeebhasan/inventory-starter/actions/workflows/run-tests.yml)
[![PHPStan](https://github.com/aldeebhasan/inventory-starter/actions/workflows/phpstan.yml/badge.svg)](https://github.com/aldeebhasan/inventory-starter/actions/workflows/phpstan.yml)

An inventory management admin panel built with **Laravel 13** and **Filament 5**. It covers the full stock lifecycle — catalog, purchasing, sales, returns, transfers and adjustments across multiple warehouses — plus 13 reports you can filter and export.

All stock math (adding, deducting, reserving, transferring, lot and cost tracking) is handled by the [`aldeebhasan/inventorix`](https://github.com/aldeebhasan/inventorix) package. This app owns the business documents, their workflows and the UI.

## Features

### Catalog
Products (inventory and non-inventory), categories, brands, add-ons, and units of measure with conversion factors (for example, 1 ton = 1000 kg). Products link to suppliers with a per-supplier unit cost.

### Documents and workflows
Every document has a status workflow. Status changes are buttons on the document's view page, and each change is logged in a status history with an optional reason.

| Document | Workflow | Stock effect |
|---|---|---|
| **Purchase Order** | Draft → Confirmed → Received | Stock is added on Receive, at the line's unit cost |
| **Sale Order** | Draft → Confirmed → Picked → Shipped → Fulfilled | Stock is reserved on Confirm and deducted on Ship |
| **Refunded Order** (customer return) | Draft → Completed | Stock is added back on Complete, at product cost |
| **Supplier Return** | Draft → Approved → Completed | Stock is deducted on Complete, after checking available stock |
| **Stock Adjustment** | Draft → Processing → Applied / Partially Applied | Applied in background jobs, one item at a time, with retry for failed items |
| **Transfer Order** | Draft → Confirmed → Sending → In Transit → Receiving → Completed | Two-phase send and receive, run in background jobs |

Cancelling is allowed before stock leaves for good: purchase orders until received, sale orders until shipped (releasing their reservations), transfers until sending starts, and adjustments while in draft. Refunds and supplier returns can be cancelled even after completion, which reverses their stock. Multi-line stock operations are atomic: if one line fails, none are applied. Line quantities in a derived unit are converted to the product's base unit.

Refunds can start from a shipped sale order and supplier returns from a received purchase order, with lines, prices and units pre-filled from the original order. Each sale and purchase order shows the refunds or returns raised against it.

### Reports
Reports sit under a collapsible **Reports** item in each module's sidebar section. Every report has a **CSV / Excel export** that runs in the background and notifies you when the file is ready.

| Module | Reports |
|---|---|
| **Sales** | Sales Summary, Customer Returns Analysis |
| **Purchase** | Reorder Suggestions, Purchase Cost Analysis, Supplier Performance |
| **Inventory** | Low Stock Alerts, Inventory Valuation, Stock Velocity, Product Movement Summary, Dead Stock, Overstock Alerts, Expiring Stock, ABC Analysis |

The **Inventory** section also has Stocks, Stock Movements and Stock Reservations pages. The dashboard shows stock stats and recent movements.

Reports are built for large catalogs: each page computes its figures in SQL for the rows shown, and tests check that a page's query count does not grow with its row count.

## Tech stack

- PHP 8.3+, Laravel 13, Filament 5, Livewire 4, Tailwind CSS 4
- [`aldeebhasan/inventorix`](https://github.com/aldeebhasan/inventorix) for stock operations
- Pest 4, PHPStan (Larastan), Laravel Pint

## Requirements

- PHP 8.3 or newer
- Composer
- Node.js and npm
- MySQL / MariaDB (the default in `.env.example`) or SQLite

## Installation

```bash
git clone https://github.com/aldeebhasan/inventory-starter.git
cd inventory-starter
```

Set your database connection in `.env` (copy it from `.env.example` first if you want to edit it before setup), then run:

```bash
composer setup
```

This installs PHP and npm dependencies, creates `.env` if it is missing, generates the app key, runs the migrations and builds the frontend assets.

Optionally, load demo data (brands, categories, units, warehouses and products):

```bash
php artisan db:seed --class=DemoDataSeeder
```

## Running locally

```bash
composer dev
```

This starts the web server, a queue worker, the log viewer and Vite together. Open [http://localhost:8000/admin](http://localhost:8000/admin) and register an account from the login page. If you use [Laravel Herd](https://herd.laravel.com), the app is served at `https://invenotry-starter.test/admin` instead (the project folder name), and you only need `npm run dev` plus a queue worker.

The **queue worker matters**: stock adjustments, transfers and report exports run as background jobs. With `QUEUE_CONNECTION=sync` they run inside the web request instead, which is fine for small data but can time out on large exports.

## Production

On top of a standard Laravel deployment:

1. **Migrations:** `php artisan migrate --force`
2. **Queue worker:** keep `php artisan queue:work` running (for example with Supervisor). Without it, adjustments, transfers and exports never finish.
3. **Scheduler:** add the cron entry `* * * * * php /path/to/artisan schedule:run`. It runs `exports:prune` daily, which deletes report exports older than 7 days and their files. You can also run `php artisan exports:prune --days=N` by hand.
4. **Assets:** `npm run build`

## Testing and code quality

```bash
composer test          # Pest test suite
composer analyse       # PHPStan (Larastan)
composer format        # Laravel Pint
```

Tests run against an in-memory SQLite database. CI runs the suite on PHP 8.3 and 8.4 with Laravel 13, using both the lowest and the latest allowed dependency versions, plus PHPStan.

## Project structure

```
app/
  Concerns/TracksStatus.php      status history for every document
  Enums/                         document statuses and types
  Filament/
    Resources/{Model}/           Pages, Schemas (forms, infolists), Tables, Actions (workflow steps)
    Pages/                       report and stock pages
    Exports/                     one exporter per report
    Widgets/                     dashboard widgets
  Jobs/                          queued adjustment and transfer processing
  Models/                        Location extends the inventorix Location model
docs/                            per-module design docs
tests/Feature/{Group}/           Pest tests, grouped by module
```

## Documentation

Each module has a design doc in [`docs/`](docs) that describes its models, workflow, actions and tests:

- [Catalog](docs/catalog.md)
- Sales: [Customers](docs/sales/customers.md), [Sale Orders](docs/sales/sale-orders.md), [Refunded Orders](docs/sales/customer-returns.md)
- Purchase: [Suppliers](docs/purchase/suppliers.md), [Purchase Orders](docs/purchase/purchase-orders.md), [Supplier Returns](docs/purchase/supplier-returns.md)
- Inventory: [Locations](docs/inventory/locations.md), [Transfer Orders](docs/inventory/transfer-orders.md), [Adjustment Orders](docs/inventory/adjustment-orders.md), [Stocks](docs/inventory/inventories.md), [Stock Reservations](docs/inventory/stock-reservations.md)
- [Reports roadmap and rules](docs/reports-roadmap.md) — links to every report's doc
- [Status tracking](docs/status-tracking.md), [Dashboard widgets](docs/dashboard/widgets.md)

## License

Released under the [MIT license](LICENSE.md).
