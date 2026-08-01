<?php

use App\Enums\SaleOrderStatus;
use App\Filament\Resources\SaleOrders\Pages\CreateSaleOrder;
use App\Filament\Resources\SaleOrders\Pages\ListSaleOrders;
use App\Filament\Resources\SaleOrders\Pages\ViewSaleOrder;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleOrder;
use App\Models\SaleOrderItem;
use App\Models\Unit;
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

it('can render the sale orders list page', function () {
    Livewire::test(ListSaleOrders::class)->assertSuccessful();
});

it('can list sale orders in the table', function () {
    $orders = SaleOrder::factory()->count(3)->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ListSaleOrders::class)
        ->assertCanSeeTableRecords($orders);
});

it('auto-generates order number on creation', function () {
    $order = SaleOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    expect($order->order_number)->toStartWith('SO-');
});

it('can create a sale order', function () {
    Livewire::test(CreateSaleOrder::class)
        ->fillForm([
            'customer_id' => $this->customer->id,
            'location_id' => $this->location->id,
            'ordered_at' => now()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(SaleOrder::class, [
        'customer_id' => $this->customer->id,
        'status' => SaleOrderStatus::Draft,
    ]);
});

it('can soft-delete a sale order', function () {
    $order = SaleOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ListSaleOrders::class)
        ->callAction(TestAction::make('delete')->table($order));

    assertSoftDeleted(SaleOrder::class, ['id' => $order->id]);
});

// --- Workflow Actions ---

it('can confirm a draft sale order and reserves stock', function () {
    $product = Product::factory()->create();

    // First add some stock so reservation can succeed
    $product->addStock(50, $this->location->id);

    $order = SaleOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
    ]);
    $item = SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);

    Livewire::test(ViewSaleOrder::class, ['record' => $order->id])
        ->callAction('confirm');

    expect($order->fresh()->status)->toBe(SaleOrderStatus::Confirmed);

    // Reservation stored on the item
    expect($item->fresh()->reservation_id)->not->toBeNull();

    // Reservation record created in inventorix
    assertDatabaseHas('inventorix_reservations', [
        'stockable_type' => Product::class,
        'stockable_id' => $product->id,
    ]);
});

it('can pick a confirmed sale order', function () {
    $order = SaleOrder::factory()->confirmed()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ViewSaleOrder::class, ['record' => $order->id])
        ->callAction('pick');

    expect($order->fresh()->status)->toBe(SaleOrderStatus::Picked);
});

it('can ship a picked sale order and fulfills reservations', function () {
    $product = Product::factory()->create();
    $product->addStock(50, $this->location->id);

    $order = SaleOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Picked,
    ]);

    // Create a reservation manually so the ship action can fulfill it
    $reservation = $product->reserve(5, $this->location->id);
    $item = SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 5,
        'reservation_id' => $reservation->id,
    ]);

    Livewire::test(ViewSaleOrder::class, ['record' => $order->id])
        ->callAction('ship');

    $order->refresh();
    expect($order->status)->toBe(SaleOrderStatus::Shipped);
    expect($order->shipped_at)->not->toBeNull();
});

it('can cancel a draft sale order', function () {
    $order = SaleOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
    ]);

    Livewire::test(ViewSaleOrder::class, ['record' => $order->id])
        ->callAction('cancel');

    expect($order->fresh()->status)->toBe(SaleOrderStatus::Cancelled);
});

it('can cancel a confirmed sale order and releases reservations', function () {
    $product = Product::factory()->create();
    $product->addStock(50, $this->location->id);

    $order = SaleOrder::factory()->confirmed()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);
    $reservation = $product->reserve(5, $this->location->id);
    $item = SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 5,
        'reservation_id' => $reservation->id,
    ]);

    Livewire::test(ViewSaleOrder::class, ['record' => $order->id])
        ->callAction('cancel');

    expect($order->fresh()->status)->toBe(SaleOrderStatus::Cancelled);
    expect($item->fresh()->reservation_id)->toBeNull();
});

it('confirm action is not visible on a shipped order', function () {
    $order = SaleOrder::factory()->shipped()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ViewSaleOrder::class, ['record' => $order->id])
        ->assertActionHidden('confirm');
});

it('cannot edit a confirmed sale order', function () {
    $order = SaleOrder::factory()->confirmed()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    $this->get(route('filament.admin.resources.sale-orders.edit', $order))
        ->assertForbidden();
});

it('converts derived unit quantity to base unit on confirm reservation', function () {
    $baseUnit = Unit::create(['name' => 'Kilogram', 'abbreviation' => 'kg']);
    $derivedUnit = Unit::create([
        'name' => 'Ton',
        'abbreviation' => 't',
        'base_unit_id' => $baseUnit->id,
        'conversion_factor' => 1000,
    ]);
    $product = Product::factory()->create(['unit_id' => $baseUnit->id]);
    $product->addStock(5000, $this->location->id); // 5000 kg in stock

    $order = SaleOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
    ]);
    $item = SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $product->id,
        'unit_id' => $derivedUnit->id,
        'quantity' => 2, // 2 tons = 2000 kg
    ]);

    Livewire::test(ViewSaleOrder::class, ['record' => $order->id])
        ->callAction('confirm');

    expect($order->fresh()->status)->toBe(SaleOrderStatus::Confirmed);
    expect($item->fresh()->reservation_id)->not->toBeNull();

    // Reservation should be for 2000 kg (2 tons × 1000)
    assertDatabaseHas('inventorix_reservations', [
        'stockable_type' => Product::class,
        'stockable_id' => $product->id,
        'quantity' => 2000,
    ]);
});

it('can filter sale orders by status', function () {
    $draft = SaleOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
        'status' => SaleOrderStatus::Draft,
    ]);
    $shipped = SaleOrder::factory()->shipped()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ListSaleOrders::class)
        ->filterTable('status', SaleOrderStatus::Draft->value)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$shipped]);
});
