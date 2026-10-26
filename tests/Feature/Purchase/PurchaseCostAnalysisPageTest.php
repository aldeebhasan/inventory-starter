<?php

use App\Enums\PurchaseOrderStatus;
use App\Filament\Exports\PurchaseCostAnalysisExporter;
use App\Filament\Pages\PurchaseCostAnalysis;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
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
    $this->supplier = Supplier::factory()->create();
});

it('can render the purchase cost analysis page', function () {
    Livewire::test(PurchaseCostAnalysis::class)->assertSuccessful();
});

it('lists purchase order items from confirmed/received POs', function () {
    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);
    $items = PurchaseOrderItem::factory()->count(2)->create([
        'purchase_order_id' => $po->id,
    ]);

    Livewire::test(PurchaseCostAnalysis::class)
        ->assertCanSeeTableRecords($items);
});

it('does not list items from draft POs', function () {
    $draftPo = PurchaseOrder::factory()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
        'status' => PurchaseOrderStatus::Draft,
    ]);
    $item = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $draftPo->id,
    ]);

    Livewire::test(PurchaseCostAnalysis::class)
        ->assertCanNotSeeTableRecords([$item]);
});

it('can filter by product', function () {
    $product1 = Product::factory()->create();
    $product2 = Product::factory()->create();

    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    $item1 = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product1->id,
    ]);
    $item2 = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product2->id,
    ]);

    Livewire::test(PurchaseCostAnalysis::class)
        ->filterTable('product_id', $product1->id)
        ->assertCanSeeTableRecords([$item1])
        ->assertCanNotSeeTableRecords([$item2]);
});

it('can filter by supplier', function () {
    $supplier2 = Supplier::factory()->create();

    $po1 = PurchaseOrder::factory()->confirmed()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);
    $po2 = PurchaseOrder::factory()->confirmed()->create([
        'supplier_id' => $supplier2->id,
        'location_id' => $this->location->id,
    ]);

    $item1 = PurchaseOrderItem::factory()->create(['purchase_order_id' => $po1->id]);
    $item2 = PurchaseOrderItem::factory()->create(['purchase_order_id' => $po2->id]);

    Livewire::test(PurchaseCostAnalysis::class)
        ->filterTable('supplier_id', $this->supplier->id)
        ->assertCanSeeTableRecords([$item1])
        ->assertCanNotSeeTableRecords([$item2]);
});

it('shows cost data correctly', function () {
    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'unit_cost' => 10.50,
        'quantity' => 5,
    ]);

    Livewire::test(PurchaseCostAnalysis::class)->assertSuccessful();
});

/**
 * One received PO from the supplier, ordered $daysAgo days ago, with one line for the product.
 */
function purchaseAtCost(Supplier $supplier, Product $product, float $unitCost, int $daysAgo, Location $location): PurchaseOrderItem
{
    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $supplier->id,
        'location_id' => $location->id,
        'ordered_at' => now()->subDays($daysAgo),
    ]);

    return PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'unit_cost' => $unitCost,
        'quantity' => 2,
    ]);
}

it('does not run per-row queries', function () {
    $createLines = function (int $count): void {
        foreach (range(1, $count) as $i) {
            $product = Product::factory()->create();
            purchaseAtCost($this->supplier, $product, 10, 10, $this->location);
            purchaseAtCost($this->supplier, $product, 12, 1, $this->location);
        }
    };

    $createLines(1);
    $small = countQueries(fn () => Livewire::test(PurchaseCostAnalysis::class)->assertCountTableRecords(2));

    $createLines(5);
    $large = countQueries(fn () => Livewire::test(PurchaseCostAnalysis::class)->assertCountTableRecords(12));

    expect($large)->toBe($small);
});

it('shows the cost trend against the previous PO from the same supplier', function () {
    $product = Product::factory()->create();
    $first = purchaseAtCost($this->supplier, $product, 10, 20, $this->location);
    $up = purchaseAtCost($this->supplier, $product, 12, 10, $this->location);
    $down = purchaseAtCost($this->supplier, $product, 11, 1, $this->location);
    $otherSupplier = purchaseAtCost(Supplier::factory()->create(), $product, 50, 5, $this->location);

    Livewire::test(PurchaseCostAnalysis::class)
        ->assertTableColumnStateSet('cost_trend', 'flat', $first)
        ->assertTableColumnStateSet('cost_trend', 'up', $up)
        ->assertTableColumnStateSet('cost_trend', 'down', $down)
        ->assertTableColumnStateSet('cost_trend', 'flat', $otherSupplier);
});

it('dispatches the purchase cost analysis export', function () {
    $exports = ExportAction::fake();

    Livewire::test(PurchaseCostAnalysis::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(PurchaseCostAnalysisExporter::class);
});

it('exports computed row values', function () {
    $product = Product::factory()->create(['name' => 'Bolt']);
    purchaseAtCost($this->supplier, $product, 10, 10, $this->location);
    $item = purchaseAtCost($this->supplier, $product, 12, 1, $this->location);

    $record = Livewire::test(PurchaseCostAnalysis::class)->instance()->getTableQueryForExport()->findOrFail($item->id);

    expect(PurchaseCostAnalysisExporter::test()->export($record))->toEqual([
        'Bolt',
        $this->supplier->name,
        $item->purchaseOrder->order_number,
        now()->subDay()->toDateString(),
        12,
        2,
        24.0,
        10,
        'up',
    ]);
});

it('runs a real export', function () {
    Storage::fake('local');
    $product = Product::factory()->create();
    purchaseAtCost($this->supplier, $product, 10, 10, $this->location);
    purchaseAtCost($this->supplier, $product, 12, 1, $this->location);

    Livewire::test(PurchaseCostAnalysis::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $csv = collect(Storage::disk('local')->files($export->getFileDirectory()))
        ->filter(fn (string $file): bool => str_ends_with($file, '.csv') && ! str_ends_with($file, 'headers.csv'))
        ->map(fn (string $file): string => (string) Storage::disk('local')->get($file))
        ->implode('');

    expect($export->successful_rows)->toBe(2)
        ->and($csv)->toContain(",up\n");
});
