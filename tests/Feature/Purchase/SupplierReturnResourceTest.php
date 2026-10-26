<?php

use Aldeebhasan\Inventorix\Models\Movement;
use App\Enums\ReturnOrderStatus;
use App\Enums\ReturnOrderType;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\SupplierReturns\Pages\CreateSupplierReturn;
use App\Filament\Resources\SupplierReturns\Pages\EditSupplierReturn;
use App\Filament\Resources\SupplierReturns\Pages\ListSupplierReturns;
use App\Filament\Resources\SupplierReturns\Pages\ViewSupplierReturn;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReturnOrder;
use App\Models\ReturnOrderItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->supplier = Supplier::factory()->create();
});

function supplierReturn(array $attributes = []): ReturnOrder
{
    return ReturnOrder::factory()->supplierReturn()->create(array_merge([
        'supplier_id' => test()->supplier->id,
        'location_id' => test()->location->id,
    ], $attributes));
}

function supplierReturnItem(ReturnOrder $return, Product $product, float $quantity, array $attributes = []): ReturnOrderItem
{
    return ReturnOrderItem::factory()->create(array_merge([
        'return_order_id' => $return->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
    ], $attributes));
}

// --- Model ---

it('generates an SRT order number and links to its purchase order', function () {
    $purchaseOrder = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);
    $return = supplierReturn(['purchase_order_id' => $purchaseOrder->id]);
    ReturnOrder::factory()->customerReturn()->create(['location_id' => $this->location->id]);

    expect($return->order_number)->toStartWith('SRT-')
        ->and($return->purchaseOrder->is($purchaseOrder))->toBeTrue()
        ->and($purchaseOrder->supplierReturns->pluck('id')->all())->toBe([$return->id]);
});

it('converts a derived unit quantity to the base unit', function () {
    $baseUnit = Unit::create(['name' => 'Kilogram', 'abbreviation' => 'kg']);
    $derivedUnit = Unit::create([
        'name' => 'Ton',
        'abbreviation' => 't',
        'base_unit_id' => $baseUnit->id,
        'conversion_factor' => 1000,
    ]);
    $item = supplierReturnItem(supplierReturn(), Product::factory()->create(), 2, ['unit_id' => $derivedUnit->id]);

    expect($item->convertedQuantity())->toBe(2000.0);
});

// --- List & Create ---

it('can render the supplier returns list page', function () {
    Livewire::test(ListSupplierReturns::class)->assertSuccessful();
});

it('lists supplier returns but not customer refunds', function () {
    $returns = collect([supplierReturn(), supplierReturn()]);
    $refund = ReturnOrder::factory()->customerReturn()->create(['location_id' => $this->location->id]);

    Livewire::test(ListSupplierReturns::class)
        ->assertCanSeeTableRecords($returns)
        ->assertCanNotSeeTableRecords([$refund]);
});

it('does not open a customer refund through the supplier returns resource', function () {
    $refund = ReturnOrder::factory()->customerReturn()->create(['location_id' => $this->location->id]);

    $this->get(SupplierReturnResource::getUrl('view', ['record' => $refund]))->assertNotFound();
});

it('can filter supplier returns by status', function () {
    $draft = supplierReturn();
    $approved = supplierReturn(['status' => ReturnOrderStatus::Approved]);

    Livewire::test(ListSupplierReturns::class)
        ->filterTable('status', ReturnOrderStatus::Approved->value)
        ->assertCanSeeTableRecords([$approved])
        ->assertCanNotSeeTableRecords([$draft]);
});

it('can create a supplier return on the fly', function () {
    $product = Product::factory()->create(['cost' => 12.5]);

    Livewire::test(CreateSupplierReturn::class)
        ->fillForm([
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'reason' => 'Damaged on arrival',
            'items.0.product_id' => $product->id,
            'items.0.quantity' => 3,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $return = ReturnOrder::query()->where('reason', 'Damaged on arrival')->firstOrFail();

    expect($return->type)->toBe(ReturnOrderType::SupplierReturn)
        ->and($return->status)->toBe(ReturnOrderStatus::Draft)
        ->and($return->supplier_id)->toBe($this->supplier->id)
        ->and($return->created_by)->toBe(auth()->id())
        ->and($return->items->first()->price)->toBe(12.5);
});

it('defaults line cost and unit from the original purchase order line', function () {
    $unit = Unit::create(['name' => 'Box', 'abbreviation' => 'bx']);
    $product = Product::factory()->create(['cost' => 10.0]);
    $purchaseOrder = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'quantity' => 5,
        'unit_cost' => 8.0,
    ]);

    Livewire::withQueryParams([
        'purchase_order_id' => $purchaseOrder->id,
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ])
        ->test(CreateSupplierReturn::class)
        ->assertSchemaStateSet([
            'purchase_order_id' => $purchaseOrder->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
        ])
        ->fillForm([
            'reason' => 'Wrong item',
            'items.0.product_id' => $product->id,
        ])
        ->assertSchemaStateSet([
            'items.0.price' => 8.0,
            'items.0.unit_id' => $unit->id,
        ])
        ->fillForm(['items.0.quantity' => 2])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas(ReturnOrder::class, [
        'purchase_order_id' => $purchaseOrder->id,
        'type' => ReturnOrderType::SupplierReturn,
    ]);
    assertDatabaseHas(ReturnOrderItem::class, ['product_id' => $product->id, 'unit_id' => $unit->id, 'price' => 8.0]);
});

