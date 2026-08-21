<?php

use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Enums\ProductType;
use App\Filament\Pages\Inventories;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the inventories page', function () {
    Livewire::test(Inventories::class)->assertSuccessful();
});

it('can list stock records in the table', function () {
    $this->product->addStock(10, $this->location);

    $stocks = Stock::whereHasMorph('stockable', [Product::class])->get();

    Livewire::test(Inventories::class)
        ->assertCanSeeTableRecords($stocks);
});

it('can filter stocks by location', function () {
    $otherLocation = createLocation(['name' => 'Other Warehouse']);

    $this->product->addStock(5, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);
    $product2->addStock(8, $otherLocation);

    $stockInLocation = Stock::where('location_id', $this->location->id)->get();
    $stockInOther = Stock::where('location_id', $otherLocation->id)->get();

    Livewire::test(Inventories::class)
        ->filterTable('location_id', $this->location->id)
        ->assertCanSeeTableRecords($stockInLocation)
        ->assertCanNotSeeTableRecords($stockInOther);
});

it('can filter stocks by product', function () {
    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);

    $this->product->addStock(5, $this->location);
    $product2->addStock(8, $this->location);

    $stock1 = Stock::where('stockable_id', $this->product->id)->where('stockable_type', Product::class)->get();
    $stock2 = Stock::where('stockable_id', $product2->id)->where('stockable_type', Product::class)->get();

    Livewire::test(Inventories::class)
        ->filterTable('stockable_id', $this->product->id)
        ->assertCanSeeTableRecords($stock1)
        ->assertCanNotSeeTableRecords($stock2);
});

it('can set threshold for a stock record', function () {
    $this->product->addStock(10, $this->location);

    $stock = Stock::where('stockable_id', $this->product->id)
        ->where('stockable_type', Product::class)
        ->where('location_id', $this->location->id)
        ->firstOrFail();

    Livewire::test(Inventories::class)
        ->callTableAction('set_threshold', $stock, data: [
            'min_quantity' => 5,
            'max_quantity' => 20,
        ])
        ->assertHasNoTableActionErrors();

    $threshold = Threshold::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->where('location_id', $this->location->id)
        ->first();

    expect($threshold)->not->toBeNull()
        ->and((float) $threshold->min_quantity)->toBe(5.0)
        ->and((float) $threshold->max_quantity)->toBe(20.0);
});

it('can update an existing threshold', function () {
    $this->product->addStock(10, $this->location);

    $this->product->setStockThreshold(2, 15, $this->location);

    $stock = Stock::where('stockable_id', $this->product->id)
        ->where('stockable_type', Product::class)
        ->where('location_id', $this->location->id)
        ->firstOrFail();

    Livewire::test(Inventories::class)
        ->callTableAction('set_threshold', $stock, data: [
            'min_quantity' => 10,
            'max_quantity' => 50,
        ])
        ->assertHasNoTableActionErrors();

    $threshold = Threshold::where('stockable_type', Product::class)
        ->where('stockable_id', $this->product->id)
        ->where('location_id', $this->location->id)
        ->first();

    expect((float) $threshold->min_quantity)->toBe(10.0)
        ->and((float) $threshold->max_quantity)->toBe(50.0);
});

it('requires min_quantity when setting a threshold', function () {
    $this->product->addStock(10, $this->location);

    $stock = Stock::where('stockable_id', $this->product->id)
        ->where('stockable_type', Product::class)
        ->where('location_id', $this->location->id)
        ->firstOrFail();

    Livewire::test(Inventories::class)
        ->callTableAction('set_threshold', $stock, data: [
            'min_quantity' => null,
            'max_quantity' => null,
        ])
        ->assertHasTableActionErrors(['min_quantity' => 'required']);
});
