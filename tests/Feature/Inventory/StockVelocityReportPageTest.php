<?php

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use App\Enums\ProductType;
use App\Filament\Exports\StockVelocityReportExporter;
use App\Filament\Pages\StockVelocityReport;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
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

it('can render the stock velocity report page', function () {
    Livewire::test(StockVelocityReport::class)->assertSuccessful();
});

it('lists stocks with positive quantity', function () {
    $this->product->addStock(20, $this->location);

    $stocks = Stock::whereHasMorph('stockable', [Product::class])
        ->where('quantity', '>', 0)
        ->get();

    Livewire::test(StockVelocityReport::class)
        ->assertCanSeeTableRecords($stocks);
});

it('does not show zero-quantity stocks', function () {
    $this->product->addStock(5, $this->location);
    $this->product->deductStock(5, $this->location);

    $stocks = Stock::where('stockable_id', $this->product->id)->get();

    Livewire::test(StockVelocityReport::class)
        ->assertCanNotSeeTableRecords($stocks);
});

it('can filter by location', function () {
    $otherLocation = createLocation(['name' => 'Other Warehouse']);

    $this->product->addStock(10, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);
    $product2->addStock(5, $otherLocation);

    $stockInLocation = Stock::where('location_id', $this->location->id)->get();
    $stockInOther = Stock::where('location_id', $otherLocation->id)->get();

    Livewire::test(StockVelocityReport::class)
        ->filterTable('location_id', $this->location->id)
        ->assertCanSeeTableRecords($stockInLocation)
        ->assertCanNotSeeTableRecords($stockInOther);
});

it('can filter by category', function () {
    $category = Category::create(['name' => 'Velocity Category']);
    $this->product->categories()->attach($category);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);

    $this->product->addStock(10, $this->location);
    $product2->addStock(5, $this->location);

    $stock1 = Stock::where('stockable_id', $this->product->id)->get();
    $stock2 = Stock::where('stockable_id', $product2->id)->get();

    Livewire::test(StockVelocityReport::class)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords($stock1)
        ->assertCanNotSeeTableRecords($stock2);
});

it('shows healthy status for products with no consumption', function () {
    $this->product->addStock(50, $this->location);

    Livewire::test(StockVelocityReport::class)
        ->assertSuccessful();
});

it('does not run per-row queries as rows grow', function () {
    $seed = function (int $count): void {
        Product::factory()->count($count)->create(['brand_id' => Brand::factory()])
            ->each(function (Product $product): void {
                $product->addStock(20, $this->location);
                $product->deductStock(5, $this->location);
            });
    };

    $seed(2);
    $small = countQueries(fn () => Livewire::test(StockVelocityReport::class)->assertCountTableRecords(2));

    $seed(6);
    $large = countQueries(fn () => Livewire::test(StockVelocityReport::class)->assertCountTableRecords(8));

    expect($large)->toBe($small);
});

function velocityStock(Location $location, float $added, float $deducted, string $name = 'Item'): Stock
{
    $product = Product::factory()->create(['name' => $name]);
    $product->addStock($added, $location);
    $product->deductStock($deducted, $location);

    return Stock::query()->where('stockable_id', $product->id)->firstOrFail();
}

it('filters by velocity status in SQL', function () {
    // 30-day period: critical < 7 days of stock, warning < 14, healthy otherwise
    $critical = velocityStock($this->location, 100, 90);   // 10 left / 3 per day  = 3 days
    $warning = velocityStock($this->location, 100, 75);    // 25 left / 2.5 per day = 10 days
    $healthy = velocityStock($this->location, 100, 1);     // 99 left / 0.03 per day
    $idle = velocityStock($this->location, 10, 0.0001);

    Livewire::test(StockVelocityReport::class)
        ->filterTable('status', 'critical')
        ->assertCanSeeTableRecords([$critical])
        ->assertCanNotSeeTableRecords([$warning, $healthy, $idle])
        ->filterTable('status', 'warning')
        ->assertCanSeeTableRecords([$warning])
        ->assertCanNotSeeTableRecords([$critical, $healthy])
        ->filterTable('status', 'healthy')
        ->assertCanSeeTableRecords([$healthy, $idle])
        ->assertCanNotSeeTableRecords([$critical, $warning]);
});

it('sorts by product name', function () {
    $b = velocityStock($this->location, 10, 1, 'Bravo');
    $a = velocityStock($this->location, 20, 1, 'Alpha');
    $c = velocityStock($this->location, 5, 1, 'Charlie');

    Livewire::test(StockVelocityReport::class)
        ->sortTable('stockable.name')
        ->assertCanSeeTableRecords([$a, $b, $c], inOrder: true)
        ->sortTable('stockable.name', 'desc')
        ->assertCanSeeTableRecords([$c, $b, $a], inOrder: true);
});

it('dispatches the stock velocity export', function () {
    $exports = ExportAction::fake();

    Livewire::test(StockVelocityReport::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(StockVelocityReportExporter::class);
});

it('exports computed velocity values including the peak demand day', function () {
    $stock = velocityStock($this->location, 100, 60, 'Widget');
    Movement::query()->where('type', MovementType::Deduct)->update(['created_at' => now()->subDays(2)]);
    Product::findOrFail($stock->stockable_id)->deductStock(30, $this->location);

    $record = StockVelocityReport::getReportQuery(30)->whereKey($stock->id)->firstOrFail();

    $row = StockVelocityReportExporter::test(options: ['period' => 30])->export($record);

    expect($row)->toBe([
        'Widget',
        $this->location->name,
        '10.0000',
        '10',
        '3.00',
        '3',
        now()->subDays(2)->toDateString(),
        'Critical',
    ]);
});

it('runs a real stock velocity export end to end', function () {
    Storage::fake(config('filament.default_filesystem_disk', 'local'));
    velocityStock($this->location, 100, 90, 'Fast Mover');
    velocityStock($this->location, 100, 1, 'Slow Mover');

    Livewire::test(StockVelocityReport::class)
        ->filterTable('status', 'critical')
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $csv = $export->getFileDisk()->get($export->getFileDirectory().'/0000000000000001.csv');

    expect($export->successful_rows)->toBe(1)
        ->and($csv)->toContain('Fast Mover')
        ->and($csv)->toContain('Critical')
        ->and($csv)->not->toContain('Slow Mover');
});
