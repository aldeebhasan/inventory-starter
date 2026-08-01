# Customer Returns

**Navigation Group:** Sales
**Purpose:** Customer sends goods back to the warehouse → stock is added back via `addStock()`.
**References:** Sale Orders via `originalOrder()` morphTo.
**Shared model:** `ReturnOrder` with `type = customer_return` — scoped at the resource level.

## State Machine

```
Draft ──[Approve]──> Approved ──[Complete]──> Completed
  └──[Cancel]──> Cancelled      └──[Cancel]──> Cancelled

Complete: addStock() per item (TransactionType::Sale)
```

## Models

Uses the shared `ReturnOrder` / `ReturnOrderItem` models — see `docs/purchase/supplier-returns.md` for the full model definition.

The `CustomerReturnResource` scopes all queries to `type = customer_return`:
```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()->where('type', ReturnOrderType::CustomerReturn);
}
```

And auto-sets the type on create:
```php
protected function mutateFormDataBeforeCreate(array $data): array
{
    $data['type'] = ReturnOrderType::CustomerReturn->value;
    return $data;
}
```

## Filament Resource

**Path:** `app/Filament/Resources/CustomerReturns/`
**Icon:** `Heroicon::OutlinedArrowUturnLeft`

### Form (`Schemas/CustomerReturnForm.php`)

| Field | Type | Notes |
|---|---|---|
| order_number | TextInput | auto-generated CRT-XXXXX, unique |
| customer_id | Select | relationship('customer', 'name'), searchable, required |
| original_order_id | Select | from SaleOrders, nullable, label 'Original Sale Order' |
| location_id | Select | relationship('location', 'name'), required |
| reason | TextInput | required |
| notes | Textarea | nullable |
| items | Repeater | relationship() — product_id (span 2), quantity (span 1), unit_cost nullable (span 1) |

### Table (`Tables/CustomerReturnsTable.php`)

| Column | Notes |
|---|---|
| order_number | sortable, searchable |
| customer.name | sortable |
| status | badge |
| created_at | date, sortable |

Filters: `SelectFilter` on status, `TrashedFilter`

Pages: `ListCustomerReturns`, `CreateCustomerReturn`, `ViewCustomerReturn`, `EditCustomerReturn` (Draft only)

### Workflow Actions (header actions on `ViewCustomerReturn`)

**ApproveAction** — `Draft → Approved`

**CompleteAction** — `Approved → Completed` + add stock back
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
                        transactionType: TransactionType::Sale,
                        causable: $record,
                        reference: $item,
                        cost: $item->unit_cost,
                        createdBy: auth()->id(),
                    );
                    $item->product->addStock($item->quantity, $record->location_id, $dto);
                }
            });
            $record->update(['status' => ReturnOrderStatus::Completed]);
        });
    })
```

**CancelAction** — `Draft|Approved → Cancelled`
