<?php

use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Enums\ProductType;
use App\Filament\Exports\InventoriesExporter;
use App\Filament\Pages\Inventories;
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

function seedInventoriesPageStocks(int $count, Location $location): void
{
    $category = Category::create(['name' => 'Category '.uniqid()]);
    $brand = Brand::create(['name' => 'Brand '.uniqid()]);

    for ($i = 0; $i < $count; $i++) {
        $product = Product::factory()->create(['type' => ProductType::Inventory, 'brand_id' => $brand->id, 'cost' => 3]);
        $product->categories()->attach($category);
        $product->addStock(20, $location);
        $product->setStockThreshold(min: 5, max: 50, location: $location);
    }
}

it('does not run per-row queries when rendering', function () {
    seedInventoriesPageStocks(2, $this->location);
    $small = countQueries(fn () => Livewire::test(Inventories::class)->assertCountTableRecords(2));

    seedInventoriesPageStocks(6, $this->location);
    $large = countQueries(fn () => Livewire::test(Inventories::class)->assertCountTableRecords(8));

    expect($large)->toBe($small);
});

it('shows thresholds and status from the table query', function () {
    $this->product->addStock(10, $this->location);
    $this->product->setStockThreshold(min: 12, max: 40, location: $this->location);
    $stock = Stock::where('stockable_id', $this->product->id)->firstOrFail();

    Livewire::test(Inventories::class)
        ->assertTableColumnStateSet('threshold_min', '12.00', $stock)
        ->assertTableColumnStateSet('threshold_max', '40.00', $stock)
        ->assertTableColumnStateSet('stock_status', 'Low Stock', $stock);
});

it('sorts by product name', function () {
    $zeta = Product::factory()->create(['type' => ProductType::Inventory, 'name' => 'Zeta']);
    $alpha = Product::factory()->create(['type' => ProductType::Inventory, 'name' => 'Alpha']);
    $zeta->addStock(5, $this->location);
    $alpha->addStock(5, $this->location);

    $zetaStock = Stock::where('stockable_id', $zeta->id)->firstOrFail();
    $alphaStock = Stock::where('stockable_id', $alpha->id)->firstOrFail();

    Livewire::test(Inventories::class)
        ->sortTable('stockable.name')
        ->assertCanSeeTableRecords([$alphaStock, $zetaStock], inOrder: true)
        ->sortTable('stockable.name', 'desc')
        ->assertCanSeeTableRecords([$zetaStock, $alphaStock], inOrder: true);
});

it('filters to non-low stock when low stock only is false', function () {
    $healthy = Product::factory()->create(['type' => ProductType::Inventory]);
    $this->product->addStock(2, $this->location);
    $this->product->setStockThreshold(min: 10, location: $this->location);
    $healthy->addStock(50, $this->location);
    $healthy->setStockThreshold(min: 10, location: $this->location);

    $lowStock = Stock::where('stockable_id', $this->product->id)->get();
    $healthyStock = Stock::where('stockable_id', $healthy->id)->get();

    Livewire::test(Inventories::class)
        ->filterTable('low_stock_only', true)
        ->assertCanSeeTableRecords($lowStock)
        ->assertCanNotSeeTableRecords($healthyStock)
        ->filterTable('low_stock_only', false)
        ->assertCanSeeTableRecords($healthyStock)
        ->assertCanNotSeeTableRecords($lowStock);
});

it('dispatches an export from the table header', function () {
    $this->product->addStock(10, $this->location);
    $exports = ExportAction::fake();

    Livewire::test(Inventories::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(InventoriesExporter::class, function (Export $export, Builder $query): bool {
        expect($query->count())->toBe(1);

        return true;
    });
});

it('exports row values matching the table columns', function () {
    $brand = Brand::create(['name' => 'Acme']);
    $category = Category::create(['name' => 'Tools']);
    $this->product->update(['name' => 'Hammer', 'brand_id' => $brand->id, 'cost' => 4]);
    $this->product->categories()->attach($category);
    $this->product->addStock(10, $this->location);
    $this->product->reserve(2, $this->location->id);
    $this->product->setStockThreshold(min: 9, max: 30, location: $this->location);

    $stock = Livewire::test(Inventories::class)->instance()->getFilteredTableQuery()->firstOrFail();

    expect(InventoriesExporter::test()->export($stock))->toBe([
        'Hammer', 'Acme', 'Tools', $this->location->name, '10.0000', '2.0000', '8', '32', '9', '30', 'Low Stock',
    ]);
});

it('does not run per-row queries during a real export', function () {
    Storage::fake('local');
    seedInventoriesPageStocks(2, $this->location);
    $small = countQueries(fn () => Livewire::test(Inventories::class)->callAction(TestAction::make('export')->table()));

    seedInventoriesPageStocks(6, $this->location);
    $this->actingAs(User::query()->firstOrFail()); // the sync export jobs leave the auth guard without a user
    $large = countQueries(fn () => Livewire::test(Inventories::class)->callAction(TestAction::make('export')->table()));

    expect(Export::query()->latest('id')->firstOrFail()->successful_rows)->toBe(8)
        ->and($large)->toBe($small);
});

it('runs a real csv export end to end', function () {
    Storage::fake('local');
    seedInventoriesPageStocks(3, $this->location);

    Livewire::test(Inventories::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $csv = collect(Storage::disk('local')->files($export->getFileDirectory()))
        ->filter(fn (string $file): bool => str_ends_with($file, '.csv'))
        ->map(fn (string $file): string => Storage::disk('local')->get($file))
        ->join('');

    expect($export->refresh()->successful_rows)->toBe(3)
        ->and($csv)->toContain(Product::query()->latest('id')->first()->name);
});
