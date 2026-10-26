<?php

use App\Filament\Exports\SalesSummaryExporter;
use App\Models\User;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

function makeExport(int $daysOld): Export
{
    $export = new Export;
    $export->forceFill([
        'file_disk' => 'local',
        'file_name' => 'export-'.$daysOld,
        'exporter' => SalesSummaryExporter::class,
        'total_rows' => 1,
        'user_id' => User::factory()->create()->id,
        'created_at' => now()->subDays($daysOld),
        'updated_at' => now()->subDays($daysOld),
    ])->save();

    Storage::disk('local')->put($export->getFileDirectory().'/export.csv', 'a,b');

    return $export;
}

it('deletes exports older than the cutoff along with their files', function () {
    Storage::fake('local');
    $old = makeExport(daysOld: 8);
    $recent = makeExport(daysOld: 2);

    $this->artisan('exports:prune')
        ->expectsOutput('Deleted 1 old export(s).')
        ->assertSuccessful();

    expect(Export::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->directoryExists($old->getFileDirectory()))->toBeFalse()
        ->and(Export::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($recent->getFileDirectory().'/export.csv'))->toBeTrue();
});

it('accepts a custom age in days', function () {
    Storage::fake('local');
    $export = makeExport(daysOld: 2);

    $this->artisan('exports:prune', ['--days' => 1])->assertSuccessful();

    expect(Export::query()->whereKey($export->id)->exists())->toBeFalse();
});

it('schedules the export cleanup daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'exports:prune'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});
