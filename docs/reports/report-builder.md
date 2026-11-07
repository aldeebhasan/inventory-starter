# Report Builder

**Navigation Group:** Report Builder → Reports (`/admin/report-builder/reports`)
**Purpose:** Users build their own reports over catalog, sales, purchase and inventory data without code, then preview, download or export them. Built on `aldeebhasan/panorama` `^0.5`.

**Do not:** Hand-write a Filament page for a one-off report a user could build here. Do not make a dataset user-scoped: preview rows are cached by report and parameters, not by user.

## Pieces

| Piece | File | Notes |
|---|---|---|
| Datasets (23) | `app/Reports/Datasets/*` | Discovered from `config/panorama.php`. One per table, all extend `App\Reports\TableDataset` |
| Dataset base | `app/Reports/TableDataset.php` | Lives outside `Datasets/` because discovery registers every `Dataset` subclass there. Reads with `lazyById(1000)` |
| Definition model | `App\Models\ReportDefinition` (`report_definitions`) | `definition` JSON, `is_shared`, soft deletes, `visibleTo()` scope, `runs()` |
| Builder metadata + validation | `App\Reports\ReportSchema` | Scope, field/relation/parameter options, `errors()`, `normalize()` |
| Panorama report | `App\Reports\DefinedReport` | Extends `Report` (Panorama caches only `Report` instances). `$cacheFor = 300` |
| Export job | `App\Jobs\RunDefinedReportJob` | XLSX/HTML. Panorama's `RunReport` can't rebuild a DB-defined report |
| Download route | `filament.admin.report-builder.download` → `DownloadReportRunController` | Stored file; only for the run's own user |
| Resource | `app/Filament/Resources/ReportDefinitions/` | List + the visual builder (`Pages/BuildReport`, `Pages/CreateReport`) + runs widget |
| Builder edits | `App\Reports\DefinitionEditor` | Add/remove dimensions and metrics, auto-join, prune joins, buckets, filters, sort, date range |
| Builder canvas data | `App\Reports\ReportPreview` | First 500 rows, totals, chart series, KPIs, previous period |
| Date presets | `App\Reports\DateRange` | Presets → `from` / `to`, and the previous period |
| Chart | `resources/js/report-builder.js` (Chart.js, Alpine `reportChart`) | Loaded only on the builder pages by a `HEAD_END` render hook; needs `npm run build` |
| Policy | `App\Policies\ReportDefinitionPolicy` | View if owner or shared; update/delete/restore owner only |

## Datasets

- **Master data** (no date parameter): `products`, `categories`, `product_categories` (pivot), `brands`, `units`, `addons`, `customers`, `suppliers`, `locations` (`type` read from `meta`).
- **Transactional** (accept `from` / `to`, pushed down to `created_at`): `sale_orders`, `sale_order_items`, `purchase_orders`, `purchase_order_items`, `return_orders`, `return_order_items`, `transfer_orders`, `transfer_order_items`, `stock_adjustments`, `stock_adjustment_items`, `stock_movements`, `stock_transactions`, `reservations`, `stocks` (stocks has no date parameter).
- Soft-deletable tables include deleted rows with an `is_deleted` bool.
- Every date/datetime field has time buckets `{field}__day|week|month|year` (strings: `2026-01-05`, `2026-W02`, `2026-01`, `2026`) computed in PHP as rows stream (`TableDataset::bucketed()`), so grouping by month works the same on MariaDB and SQLite. Datasets declare their own fields in `fields()`; `schema()` adds the buckets.
- Stock tables keep `Product` rows only and expose `stockable_id` as `product_id`.
- Polymorphic links become typed, joinable ids, filled only when the type matches:
  - `stock_transactions.causable` → `purchase_order_id`, `sale_order_id`, `return_order_id`, `stock_adjustment_id`, `transfer_order_id`
  - `stock_movements.reference` → `*_order_item_id` / `stock_adjustment_item_id`
  - `reservations.reference` → `sale_order_item_id`
- Relations come from `references()`; `php artisan datasets:list` shows them all.

## Definition JSON

```json
{
  "base": "sale_order_items",
  "joins": [{"relation": "sale_order_items.product_id>products.id", "alias": "products", "type": "left"}],
  "columns": [{"field": "products.name", "label": "Product", "format": "text", "total": false}],
  "filters": [{"field": "sale_orders.status", "operator": "in", "value": "confirmed,shipped"}],
  "parameters": ["from", "to"],
  "group_by": ["products.name"],
  "aggregates": [{"fn": "sum", "field": "sale_order_items.quantity", "as": "qty"}],
  "having": [{"column": "qty", "operator": ">", "value": "1"}],
  "order_by": [{"column": "qty", "direction": "desc"}],
  "limit": null,
  "visualization": "bar",
  "date_range": {"preset": "last_30_days", "from": null, "to": null, "compare": true}
}
```

