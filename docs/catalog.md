# Catalog

**Navigation Group:** Catalog
**Resources:** Categories, Brands, Units, Products (Addons managed via RelationManager on Product)

---

## Entities & Relationships

```
Brand ──< Product >──< Category
         │       └──< Addon
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

**`addons`**
- id, product_id FK → products CASCADE, name, price decimal(12,3), description text nullable, is_active bool default true, timestamps, softDeletes

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
- **Drop** `category_id` — replaced by `category_product` pivot

---

## Models

### `Unit`
- Traits: `HasFactory`
- `#[Fillable(['name', 'abbreviation'])]`
- Relationships: `products()` hasMany

### `Brand`
- Traits: `HasFactory`, `SoftDeletes`
- `#[Fillable(['name', 'logo', 'is_active'])]`
- Relationships: `products()` hasMany

### `Category` (update existing)
- Add: `products()` belongsToMany Product (via `category_product`)

### `Addon`
- Traits: `HasFactory`, `SoftDeletes`
- `#[Fillable(['product_id', 'name', 'price', 'description', 'is_active'])]`
- Casts: `price => decimal:3`, `is_active => boolean`
- Relationships: `product()` belongsTo Product

### `Product` (update existing)
- Remove: `category()` belongsTo — **drop this relationship**
- Add:
  - `brand()` belongsTo Brand
  - `unit()` belongsTo Unit
  - `categories()` belongsToMany Category (via `category_product`)
  - `addons()` hasMany Addon
  - `suppliers()` belongsToMany Supplier (via `product_supplier`) `->withPivot(['unit_cost', 'supplier_sku'])`
- Update `#[Fillable]`: replace `category_id` with `brand_id`, add `unit_id`

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

### `CategoryResource` (update existing)

Only add `$navigationGroup = 'Catalog'`. No form/table changes needed.

---

### `ProductResource` (update existing)

Add `$navigationGroup = 'Catalog'`.

**Form changes (`Schemas/ProductForm.php`)**

| Change | Detail |
|---|---|
| Remove | `Select::make('category_id')` |
| Add | `Select::make('categories')->multiple()->relationship('categories', 'name')->searchable()->preload()` |
| Add | `Select::make('brand_id')->relationship('brand', 'name')->searchable()->nullable()` |
| Add | `Select::make('unit_id')->relationship('unit', 'name')->searchable()->nullable()->helperText('Unit of measure, e.g. kg, piece')` |
| Add | `Select::make('suppliers')->multiple()->relationship('suppliers', 'name')->searchable()` |

**Table changes (`Tables/ProductsTable.php`)**

| Change | Detail |
|---|---|
| Remove | `TextColumn::make('category.name')` |
| Add | `TextColumn::make('brand.name')->sortable()` |
| Add | `TextColumn::make('categories_count')->counts('categories')->label('Categories')` |

**Infolist (`Schemas/ProductInfolist.php`)**

Add stock summary table per location sourced from `inventorix_stocks`.

**Relation Manager**

Add `AddonsRelationManager` on the View/Edit page:
- Table columns: name, price, is_active
- Form: name (TextInput), price (TextInput numeric), description (Textarea), is_active (Toggle)
- Actions: CreateAction, EditAction, DeleteAction

Pages: `ListProducts`, `CreateProduct`, `ViewProduct` (with AddonsRelationManager), `EditProduct`

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
