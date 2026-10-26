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
| Product | products.name | searchable; sortable via a `products.name` subquery |
| Brand | products.brand.name | |
| Category | products.categories | first category name |
| Location | inventorix_locations.name | |
| Qty On Hand | inventorix_stocks.quantity | |
| Available | quantity - reserved_quantity | computed |
| Avg Cost/Unit | products.cost | |
| Total Value | available x cost | computed; sortable via SQL expression |
| % of Total | row_value / grand_total x 100 | computed, suffix `%` |

## Data Source

```php
Stock::query()
    ->with(['stockable' => fn (MorphTo $m) => $m->morphWith([Product::class => ['brand', 'categories']]), 'location'])
    ->whereHasMorph('stockable', [Product::class])
    ->where('quantity', '>', 0)
```
Value = `(quantity - reserved_quantity) * product.cost`

### Grand total (`% of Total`)

`InventoryValuation::grandTotal()` is a single SQL aggregate over all product stocks with `quantity > 0` (ignores table filters):

```sql
SELECT SUM((inventorix_stocks.quantity - inventorix_stocks.reserved_quantity) * products.cost)
FROM inventorix_stocks JOIN products ON products.id = inventorix_stocks.stockable_id
WHERE inventorix_stocks.stockable_type = <Product morph class> AND products.deleted_at IS NULL AND inventorix_stocks.quantity > 0
```

It previously loaded every stock row (and lazily each product) into PHP on every render.

## Header Actions

**Export** — `ExportAction` with `App\Filament\Exports\InventoryValuationExporter` (CSV/XLSX, queued). Exports the current filtered/sorted table query. Columns: Product, Brand, Category, Location, Qty On Hand, Available, Avg Cost/Unit, Total Value, % of Total. `modifyQuery()` eager-loads `location` and `stockable` (Product → brand, categories); the grand total is computed once per exporter instance (one aggregate per chunk).

## Performance

- No per-row queries: page query count is constant (6 queries for 2 or 8 rows; was 8 → 12 because the grand total loaded every row)
- Grand total is one SQL `SUM`

## Tests

`tests/Feature/Inventory/InventoryValuationPageTest.php` — render, listing, filters, grand total SQL aggregate (reserved excluded, 1 query), query growth, product-name sort, export (fake dispatch, row values, real CSV).