it('caps the returned quantity at the purchase order line quantity', function () {
    $product = Product::factory()->create();
    $purchaseOrder = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);

    Livewire::test(CreateSupplierReturn::class)
        ->fillForm([
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $purchaseOrder->id,
            'location_id' => $this->location->id,
            'reason' => 'Too many',
        ])
        ->fillForm([
            'items.0.product_id' => $product->id,
            'items.0.quantity' => 6,
        ])
        ->call('create')
        ->assertHasFormErrors(['items.0.quantity' => 'max']);
});

it('validates required fields on create', function () {
    Livewire::test(CreateSupplierReturn::class)
        ->fillForm([
            'supplier_id' => null,
            'location_id' => null,
            'reason' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['supplier_id' => 'required', 'location_id' => 'required', 'reason' => 'required']);
});

it('can soft-delete a draft supplier return', function () {
    $return = supplierReturn();

    Livewire::test(ListSupplierReturns::class)
        ->callAction(TestAction::make('delete')->table($return));

    assertSoftDeleted($return);
});

// --- Edit & View ---

it('can edit a draft supplier return', function () {
    $return = supplierReturn();
    supplierReturnItem($return, Product::factory()->create(), 1);

    Livewire::test(EditSupplierReturn::class, ['record' => $return->id])
        ->assertSchemaStateSet(['reason' => $return->reason])
        ->fillForm(['reason' => 'Updated reason'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($return->fresh()->reason)->toBe('Updated reason');
});

it('cannot edit an approved supplier return', function () {
    $return = supplierReturn(['status' => ReturnOrderStatus::Approved]);

    $this->get(SupplierReturnResource::getUrl('edit', ['record' => $return]))->assertForbidden();
});

it('can render the view page', function () {
    $return = supplierReturn();

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])
        ->assertSuccessful()
        ->assertSee($return->order_number)
        ->assertSee($this->supplier->name);
});

// --- Workflow ---

it('can approve a draft supplier return without touching stock', function () {
    $product = Product::factory()->create();
    $product->addStock(10, $this->location->id);
    $return = supplierReturn();
    supplierReturnItem($return, $product, 4);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])
        ->assertActionHidden('complete')
        ->callAction('approve');

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Approved)
        ->and($product->totalStock($this->location))->toBe(10.0);
});

it('completes an approved supplier return and deducts stock against the return line', function () {
    $product = Product::factory()->create(['cost' => 10.0]);
    $product->addStock(10, $this->location->id);
    $return = supplierReturn(['status' => ReturnOrderStatus::Approved]);
    $item = supplierReturnItem($return, $product, 4, ['price' => 8.0]);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])
        ->assertActionHidden('approve')
        ->callAction('complete')
        ->assertNotified('Supplier return completed');

    $movement = Movement::query()
        ->where('reference_type', $item->getMorphClass())
        ->where('reference_id', $item->id)
        ->firstOrFail();

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Completed)
        ->and($product->totalStock($this->location))->toBe(6.0)
        ->and((float) $movement->quantity)->toBe(4.0)
        ->and($movement->transaction->causable->is($return))->toBeTrue();
});

it('deducts the base-unit quantity when a line uses a derived unit', function () {
    $baseUnit = Unit::create(['name' => 'Kilogram', 'abbreviation' => 'kg']);
    $derivedUnit = Unit::create([
        'name' => 'Ton',
        'abbreviation' => 't',
        'base_unit_id' => $baseUnit->id,
        'conversion_factor' => 1000,
    ]);
    $product = Product::factory()->create(['unit_id' => $baseUnit->id]);
    $product->addStock(5000, $this->location->id);
    $return = supplierReturn(['status' => ReturnOrderStatus::Approved]);
    supplierReturnItem($return, $product, 2, ['unit_id' => $derivedUnit->id]);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])->callAction('complete');

    expect($product->totalStock($this->location))->toBe(3000.0);
});

it('skips non-inventory products when completing', function () {
    $product = Product::factory()->nonInventory()->create();
    $return = supplierReturn(['status' => ReturnOrderStatus::Approved]);
    supplierReturnItem($return, $product, 3);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])->callAction('complete');

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Completed);
});

it('blocks completion and changes nothing when stock is insufficient', function () {
    $enough = Product::factory()->create();
    $enough->addStock(10, $this->location->id);
    $short = Product::factory()->create();
    $short->addStock(2, $this->location->id);
    $return = supplierReturn(['status' => ReturnOrderStatus::Approved]);
    supplierReturnItem($return, $enough, 5);
    supplierReturnItem($return, $short, 3);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])
        ->callAction('complete')
        ->assertNotified('Cannot complete — insufficient stock');

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Approved)
        ->and($enough->totalStock($this->location))->toBe(10.0)
        ->and($short->totalStock($this->location))->toBe(2.0);
});

