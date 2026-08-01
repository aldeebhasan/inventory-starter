# Customers

**Navigation Group:** Sales

## Model

`app/Models/Customer` — `HasFactory`, `SoftDeletes`

Relationships: `saleOrders()` hasMany, `customerReturns()` hasMany ReturnOrder (scoped to `type = customer_return`)

## Filament Resource

**Path:** `app/Filament/Resources/Customers/`
**Icon:** `Heroicon::OutlinedUsers`

### Form (`Schemas/CustomerForm.php`)

| Field | Type | Notes |
|---|---|---|
| name | TextInput | required |
| email | TextInput | email, required |
| phone | TextInput | nullable |
| address | Textarea | nullable |
| tax_number | TextInput | nullable |
| is_active | Toggle | default true |

### Table (`Tables/CustomersTable.php`)

| Column | Notes |
|---|---|
| name | sortable, searchable |
| email | |
| phone | |
| is_active | IconColumn boolean |
| sale_orders_count | counts('saleOrders'), label 'Orders' |

Filters: `TernaryFilter` on is_active, `TrashedFilter`

Actions: `EditAction`, `DeleteAction`
Bulk: `DeleteBulkAction`, `ForceDeleteBulkAction`, `RestoreBulkAction`

Pages: `ListCustomers`, `CreateCustomer`, `EditCustomer`