- **`relation`** is `localAlias.field>dataset.field`, in either direction. Keys are always passed explicitly, so a dataset with two relations to the same target never hits Panorama's "ambiguous relation" error.
- **`alias`** must be unique. The same dataset can be joined twice under different aliases (e.g. a transfer's from and to locations, which needs Panorama ≥ 0.5).
- **Output names:** a selected or grouped field `alias.field` is output as `alias__field`; aggregates use their `as`.
- **Filter values** are cast to the field type. `in` / `not in` / `between` take comma-separated values.
- **`visualization`:** `table`, `bar`, `horizontal_bar`, `line`, `area`, `pie`, `donut` or `kpi`. Charts need a dimension and a metric; `kpi` needs metrics only.
- **`date_range`:** a `DateRange` preset (or `custom` with `from` / `to`). A bounded range on dated data sets `parameters` to `from` / `to`; `all_time` clears them.
- **Formats:** `text`, `integer`, `number` (2 decimals), `money` (USD, the project currency), `percent`, `date`, `datetime`.

## Builder UI

One full-width page for new and saved reports (`/create`, `/{record}`). Edits apply to the definition in memory; **Save** stores it.

- **Start:** choose a dataset from cards grouped by Sales / Purchase / Inventory / Catalog.
- **Field list** (left, searchable): the base dataset, every joined alias, then each dataset one relation away (`Products · via sale_order_items.product_id`).
  - Clicking a number adds it as a metric (sum); clicking anything else adds it as a dimension. Hover buttons add either way: a non-number becomes a *count distinct* metric.
  - A field from a related dataset adds a left join automatically. A second join to the same dataset gets an alias like `products_2`. A join nothing uses any more is removed, unless a later join goes through it.
  - Date fields come in by day; their chip switches between exact, day, week, month and year.
- **Dimensions and metrics:** with no metrics the report lists rows (`columns`); with metrics it groups by the dimensions (`group_by`).
  - Chips rename and reformat columns, and switch a metric's function.
  - Metric labels and formats are inferred: `Total quantity`, money for price/cost/amount/total, integer for counts.
- **Toolbar:**
  - Report name, visualization switcher, date preset with custom range and "compare to previous period".
  - Filter chips: a filter on a metric of a grouped report becomes a `having` condition.
  - Top N.
- **Canvas:**
  - KPI cards, with the % change against the previous period.
  - Chart.js chart: the previous period is drawn as a dashed series for the first metric.
  - Table: sortable headers, totals row.
  - Shows the first 500 rows (`ReportPreview::ROW_LIMIT`). Totals are dropped when rows are cut off.
  - Preview rows are cached 5 minutes under `preview-{md5(definition)}`, apart from the full result.
- **Shared reports:** others can open and explore them. Save, Settings and Delete show only to the owner.
- **Export menu** (saved reports only, and only with no unsaved changes): CSV/JSON stream, XLSX/HTML queue `RunDefinedReportJob`, using the report's date range.
- **Runs widget** below: history across edits, with download links on your own stored runs.
- **Stale definitions** show "This report needs editing" with the reasons, and are not run.

## Caching and keys

`ReportDefinition::reportKey()` is `defined-{id}-{updated_at}`. Any edit changes the key, so rows cached for an older definition are never served, whatever parameters they were cached under. History is grouped by the `defined-{id}-` prefix.

## Security

- The resource query is `visibleTo(user)`, so other users' private reports 404.
- `DefinedReport::authorize()` re-checks visibility on every run.
- The job fails without retrying if the report was deleted or unshared while it waited.
- The download route checks the run's `user_id`.

## Scheduling

`reports:prune` runs daily (`routes/console.php`) and keeps 90 days of `report_runs`. History is enabled by `PANORAMA_HISTORY=true` (`.env`, `.env.example`, `phpunit.xml`); the config default is `false`.

## Tests

`tests/Feature/ReportBuilder/`: `DatasetsTest`, `DefinedReportTest`, `ReportBuilderEngineTest` (date ranges, editor, preview), `RunDefinedReportJobTest`, `ReportDefinitionResourceTest` (list, builder page, runs widget).