it('does not count stock reserved for sale orders as available', function () {
    $product = Product::factory()->create();
    $product->addStock(10, $this->location->id);
    $product->reserve(8, $this->location->id);
    $return = supplierReturn(['status' => ReturnOrderStatus::Approved]);
    supplierReturnItem($return, $product, 5);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])
        ->callAction('complete')
        ->assertNotified('Cannot complete — insufficient stock');

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Approved)
        ->and($product->totalStock($this->location))->toBe(10.0);
});

it('can cancel a draft or approved supplier return with a logged reason', function (ReturnOrderStatus $status) {
    $product = Product::factory()->create();
    $product->addStock(10, $this->location->id);
    $return = supplierReturn(['status' => $status]);
    supplierReturnItem($return, $product, 4);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])
        ->callAction('cancel', data: ['reason' => 'Supplier refused']);

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Cancelled)
        ->and($product->totalStock($this->location))->toBe(10.0)
        ->and($return->statusLogs()->reorder()->latest('id')->first()->reason)->toBe('Supplier refused');
})->with([ReturnOrderStatus::Draft, ReturnOrderStatus::Approved]);

it('only offers cancel on a completed return', function () {
    $return = supplierReturn(['status' => ReturnOrderStatus::Completed]);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])
        ->assertActionHidden('approve')
        ->assertActionHidden('complete')
        ->assertActionVisible('cancel');
});

it('hides all workflow actions on a cancelled return', function () {
    $return = supplierReturn(['status' => ReturnOrderStatus::Cancelled]);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])
        ->assertActionHidden('approve')
        ->assertActionHidden('complete')
        ->assertActionHidden('cancel');
});

it('restores stock when cancelling a completed return', function () {
    $product = Product::factory()->create(['cost' => 10.0]);
    $product->addStock(10, $this->location->id);
    $return = supplierReturn(['status' => ReturnOrderStatus::Approved]);
    $item = supplierReturnItem($return, $product, 4);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])->callAction('complete');
    expect($product->totalStock($this->location))->toBe(6.0);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])
        ->callAction('cancel', data: ['reason' => 'Supplier rejected the return']);

    $restore = Movement::query()
        ->where('reference_type', $item->getMorphClass())
        ->where('reference_id', $item->id)
        ->where('type', 'add')
        ->firstOrFail();

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Cancelled)
        ->and($product->totalStock($this->location))->toBe(10.0)
        ->and((float) $restore->cost_per_unit)->toBe(10.0)
        ->and($return->statusLogs()->reorder()->latest('id')->first()->reason)->toBe('Supplier rejected the return');
});

it('restores the base-unit quantity when cancelling a completed return in a derived unit', function () {
    $baseUnit = Unit::create(['name' => 'Kilogram', 'abbreviation' => 'kg']);
    $ton = Unit::create([
        'name' => 'Ton',
        'abbreviation' => 't',
        'base_unit_id' => $baseUnit->id,
        'conversion_factor' => 1000,
    ]);
    $product = Product::factory()->create(['unit_id' => $baseUnit->id]);
    $product->addStock(3000, $this->location->id);
    $return = supplierReturn(['status' => ReturnOrderStatus::Completed]);
    supplierReturnItem($return, $product, 2, ['unit_id' => $ton->id]);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])
        ->callAction('cancel', data: ['reason' => 'Wrong return']);

    expect($product->totalStock($this->location))->toBe(5000.0);
});

it('skips non-inventory products when cancelling a completed return', function () {
    $return = supplierReturn(['status' => ReturnOrderStatus::Completed]);
    supplierReturnItem($return, Product::factory()->nonInventory()->create(), 3);

    Livewire::test(ViewSupplierReturn::class, ['record' => $return->id])
        ->callAction('cancel', data: ['reason' => 'Wrong return']);

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Cancelled);
});

// --- Purchase order integration ---

it('offers a create supplier return action only on received purchase orders', function () {
    $received = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);
    $confirmed = PurchaseOrder::factory()->confirmed()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $received->id])
        ->assertActionVisible('createSupplierReturn')
        ->assertActionHasUrl('createSupplierReturn', SupplierReturnResource::getUrl('create', [
            'purchase_order_id' => $received->id,
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
        ]));

    Livewire::test(ViewPurchaseOrder::class, ['record' => $confirmed->id])
        ->assertActionHidden('createSupplierReturn');
});

it('lists supplier returns on the purchase order view page', function () {
    $purchaseOrder = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);
    $return = supplierReturn(['purchase_order_id' => $purchaseOrder->id]);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $purchaseOrder->id])
        ->assertSee('Supplier Returns')
        ->assertSee($return->order_number)
        ->assertSee(SupplierReturnResource::getUrl('view', ['record' => $return]));
});
