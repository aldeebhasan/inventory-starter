<?php

use Aldeebhasan\Inventorix\Models\Stock;
use App\Enums\ProductType;
use App\Filament\Pages\ReorderSuggestions;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the reorder suggestions page', function () {
    Livewire::test(ReorderSuggestions::class)->assertSuccessful();
});

it('shows low stock products', function () {
    $this->product->addStock(3, $this->location);
    $this->product->setStockThreshold(min: 10, location: $this->location);

    $stocks = Stock::whereHasMorph('stockable', [Product::class])
        ->where('stockable_id', $this->product->id)
        ->get();

    Livewire::test(ReorderSuggestions::class)
        ->assertCanSeeTableRecords($stocks);
});

it('does not show products above threshold', function () {
    $this->product->addStock(15, $this->location);
    $this->product->setStockThreshold(min: 10, location: $this->location);

    $stocks = Stock::where('stockable_id', $this->product->id)->get();

    Livewire::test(ReorderSuggestions::class)
        ->assertCanNotSeeTableRecords($stocks);
});

it('can filter by location', function () {
    $otherLocation = createLocation(['name' => 'Other Warehouse']);

    $this->product->addStock(3, $this->location);
    $this->product->setStockThreshold(min: 10, location: $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);
    $product2->addStock(2, $otherLocation);
    $product2->setStockThreshold(min: 10, location: $otherLocation);

    $stockInLocation = Stock::where('location_id', $this->location->id)
        ->where('stockable_id', $this->product->id)
        ->get();
    $stockInOther = Stock::where('location_id', $otherLocation->id)->get();

    Livewire::test(ReorderSuggestions::class)
        ->filterTable('location_id', $this->location->id)
        ->assertCanSeeTableRecords($stockInLocation)
        ->assertCanNotSeeTableRecords($stockInOther);
});

it('can filter by category', function () {
    $category = Category::create(['name' => 'Reorder Category']);
    $this->product->categories()->attach($category);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);

    $this->product->addStock(3, $this->location);
    $this->product->setStockThreshold(min: 10, location: $this->location);

    $product2->addStock(2, $this->location);
    $product2->setStockThreshold(min: 10, location: $this->location);

    $stock1 = Stock::where('stockable_id', $this->product->id)->get();

    Livewire::test(ReorderSuggestions::class)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords($stock1);
});
