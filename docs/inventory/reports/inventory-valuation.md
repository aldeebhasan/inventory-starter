# Inventory Valuation

**Navigation Group:** Inventory
**Navigation Sort:** 9
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/InventoryValuation.php`
**Icon:** `Heroicon::OutlinedCurrencyDollar`

## Purpose

Shows the total monetary value of inventory on hand, broken down by product and location. Used by finance for balance sheet reporting, insurance documentation, and management decision-making.

## Stats Header

Display stat cards above the table using `getHeaderWidgets()`:

| Stat | Value | Notes |
|---|---|---|
| Total Inventory Value | `Inventorix::totalValuation()` | formatted as currency |
| Products Tracked | count of distinct stockable_id in stocks | |
| Locations | count of distinct location_id in stocks | |

## Filters

| Filter | Type | Notes |
|---|---|---|
| Location | Select | optional, from `Location::all()` |
| Category | Select | optional, filter products by category |
| Brand | Select | optional, filter products by brand |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Brand | products.brand.name | |
| Category | products.categories | first category name |
| Location | inventorix_locations.name | |
| Qty On Hand | inventorix_stocks.quantity | |
| Available | quantity - reserved_quantity | computed |
| Avg Cost/Unit | from costed Add movements | see data source |
| Total Value | available x avg_cost | computed, numeric(2) |
| % of Total | row_value / grand_total x 100 | computed, suffix `%` |

## Data Source

Two approaches depending on whether the system uses lot-costing or product-level cost:

**Option A — Product cost (simpler, uses `products.cost`):**
```php
Stock::query()
    ->with(['stockable.brand', 'stockable.categories', 'location'])
    ->whereHasMorph('stockable', [Product::class])
    ->where('quantity', '>', 0)
```
Value = `(quantity - reserved_quantity) * product.cost`

**Option B — Movement-based costing (uses cost_per_unit from Add movements):**
```php
Movement::query()
    ->where('type', MovementType::Add)
    ->whereNotNull('cost_per_unit')
    ->selectRaw('stockable_id, location_id, SUM((quantity - COALESCE(consumed_quantity, 0)) * cost_per_unit) as total_value, SUM(quantity - COALESCE(consumed_quantity, 0)) as remaining_qty')
    ->groupBy('stockable_id', 'location_id')
    ->having('remaining_qty', '>', 0)
```

Use Option A initially (product.cost). Option B is available via `Inventorix::totalValuation()` if lot-level costing is active.

## Header Actions

**Export CSV** — exports the current filtered table data to a CSV file.

## Performance

- Single query with joins; no per-row computations
- Paginate 25 rows default
- Grand total for `% of Total` can be computed once via a subquery or cached stat
