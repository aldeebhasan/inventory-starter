<?php

use App\Enums\ReturnOrderStatus;
use App\Enums\ReturnOrderType;
use App\Filament\Resources\RefundedOrders\Pages\CreateRefundedOrder;
use App\Filament\Resources\RefundedOrders\Pages\ListRefundedOrders;
use App\Filament\Resources\RefundedOrders\Pages\ViewRefundedOrder;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ReturnOrder;
use App\Models\ReturnOrderItem;
use App\Models\SaleOrder;
use App\Models\SaleOrderItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->customer = Customer::factory()->create();
});

// --- List & Create ---

it('can render the refunded orders list page', function () {
    Livewire::test(ListRefundedOrders::class)->assertSuccessful();
});

it('can list refunded orders in the table', function () {
    $returns = ReturnOrder::factory()->count(3)->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ListRefundedOrders::class)
        ->assertCanSeeTableRecords($returns);
});

it('does not list supplier returns in refunded orders table', function () {
    $customerReturn = ReturnOrder::factory()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);
    $supplierReturn = ReturnOrder::factory()->supplierReturn()->create([
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ListRefundedOrders::class)
        ->assertCanSeeTableRecords([$customerReturn])
        ->assertCanNotSeeTableRecords([$supplierReturn]);
});

it('auto-generates order number with CRT prefix', function () {
    $return = ReturnOrder::factory()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    expect($return->order_number)->toStartWith('CRT-');
});

it('can create a refunded order', function () {
    $product = Product::factory()->create();

    Livewire::test(CreateRefundedOrder::class)
        ->fillForm([
            'customer_id' => $this->customer->id,
            'location_id' => $this->location->id,
            'reason' => 'Damaged goods',
            'items.0.product_id' => $product->id,
            'items.0.quantity' => 3,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(ReturnOrder::class, [
        'customer_id' => $this->customer->id,
        'type' => ReturnOrderType::CustomerReturn,
        'status' => ReturnOrderStatus::Draft,
        'reason' => 'Damaged goods',
    ]);
});

it('validates required fields on create', function () {
    Livewire::test(CreateRefundedOrder::class)
        ->fillForm([
            'customer_id' => null,
            'location_id' => null,
            'reason' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'customer_id' => 'required',
            'location_id' => 'required',
            'reason' => 'required',
        ]);
});

it('can soft-delete a refunded order', function () {
    $return = ReturnOrder::factory()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ListRefundedOrders::class)
        ->callAction(TestAction::make('delete')->table($return));

    assertSoftDeleted(ReturnOrder::class, ['id' => $return->id]);
});

// --- View ---

it('can render the view page', function () {
    $return = ReturnOrder::factory()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ViewRefundedOrder::class, ['record' => $return->id])
        ->assertSuccessful();
});

// --- Workflow: Complete ---

it('can complete a draft refunded order and adds stock back', function () {
    $product = Product::factory()->create();
    $product->addStock(10, $this->location->id);

    $return = ReturnOrder::factory()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'status' => ReturnOrderStatus::Draft,
    ]);
    ReturnOrderItem::factory()->create([
        'return_order_id' => $return->id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);

    Livewire::test(ViewRefundedOrder::class, ['record' => $return->id])
        ->callAction('complete');

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Completed);

    // Stock should be 10 + 5 = 15
    expect($product->totalStock($this->location))->toBe(15.0);
});

it('complete action is hidden on completed return', function () {
    $return = ReturnOrder::factory()->completed()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ViewRefundedOrder::class, ['record' => $return->id])
        ->assertActionHidden('complete');
});

// --- Workflow: Cancel ---

it('can cancel a draft refunded order without inventory reversal', function () {
    $product = Product::factory()->create();
    $product->addStock(10, $this->location->id);

    $return = ReturnOrder::factory()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'status' => ReturnOrderStatus::Draft,
    ]);
    ReturnOrderItem::factory()->create([
        'return_order_id' => $return->id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);

    Livewire::test(ViewRefundedOrder::class, ['record' => $return->id])
        ->callAction('cancel', data: ['reason' => 'Changed mind']);

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Cancelled);

    // Stock should remain at 10 (no reversal needed)
    expect($product->totalStock($this->location))->toBe(10.0);
});

it('can cancel a completed refunded order and reverses stock', function () {
    $product = Product::factory()->create();
    $product->addStock(10, $this->location->id);

    $return = ReturnOrder::factory()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'status' => ReturnOrderStatus::Draft,
    ]);
    ReturnOrderItem::factory()->create([
        'return_order_id' => $return->id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);

    // First complete it (stock becomes 15)
    Livewire::test(ViewRefundedOrder::class, ['record' => $return->id])
        ->callAction('complete');

    expect($product->totalStock($this->location))->toBe(15.0);

    // Now cancel the completed return (stock should go back to 10)
    Livewire::test(ViewRefundedOrder::class, ['record' => $return->id])
        ->callAction('cancel', data: ['reason' => 'Error']);

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Cancelled);
    expect($product->totalStock($this->location))->toBe(10.0);
});

