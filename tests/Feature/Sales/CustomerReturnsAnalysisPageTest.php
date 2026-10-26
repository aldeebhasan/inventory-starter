<?php

use App\Filament\Exports\CustomerReturnsAnalysisExporter;
use App\Filament\Pages\CustomerReturnsAnalysis;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ReturnOrder;
use App\Models\ReturnOrderItem;
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
    $this->product = Product::factory()->create(['cost' => 10.00]);
});

it('can render the customer returns analysis page', function () {
    Livewire::test(CustomerReturnsAnalysis::class)->assertSuccessful();
});

it('shows return data for completed customer returns', function () {
    $returnOrder = ReturnOrder::factory()->customerReturn()->completed()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    ReturnOrderItem::factory()->create([
        'return_order_id' => $returnOrder->id,
        'product_id' => $this->product->id,
        'quantity' => 5,
        'price' => 25.00,
    ]);

    Livewire::test(CustomerReturnsAnalysis::class)->assertSuccessful();
});

it('does not include draft returns', function () {
    $returnOrder = ReturnOrder::factory()->customerReturn()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    ReturnOrderItem::factory()->create([
        'return_order_id' => $returnOrder->id,
        'product_id' => $this->product->id,
    ]);

    Livewire::test(CustomerReturnsAnalysis::class)->assertSuccessful();
});

it('can filter by customer', function () {
    $customer2 = Customer::factory()->create();

    $ro1 = ReturnOrder::factory()->customerReturn()->completed()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);
    $ro2 = ReturnOrder::factory()->customerReturn()->completed()->create([
        'customer_id' => $customer2->id,
        'location_id' => $this->location->id,
    ]);

    ReturnOrderItem::factory()->create([
        'return_order_id' => $ro1->id,
        'product_id' => $this->product->id,
    ]);
    ReturnOrderItem::factory()->create([
        'return_order_id' => $ro2->id,
        'product_id' => $this->product->id,
    ]);

    Livewire::test(CustomerReturnsAnalysis::class)
        ->filterTable('customer_id', $this->customer->id)
        ->assertSuccessful();
});

it('can filter by product', function () {
    $product2 = Product::factory()->create(['cost' => 5.00]);

    $ro = ReturnOrder::factory()->customerReturn()->completed()->create([
        'customer_id' => $this->customer->id,
        'location_id' => $this->location->id,
    ]);

    ReturnOrderItem::factory()->create([
        'return_order_id' => $ro->id,
        'product_id' => $this->product->id,
    ]);
    ReturnOrderItem::factory()->create([
        'return_order_id' => $ro->id,
        'product_id' => $product2->id,
    ]);

    Livewire::test(CustomerReturnsAnalysis::class)
        ->filterTable('product_id', $this->product->id)
        ->assertSuccessful();
});

it('does not include supplier returns', function () {
    $returnOrder = ReturnOrder::factory()->supplierReturn()->completed()->create([
        'location_id' => $this->location->id,
    ]);

    ReturnOrderItem::factory()->create([
        'return_order_id' => $returnOrder->id,
        'product_id' => $this->product->id,
    ]);

    Livewire::test(CustomerReturnsAnalysis::class)->assertSuccessful();
});

/**
 * Creates $count products, each sold (10 units) and returned (quantity $returned) by the customer.
 *
 * @return Collection<int, Product>
 */
function returnProductsForAnalysis(int $count, Customer $customer, Location $location, float $returned = 2): Collection
{
    $saleOrder = SaleOrder::factory()->shipped()->create(['customer_id' => $customer->id, 'location_id' => $location->id]);
    $returnOrder = ReturnOrder::factory()->customerReturn()->completed()->create(['customer_id' => $customer->id, 'location_id' => $location->id]);

    return Product::factory()->count($count)->create()
        ->each(function (Product $product) use ($saleOrder, $returnOrder, $returned): void {
            SaleOrderItem::factory()->create(['sale_order_id' => $saleOrder->id, 'product_id' => $product->id, 'quantity' => 10]);
            ReturnOrderItem::factory()->create(['return_order_id' => $returnOrder->id, 'product_id' => $product->id, 'quantity' => $returned, 'price' => 5]);
        });
}

it('does not run per-row queries', function () {
    returnProductsForAnalysis(2, $this->customer, $this->location);
    $small = countQueries(fn () => Livewire::test(CustomerReturnsAnalysis::class)->assertCountTableRecords(2));

    returnProductsForAnalysis(8, $this->customer, $this->location);
    $large = countQueries(fn () => Livewire::test(CustomerReturnsAnalysis::class)->assertCountTableRecords(10));

    expect($large)->toBe($small);
});

it('computes qty sold and return rate per product, respecting the date filter', function () {
    $product = returnProductsForAnalysis(1, $this->customer, $this->location, returned: 2)->first();

    Livewire::test(CustomerReturnsAnalysis::class)
        ->assertTableColumnStateSet('qty_returned', 2, $product)
        ->assertTableColumnStateSet('qty_sold', 10, $product)
        ->assertTableColumnStateSet('return_rate', '20.0%', $product);

    // Move the sale outside the filtered window: the return stays, qty sold drops to 0.
    SaleOrder::query()->update(['created_at' => now()->subDays(20)]);

    Livewire::test(CustomerReturnsAnalysis::class)
        ->filterTable('date_range', ['from' => now()->subDays(5)->toDateString(), 'until' => now()->toDateString()])
        ->assertCanSeeTableRecords([$product])
        ->assertTableColumnStateSet('qty_sold', 0, $product)
        ->assertTableColumnStateSet('return_rate', '—', $product);
});

it('sorts by qty sold', function () {
    $low = returnProductsForAnalysis(1, $this->customer, $this->location)->first();
    $high = returnProductsForAnalysis(1, $this->customer, $this->location)->first();
    $order = SaleOrder::factory()->shipped()->create(['customer_id' => $this->customer->id, 'location_id' => $this->location->id]);
    SaleOrderItem::factory()->create(['sale_order_id' => $order->id, 'product_id' => $high->id, 'quantity' => 100]);

    Livewire::test(CustomerReturnsAnalysis::class)
        ->sortTable('qty_sold', 'asc')
        ->assertCanSeeTableRecords([$low, $high], inOrder: true)
        ->sortTable('qty_sold', 'desc')
        ->assertCanSeeTableRecords([$high, $low], inOrder: true);
});

it('dispatches the customer returns analysis export', function () {
    $exports = ExportAction::fake();

    Livewire::test(CustomerReturnsAnalysis::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(CustomerReturnsAnalysisExporter::class);
});

it('exports computed row values', function () {
    $product = returnProductsForAnalysis(1, $this->customer, $this->location, returned: 2)->first();
    $product->update(['name' => 'Kettle']);

    $record = Livewire::test(CustomerReturnsAnalysis::class)->instance()->getTableQueryForExport()->firstOrFail();

    expect(CustomerReturnsAnalysisExporter::test()->export($record))
        ->toEqual(['Kettle', 2, 10, 20.0, 10]);
});

it('runs a real export of the aggregated rows', function () {
    Storage::fake('local');
    $products = returnProductsForAnalysis(3, $this->customer, $this->location);

    Livewire::test(CustomerReturnsAnalysis::class)
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
