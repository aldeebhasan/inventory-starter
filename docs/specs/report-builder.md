# Report Builder

**Module:** Reports
**Status:** Implemented — 2026-11-08 · Module doc: `docs/reports/report-builder.md`

## Goal
Let any user build a report across catalog, sales, purchase and inventory data without code: pick datasets, then columns, filters and grouping, then preview, download or queue it. Built on `aldeebhasan/panorama` `^0.5`.

## Scope
- **In:** no-code builder UI, saved report definitions (private or shared), on-screen paginated preview, CSV/JSON streamed downloads, queued XLSX/HTML exports with bell + mail notification, run history per report.
- **Out:** scheduled runs, charts, code-defined Panorama reports, changes to the 14 existing report pages, external sources (APIs/files), roles/permissions.

## Behavior
- New sidebar group **Report Builder** (after Inventory) with one resource: **Reports**.
- **List:** my reports plus the ones others have shared. Search by name; filters for mine / shared. Soft delete/restore.
- **Create/Edit** (owner only), built with live form fields and repeaters:
  1. **Base dataset:** select from the registered datasets.
  2. **Joins:** repeater. Each row picks a dataset related to one already in the report (only relations declared with `references()`; join keys come from the schema) and chooses inner or left join.
  3. **Columns:** repeater with field (from the chosen datasets), label, format (`text|number|money|percent|date|datetime`) and a `total` toggle.
  4. **Fixed filters:** repeater with field, operator (`=, !=, >, >=, <, <=, in, not in, between, null, not null, like`) and value.
  5. **Run-time parameters:** checklist of the parameters the chosen datasets declare (e.g. `from`/`to`). They are asked each time the report runs.
  6. **Group by + aggregates:** group fields, plus aggregate rows (`sum|avg|min|max|count|countDistinct`, field, alias), plus optional `having`.
  7. **Order by / limit.**
  8. **Visibility:** private or shared with all users.
- **View** page (read access = owner or shared):
  - Header actions: **Run** (modal for run-time params, then fills the preview), **Refresh** (`Panorama::forget()`), **Download CSV**, **Download JSON** (both streamed), **Export XLSX**, **Export HTML** (both queued), **Edit** and **Delete** (owner only).
  - **Preview table:** all rows, paginated.
  - **Runs table:** that report's past runs (status, format, rows, duration, user, date) and a download link for stored files.
- Queued export: a Filament notification says it was queued. When ready, it sends a bell notification with a download link plus Panorama's `ReportReady` mail. On failure it sends a bell notification plus `ReportFailed`.

## Implementation
| Piece | Type | New / Reuse | Notes |
|---|---|---|---|
| `App\Models\ReportDefinition` | Model + migration + factory | New | `belongsTo(User)`, `definition` cast to array, `SoftDeletes`, `scopeVisibleTo(User)` |
| `app/Reports/Datasets/*` | Panorama datasets | New | One per table (see Data). `fetch()` = `DB::table()->cursor()` with `from`/`to` pushdown on the date column; enums as `enum()`; `is_deleted` bool on soft-deletable tables |
| `config/panorama.php` | Config | Reuse | `datasets.discover` → `app_path('Reports/Datasets')`; `history.enabled` via `PANORAMA_HISTORY=true` |
| `App\Reports\DefinedReport` | extends Panorama `Report` | New | Built from a `ReportDefinition`. `key()` = `defined-{id}-{updated_at}`; `query()` maps the JSON onto `DatasetQuery` (`join/leftJoin/where*/groupBy/having/select/orderBy/limit`); `columns()` maps formats to `Column`; `authorize()` = owner or shared; `$cacheFor = 300` |
| `App\Jobs\RunDefinedReportJob` | Queued job | New | Panorama's `RunReport` can't rebuild a DB-defined report (it needs a registered key or a resolvable class), so this job loads the definition → `Panorama::run(new DefinedReport($def), $params, $user)->render($format)->store()` → sends a Filament DB notification + `ReportReady`. `failed()` sends `ReportFailed` + a bell notification. `tries=3`, backoff |
| `app/Filament/Resources/ReportDefinitions/` | Resource (List/Create/Edit/View, Schemas, Tables) | New | Same structure as other resources; nav group `Report Builder` |
| `ViewReportDefinition` preview | Table with `->records(fn ($page, $recordsPerPage))` | New | Builds a `LengthAwarePaginator` from `$run->rows()` (cached result) |
| Runs table | Table widget on View page | New | `RecordedRun::forReport('defined-{id}')->latestFirst()` |
| `report-builder.download` route | Route + controller | New | Streams a stored file; only for the run's own user |
| `AdminPanelProvider` | Panel | Reuse | Add `NavigationGroup::make('Report Builder')` |
| `routes/console.php` | Schedule | Reuse | `Schedule::command('reports:prune')->daily()`, next to `exports:prune` |
| `report_runs` migration | Migration | New (published) | `vendor:publish --tag=panorama-migrations`, then `php artisan migrate` |

- Stock operations: none. The feature is read-only.
- Queue: only XLSX/HTML are queued, since they are built fully in memory. Each export is its own job, so one failure never affects others. Retries come from the job's `tries`.

