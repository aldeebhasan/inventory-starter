# Catalog

## Categories

**Status:** Already implemented. Only change needed:

```php
protected static ?string $navigationGroup = 'Catalog';
```

---

## Products

**Status:** Already implemented. Changes needed:
1. Add `$navigationGroup = 'Catalog'`
2. Enhance `ProductInfolist` to display a stock summary per location (sourced from `inventorix_stocks`)

### HasInventory Methods

All inventory operations go through these trait methods — never write raw stock SQL or bypass these:

| Method | Signature |
|---|---|
| Add stock | `$product->addStock(qty, locationId, dto)` |
| Deduct stock | `$product->deductStock(qty, locationId, dto)` |
| Adjust stock | `$product->adjustStock(qty, locationId, dto)` |
| Transfer stock | `$product->transferStock(qty, fromId, toId, dto)` |
| Reserve | `$product->reserve(qty, locationId, dto)` → Reservation |
| Fulfill reservation | `$product->fulfillReservation(reservationId)` |
| Release reservation | `$product->releaseReservation(reservationId)` |
| Stock at location | `$product->stockAt(locationId)` → Stock model |
| Total stock | `$product->totalStock()` → float |
| Available stock | `$product->availableStock(locationId)` → float |
| Low stock check | `$product->isLowStock(locationId)` → bool |
