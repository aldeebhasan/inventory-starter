# Stock Reservations

Read-only page showing all stock reservations from the `inventorix_reservations` table.

## Location

`app/Filament/Pages/StockReservations.php`

## Features

- Lists all reservations with product, location, quantity, status, note, expiry, and date
- Filters: Product, Location, Status (Pending/Fulfilled/Released), Date Range
- Product column sortable via a `products.name` subquery (the default morphTo relationship sort produced invalid SQL)
- Default sort: newest first
- Export: `ExportAction` in the table header with `App\Filament\Exports\StockReservationsExporter` (CSV/XLSX, queued). Columns: Product, Location, Qty, Status, Note, Expires At, Date. `modifyQuery()` eager-loads `stockable` and `location`.
- Navigation: Inventory group, icon: `OutlinedLockClosed`

## Model

Uses `Aldeebhasan\Inventorix\Models\Reservation` directly (package model, no app-level extension).

## Statuses

| Status | Color | Meaning |
|---|---|---|
| Pending | warning | Stock is reserved, awaiting fulfillment |
| Fulfilled | success | Reservation was fulfilled (stock deducted) |
| Released | gray | Reservation was cancelled/released |

## Data Loading

`Reservation::query()->with(['stockable', 'location', 'reference'])->whereHasMorph('stockable', [Product::class])` — no per-row queries (constant query count per page).

## Tests

`tests/Feature/Inventory/StockReservationsPageTest.php` — render, listing, filters (product, location, status, date range), product-name sort, query growth, export (fake dispatch, row values, real CSV).
