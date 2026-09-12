<?php

use Aldeebhasan\Inventorix\Models\Threshold;
use App\Enums\ProductType;
use App\Filament\Pages\OverstockAlerts;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the overstock alerts page', function () {
    Livewire::test(OverstockAlerts::class)->assertSuccessful();
});

it('shows overstocked products', function () {
    $this->product->addStock(100, $this->location);

    Threshold::create([
        'stockable_type' => Product::class,
        'stockable_id' => $this->product->id,
        'location_id' => $this->location->id,
        'min_quantity' => 5,
        'max_quantity' => 50,
    ]);

    Livewire::test(OverstockAlerts::class)->assertSuccessful();
});

it('does not show products below max threshold', function () {
    $this->product->addStock(30, $this->location);

    Threshold::create([
        'stockable_type' => Product::class,
        'stockable_id' => $this->product->id,
        'location_id' => $this->location->id,
        'min_quantity' => 5,
        'max_quantity' => 50,
    ]);

    Livewire::test(OverstockAlerts::class)->assertSuccessful();
});

it('does not show products without max threshold', function () {
    $this->product->addStock(100, $this->location);

    Threshold::create([
        'stockable_type' => Product::class,
        'stockable_id' => $this->product->id,
        'location_id' => $this->location->id,
        'min_quantity' => 5,
        'max_quantity' => null,
    ]);

    Livewire::test(OverstockAlerts::class)->assertSuccessful();
});

it('can filter by location', function () {
    $otherLocation = createLocation(['name' => 'Other Warehouse']);

    $this->product->addStock(100, $this->location);

    Threshold::create([
        'stockable_type' => Product::class,
        'stockable_id' => $this->product->id,
        'location_id' => $this->location->id,
        'min_quantity' => 5,
        'max_quantity' => 50,
    ]);

    Livewire::test(OverstockAlerts::class)
        ->filterTable('location_id', $otherLocation->id)
        ->assertSuccessful();
});