it('cancel is not available on a cancelled return', function () {
    $return = ReturnOrder::factory()->cancelled()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ViewRefundedOrder::class, ['record' => $return->id])
        ->assertActionHidden('cancel');
});

it('cancel logs reason in status history', function () {
    $return = ReturnOrder::factory()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'status' => ReturnOrderStatus::Draft,
    ]);

    Livewire::test(ViewRefundedOrder::class, ['record' => $return->id])
        ->callAction('cancel', data: ['reason' => 'Wrong items']);

    assertDatabaseHas('status_logs', [
        'trackable_type' => ReturnOrder::class,
        'trackable_id' => $return->id,
        'new_status' => ReturnOrderStatus::Cancelled->value,
        'reason' => 'Wrong items',
    ]);
});

// --- Edit restrictions ---

it('cannot edit a completed refunded order', function () {
    $return = ReturnOrder::factory()->completed()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    $this->get(route('filament.admin.resources.refunded-orders.edit', $return))
        ->assertForbidden();
});

// --- Filtering ---

it('can filter refunded orders by status', function () {
    $draft = ReturnOrder::factory()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'status' => ReturnOrderStatus::Draft,
    ]);
    $completed = ReturnOrder::factory()->completed()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ListRefundedOrders::class)
        ->filterTable('status', ReturnOrderStatus::Draft->value)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$completed]);
});

// --- Creation Modes ---

it('can create a return from a sale order with sale order item price', function () {
    $product = Product::factory()->create(['price' => 50.0]);
    $saleOrder = SaleOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);
    SaleOrderItem::factory()->create([
        'sale_order_id' => $saleOrder->id,
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_price' => 75.0,
    ]);

    Livewire::test(CreateRefundedOrder::class)
        ->fillForm([
            'customer_id' => $this->customer->id,
            'sale_order_id' => $saleOrder->id,
            'location_id' => $this->location->id,
            'reason' => 'Defective',
            'items.0.product_id' => $product->id,
            'items.0.quantity' => 2,
            'items.0.price' => 75.0,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $return = ReturnOrder::query()->latest()->first();
    expect($return->sale_order_id)->toBe($saleOrder->id);

    assertDatabaseHas(ReturnOrderItem::class, [
        'return_order_id' => $return->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => 75.0,
    ]);
});

it('can create a return on the fly with product price', function () {
    $product = Product::factory()->create(['price' => 30.0]);

    Livewire::test(CreateRefundedOrder::class)
        ->fillForm([
            'customer_id' => $this->customer->id,
            'location_id' => $this->location->id,
            'reason' => 'Not needed',
            'items.0.product_id' => $product->id,
            'items.0.quantity' => 1,
            'items.0.price' => 30.0,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $return = ReturnOrder::query()->latest()->first();
    expect($return->sale_order_id)->toBeNull();

    assertDatabaseHas(ReturnOrderItem::class, [
        'return_order_id' => $return->id,
        'product_id' => $product->id,
        'price' => 30.0,
    ]);
});

it('uses sale order item price as cost when completing a return from sale order', function () {
    $product = Product::factory()->create(['cost' => 20.0, 'price' => 50.0]);
    $product->addStock(10, $this->location->id);

    $saleOrder = SaleOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);
    SaleOrderItem::factory()->create([
        'sale_order_id' => $saleOrder->id,
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_price' => 75.0,
    ]);

    $return = ReturnOrder::factory()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'sale_order_id' => $saleOrder->id,
        'status' => ReturnOrderStatus::Draft,
    ]);
    ReturnOrderItem::factory()->create([
        'return_order_id' => $return->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'price' => 75.0,
    ]);

    Livewire::test(ViewRefundedOrder::class, ['record' => $return->id])
        ->callAction('complete');

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Completed);
    expect($product->totalStock($this->location))->toBe(13.0);
});

it('uses product cost when completing a return on the fly', function () {
    $product = Product::factory()->create(['cost' => 20.0, 'price' => 50.0]);
    $product->addStock(10, $this->location->id);

    $return = ReturnOrder::factory()->create([
        'type' => ReturnOrderType::CustomerReturn,
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'status' => ReturnOrderStatus::Draft,
    ]);
    ReturnOrderItem::factory()->create([
        'return_order_id' => $return->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'price' => 50.0,
    ]);

    Livewire::test(ViewRefundedOrder::class, ['record' => $return->id])
        ->callAction('complete');

    expect($return->fresh()->status)->toBe(ReturnOrderStatus::Completed);
    expect($product->totalStock($this->location))->toBe(13.0);
});
