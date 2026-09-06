<?php

use Aldeebhasan\Inventorix\Models\Stock;
use App\Enums\ProductType;
use App\Filament\Pages\StockReport;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the stock report page', function () {
    Livewire::test(StockReport::class)->assertSuccessful();
});

it('lists stock records in the table', function () {
    $this->product->addStock(10, $this->location);

    $stocks = Stock::whereHasMorph('stockable', [Product::class])->get();

    Livewire::test(StockReport::class)
        ->assertCanSeeTableRecords($stocks);
});

it('can filter by location', function () {
    $otherLocation = createLocation(['name' => 'Other Warehouse']);

    $this->product->addStock(5, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);
    $product2->addStock(8, $otherLocation);

    $stockInLocation = Stock::where('location_id', $this->location->id)->get();
    $stockInOther = Stock::where('location_id', $otherLocation->id)->get();

    Livewire::test(StockReport::class)
        ->filterTable('location_id', $this->location->id)
        ->assertCanSeeTableRecords($stockInLocation)
        ->assertCanNotSeeTableRecords($stockInOther);
});

it('can filter by category', function () {
    $category = Category::create(['name' => 'Tools']);
    $this->product->categories()->attach($category);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);

    $this->product->addStock(5, $this->location);
    $product2->addStock(3, $this->location);

    $stock1 = Stock::where('stockable_id', $this->product->id)->get();
    $stock2 = Stock::where('stockable_id', $product2->id)->get();

    Livewire::test(StockReport::class)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords($stock1)
        ->assertCanNotSeeTableRecords($stock2);
});

it('can filter low stock only', function () {
    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);

    $this->product->addStock(3, $this->location);
    $this->product->setStockThreshold(min: 10, location: $this->location);

    $product2->addStock(50, $this->location);
    $product2->setStockThreshold(min: 5, location: $this->location);

    $lowStock = Stock::where('stockable_id', $this->product->id)->get();
    $healthyStock = Stock::where('stockable_id', $product2->id)->get();

    Livewire::test(StockReport::class)
        ->filterTable('low_stock_only', true)
        ->assertCanSeeTableRecords($lowStock)
        ->assertCanNotSeeTableRecords($healthyStock);
});
