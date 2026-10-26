<?php

use App\Enums\ProductType;
use App\Filament\Exports\SalesSummaryExporter;
use App\Filament\Pages\SalesSummary;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleOrder;
use App\Models\SaleOrderItem;
use App\Models\User;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->customer = Customer::factory()->create();
    $this->product = Product::factory()->create([
        'type' => ProductType::Inventory,
        'cost' => 10.00,
        'price' => 25.00,
    ]);
});

it('can render the sales summary page', function () {
    Livewire::test(SalesSummary::class)->assertSuccessful();
});

it('shows aggregated sales data for shipped orders', function () {
    $order = SaleOrder::factory()->shipped()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 10,
        'unit_price' => 25.00,
    ]);

    Livewire::test(SalesSummary::class)->assertSuccessful();
});

it('does not include draft orders', function () {
    $draftOrder = SaleOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    SaleOrderItem::factory()->create([
        'sale_order_id' => $draftOrder->id,
        'product_id' => $this->product->id,
    ]);

    Livewire::test(SalesSummary::class)->assertSuccessful();
});

it('can filter by product', function () {
    $product2 = Product::factory()->create(['cost' => 5.00]);

    $order = SaleOrder::factory()->shipped()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $this->product->id,
    ]);
    SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $product2->id,
    ]);

    Livewire::test(SalesSummary::class)
        ->filterTable('product_id', $this->product->id)
        ->assertSuccessful();
});

it('can filter by customer', function () {
    $customer2 = Customer::factory()->create();

    $order1 = SaleOrder::factory()->shipped()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);
    $order2 = SaleOrder::factory()->shipped()->create([
        'customer_id' => $customer2->id,
        'location_id' => $this->location->id,
    ]);

    SaleOrderItem::factory()->create([
        'sale_order_id' => $order1->id,
        'product_id' => $this->product->id,
    ]);
    SaleOrderItem::factory()->create([
        'sale_order_id' => $order2->id,
        'product_id' => $this->product->id,
    ]);

    Livewire::test(SalesSummary::class)
        ->filterTable('customer_id', $this->customer->id)
        ->assertSuccessful();
});

it('includes picked orders in summary', function () {
    $order = SaleOrder::factory()->picked()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    SaleOrderItem::factory()->create([
        'sale_order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 5,
        'unit_price' => 20.00,
    ]);

    Livewire::test(SalesSummary::class)->assertSuccessful();
});

/**
 * @return Collection<int, Product>
 */
function sellProductsForSummary(int $count, Customer $customer, Location $location): Collection
{
    $order = SaleOrder::factory()->shipped()->create([
        'customer_id' => $customer->id,
        'location_id' => $location->id,
    ]);
    $category = Category::create(['name' => 'Cat '.uniqid()]);

    return Product::factory()->count($count)->create(['brand_id' => Brand::factory()])
        ->each(function (Product $product) use ($order, $category): void {
            $product->categories()->attach($category);
            SaleOrderItem::factory()->create(['sale_order_id' => $order->id, 'product_id' => $product->id]);
        });
}

it('does not run per-row queries', function () {
    sellProductsForSummary(2, $this->customer, $this->location);
    $small = countQueries(fn () => Livewire::test(SalesSummary::class)->assertCountTableRecords(2));

    sellProductsForSummary(8, $this->customer, $this->location);
    $large = countQueries(fn () => Livewire::test(SalesSummary::class)->assertCountTableRecords(10));

    expect($large)->toBe($small);
});

it('aggregates revenue, cost and margin per product', function () {
    $order = SaleOrder::factory()->shipped()->create(['customer_id' => $this->customer->id, 'location_id' => $this->location->id]);
    SaleOrderItem::factory()->create(['sale_order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 25]);
    SaleOrderItem::factory()->create(['sale_order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 6, 'unit_price' => 25]);

    Livewire::test(SalesSummary::class)
        ->assertCountTableRecords(1)
        ->assertTableColumnStateSet('qty_sold', 10, $this->product)
        ->assertTableColumnStateSet('total_revenue', 250, $this->product)
        ->assertTableColumnStateSet('total_cost', 100, $this->product)
        ->assertTableColumnStateSet('margin_pct', '60.0%', $this->product);
});

it('filters by category and searches by product name', function () {
    $category = Category::create(['name' => 'Widgets']);
    $this->product->update(['name' => 'Blue Widget']);
    $this->product->categories()->attach($category);
    $other = sellProductsForSummary(1, $this->customer, $this->location)->first();

    $order = SaleOrder::factory()->shipped()->create(['customer_id' => $this->customer->id, 'location_id' => $this->location->id]);
    SaleOrderItem::factory()->create(['sale_order_id' => $order->id, 'product_id' => $this->product->id]);

    Livewire::test(SalesSummary::class)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords([$this->product])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(SalesSummary::class)
        ->searchTable('Blue Widget')
        ->assertCanSeeTableRecords([$this->product])
        ->assertCanNotSeeTableRecords([$other]);
});

it('sorts by total revenue', function () {
    $order = SaleOrder::factory()->shipped()->create(['customer_id' => $this->customer->id, 'location_id' => $this->location->id]);
    $cheap = Product::factory()->create();
    SaleOrderItem::factory()->create(['sale_order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 100]);
    SaleOrderItem::factory()->create(['sale_order_id' => $order->id, 'product_id' => $cheap->id, 'quantity' => 1, 'unit_price' => 1]);

    Livewire::test(SalesSummary::class)
        ->sortTable('total_revenue', 'asc')
        ->assertCanSeeTableRecords([$cheap, $this->product], inOrder: true)
        ->sortTable('total_revenue', 'desc')
        ->assertCanSeeTableRecords([$this->product, $cheap], inOrder: true);
});

it('dispatches the sales summary export', function () {
    $exports = ExportAction::fake();

    Livewire::test(SalesSummary::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(SalesSummaryExporter::class);
});

it('exports computed row values', function () {
    $brand = Brand::factory()->create(['name' => 'Acme']);
    $category = Category::create(['name' => 'Tools']);
    $this->product->update(['name' => 'Hammer', 'brand_id' => $brand->id]);
    $this->product->categories()->attach($category);
    $order = SaleOrder::factory()->shipped()->create(['customer_id' => $this->customer->id, 'location_id' => $this->location->id]);
    SaleOrderItem::factory()->create(['sale_order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 25]);

    $record = Livewire::test(SalesSummary::class)->instance()->getTableQueryForExport()->firstOrFail();

    expect(SalesSummaryExporter::test()->export($record))
        ->toEqual(['Hammer', 'Acme', 'Tools', 10, 25, 250, 100, 150.0, 60.0]);
});

it('runs a real export of the aggregated rows', function () {
    Storage::fake('local');
    $products = sellProductsForSummary(3, $this->customer, $this->location);

    Livewire::test(SalesSummary::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $rows = collect(Storage::disk('local')->files($export->getFileDirectory()))
        ->filter(fn (string $file): bool => str_ends_with($file, '.csv') && ! str_ends_with($file, 'headers.csv'))
        ->flatMap(fn (string $file): array => array_filter(explode("\n", (string) Storage::disk('local')->get($file))));

    expect($export->successful_rows)->toBe(3)
        ->and($rows)->toHaveCount(3)
        ->and($rows->implode("\n"))->toContain(...$products->pluck('name')->all());
});
