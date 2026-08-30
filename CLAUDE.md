<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

- php 8.3, filament/filament v5, laravel/framework v13, livewire/livewire v4, larastan/larastan v3, pestphp/pest v4, phpunit/phpunit v12
- Also: laravel/boost v2, laravel/mcp v0, laravel/pail v1, laravel/pint v1, laravel/prompts v0

## Conventions

- Follow existing code conventions. Check sibling files for structure, approach, and naming.
- Check for existing components to reuse before writing a new one.
- Activate domain-specific skills from `**/skills/**` when working in that domain.
- Do not create verification scripts when tests cover that functionality.
- Stick to existing directory structure and dependencies — don't change without approval.
- Only create documentation files if explicitly requested.
- If frontend changes aren't reflected, ask user to run `npm run build` or `npm run dev`.

## Project Architecture (`docs/`)

### Core Decisions (always apply — no file read needed)

1. **No custom inventory logic.** All stock ops via `HasInventory` trait + `StockOperationDto`. Never write raw stock SQL.
2. **`Inventorix::bulk()` for all multi-item operations.** Atomic rollback.
3. **Workflow actions on View pages only.** Status transitions are header actions on `ViewXxx` — never inline table actions.
4. **`App\Models\Location` extends the package model.** No migration needed — wraps `inventorix_locations`.
5. **Reservation lifecycle on Sale Orders.** `reservation_id` on `sale_order_items`; never skip Confirm before Dispatch.
6. **Status tracking.** All order models use `TracksStatus` trait. Logged to `status_logs`. See `docs/status-tracking.md`.
7. **Resource structure:** `app/Filament/Resources/{PluralModel}/Pages|Schemas|Tables/`
8. **Tests are part of building.** Write and run tests per unit before moving on.

### Doc Lookup (read only the file for the module you are working on)

| Working on | Read |
|---|---|
| Categories, Brands, Units, Products, Addons | `docs/catalog.md` |
| Customers, Sale Orders, Customer Returns | `docs/sales/customers.md`, `docs/sales/sale-orders.md`, `docs/sales/customer-returns.md` |
| Suppliers, Purchase Orders, Supplier Returns | `docs/purchase/suppliers.md`, `docs/purchase/purchase-orders.md`, `docs/purchase/supplier-returns.md` |
| Locations | `docs/inventory/locations.md` |
| Transfer Orders | `docs/inventory/transfer-orders.md` |
| Adjustment Orders | `docs/inventory/adjustment-orders.md` |
| Stocks page | `docs/inventory/inventories.md` |
| Stocks Transactions page | `docs/inventory/inventory-transactions.md` |
| Stock Report page | `docs/inventory/stock-report.md` |
| Movement History page | `docs/inventory/movement-history.md` |
| Low Stock Alerts page | `docs/inventory/low-stock-alerts.md` |
| Dashboard widgets | `docs/dashboard/widgets.md` |
| Status tracking (global) | `docs/status-tracking.md` |

- Do not read doc files for unrelated modules.
- If a doc contradicts existing code, code takes precedence — update the doc.
- `docs/architecture.md` is for human reference only.

## Testing Requirements (MANDATORY)

Tests written as part of building, not after. Per unit: implement → write Pest tests → run and fix → move on.

| Unit | Required tests |
|---|---|
| Model + migration | fillable/cast, factory creates valid record, relationships |
| Resource list page | renders, lists records, search, filters, soft-delete/restore |
| Resource create page | creates record, validates required fields, saves relationships |
| Resource edit page | loads data, updates record, validates |
| Resource view page | renders with correct data |
| Workflow action | status transitions, side effects, guard conditions |
| Stock operation | inventory added/deducted/reserved/fulfilled, non-inventory skipped |

Location: `tests/Feature/{Group}/{Model}ResourceTest.php`. Never skip or delete tests without approval. Run full suite (`php artisan test --compact`) before declaring complete.

=== boost rules ===

# Laravel Boost

- Prefer Boost MCP tools over manual alternatives.
- `database-query` for read-only queries. `database-schema` for table structure. `get-absolute-url` for URLs. `browser-logs` for browser errors.
- Always use `search-docs` before code changes. Pass `packages` array to scope. Use broad topic queries without package names.
- Search syntax: words = AND, `"quoted"` = exact phrase, multiple queries = OR.

=== php rules ===

# PHP

- Curly braces for all control structures. PHP 8 constructor promotion. Explicit return types and type hints.
- TitleCase for Enum keys. PHPDoc blocks with array shape types over inline comments.

=== herd rules ===

# Laravel Herd

- App served at `https?://[kebab-case-project-dir].test`. Use `get-absolute-url` for URLs. Never run serve commands.

=== laravel/core rules ===

# Laravel

- Use `php artisan make:` commands. Pass `--no-interaction`. Use `php artisan [command] --help` to check options.
- Create factories and seeders with new models. Use factories in tests. Prefer named routes with `route()`.
- Pest tests: `php artisan make:test --pest {name}` (no directory prefix). Run: `php artisan test --compact`.
- Vite errors: run `npm run build` or ask user to run `npm run dev`.

=== pint/core rules ===

# Pint

- Run `vendor/bin/pint --dirty --format agent` after modifying PHP files. Do not use `--test`.

=== filament/filament rules ===

## Filament

- Use `search-docs` for documentation. Use Filament Artisan commands with `--no-interaction`.
- Use static `make()` to initialize components. Config methods accept Closures.
- `Get $get` for reading field values, `Set $set` in `afterStateUpdated()` on `live()` fields. Prefer `live(onBlur: true)` on text inputs.

### Correct Namespaces

- Form fields: `Filament\Forms\Components\`
- Infolist entries: `Filament\Infolists\Components\`
- Layout (`Grid`, `Section`, `Tabs`, etc.): `Filament\Schemas\Components\`
- Get/Set: `Filament\Schemas\Components\Utilities\`
- Table columns: `Filament\Tables\Columns\`
- Table filters: `Filament\Tables\Filters\`
- Actions: `Filament\Actions\` only — never sub-namespaces
- Icons: `Filament\Support\Icons\Heroicon` enum

### Common Mistakes

- File visibility is `private` by default — use `->visibility('public')` when needed.
- Layout components don't span full-width by default — use `->columnSpan()` / `->columnSpanFull()`.
- BelongsTo: `Select::make('x_id')->relationship('x', 'name')` — no `BelongsToSelect`.
- Repeater uses `->schema()`, not `->fields()`.
- Never `->dehydrated(false)` on fields that need saving.
- Property types: `$navigationIcon`: `string|BackedEnum|null`, `$navigationGroup`: `string|UnitEnum|null`, `$view`: `protected string` (not static).

### Testing

- `$this->actingAs(User::factory()->create())` before panel tests.
- Edit pages: pass `['record' => $id]`, use `->call('save')`, no `->assertRedirect()`.
- Page actions: `->callAction(DeleteAction::class)`. Table actions: `->callAction(TestAction::make('name')->table($record))`.

</laravel-boost-guidelines>
