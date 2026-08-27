# Status Tracking

**Purpose:** A global, polymorphic status log that records every status transition for any trackable model (Sale Orders, Purchase Orders, Transfer Orders, Adjustment Orders, Return Orders, etc.). Provides a full audit trail of who changed what, when, and optionally why.

## Migration: `status_logs`

| Column | Type | Notes |
|---|---|---|
| id | bigIncrements | |
| trackable_type | string | morph type (e.g. `App\Models\SaleOrder`) |
| trackable_id | unsignedBigInteger | morph id |
| old_status | string | nullable — null on initial creation |
| new_status | string | required |
| reason | text | nullable — optional note explaining the transition (e.g. cancellation reason) |
| created_by | unsignedBigInteger | nullable, FK → users.id |
| created_at | timestamp | |

Index: `(trackable_type, trackable_id, created_at)` for fast lookups.

## Model: `StatusLog`

```php
class StatusLog extends Model
{
    public $timestamps = false; // only created_at, no updated_at

    protected $fillable = [
        'trackable_type',
        'trackable_id',
        'old_status',
        'new_status',
        'reason',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

## Trait: `TracksStatus`

Applied to any model that needs status logging. The trait automatically logs transitions when the `status` attribute changes.

```php
trait TracksStatus
{
    public static function bootTracksStatus(): void
    {
        static::creating(function (Model $model) {
            // Defer logging until after the model has an ID
        });

        static::created(function (Model $model) {
            if ($model->status !== null) {
                $model->logStatusChange(null, $model->status);
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('status')) {
                $model->logStatusChange(
                    $model->getOriginal('status'),
                    $model->status,
                );
            }
        });
    }

    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'trackable')->orderBy('created_at');
    }

    public function latestStatusLog(): MorphOne
    {
        return $this->morphOne(StatusLog::class, 'trackable')->latestOfMany('created_at');
    }

    public function logStatusChange(
        string|BackedEnum|null $oldStatus,
        string|BackedEnum $newStatus,
        ?string $reason = null,
    ): StatusLog {
        return $this->statusLogs()->create([
            'old_status' => $oldStatus instanceof BackedEnum ? $oldStatus->value : $oldStatus,
            'new_status' => $newStatus instanceof BackedEnum ? $newStatus->value : $newStatus,
            'reason' => $reason,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }
}
```

## Usage

### Applying the trait

Add `use TracksStatus;` to any model with a `status` column:

```php
class SaleOrder extends Model
{
    use TracksStatus;
    // ...
}
```

All status changes via `$model->update(['status' => ...])` are automatically logged.

### Logging with a reason

For cancellations or other transitions where a reason is useful, call `logStatusChange` explicitly before the update, or pass the reason through the cancel action:

```php
// In a cancel action with a reason field:
$record->logStatusChange($record->status, SaleOrderStatus::Cancelled, reason: $data['reason']);
$record->updateQuietly(['status' => SaleOrderStatus::Cancelled]);
```

Note: `updateQuietly` skips the auto-log in the `updating` event since we already logged manually with the reason.

### Querying status history

```php
$order->statusLogs; // all transitions in chronological order
$order->latestStatusLog; // most recent transition
StatusLog::where('trackable_type', SaleOrder::class)
    ->where('new_status', 'cancelled')
    ->with('creator')
    ->get(); // all cancelled sale orders with who cancelled them
```

## Display in Filament

### View Page Section

Add a "Status History" section to the View page of any trackable resource:

```php
Section::make('Status History')
    ->schema([
        RepeatableEntry::make('statusLogs')
            ->schema([
                TextEntry::make('new_status')->badge(),
                TextEntry::make('old_status')->placeholder('(initial)'),
                TextEntry::make('reason')->placeholder('-'),
                TextEntry::make('creator.name')->label('Changed by'),
                TextEntry::make('created_at')->dateTime(),
            ])
            ->columns(5),
    ])
    ->collapsible()
    ->collapsed(),
```

## Tracked Models & State Machines

### SaleOrder (`SaleOrderStatus`)

```
Draft -> Confirmed -> Dispatched -> Delivered -> Fulfilled
  └─[Cancel]  └─[Cancel]    └─[Cancel]     └─[Cancel]
                                                        -> Cancelled
```

| Transition | Inventory Effect |
|---|---|
| Draft -> Confirmed | `reserve()` per item |
| Confirmed -> Dispatched | `fulfillReservation()` per item |
| Dispatched -> Delivered | none |
| Delivered -> Fulfilled | none |
| Cancel from Draft | none |
| Cancel from Confirmed | `releaseReservation()` per item |
| Cancel from Dispatched/Delivered | `addStock()` per item (Reversal) |

### PurchaseOrder (`PurchaseOrderStatus`)

```
Draft -> Confirmed -> Received
  └─[Cancel]  └─[Cancel]
                        -> Cancelled
```

| Transition | Inventory Effect |
|---|---|
| Draft -> Confirmed | none |
| Confirmed -> Received | `addStock()` per item (Purchase) |
| Cancel from Draft/Confirmed | none |

### TransferOrder (`TransferOrderStatus`)

```
Draft -> Confirmed -> Sending -> InTransit -> Receiving -> Completed
  └─[Cancel]  └─[Cancel]   └─> PartiallySent          └─> PartiallyCompleted
                                  └─[Retry]-> Sending        └─[Retry]-> Receiving
```

| Transition | Inventory Effect |
|---|---|
| Draft -> Confirmed | none (validates stock sufficiency) |
| Confirmed -> Sending | `deductStock()` per item from source (Transfer) |
| Sending -> InTransit | none (all items sent) |
| Sending -> PartiallySent | none (some items failed) |
| InTransit -> Receiving | `addStock()` per item at destination (Transfer) |
| Receiving -> Completed | none (all items received) |
| Receiving -> PartiallyCompleted | none (some items failed) |
| Cancel from Draft/Confirmed | none |

### StockAdjustment (`StockAdjustmentStatus`)

```
Draft -> Processing -> Applied
  └─[Cancel]          └─> PartiallyApplied
                              └─[Retry]-> Processing
```

| Transition | Inventory Effect |
|---|---|
| Draft -> Processing | snapshots `current_stock`, dispatches job |
| Processing -> Applied | per item: `addStock` / `deductStock` / `adjustStock` (Adjustment) |
| Processing -> PartiallyApplied | failed items logged with reason |
| Cancel from Draft | none |

### ReturnOrder -- Customer Return (`ReturnOrderStatus`, type = customer_return)

```
Draft -> Completed
  └─[Cancel]  └─[Cancel]
                        -> Cancelled
```

| Transition | Inventory Effect |
|---|---|
| Draft -> Completed | `addStock()` per item (Sale) |
| Cancel from Draft | none |
| Cancel from Completed | `deductStock()` per item (Reversal) |

### ReturnOrder -- Supplier Return (`ReturnOrderStatus`, type = supplier_return)

```
Draft -> Approved -> Completed
  └─[Cancel]  └─[Cancel]
                        -> Cancelled
```

| Transition | Inventory Effect |
|---|---|
| Draft -> Approved | none |
| Approved -> Completed | `deductStock()` per item (Purchase) |
| Cancel from Draft/Approved | none |

## Testing

File: `tests/Feature/StatusLogTest.php`

| Test | Covers |
|---|---|
| logs initial status on create | created event fires, old_status = null |
| logs transition on update | updating event fires, old/new captured |
| does not log when status unchanged | no duplicate log entry |
| logStatusChange with reason | reason stored correctly |
| statusLogs relationship returns chronological order | ordered by created_at |
| creator relationship resolves | created_by → User |