## Data
**`report_definitions`:** `id`, `user_id` (FK users, cascade), `name` string, `description` text null, `is_shared` bool default false, `definition` json, timestamps, soft deletes.

`definition` shape:
```json
{
  "base": "sale_order_items",
  "joins": [{"dataset": "products", "type": "left"}],
  "columns": [{"field": "products.name", "label": "Product", "format": "text", "total": false}],
  "filters": [{"field": "sale_orders.status", "operator": "in", "value": ["confirmed"]}],
  "parameters": ["from", "to"],
  "group_by": ["products.name"],
  "aggregates": [{"fn": "sum", "field": "sale_order_items.quantity", "as": "qty"}],
  "having": [],
  "order_by": [{"column": "qty", "direction": "desc"}],
  "limit": null
}
```

**Datasets:**
- Catalog: `products`, `categories`, `product_categories` (pivot), `brands`, `units`, `addons`.
- Sales & Purchase: `customers`, `suppliers`, `sale_orders`, `sale_order_items`, `purchase_orders`, `purchase_order_items`, `return_orders`, `return_order_items`.
- Inventory: `locations`, `stocks`, `stock_movements`, `stock_transactions`, `reservations`, `transfer_orders`, `transfer_order_items`, `stock_adjustments`, `stock_adjustment_items` (from the `inventorix_*` tables).

## Edge Cases
- **Definition goes stale:** a dataset field is removed after a report was saved. `DatasetQuery` fails before fetching, so View shows "report needs editing" instead of a 500.
- **Memory:** joined datasets are held in memory (Panorama). The builder hint says to make the largest table the base and join smaller ones, and the `from`/`to` pushdown keeps fetches narrow.
- **Paginated preview:** results over 10,000 rows aren't cached, so each page re-runs the query. The preview shows a notice suggesting an export.
- **Visibility change:** if a shared report becomes private, other users lose access. Queued jobs re-check `authorize()` in the worker.
- **Cache leak:** cached rows aren't keyed by user. That's safe because datasets return the same rows for every user. Don't add user-scoped datasets without turning the cache off.
- **Validation:** joins must be declared relations, `sum`/`avg` need numeric fields, and `between` needs two values. Panorama validates on save via a dry `query()` build.
- **Soft-deleted rows** are included and flagged `is_deleted`, so users can filter them out.

## Tests
- `ReportDefinition` model: casts, factory, `user` relation, `visibleTo` scope.
- Datasets: each one's schema holds against factory data (`datasets:describe`-style check), the `from`/`to` pushdown works, `is_deleted` is set.
- `DefinedReport`: JSON is mapped to the query correctly (joins, filters, group/aggregate, order), `authorize()` lets owner/shared in and blocks others.
- List page: renders, shows own + shared and hides others' private reports, search, soft-delete/restore.
- Create/Edit pages: create, validation (required base, invalid join, `sum` on a non-numeric field), only the owner can edit.
- View page: renders the preview with run-time params, pagination, Refresh clears the cache.
- Download CSV/JSON streams; Export XLSX/HTML dispatches `RunDefinedReport` (`Queue::fake`).
- `RunDefinedReport`: stores the file, sends DB + mail notifications, `failed()` sends `ReportFailed`, authorization is re-checked.
- Download route: the run's owner can download, everyone else gets 403.

## Decisions
| Question | Answer |
|---|---|
| Builder type | No-code UI builder, definitions saved in DB |
| Datasets | Catalog, Sales & Purchase, Inventory (no status logs) |
| Outputs | Preview, CSV/XLSX/HTML/JSON downloads, queued runs + notification (no scheduling) |
| Existing 14 report pages | Left as they are |
| Visibility | Private or shared per report; only the owner edits/deletes |
| Joins | Only declared relations (`references()`) |
| Filters | Fixed filters + run-time parameters |
| History | On, with a Runs list per report |
| Downloads | Stream CSV/JSON, queue XLSX/HTML |
| Notifications | Filament bell + mail |
| Preview | Paginated, all rows |
| Navigation | New "Report Builder" group |
| Soft deletes | Included, with `is_deleted` field |
| Cache | 5 min per params, Refresh clears it |
| Same dataset twice (transfer from/to locations) | Upgraded Panorama to `^0.5.0`, which supports aliases; every join has an alias |
| Polymorphic links (transactions, movements, reservations) | Typed link fields (`sale_order_id`, `sale_order_item_id`, ...) with declared relations |

## Deviations from this spec (found during implementation)
- `DefinedReport` **extends** Panorama's `Report` instead of only implementing the contract: Panorama caches rows only for `Report` instances.
- The report key carries `updated_at` (`defined-{id}-{ts}`), so an edit invalidates cached rows for every parameter set. Run history is matched by the `defined-{id}-` prefix.
- Joins are stored as `{relation, alias, type}` with explicit keys, not `{dataset, type}`. Inference would fail on datasets with two relations to the same target.
- `from` / `to` are declared only by transactional datasets. Master data (products, customers, ...) is never date-filtered, so a date range can't silently drop the products a sale joins to.
- Validation runs in `ReportSchema::errors()` on save (shown under *Base dataset*), not by a dry Panorama build: a dry build would fetch the joined datasets.
- The job fails without retrying when the report is deleted or unshared before it runs.

