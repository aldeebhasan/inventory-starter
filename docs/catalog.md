# Catalog

**Navigation Group:** Catalog
**Resources:** Categories, Brands, Units, Addons, Products

---

## Entities & Relationships

```
Brand ──< Product >──< Category
         │       └──<(pivot)── Addon  (product_addon)
         │       └──>──< Supplier  (pivot: unit_cost, supplier_sku)
         └── Unit (unit of measure)
```

---

## Schema Changes (migrations required)

### New tables

**`units`**
- id, name varchar(100) (e.g. "Kilogram"), abbreviation varchar(20) (e.g. "kg"), timestamps

**`brands`**
- id, name, logo nullable, is_active bool default true, timestamps, softDeletes

**`addons`** (standalone — not tied to a product in this table)
- id, name, price decimal(12,3), description text nullable, is_active bool default true, timestamps, softDeletes

**`product_addon`** (pivot)
- product_id FK → products CASCADE
- addon_id FK → addons CASCADE
- Unique: (product_id, addon_id)

**`category_product`** (pivot)
- category_id FK → categories CASCADE
- product_id FK → products CASCADE
- Unique: (category_id, product_id)

**`product_supplier`** (pivot)
- product_id FK → products CASCADE
- supplier_id FK → suppliers CASCADE
- unit_cost decimal(12,3) nullable — supplier-specific reference cost
- supplier_sku varchar(100) nullable — supplier's part number
- Unique: (product_id, supplier_id)

### Modify `products` table

- **Add** `brand_id` FK → brands.id nullOnDelete nullable
- **Add** `unit_id` FK → units.id nullOnDelete nullable
- **Add** `type` varchar default `'inventory'` — values: `inventory` | `non_inventory`
- **Drop** `category_id` — replaced by `category_product` pivot

---

## Models

### `Unit`
- Traits: `HasFactory`
- `#[Fillable(['name', 'abbreviation', 'base_unit_id', 'conversion_factor'])]`
- Casts: `conversion_factor => float`
- Relationships: `products()` hasMany, `baseUnit()` belongsTo self, `derivedUnits()` hasMany self
- Method: `convertQty(float $qty): float` — multiplies `$qty` by `conversion_factor` if this is a derived unit; returns `$qty` unchanged if this is a base unit (no `base_unit_id`)

### `Brand`
- Traits: `HasFactory`, `SoftDeletes`
- `#[Fillable(['name', 'logo', 'is_active'])]`
- Relationships: `products()` hasMany

### `Category` (update existing)
- Add: `products()` belongsToMany Product (via `category_product`)

### `Addon`
- Traits: `HasFactory`, `SoftDeletes`
- `#[Fillable(['name', 'price', 'description', 'is_active'])]`
- Casts: `price => decimal:3`, `is_active => boolean`
- Relationships: `products()` belongsToMany Product (via `product_addon`)
- **No `product_id` column** — addons are standalone entities reusable across products

### `Product` (update existing)
- Remove: `category()` belongsTo — **drop this relationship**
- Add:
  - `brand()` belongsTo Brand
  - `unit()` belongsTo Unit
  - `categories()` belongsToMany Category (via `category_product`)
  - `addons()` belongsToMany Addon (via `product_addon`)
  - `suppliers()` belongsToMany Supplier (via `product_supplier`) `->withPivot(['unit_cost', 'supplier_sku'])`
- Update `#[Fillable]`: replace `category_id` with `brand_id`, add `unit_id`, add `type`
- Casts: `type => ProductType`
- Method `isInventory(): bool` — returns `true` when `type === ProductType::Inventory`
- **Inventory operation guard:** `addStock`, `deductStock`, `adjustStock`, `adjustStockByReference`, `transferStock`, `reserve`, `releaseReservation`, `fulfillReservation` are overridden to throw `LogicException` if `!isInventory()`. Callers (order actions) **must** guard with `$product->isInventory()` before calling these methods, so non-inventory items are skipped cleanly.

### `Supplier` (update existing)
- Add: `products()` belongsToMany Product (via `product_supplier`) `->withPivot(['unit_cost', 'supplier_sku'])`

---

## Filament Resources

### `UnitResource`
**Path:** `app/Filament/Resources/Units/`
**Icon:** `Heroicon::OutlinedScale`

**Form (`Schemas/UnitForm.php`)**

| Field | Type | Notes |
|---|---|---|
| name | TextInput | required, e.g. "Kilogram" |
| abbreviation | TextInput | required, max 20, e.g. "kg" |
| base_unit_id | Select | relationship('baseUnit', 'name'), nullable — designates this as a derived unit |
| conversion_factor | TextInput | numeric, default 1, visible only when base_unit_id is set — "1 {abbr} = {factor} {base abbr}" |

