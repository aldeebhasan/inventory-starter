# Customer Returns

**Navigation Group:** Sales
**Purpose:** Customer sends goods back to the warehouse -- stock is added back via `addStock()`.
**References:** Sale Orders via `saleOrder()` belongsTo (`sale_order_id` FK).
**Shared model:** `ReturnOrder` with `type = customer_return` -- scoped at the resource level.
**Status Tracking:** Uses `TracksStatus` trait on `ReturnOrder` -- see `docs/status-tracking.md`.

## Creation Modes

A customer return can be started in two ways:

1. **From a Sale Order** -- initiated from a specific sale order. Items are limited to the products in that sale order. The item price defaults to the sale order item's unit price. When stock is added back, the cost used is the sale order item's unit cost.
2. **On the fly** -- created independently without a sale order. Any product can be added. The item price defaults to the product's current price. When stock is added back, the cost used is the product's cost.

### Item Fields

| Field | Type | Notes |
|---|---|---|
| product_id | Select | required. If linked to a sale order, limited to that order's products |
| unit_id | Select | locked to sale order item's unit when linked; free selection on the fly |
| quantity | Numeric | required. Max capped to sale order item quantity when linked |
| price | Numeric | the return price per unit. Defaults to sale order item price (if from order) or product price (if on the fly) |

### Inventory Cost on Completion

When the return is completed and stock is added back:
- **From Sale Order:** the `cost` passed to `addStock()` is the sale order item's `unit_cost`.
- **On the fly:** the `cost` passed to `addStock()` is the product's cost (fallback).

## State Machine

```
Draft --[Complete]--> Completed --[Cancel]--> Cancelled
  └──[Cancel]--> Cancelled

Complete: addStock() per item (TransactionType::Sale), cost = sale order item cost or product cost
Cancel from Completed: deductStock() per item (TransactionType::Reversal) -- reverses the stock addition
Cancel from Draft: no inventory reversal needed
```

## Models

Uses the shared `ReturnOrder` / `ReturnOrderItem` models -- see `docs/purchase/supplier-returns.md` for the full model definition.

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
| sale_order_id | Select | from SaleOrders, nullable, label 'Original Sale Order'. When changed, resets items and auto-selects customer |
| location_id | Select | relationship('location', 'name'), required |
| reason | TextInput | required |
| notes | Textarea | nullable |
| items | Repeater | relationship() -- product_id (span 2, options filtered by sale order if linked), quantity (span 1), price (span 1, defaults per creation mode) |

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

**CompleteAction** -- `Draft -> Completed` + add stock back
```php
Action::make('complete')
    ->visible(fn (ReturnOrder $record) => $record->status === ReturnOrderStatus::Draft)
    ->requiresConfirmation()
    ->action(function (ReturnOrder $record) {
        DB::transaction(function () use ($record) {
            Inventorix::bulk(function (Transaction $tx) use ($record) {
                foreach ($record->items as $item) {
                    // Use sale order item cost if available, otherwise product cost
                    $cost = $item->saleOrderItem?->unit_cost ?? $item->product->cost;

                    $dto = new StockOperationDto(
                        transaction: $tx,
                        transactionType: TransactionType::Sale,
                        causable: $record,
                        reference: $item,
                        cost: $cost,
                        createdBy: auth()->id(),
                    );
                    $item->product->addStock($item->quantity, $record->location_id, $dto);
                }
            });
            $record->update(['status' => ReturnOrderStatus::Completed]);
        });
    })
```

**CancelAction** -- `Draft|Completed -> Cancelled` + reverse inventory if needed
```php
Action::make('cancel')
    ->visible(fn (ReturnOrder $record) => in_array($record->status, [
        ReturnOrderStatus::Draft, ReturnOrderStatus::Completed,
    ]))
    ->color('danger')
    ->requiresConfirmation()
    ->schema([
        Textarea::make('reason')->label('Cancellation Reason'),
    ])
    ->action(function (ReturnOrder $record, array $data) {
        DB::transaction(function () use ($record, $data) {
            if ($record->status === ReturnOrderStatus::Completed) {
                // Reverse the stock addition
                foreach ($record->items as $item) {
                    $dto = new StockOperationDto(
                        transactionType: TransactionType::Reversal,
                        causable: $item, // line item as causable for idempotency
                        reference: $record,
                        note: "Cancel CRT #{$record->order_number}",
                        createdBy: auth()->id(),
                    );
                    $item->product->deductStock($item->quantity, $record->location_id, $dto);
                }
            }

            $record->logStatusChange($record->status, ReturnOrderStatus::Cancelled, reason: $data['reason'] ?? null);
            $record->updateQuietly(['status' => ReturnOrderStatus::Cancelled]);
        });
    })
```
