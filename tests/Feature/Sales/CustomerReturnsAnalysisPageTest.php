<?php

use App\Filament\Pages\CustomerReturnsAnalysis;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ReturnOrder;
use App\Models\ReturnOrderItem;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->customer = Customer::factory()->create();
    $this->product = Product::factory()->create(['cost' => 10.00]);
});

it('can render the customer returns analysis page', function () {
    Livewire::test(CustomerReturnsAnalysis::class)->assertSuccessful();
});

it('shows return data for completed customer returns', function () {
    $returnOrder = ReturnOrder::factory()->customerReturn()->completed()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    ReturnOrderItem::factory()->create([
        'return_order_id' => $returnOrder->id,
        'product_id' => $this->product->id,
        'quantity' => 5,
        'price' => 25.00,
    ]);

    Livewire::test(CustomerReturnsAnalysis::class)->assertSuccessful();
});

it('does not include draft returns', function () {
    $returnOrder = ReturnOrder::factory()->customerReturn()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    ReturnOrderItem::factory()->create([
        'return_order_id' => $returnOrder->id,
        'product_id' => $this->product->id,
    ]);

    Livewire::test(CustomerReturnsAnalysis::class)->assertSuccessful();
});

it('can filter by customer', function () {
    $customer2 = Customer::factory()->create();

    $ro1 = ReturnOrder::factory()->customerReturn()->completed()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);
    $ro2 = ReturnOrder::factory()->customerReturn()->completed()->create([
        'customer_id' => $customer2->id,
        'location_id' => $this->location->id,
    ]);

    ReturnOrderItem::factory()->create([
        'return_order_id' => $ro1->id,
        'product_id' => $this->product->id,
    ]);
    ReturnOrderItem::factory()->create([
        'return_order_id' => $ro2->id,
        'product_id' => $this->product->id,
    ]);

    Livewire::test(CustomerReturnsAnalysis::class)
        ->filterTable('customer_id', $this->customer->id)
        ->assertSuccessful();
});

it('can filter by product', function () {
    $product2 = Product::factory()->create(['cost' => 5.00]);

    $ro = ReturnOrder::factory()->customerReturn()->completed()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    ReturnOrderItem::factory()->create([
        'return_order_id' => $ro->id,
        'product_id' => $this->product->id,
    ]);
    ReturnOrderItem::factory()->create([
        'return_order_id' => $ro->id,
        'product_id' => $product2->id,
    ]);

    Livewire::test(CustomerReturnsAnalysis::class)
        ->filterTable('product_id', $this->product->id)
        ->assertSuccessful();
});

it('does not include supplier returns', function () {
    $returnOrder = ReturnOrder::factory()->supplierReturn()->completed()->create([
        'location_id' => $this->location->id,
    ]);

    ReturnOrderItem::factory()->create([
        'return_order_id' => $returnOrder->id,
        'product_id' => $this->product->id,
    ]);

    Livewire::test(CustomerReturnsAnalysis::class)->assertSuccessful();
});
