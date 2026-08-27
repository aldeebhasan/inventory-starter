<?php

use App\Enums\PurchaseOrderStatus;
use App\Enums\SaleOrderStatus;
use App\Enums\StockAdjustmentStatus;
use App\Enums\TransferOrderStatus;
use App\Models\Customer;
use App\Models\PurchaseOrder;
use App\Models\SaleOrder;
use App\Models\StatusLog;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\TransferOrder;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->location = createLocation();
});

// --- Core TracksStatus Behavior ---

it('logs initial status on create', function () {
    $order = SaleOrder::create([
        'customer_id' => Customer::factory()->create()->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
        'ordered_at' => now(),
    ]);

    expect($order->statusLogs)->toHaveCount(1);

    $log = $order->statusLogs->first();
    expect($log->old_status)->toBeNull()
        ->and($log->new_status)->toBe('draft')
        ->and($log->created_by)->toBe($this->user->id);
});

it('logs transition on update', function () {
    $order = SaleOrder::create([
        'customer_id' => Customer::factory()->create()->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
        'ordered_at' => now(),
    ]);

    $order->update(['status' => SaleOrderStatus::Confirmed]);

    $logs = $order->statusLogs()->get();
    expect($logs)->toHaveCount(2);

    $transition = $logs->last();
    expect($transition->old_status)->toBe('draft')
        ->and($transition->new_status)->toBe('confirmed');
});

it('does not log when status is unchanged', function () {
    $order = SaleOrder::create([
        'customer_id' => Customer::factory()->create()->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
        'ordered_at' => now(),
    ]);

    $order->update(['notes' => 'updated notes']);

    expect($order->statusLogs()->count())->toBe(1); // only the initial create log
});

it('logs status change with reason via logStatusChange', function () {
    $order = SaleOrder::create([
        'customer_id' => Customer::factory()->create()->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
        'ordered_at' => now(),
    ]);

    $order->logStatusChange(SaleOrderStatus::Draft, SaleOrderStatus::Cancelled, 'Customer requested');
    $order->updateQuietly(['status' => SaleOrderStatus::Cancelled]);

    $logs = $order->statusLogs()->get();
    expect($logs)->toHaveCount(2); // create + manual log

    $cancelLog = $logs->last();
    expect($cancelLog->old_status)->toBe('draft')
        ->and($cancelLog->new_status)->toBe('cancelled')
        ->and($cancelLog->reason)->toBe('Customer requested');
});

it('returns status logs in chronological order', function () {
    $order = SaleOrder::create([
        'customer_id' => Customer::factory()->create()->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
        'ordered_at' => now(),
    ]);

    $order->update(['status' => SaleOrderStatus::Confirmed]);
    $order->update(['status' => SaleOrderStatus::Cancelled]);

    $logs = $order->statusLogs;
    expect($logs)->toHaveCount(3)
        ->and($logs[0]->new_status)->toBe('draft')
        ->and($logs[1]->new_status)->toBe('confirmed')
        ->and($logs[2]->new_status)->toBe('cancelled');
});

it('resolves the creator relationship', function () {
    $order = SaleOrder::create([
        'customer_id' => Customer::factory()->create()->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
        'ordered_at' => now(),
    ]);

    $log = $order->statusLogs->first();
    expect($log->creator->id)->toBe($this->user->id);
});

it('resolves the latestStatusLog relationship', function () {
    $order = SaleOrder::create([
        'customer_id' => Customer::factory()->create()->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
        'ordered_at' => now(),
    ]);

    $order->update(['status' => SaleOrderStatus::Confirmed]);

    $latest = $order->latestStatusLog;
    expect($latest->new_status)->toBe('confirmed');
});

// --- Works on all order models ---

it('tracks status on PurchaseOrder', function () {
    $order = PurchaseOrder::create([
        'supplier_id' => Supplier::factory()->create()->id,
        'location_id' => $this->location->id,
        'status' => PurchaseOrderStatus::Draft,
        'ordered_at' => now(),
    ]);

    $order->update(['status' => PurchaseOrderStatus::Confirmed]);

    $logs = $order->statusLogs()->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->last()->new_status)->toBe('confirmed');
});

it('tracks status on TransferOrder', function () {
    $toLocation = createLocation();

    $order = TransferOrder::create([
        'from_location_id' => $this->location->id,
        'to_location_id' => $toLocation->id,
        'status' => TransferOrderStatus::Draft,
    ]);

    $order->update(['status' => TransferOrderStatus::Confirmed]);

    $logs = $order->statusLogs()->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->last()->new_status)->toBe('confirmed');
});

it('tracks status on StockAdjustment', function () {
    $order = StockAdjustment::create([
        'location_id' => $this->location->id,
        'reason' => 'Test adjustment',
        'status' => StockAdjustmentStatus::Draft,
    ]);

    $order->update(['status' => StockAdjustmentStatus::Processing]);

    $logs = $order->statusLogs()->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->last()->new_status)->toBe('processing');
});

// --- Trackable morph resolves ---

it('resolves trackable morph back to the model', function () {
    $order = SaleOrder::create([
        'customer_id' => Customer::factory()->create()->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
        'ordered_at' => now(),
    ]);

    $log = StatusLog::where('trackable_type', SaleOrder::class)
        ->where('trackable_id', $order->id)
        ->first();

    expect($log->trackable)->toBeInstanceOf(SaleOrder::class)
        ->and($log->trackable->id)->toBe($order->id);
});
