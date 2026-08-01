# Supplier Returns

**Navigation Group:** Purchase
**Purpose:** Warehouse sends goods back to a supplier → stock is deducted via `deductStock()`.
**References:** Purchase Orders via `originalOrder()` morphTo.
**Shared model:** `ReturnOrder` with `type = supplier_return` — scoped at the resource level.

**Do not:** Mix with Customer Returns. Supplier returns remove stock (reversing a purchase). Using the wrong type silently corrupts stock levels.

## State Machine

```
Draft ──[Approve]──> Approved ──[Complete]──> Completed
  └──[Cancel]──> Cancelled      └──[Cancel]──> Cancelled

Complete: deductStock() per item (TransactionType::Purchase)
```

## Models

**`ReturnOrder`** — `HasFactory`, `SoftDeletes`
- Casts: `type => ReturnOrderType`, `status => ReturnOrderStatus`
- Relationships:
  - `originalOrder()` → morphTo via `original_order_type` / `original_order_id`
  - `customer()` → belongsTo (nullable, used by CustomerReturn)
  - `supplier()` → belongsTo (nullable, used by SupplierReturn)
  - `location()`, `items()`, `createdBy()`

**`ReturnOrderItem`**
- Relationships: `returnOrder()`, `product()`

The `SupplierReturnResource` scopes all queries to `type = supplier_return`:
```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()->where('type', ReturnOrderType::SupplierReturn);
}

protected function mutateFormDataBeforeCreate(array $data): array
{
    $data['type'] = ReturnOrderType::SupplierReturn->value;
    return $data;
}
```

## Filament Resource

**Path:** `app/Filament/Resources/SupplierReturns/`
**Icon:** `Heroicon::OutlinedArrowUturnRight`

### Form (`Schemas/SupplierReturnForm.php`)

| Field | Type | Notes |
|---|---|---|
| order_number | TextInput | auto-generated SRT-XXXXX, unique |
| supplier_id | Select | relationship('supplier', 'name'), searchable, required |
| original_order_id | Select | from PurchaseOrders, nullable, label 'Original Purchase Order' |
| location_id | Select | relationship('location', 'name'), required |
| reason | TextInput | required |
| notes | Textarea | nullable |
| items | Repeater | relationship() — product_id (span 2), quantity (span 1), unit_cost nullable (span 1) |

### Table (`Tables/SupplierReturnsTable.php`)

| Column | Notes |
|---|---|
| order_number | sortable, searchable |
| supplier.name | sortable |
| status | badge |
| created_at | date, sortable |

Filters: `SelectFilter` on status, `TrashedFilter`

Pages: `ListSupplierReturns`, `CreateSupplierReturn`, `ViewSupplierReturn`, `EditSupplierReturn` (Draft only)

### Workflow Actions (header actions on `ViewSupplierReturn`)

**ApproveAction** — `Draft → Approved`

**CompleteAction** — `Approved → Completed` + deduct stock
```php
Action::make('complete')
    ->visible(fn (ReturnOrder $record) => $record->status === ReturnOrderStatus::Approved)
    ->requiresConfirmation()
    ->action(function (ReturnOrder $record) {
        DB::transaction(function () use ($record) {
            Inventorix::bulk(function (Transaction $tx) use ($record) {
                foreach ($record->items as $item) {
                    $dto = new StockOperationDto(
                        transaction: $tx,
                        transactionType: TransactionType::Purchase,
                        causable: $record,
                        reference: $item,
                        cost: $item->unit_cost,
                        createdBy: auth()->id(),
                    );
                    $item->product->deductStock($item->quantity, $record->location_id, $dto);
                }
            });
            $record->update(['status' => ReturnOrderStatus::Completed]);
        });
    })
```

**CancelAction** — `Draft|Approved → Cancelled`
