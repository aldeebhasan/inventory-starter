<?php

use App\Enums\ProductType;
use App\Filament\Exports\AbcAnalysisExporter;
use App\Filament\Pages\AbcAnalysis;
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
});

it('can render the abc analysis page', function () {
    Livewire::test(AbcAnalysis::class)->assertSuccessful();
});

it('shows products with consumption data', function () {
    $product = Product::factory()->create([
        'type' => ProductType::Inventory,
        'cost' => 50.00,
    ]);

    $product->addStock(100, $this->location);
    $product->deductStock(30, $this->location);

    Livewire::test(AbcAnalysis::class)->assertSuccessful();
});

it('classifies products into A, B, C categories', function () {
    // Create multiple products with varying consumption
    $productA = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 100.00]);
    $productB = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 10.00]);
    $productC = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 1.00]);

    $productA->addStock(200, $this->location);
    $productA->deductStock(100, $this->location);

    $productB->addStock(200, $this->location);
    $productB->deductStock(50, $this->location);

    $productC->addStock(200, $this->location);
    $productC->deductStock(10, $this->location);

    Livewire::test(AbcAnalysis::class)->assertSuccessful();
});

it('handles empty data gracefully', function () {
    Livewire::test(AbcAnalysis::class)->assertSuccessful();
});

it('can filter by location', function () {
    $product = Product::factory()->create([
        'type' => ProductType::Inventory,
        'cost' => 50.00,
    ]);

    $product->addStock(50, $this->location);
    $product->deductStock(10, $this->location);

    Livewire::test(AbcAnalysis::class)
        ->filterTable('location_id', $this->location->id)
        ->assertSuccessful();
});

it('can filter by category', function () {
    $category = Category::create(['name' => 'ABC Category']);

    $product = Product::factory()->create([
        'type' => ProductType::Inventory,
        'cost' => 50.00,
    ]);
    $product->categories()->attach($category);

    $product->addStock(50, $this->location);
    $product->deductStock(10, $this->location);

    Livewire::test(AbcAnalysis::class)
        ->filterTable('category', $category->id)
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
    $small = countQueries(fn () => Livewire::test(AbcAnalysis::class)->assertCountTableRecords(2));

    $seed(6);
    $large = countQueries(fn () => Livewire::test(AbcAnalysis::class)->assertCountTableRecords(8));

    expect($large)->toBe($small);
});

/** @return array<string, Product> Products consuming 8000 / 1000 / 600 / 400 in value, created out of rank order. */
function abcProducts(Location $location): array
{
    $make = function (float $cost) use ($location): Product {
        $product = Product::factory()->create(['cost' => $cost]);
        $product->addStock(200, $location);
        $product->deductStock(100, $location);

        return $product;
    };

    $p3 = $make(6);
    $p1 = $make(80);
    $p4 = $make(4);
    $p2 = $make(10);

    return ['p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'p4' => $p4];
}

it('ranks and classifies products by cumulative consumption value', function () {
    ['p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'p4' => $p4] = abcProducts($this->location);

    // Products with no consumption or that are soft-deleted are excluded.
    $idle = Product::factory()->create(['cost' => 1000]);
    $idle->addStock(5, $this->location);
    $deleted = Product::factory()->create(['cost' => 1000]);
    $deleted->addStock(5, $this->location);
    $deleted->deductStock(5, $this->location);
    $deleted->delete();

    Livewire::test(AbcAnalysis::class)
        ->assertCanSeeTableRecords([$p1, $p2, $p3, $p4], inOrder: true)
        ->assertCanNotSeeTableRecords([$idle])
        ->assertTableColumnStateSet('consumption_value', '8,000.00', $p1)
        ->assertTableColumnStateSet('cumulative_pct', '80.0%', $p1)
        ->assertTableColumnStateSet('abc_class', 'A', $p1)
        ->assertTableColumnStateSet('cumulative_pct', '90.0%', $p2)
        ->assertTableColumnStateSet('abc_class', 'B', $p2)
        ->assertTableColumnStateSet('cumulative_pct', '96.0%', $p3)
        ->assertTableColumnStateSet('abc_class', 'C', $p3)
        ->assertTableColumnStateSet('cumulative_pct', '100.0%', $p4)
        ->assertTableColumnStateSet('abc_class', 'C', $p4)
        ->assertTableColumnStateSet('consumption_qty', '100.000', $p4)
        ->assertTableColumnStateSet('avg_cost', '4.000', $p4);
});

it('ranks only within the filtered category', function () {
    ['p2' => $p2, 'p4' => $p4, 'p1' => $p1] = abcProducts($this->location);
    $category = Category::create(['name' => 'Subset']);
    $p2->categories()->attach($category);
    $p4->categories()->attach($category);

    // 1000 + 400: p2 = 71.4% (A), p4 = 100% (C)
    Livewire::test(AbcAnalysis::class)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords([$p2, $p4], inOrder: true)
        ->assertCanNotSeeTableRecords([$p1])
        ->assertTableColumnStateSet('cumulative_pct', '71.4%', $p2)
        ->assertTableColumnStateSet('abc_class', 'A', $p2)
        ->assertTableColumnStateSet('abc_class', 'C', $p4);
});

it('dispatches the abc analysis export', function () {
    $exports = ExportAction::fake();

    Livewire::test(AbcAnalysis::class)
        ->filterTable('location_id', $this->location->id)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(AbcAnalysisExporter::class);
});

it('exports rank and classification values', function () {
    ['p2' => $p2] = abcProducts($this->location);

    $row = AbcAnalysisExporter::test(options: ['location_id' => $this->location->id])->export($p2);

    expect($row)->toBe(['2', $p2->name, null, '100', '10', '1000', '90', 'B']);
});

it('runs a real abc analysis export end to end', function () {
    Storage::fake(config('filament.default_filesystem_disk', 'local'));
    ['p1' => $p1, 'p4' => $p4] = abcProducts($this->location);

    Livewire::test(AbcAnalysis::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $lines = array_values(array_filter(explode("\n", $export->getFileDisk()->get($export->getFileDirectory().'/0000000000000001.csv'))));

    expect($export->successful_rows)->toBe(4)
        ->and($lines[0])->toStartWith('1,')->toContain($p1->name)->toEndWith(',80,A')
        ->and($lines[3])->toStartWith('4,')->toContain($p4->name)->toEndWith(',100,C');
});
