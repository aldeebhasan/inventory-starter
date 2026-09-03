# Stock Reservations

Read-only page showing all stock reservations from the `inventorix_reservations` table.

## Location

`app/Filament/Pages/StockReservations.php`

## Features

- Lists all reservations with product, location, quantity, status, note, expiry, and date
- Filters: Product, Location, Status (Pending/Fulfilled/Released), Date Range
- Default sort: newest first
- Navigation: Inventory group, icon: `OutlinedLockClosed`

## Model

Uses `Aldeebhasan\Inventorix\Models\Reservation` directly (package model, no app-level extension).

## Statuses

| Status | Color | Meaning |
|---|---|---|
| Pending | warning | Stock is reserved, awaiting fulfillment |
| Fulfilled | success | Reservation was fulfilled (stock deducted) |
| Released | gray | Reservation was cancelled/released |
