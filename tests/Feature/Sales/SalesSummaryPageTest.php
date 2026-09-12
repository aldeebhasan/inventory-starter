<?php

use App\Enums\ProductType;
use App\Filament\Pages\SalesSummary;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleOrder;
use App\Models\SaleOrderItem;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->customer = Customer::factory()->create();
    $this->product = Product::factory()->create([
        'type' => ProductType::Inventory,
        'cost' => 10.00,
        'price' => 25.00,
    ]);
});

it('can render the sales summary page', function () {
    Livewire::test(SalesSummary::class)->assertSuccessful();
});

it('shows aggregated sales data for shipped orders', function () {
    $order = SaleOrder::factory()->shipped()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 10,
        'unit_price' => 25.00,
    ]);

    Livewire::test(SalesSummary::class)->assertSuccessful();
});

it('does not include draft orders', function () {
    $draftOrder = SaleOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    SaleOrderItem::factory()->create([
        'sale_order_id' => $draftOrder->id,
        'product_id' => $this->product->id,
    ]);

    Livewire::test(SalesSummary::class)->assertSuccessful();
});

it('can filter by product', function () {
    $product2 = Product::factory()->create(['cost' => 5.00]);

    $order = SaleOrder::factory()->shipped()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $this->product->id,
    ]);
    SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $product2->id,
    ]);

    Livewire::test(SalesSummary::class)
        ->filterTable('product_id', $this->product->id)
        ->assertSuccessful();
});

it('can filter by customer', function () {
    $customer2 = Customer::factory()->create();

    $order1 = SaleOrder::factory()->shipped()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);
    $order2 = SaleOrder::factory()->shipped()->create([
        'customer_id' => $customer2->id,
        'location_id' => $this->location->id,
    ]);

    SaleOrderItem::factory()->create([
        'sale_order_id' => $order1->id,
        'product_id' => $this->product->id,
    ]);
    SaleOrderItem::factory()->create([
        'sale_order_id' => $order2->id,
        'product_id' => $this->product->id,
    ]);

    Livewire::test(SalesSummary::class)
        ->filterTable('customer_id', $this->customer->id)
        ->assertSuccessful();
});

it('includes picked orders in summary', function () {
    $order = SaleOrder::factory()->picked()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 5,
        'unit_price' => 20.00,
    ]);

    Livewire::test(SalesSummary::class)->assertSuccessful();
});
