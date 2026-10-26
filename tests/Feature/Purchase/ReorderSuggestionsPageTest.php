<?php

use Aldeebhasan\Inventorix\Models\Stock;
use App\Enums\ProductType;
use App\Filament\Exports\ReorderSuggestionsExporter;
use App\Filament\Pages\ReorderSuggestions;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
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

/**
 * A low-stock product at the location: receives $received, consumes $consumed (deduct movements), min threshold 10.
 */
function lowStockForReorder(Location $location, float $received, float $consumed, array $attributes = []): Stock
{
    $product = Product::factory()->create(['type' => ProductType::Inventory, ...$attributes]);
    $product->addStock($received, $location);

    if ($consumed > 0) {
        $product->deductStock($consumed, $location);
    }

    $product->setStockThreshold(min: 10, location: $location);

    return Stock::query()->where('stockable_id', $product->id)->where('location_id', $location->id)->firstOrFail();
}

it('does not run per-row queries', function () {
    $createStocks = function (int $count): void {
        foreach (range(1, $count) as $i) {
            $stock = lowStockForReorder($this->location, 5, 1, ['brand_id' => Brand::factory()->create()->id]);
            $stock->stockable->suppliers()->attach(Supplier::factory()->create(), ['unit_cost' => 2]);
        }
    };

    $createStocks(2);
    $small = countQueries(fn () => Livewire::test(ReorderSuggestions::class)->assertCountTableRecords(2));

    $createStocks(8);
    $large = countQueries(fn () => Livewire::test(ReorderSuggestions::class)->assertCountTableRecords(10));

    expect($large)->toBe($small);
});

it('filters by urgency using days of stock remaining', function () {
    $critical = lowStockForReorder($this->location, 31, 30);   // 1 left, 1/day   -> 1 day
    $warning = lowStockForReorder($this->location, 10, 7);     // 3 left, 7/30 day -> ~12.9 days
    $noConsumption = lowStockForReorder($this->location, 3, 0); // never consumed  -> infinite

    Livewire::test(ReorderSuggestions::class)
        ->assertCanSeeTableRecords([$critical, $warning, $noConsumption])
        ->filterTable('urgency', 'critical')
        ->assertCanSeeTableRecords([$critical])
        ->assertCanNotSeeTableRecords([$warning, $noConsumption])
        ->filterTable('urgency', 'warning')
        ->assertCanSeeTableRecords([$critical, $warning])
        ->assertCanNotSeeTableRecords([$noConsumption])
        ->filterTable('urgency', 'all')
        ->assertCanSeeTableRecords([$critical, $warning, $noConsumption]);
});

it('ignores consumption older than the velocity window', function () {
    $this->travel(-40)->days();
    $stock = lowStockForReorder($this->location, 31, 30);
    $this->travelBack();

    Livewire::test(ReorderSuggestions::class)
        ->filterTable('urgency', 'warning')
        ->assertCanNotSeeTableRecords([$stock]);
});

it('sorts by product name', function () {
    $zebra = lowStockForReorder($this->location, 3, 0, ['name' => 'Zebra Tape']);
    $apple = lowStockForReorder($this->location, 4, 0, ['name' => 'Apple Glue']);

    Livewire::test(ReorderSuggestions::class)
        ->sortTable('stockable.name', 'asc')
        ->assertCanSeeTableRecords([$apple, $zebra], inOrder: true)
        ->sortTable('stockable.name', 'desc')
        ->assertCanSeeTableRecords([$zebra, $apple], inOrder: true);
});

it('dispatches the reorder suggestions export', function () {
    $exports = ExportAction::fake();

    Livewire::test(ReorderSuggestions::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(ReorderSuggestionsExporter::class);
});

it('exports computed row values', function () {
    $stock = lowStockForReorder($this->location, 31, 30, ['name' => 'Valve', 'cost' => 9]);
    $supplier = Supplier::factory()->create(['name' => 'Valve Co']);
    $stock->stockable->suppliers()->attach($supplier, ['unit_cost' => 2.5]);

    $record = Livewire::test(ReorderSuggestions::class)->instance()->getTableQueryForExport()->findOrFail($stock->id);

    // available 1, min 10, 30 consumed / 30 days = 1/day, suggested = max(ceil(1 * 30), 10 - 1) = 30
    expect(ReorderSuggestionsExporter::test()->export($record))->toBe([
        'Valve', $this->location->name, '1', '10', '1', '1', '30', 'Valve Co', '2.5', '75',
    ]);
});

it('runs a real export', function () {
    Storage::fake('local');
    $stocks = collect([lowStockForReorder($this->location, 31, 30), lowStockForReorder($this->location, 3, 0)]);

    Livewire::test(ReorderSuggestions::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $csv = collect(Storage::disk('local')->files($export->getFileDirectory()))
        ->filter(fn (string $file): bool => str_ends_with($file, '.csv') && ! str_ends_with($file, 'headers.csv'))
        ->map(fn (string $file): string => (string) Storage::disk('local')->get($file))
        ->implode('');

    expect($export->successful_rows)->toBe(2)
        ->and($csv)->toContain(...$stocks->map(fn (Stock $stock): string => $stock->stockable->name)->all());
});
