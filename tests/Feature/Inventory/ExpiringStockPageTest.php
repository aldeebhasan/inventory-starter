<?php

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use App\Enums\ProductType;
use App\Filament\Exports\ExpiringStockExporter;
use App\Filament\Pages\ExpiringStock;
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

it('can render the expiring stock page', function () {
    Livewire::test(ExpiringStock::class)->assertSuccessful();
});

it('shows movements with expiration dates', function () {
    $this->product->addStock(10, $this->location);

    // Manually set expires_at on the movement
    $movement = Movement::where('stockable_id', $this->product->id)
        ->where('type', MovementType::Add)
        ->first();

    if ($movement) {
        $movement->update([
            'expires_at' => now()->addDays(15),
            'lot_reference' => 'LOT-001',
        ]);
    }

    Livewire::test(ExpiringStock::class)->assertSuccessful();
});

it('does not show movements without expiry date by default', function () {
    $this->product->addStock(10, $this->location);

    Livewire::test(ExpiringStock::class)->assertSuccessful();
});

it('can filter by location', function () {
    $this->product->addStock(10, $this->location);

    $movement = Movement::where('stockable_id', $this->product->id)->first();
    if ($movement) {
        $movement->update(['expires_at' => now()->addDays(10)]);
    }

    Livewire::test(ExpiringStock::class)
        ->filterTable('location_id', $this->location->id)
        ->assertSuccessful();
});

it('can filter by days until expiry', function () {
    $this->product->addStock(10, $this->location);

    $movement = Movement::where('stockable_id', $this->product->id)->first();
    if ($movement) {
        $movement->update(['expires_at' => now()->addDays(5)]);
    }

    Livewire::test(ExpiringStock::class)
        ->filterTable('days_until_expiry', '7')
        ->assertSuccessful();
});

it('can filter by product', function () {
    $this->product->addStock(10, $this->location);

    $movement = Movement::where('stockable_id', $this->product->id)->first();
    if ($movement) {
        $movement->update(['expires_at' => now()->addDays(10)]);
    }

    Livewire::test(ExpiringStock::class)
        ->filterTable('product_id', $this->product->id)
        ->assertSuccessful();
});

it('does not run per-row queries as rows grow', function () {
    $seed = function (int $count): void {
        Product::factory()->count($count)->create()
            ->each(fn (Product $product) => $product->addStock(10, $this->location));
        Movement::query()->whereNull('expires_at')->update(['expires_at' => now()->addDays(5)]);
    };

    $seed(2);
    $small = countQueries(fn () => Livewire::test(ExpiringStock::class)->assertCountTableRecords(2));

    $seed(6);
    $large = countQueries(fn () => Livewire::test(ExpiringStock::class)->assertCountTableRecords(8));

    expect($large)->toBe($small);
});

function expiringLot(Product $product, Location $location, int $daysUntilExpiry): Movement
{
    $product->addStock(10, $location);
    $movement = Movement::query()->where('stockable_id', $product->id)->whereNull('expires_at')->latest('id')->firstOrFail();
    $movement->update(['expires_at' => now()->addDays($daysUntilExpiry), 'lot_reference' => 'LOT-'.$daysUntilExpiry]);

    return $movement->refresh();
}

it('shows lots expiring today when expired lots are hidden', function () {
    $today = expiringLot($this->product, $this->location, 0);
    $expired = expiringLot(Product::factory()->create(), $this->location, -3);

    Livewire::test(ExpiringStock::class)
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$expired]);
});

it('dispatches the expiring stock export', function () {
    $exports = ExportAction::fake();

    Livewire::test(ExpiringStock::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(ExpiringStockExporter::class);
});

it('exports lot values with the status threshold from options', function () {
    $lot = expiringLot($this->product, $this->location, 10);

    expect(ExpiringStockExporter::test(options: ['days_threshold' => 14])->export($lot))
        ->toBe([$this->product->name, $this->location->name, 'LOT-10', '10', null, now()->addDays(10)->toDateString(), '10', 'Expiring Soon'])
        ->and(ExpiringStockExporter::test(options: ['days_threshold' => 7])->export($lot)[7])
        ->toBe('OK');
});

it('runs a real expiring stock export end to end', function () {
    Storage::fake(config('filament.default_filesystem_disk', 'local'));
    expiringLot($this->product, $this->location, 5);
    expiringLot(Product::factory()->create(), $this->location, 60);

    Livewire::test(ExpiringStock::class)
        ->filterTable('days_until_expiry', '7')
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $csv = $export->getFileDisk()->get($export->getFileDirectory().'/0000000000000001.csv');

    expect($export->successful_rows)->toBe(1)
        ->and($csv)->toContain('LOT-5')
        ->and($csv)->toContain('Expiring Soon')
        ->and($csv)->not->toContain('LOT-60');
});
