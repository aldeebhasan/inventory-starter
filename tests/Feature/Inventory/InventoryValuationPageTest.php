<?php

use Aldeebhasan\Inventorix\Models\Stock;
use App\Enums\ProductType;
use App\Filament\Exports\InventoryValuationExporter;
use App\Filament\Pages\InventoryValuation;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create([
        'type' => ProductType::Inventory,
        'cost' => 25.00,
    ]);
});

it('can render the inventory valuation page', function () {
    Livewire::test(InventoryValuation::class)->assertSuccessful();
});

it('lists stock records with positive quantity', function () {
    $this->product->addStock(10, $this->location);

    $stocks = Stock::whereHasMorph('stockable', [Product::class])
        ->where('quantity', '>', 0)
        ->get();

    Livewire::test(InventoryValuation::class)
        ->assertCanSeeTableRecords($stocks);
});

it('does not show zero-quantity stocks', function () {
    $this->product->addStock(5, $this->location);
    $this->product->deductStock(5, $this->location);

    $stocks = Stock::whereHasMorph('stockable', [Product::class])
        ->where('stockable_id', $this->product->id)
        ->get();

    Livewire::test(InventoryValuation::class)
        ->assertCanNotSeeTableRecords($stocks);
});

it('can filter by location', function () {
    $otherLocation = createLocation(['name' => 'Other Warehouse']);

    $this->product->addStock(10, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 15.00]);
    $product2->addStock(5, $otherLocation);

    $stockInLocation = Stock::where('location_id', $this->location->id)->get();
    $stockInOther = Stock::where('location_id', $otherLocation->id)->get();

    Livewire::test(InventoryValuation::class)
        ->filterTable('location_id', $this->location->id)
        ->assertCanSeeTableRecords($stockInLocation)
        ->assertCanNotSeeTableRecords($stockInOther);
});

it('can filter by brand', function () {
    $brand = Brand::create(['name' => 'TestBrand']);
    $this->product->update(['brand_id' => $brand->id]);
    $this->product->addStock(10, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 15.00]);
    $product2->addStock(5, $this->location);

    $stock1 = Stock::where('stockable_id', $this->product->id)->get();
    $stock2 = Stock::where('stockable_id', $product2->id)->get();

    Livewire::test(InventoryValuation::class)
        ->filterTable('brand', $brand->id)
        ->assertCanSeeTableRecords($stock1)
        ->assertCanNotSeeTableRecords($stock2);
});

it('can filter by category', function () {
    $category = Category::create(['name' => 'Valuation Category']);
    $this->product->categories()->attach($category);
    $this->product->addStock(10, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 15.00]);
    $product2->addStock(5, $this->location);

    $stock1 = Stock::where('stockable_id', $this->product->id)->get();
    $stock2 = Stock::where('stockable_id', $product2->id)->get();

    Livewire::test(InventoryValuation::class)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords($stock1)
        ->assertCanNotSeeTableRecords($stock2);
});

function seedInventoryValuationPageStocks(int $count, Location $location): void
{
    $category = Category::create(['name' => 'Category '.uniqid()]);
    $brand = Brand::create(['name' => 'Brand '.uniqid()]);

    for ($i = 0; $i < $count; $i++) {
        $product = Product::factory()->create(['type' => ProductType::Inventory, 'brand_id' => $brand->id, 'cost' => 3]);
        $product->categories()->attach($category);
        $product->addStock(20, $location);
    }
}

it('computes the grand total in SQL excluding reserved quantity', function () {
    $this->product->addStock(10, $this->location);
    $this->product->reserve(4, $this->location->id);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 10.00]);
    $product2->addStock(5, $this->location);

    $emptied = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 99.00]);
    $emptied->addStock(3, $this->location);
    $emptied->deductStock(3, $this->location);

    // (10 - 4) * 25 + 5 * 10 = 200
    $queries = countQueries(fn () => expect(InventoryValuation::grandTotal())->toBe(200.0));
    expect($queries)->toBe(1);

    $stock = Stock::where('stockable_id', $this->product->id)->firstOrFail();

    Livewire::test(InventoryValuation::class)
        ->assertTableColumnStateSet('total_value', '150.00', $stock)
        ->assertTableColumnStateSet('pct_of_total', '75.00%', $stock);
});

it('does not run per-row queries when rendering', function () {
    seedInventoryValuationPageStocks(2, $this->location);
    $small = countQueries(fn () => Livewire::test(InventoryValuation::class)->assertCountTableRecords(2));

    seedInventoryValuationPageStocks(6, $this->location);
    $large = countQueries(fn () => Livewire::test(InventoryValuation::class)->assertCountTableRecords(8));

    expect($large)->toBe($small);
});

it('sorts by product name', function () {
    $zeta = Product::factory()->create(['type' => ProductType::Inventory, 'name' => 'Zeta']);
    $alpha = Product::factory()->create(['type' => ProductType::Inventory, 'name' => 'Alpha']);
    $zeta->addStock(5, $this->location);
    $alpha->addStock(5, $this->location);

    $zetaStock = Stock::where('stockable_id', $zeta->id)->firstOrFail();
    $alphaStock = Stock::where('stockable_id', $alpha->id)->firstOrFail();

    Livewire::test(InventoryValuation::class)
        ->sortTable('stockable.name')
        ->assertCanSeeTableRecords([$alphaStock, $zetaStock], inOrder: true)
        ->sortTable('stockable.name', 'desc')
        ->assertCanSeeTableRecords([$zetaStock, $alphaStock], inOrder: true);
});

it('dispatches an export from the table header', function () {
    $this->product->addStock(10, $this->location);
    $exports = ExportAction::fake();

    Livewire::test(InventoryValuation::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(InventoryValuationExporter::class, function (Export $export, Builder $query): bool {
        expect($query->count())->toBe(1);

        return true;
    });
});

it('exports row values matching the table columns', function () {
    $brand = Brand::create(['name' => 'Acme']);
    $category = Category::create(['name' => 'Tools']);
    $this->product->update(['name' => 'Hammer', 'brand_id' => $brand->id]);
    $this->product->categories()->attach($category);
    $this->product->addStock(10, $this->location);
    $this->product->reserve(2, $this->location->id);

    $other = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 10.00]);
    $other->addStock(20, $this->location);

    $stock = Livewire::test(InventoryValuation::class)->instance()->getFilteredTableQuery()
        ->where('stockable_id', $this->product->id)->firstOrFail();

    // value (10 - 2) * 25 = 200 of grand total 200 + 200 = 400
    expect(InventoryValuationExporter::test()->export($stock))->toBe([
        'Hammer', 'Acme', 'Tools', $this->location->name, '10.0000', '8', '25', '200', '50',
    ]);
});

it('runs a real csv export end to end', function () {
    Storage::fake('local');
    seedInventoryValuationPageStocks(3, $this->location);

    Livewire::test(InventoryValuation::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $csv = collect(Storage::disk('local')->files($export->getFileDirectory()))
        ->filter(fn (string $file): bool => str_ends_with($file, '.csv'))
        ->map(fn (string $file): string => Storage::disk('local')->get($file))
        ->join('');

    expect($export->refresh()->successful_rows)->toBe(3)
        ->and($csv)->toContain(Product::query()->latest('id')->first()->name)
        ->and($csv)->toContain('33.33');
});
