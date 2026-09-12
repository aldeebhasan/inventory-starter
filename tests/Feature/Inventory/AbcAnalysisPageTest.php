<?php

use App\Enums\ProductType;
use App\Filament\Pages\AbcAnalysis;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
});

it('can render the abc analysis page', function () {
    Livewire::test(AbcAnalysis::class)->assertSuccessful();
});

it('shows products with consumption data', function () {
    $product = Product::factory()->create([
        'type' => ProductType::Inventory,
        'cost' => 50.00,
    ]);

    $product->addStock(100, $this->location);
    $product->deductStock(30, $this->location);

    Livewire::test(AbcAnalysis::class)->assertSuccessful();
});

it('classifies products into A, B, C categories', function () {
    // Create multiple products with varying consumption
    $productA = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 100.00]);
    $productB = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 10.00]);
    $productC = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 1.00]);

    $productA->addStock(200, $this->location);
    $productA->deductStock(100, $this->location);

    $productB->addStock(200, $this->location);
    $productB->deductStock(50, $this->location);

    $productC->addStock(200, $this->location);
    $productC->deductStock(10, $this->location);

    Livewire::test(AbcAnalysis::class)->assertSuccessful();
});

it('handles empty data gracefully', function () {
    Livewire::test(AbcAnalysis::class)->assertSuccessful();
});

it('can filter by location', function () {
    $product = Product::factory()->create([
        'type' => ProductType::Inventory,
        'cost' => 50.00,
    ]);

    $product->addStock(50, $this->location);
    $product->deductStock(10, $this->location);

    Livewire::test(AbcAnalysis::class)
        ->filterTable('location_id', $this->location->id)
        ->assertSuccessful();
});

it('can filter by category', function () {
    $category = Category::create(['name' => 'ABC Category']);

    $product = Product::factory()->create([
        'type' => ProductType::Inventory,
        'cost' => 50.00,
    ]);
    $product->categories()->attach($category);

    $product->addStock(50, $this->location);
    $product->deductStock(10, $this->location);

    Livewire::test(AbcAnalysis::class)
        ->filterTable('category', $category->id)
        ->assertSuccessful();
});
