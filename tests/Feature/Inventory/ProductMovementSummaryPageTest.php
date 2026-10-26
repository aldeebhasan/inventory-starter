<?php

use Aldeebhasan\Inventorix\Models\Movement;
use App\Enums\ProductType;
use App\Filament\Exports\ProductMovementSummaryExporter;
use App\Filament\Pages\ProductMovementSummary;
use App\Models\Brand;
use App\Models\Category;
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

it('can render the product movement summary page', function () {
    Livewire::test(ProductMovementSummary::class)->assertSuccessful();
});

it('lists products in the table', function () {
    $this->product->addStock(10, $this->location);

    Livewire::test(ProductMovementSummary::class)
        ->assertCanSeeTableRecords([$this->product]);
});

it('can filter by category', function () {
    $category = Category::create(['name' => 'Movement Category']);
    $this->product->categories()->attach($category);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);

    $this->product->addStock(10, $this->location);
    $product2->addStock(5, $this->location);

    Livewire::test(ProductMovementSummary::class)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords([$this->product])
        ->assertCanNotSeeTableRecords([$product2]);
});

it('shows movements for products with stock changes', function () {
    $this->product->addStock(20, $this->location);
    $this->product->deductStock(5, $this->location);

    Livewire::test(ProductMovementSummary::class)
        ->assertCanSeeTableRecords([$this->product]);
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
    $small = countQueries(fn () => Livewire::test(ProductMovementSummary::class)->assertCountTableRecords(2));

    $seed(6);
    $large = countQueries(fn () => Livewire::test(ProductMovementSummary::class)->assertCountTableRecords(8));

    expect($large)->toBe($small);
});

it('only lists products with movements in the selected date range', function () {
    $this->product->addStock(10, $this->location);

    $oldProduct = Product::factory()->create();
    $oldProduct->addStock(10, $this->location);
    Movement::query()->where('stockable_id', $oldProduct->id)->update(['created_at' => now()->subDays(60)]);

    Livewire::test(ProductMovementSummary::class)
        ->assertCanSeeTableRecords([$this->product])
        ->assertCanNotSeeTableRecords([$oldProduct])
        ->filterTable('date_range', ['from' => now()->subDays(90)->toDateString(), 'until' => now()->subDays(45)->toDateString()])
        ->assertCanSeeTableRecords([$oldProduct])
        ->assertCanNotSeeTableRecords([$this->product]);
});

it('only lists products with movements at the selected location', function () {
    $otherLocation = createLocation();
    $this->product->addStock(10, $this->location);
    $other = Product::factory()->create();
    $other->addStock(10, $otherLocation);

    Livewire::test(ProductMovementSummary::class)
        ->filterTable('location_id', $otherLocation->id)
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$this->product]);
});

it('dispatches the product movement summary export', function () {
    $exports = ExportAction::fake();

    Livewire::test(ProductMovementSummary::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(ProductMovementSummaryExporter::class);
});

it('exports computed movement values', function () {
    $category = Category::create(['name' => 'Tools']);
    $this->product->categories()->attach($category);
    $this->product->addStock(20, $this->location);
    $this->product->deductStock(5, $this->location);

    $record = ProductMovementSummary::getReportQuery(now()->subDays(30)->toDateString(), now()->toDateString(), null)
        ->whereKey($this->product->id)
        ->firstOrFail();

    $row = ProductMovementSummaryExporter::test()->export($record);

    expect($row)->toBe([$this->product->name, null, 'Tools', '20', '5', '15', '15', '0.33']);
});

it('runs a real product movement summary export end to end', function () {
    Storage::fake(config('filament.default_filesystem_disk', 'local'));
    $this->product->addStock(20, $this->location);
    $this->product->deductStock(5, $this->location);
    $oldProduct = Product::factory()->create();
    $oldProduct->addStock(10, $this->location);
    Movement::query()->where('stockable_id', $oldProduct->id)->update(['created_at' => now()->subDays(60)]);

    Livewire::test(ProductMovementSummary::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $csv = $export->getFileDisk()->get($export->getFileDirectory().'/0000000000000001.csv');

    expect($export->successful_rows)->toBe(1)
        ->and($csv)->toContain($this->product->name)
        ->and($csv)->toContain('0.33')
        ->and($csv)->not->toContain($oldProduct->name);
});
