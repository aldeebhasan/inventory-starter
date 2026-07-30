# Suppliers

**Navigation Group:** Parties
**Note:** No soft deletes — suppliers are deactivated via `is_active`, not deleted.

## Model

`app/Models/Supplier` — `HasFactory`

Relationships: `purchaseOrders()` hasMany, `returnOrders()` hasMany

## Filament Resource

**Path:** `app/Filament/Resources/Suppliers/`
**Icon:** `Heroicon::OutlinedTruck`

### Form (`Schemas/SupplierForm.php`)

| Field | Type | Notes |
|---|---|---|
| name | TextInput | required |
| email | TextInput | email, required |
| phone | TextInput | nullable |
| address | Textarea | nullable |
| tax_number | TextInput | nullable |
| is_active | Toggle | default true |

### Table (`Tables/SuppliersTable.php`)

| Column | Notes |
|---|---|
| name | sortable, searchable |
| email | |
| phone | |
| is_active | IconColumn boolean |
| purchase_orders_count | counts('purchaseOrders'), label 'POs' |

Filters: `TernaryFilter` on is_active — Actions: `EditAction`

### Pages

- `ListSuppliers` — header: `CreateAction`
- `CreateSupplier`
- `EditSupplier`
