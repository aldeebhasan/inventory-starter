# Dead Stock Report

**Navigation Group:** Inventory
**Navigation Sort:** 14
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/DeadStockReport.php`
**Icon:** `Heroicon::OutlinedArchiveBoxXMark`

## Purpose

Identifies products with stock on hand but zero or near-zero movement over a configurable period. Candidates for clearance sales, write-offs, or return to supplier. Helps free up capital and warehouse space.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Min Days Inactive | Select | 30 / 60 / 90 / 180 / 365, default 90 |
| Location | Select | optional |
| Category | Select | optional |
| Brand | Select | optional |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Brand | products.brand.name | |
| Location | inventorix_locations.name | |
| Qty On Hand | inventorix_stocks.quantity | |
| Last Movement Date | MAX(movements.created_at) | date format, or `Never` if null |
| Days Since Last Movement | DATEDIFF(now, last_movement) | integer |
| Stock Value | quantity x product.cost | numeric(2), currency |

## Sorting

- Default: Qty On Hand DESC.
- **Days Since Last Movement** is sortable in SQL: it orders by the `last_deduct_at` subquery (older = more days inactive) and treats never-deducted stock (`NULL`) as the most inactive — first when sorting DESC, last when ASC.

## Data Source

`DeadStockReport::getReportQuery(int $minDaysInactive)` (static, shared with the exporter):

```php
Stock::query()
    ->select('inventorix_stocks.*')
    ->addSelect([
        // latest deduction ever for this stockable + location (NULL = never)
        'last_deduct_at' => Movement::query()->whereColumn(...stockable/type/location...)
            ->where('type', MovementType::Deduct)->selectRaw('MAX(created_at)'),
        'brand_name' => Brand name via products.brand_id,
    ])
    ->with(['stockable', 'location'])
    ->whereHasMorph('stockable', [Product::class])
    ->where('quantity', '>', 0)
    ->whereNotExists(/* deduct movement since now() - minDaysInactive */);
```

The Min Days Inactive filter feeds the cutoff (the table query is a closure, so it is rebuilt with the current filter value). Brand is selected as a subquery because a nested eager load through the `stockable` morphTo (`stockable.brand`) cannot be serialized for exports.

## Export

Header action `ExportAction` → `App\Filament\Exports\DeadStockReportExporter` (model `Stock`). It exports the page's filtered/sorted table query, so `last_deduct_at` and `brand_name` come from the same subqueries — no per-row queries.

Columns: Product, Brand, Location, Qty On Hand, Last Movement Date, Days Since Last Movement, Stock Value (`quantity × product.cost`). Formatting helpers (`formatLastMovement`, `formatDaysInactive`, `stockValue`) are static on the page and reused by the exporter.

## Performance

- One paginated query; `last_deduct_at` / `brand_name` are correlated subqueries evaluated only for the returned page (previously a `MAX(created_at)` aggregate over every stockable/location pair was loaded on each render).
- Query count per render is constant (verified by a `countQueries` test).