**Unit conversion rule:** `conversion_factor` expresses how many base-unit quantities equal 1 of this unit. Example: 1 Ton = 1000 kg → set base_unit to "Kilogram", conversion_factor = 1000.

**Table (`Tables/UnitsTable.php`)**

| Column | Notes |
|---|---|
| name | sortable, searchable |
| abbreviation | |
| products_count | counts('products'), label 'Products' |

Actions: `EditAction`, `DeleteAction`

Pages: `ListUnits`, `CreateUnit`, `EditUnit`

---

### `BrandResource`
**Path:** `app/Filament/Resources/Brands/`
**Icon:** `Heroicon::OutlinedTag`

**Form (`Schemas/BrandForm.php`)**

| Field | Type | Notes |
|---|---|---|
| name | TextInput | required |
| logo | FileUpload | image, directory('brands'), visibility('public'), nullable |
| is_active | Toggle | default true |

**Table (`Tables/BrandsTable.php`)**

| Column | Notes |
|---|---|
| logo | ImageColumn |
| name | sortable, searchable |
| is_active | IconColumn boolean |
| products_count | counts('products'), label 'Products' |

Filters: `TernaryFilter` on is_active, `TrashedFilter`
Actions: `EditAction`, `DeleteAction`
Bulk: `DeleteBulkAction`, `ForceDeleteBulkAction`, `RestoreBulkAction`

Pages: `ListBrands`, `CreateBrand`, `EditBrand`

---

### `AddonResource`
**Path:** `app/Filament/Resources/Addons/`
**Navigation Group:** Catalog
**Icon:** `Heroicon::OutlinedPuzzlePiece`

Addons are standalone catalog items. They are defined once and then attached to products via the `product_addon` pivot. The same addon can be attached to a product multiple times (e.g., "Gift Wrap × 2").

**Form (`Schemas/AddonForm.php`)**

| Field | Type | Notes |
|---|---|---|
| name | TextInput | required |
| price | TextInput | numeric, required, min 0 |
| description | Textarea | nullable |
| is_active | Toggle | default true |

**Table (`Tables/AddonsTable.php`)**

| Column | Notes |
|---|---|
| name | sortable, searchable |
| price | money format, sortable |
| is_active | IconColumn boolean |
| products_count | counts('products'), label 'Products' |

Filters: `TernaryFilter` on is_active, `TrashedFilter`
Actions: `EditAction`, `DeleteAction`
Bulk: `DeleteBulkAction`, `ForceDeleteBulkAction`, `RestoreBulkAction`

Pages: `ListAddons`, `CreateAddon`, `EditAddon`

---

### `CategoryResource` (update existing)

Only add `$navigationGroup = 'Catalog'`. No form/table changes needed.

---

### `ProductResource` (update existing)

Add `$navigationGroup = 'Catalog'`.

**Form changes (`Schemas/ProductForm.php`)**

| Change | Detail |
|---|---|
| Remove | `Select::make('category_id')` |
| Add | `Select::make('type')->options(ProductType::class)->default(ProductType::Inventory)->required()` |
| Add | `Select::make('categories')->multiple()->relationship('categories', 'name')->searchable()->preload()` |
| Add | `Select::make('brand_id')->relationship('brand', 'name')->searchable()->nullable()` |
| Add | `Select::make('unit_id')->relationship('unit', 'name')->searchable()->nullable()->helperText('Unit of measure, e.g. kg, piece')` |
| Add | `Select::make('suppliers')->multiple()->relationship('suppliers', 'name')->searchable()` |

**Addon attachment on Product**

Addons use the same pattern as categories — a simple multi-select:

```php
Select::make('addons')
    ->multiple()
    ->relationship('addons', 'name')
    ->searchable()
    ->preload()
```

**Table changes (`Tables/ProductsTable.php`)**

| Change | Detail |
|---|---|
| Remove | `TextColumn::make('category.name')` |
| Add | `TextColumn::make('brand.name')->sortable()` |
| Add | `TextColumn::make('categories_count')->counts('categories')->label('Categories')` |

**Infolist (`Schemas/ProductInfolist.php`)**

Add stock summary table per location sourced from `inventorix_stocks`.

Pages: `ListProducts`, `CreateProduct`, `ViewProduct`, `EditProduct`

---

## HasInventory Methods

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

---

## Notes on Supplier ↔ Product

The `product_supplier` pivot stores a **reference cost** (`unit_cost`) and the supplier's part number (`supplier_sku`). This is separate from the `unit_cost` on a `PurchaseOrderItem` — the PO line cost is what was actually paid on that order. The pivot cost is a default that can pre-fill PO line items when a product+supplier combination is selected.
