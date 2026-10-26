<?php

use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Enums\ProductType;
use App\Filament\Exports\OverstockAlertsExporter;
use App\Filament\Pages\OverstockAlerts;
use App\Models\Brand;
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

function seedOverstockAlertsPageStocks(int $count, Location $location): void
{
    $brand = Brand::create(['name' => 'Brand '.uniqid()]);

    for ($i = 0; $i < $count; $i++) {
        $product = Product::factory()->create(['type' => ProductType::Inventory, 'brand_id' => $brand->id]);
        $product->addStock(100, $location);
        $product->setStockThreshold(min: 5, max: 50, location: $location);
    }
}

it('does not run per-row queries when rendering', function () {
    seedOverstockAlertsPageStocks(2, $this->location);
    $small = countQueries(fn () => Livewire::test(OverstockAlerts::class)->assertCountTableRecords(2));

    seedOverstockAlertsPageStocks(6, $this->location);
    $large = countQueries(fn () => Livewire::test(OverstockAlerts::class)->assertCountTableRecords(8));

    expect($large)->toBe($small);
});

it('shows the location max threshold over the global one and the surplus', function () {
    $this->product->addStock(100, $this->location);
    $this->product->setStockThreshold(min: 1, max: 30);
    $this->product->setStockThreshold(min: 1, max: 40, location: $this->location);

    $stock = Stock::where('stockable_id', $this->product->id)->firstOrFail();

    Livewire::test(OverstockAlerts::class)
        ->assertTableColumnStateSet('max_threshold', '40.00', $stock)
        ->assertTableColumnStateSet('surplus', '60.00', $stock);
});

it('dispatches an export from the table header', function () {
    seedOverstockAlertsPageStocks(2, $this->location);
    $exports = ExportAction::fake();

    Livewire::test(OverstockAlerts::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(OverstockAlertsExporter::class, function (Export $export, Builder $query): bool {
        expect($query->count())->toBe(2);

        return true;
    });
});

it('exports row values matching the table columns', function () {
    $brand = Brand::create(['name' => 'Acme']);
    $this->product->update(['name' => 'Hammer', 'brand_id' => $brand->id]);
    $this->product->addStock(100, $this->location);
    $this->product->reserve(10, $this->location->id);
    $this->product->setStockThreshold(min: 5, max: 50, location: $this->location);

    $stock = Livewire::test(OverstockAlerts::class)->instance()->getFilteredTableQuery()->firstOrFail();

    expect(OverstockAlertsExporter::test()->export($stock))->toBe([
        'Hammer', 'Acme', $this->location->name, '100.0000', '10.0000', '90', '50', '40',
    ]);
});

it('runs a real csv export end to end', function () {
    Storage::fake('local');
    seedOverstockAlertsPageStocks(3, $this->location);

    Livewire::test(OverstockAlerts::class)
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
