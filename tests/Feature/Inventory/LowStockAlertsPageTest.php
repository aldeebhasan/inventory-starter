<?php

use Aldeebhasan\Inventorix\Models\Stock;
use App\Enums\ProductType;
use App\Filament\Exports\LowStockAlertsExporter;
use App\Filament\Pages\LowStockAlerts;
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

it('can render the low stock alerts page', function () {
    Livewire::test(LowStockAlerts::class)->assertSuccessful();
});

it('shows products below their minimum threshold', function () {
    $this->product->addStock(3, $this->location);
    $this->product->setStockThreshold(min: 10, location: $this->location);

    $stocks = Stock::whereHasMorph('stockable', [Product::class])
        ->where('stockable_id', $this->product->id)
        ->get();

    Livewire::test(LowStockAlerts::class)
        ->assertCanSeeTableRecords($stocks);
});

it('does not show products above their minimum threshold', function () {
    $this->product->addStock(15, $this->location);
    $this->product->setStockThreshold(min: 10, location: $this->location);

    $stocks = Stock::whereHasMorph('stockable', [Product::class])
        ->where('stockable_id', $this->product->id)
        ->get();

    Livewire::test(LowStockAlerts::class)
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

    Livewire::test(LowStockAlerts::class)
        ->filterTable('location_id', $this->location->id)
        ->assertCanSeeTableRecords($stockInLocation)
        ->assertCanNotSeeTableRecords($stockInOther);
});

it('can filter by category', function () {
    $category = Category::create(['name' => 'Electronics']);
    $this->product->categories()->attach($category);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);

    $this->product->addStock(3, $this->location);
    $this->product->setStockThreshold(min: 10, location: $this->location);

    $product2->addStock(2, $this->location);
    $product2->setStockThreshold(min: 10, location: $this->location);

    $stock1 = Stock::where('stockable_id', $this->product->id)->get();

    Livewire::test(LowStockAlerts::class)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords($stock1);
});

it('displays navigation badge with low stock count', function () {
    $this->product->addStock(3, $this->location);
    $this->product->setStockThreshold(min: 10, location: $this->location);

    expect(LowStockAlerts::getNavigationBadge())->not->toBeNull();
    expect(LowStockAlerts::getNavigationBadgeColor())->toBe('danger');
});

function seedLowStockAlertsPageStocks(int $count, Location $location): void
{
    $brand = Brand::create(['name' => 'Brand '.uniqid()]);

    for ($i = 0; $i < $count; $i++) {
        $product = Product::factory()->create(['type' => ProductType::Inventory, 'brand_id' => $brand->id]);
        $product->addStock(2, $location);
        $product->setStockThreshold(min: 10, location: $location);
    }
}

it('does not run per-row queries when rendering', function () {
    seedLowStockAlertsPageStocks(2, $this->location);
    $small = countQueries(fn () => Livewire::test(LowStockAlerts::class)->assertCountTableRecords(2));

    seedLowStockAlertsPageStocks(6, $this->location);
    $large = countQueries(fn () => Livewire::test(LowStockAlerts::class)->assertCountTableRecords(8));

    expect($large)->toBe($small);
});

it('shows the location threshold over the global one and the deficit', function () {
    $this->product->addStock(3, $this->location);
    $this->product->setStockThreshold(min: 20);
    $this->product->setStockThreshold(min: 10, location: $this->location);

    $stock = Stock::where('stockable_id', $this->product->id)->firstOrFail();

    Livewire::test(LowStockAlerts::class)
        ->assertCountTableRecords(1)
        ->assertTableColumnStateSet('min_threshold', '10.00', $stock)
        ->assertTableColumnStateSet('deficit', '7.00', $stock);
});

it('counts the navigation badge from the low stock query', function () {
    seedLowStockAlertsPageStocks(3, $this->location);
    $healthy = Product::factory()->create(['type' => ProductType::Inventory]);
    $healthy->addStock(50, $this->location);
    $healthy->setStockThreshold(min: 10, location: $this->location);

    expect(LowStockAlerts::getNavigationBadge())->toBe('3');
});

it('sorts by product name', function () {
    $zeta = Product::factory()->create(['type' => ProductType::Inventory, 'name' => 'Zeta']);
    $alpha = Product::factory()->create(['type' => ProductType::Inventory, 'name' => 'Alpha']);

    foreach ([$zeta, $alpha] as $product) {
        $product->addStock(2, $this->location);
        $product->setStockThreshold(min: 10, location: $this->location);
    }

    $zetaStock = Stock::where('stockable_id', $zeta->id)->firstOrFail();
    $alphaStock = Stock::where('stockable_id', $alpha->id)->firstOrFail();

    Livewire::test(LowStockAlerts::class)
        ->sortTable('stockable.name')
        ->assertCanSeeTableRecords([$alphaStock, $zetaStock], inOrder: true)
        ->sortTable('stockable.name', 'desc')
        ->assertCanSeeTableRecords([$zetaStock, $alphaStock], inOrder: true);
});

it('dispatches an export from the table header', function () {
    seedLowStockAlertsPageStocks(2, $this->location);
    $exports = ExportAction::fake();

    Livewire::test(LowStockAlerts::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(LowStockAlertsExporter::class, function (Export $export, Builder $query): bool {
        expect($query->count())->toBe(2);

        return true;
    });
});

it('exports row values matching the table columns', function () {
    $brand = Brand::create(['name' => 'Acme']);
    $this->product->update(['name' => 'Hammer', 'brand_id' => $brand->id]);
    $this->product->addStock(5, $this->location);
    $this->product->reserve(1, $this->location->id);
    $this->product->setStockThreshold(min: 10, location: $this->location);

    $stock = Livewire::test(LowStockAlerts::class)->instance()->getFilteredTableQuery()->firstOrFail();

    expect(LowStockAlertsExporter::test()->export($stock))->toBe([
        'Hammer', 'Acme', $this->location->name, '5.0000', '1.0000', '4', '10', '6',
    ]);
});

it('runs a real csv export end to end', function () {
    Storage::fake('local');
    seedLowStockAlertsPageStocks(3, $this->location);

    Livewire::test(LowStockAlerts::class)
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
