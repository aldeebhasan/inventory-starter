# Locations

**Navigation Group:** Warehouse
**Package table:** `inventorix_locations` — no migration needed, owned by the package.

## Model

`app/Models/Location` extends `Aldeebhasan\Inventorix\Models\Location`.

Key additions over the package model:
- `type` accessor reads from `meta['type']` JSON column
- `scopeWarehouses()` — filters by `meta->type = 'warehouse'`
- `scopeActive()` — filters by `is_active = true`
- Relationships: `purchaseOrders()`, `saleOrders()`, `transferOrdersFrom()` (FK: from_location_id), `toTransferOrders()` (FK: to_location_id), `adjustmentOrders()`

## Filament Resource

**Path:** `app/Filament/Resources/Locations/`
**Icon:** `Heroicon::OutlinedBuildingOffice2`

### Form (`Schemas/LocationForm.php`)

| Field | Type | Notes |
|---|---|---|
| name | TextInput | required |
| code | TextInput | unique (ignore record) |
| meta.type | Select | options: LocationType enum, required |
| parent_id | Select | relationship('parent', 'name'), nullable |
| is_active | Toggle | default true |

### Table (`Tables/LocationsTable.php`)

| Column | Notes |
|---|---|
| name | sortable, searchable |
| code | sortable |
| meta.type | badge, label 'Type' |
| parent.name | label 'Parent' |
| is_active | IconColumn boolean |

Filters: `SelectFilter` on type, `TernaryFilter` on is_active

### Pages

- `ListLocations` — header: `CreateAction`
- `CreateLocation`
- `EditLocation` — no soft delete (package model has none)
