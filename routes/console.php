<?php

use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Filament never deletes export files, so old exports would pile up in storage forever.
Artisan::command('exports:prune {--days=7 : Delete exports older than this many days}', function () {
    $deleted = 0;

    Export::query()
        ->where('created_at', '<', now()->subDays((int) $this->option('days')))
        ->chunkById(100, function ($exports) use (&$deleted) {
            foreach ($exports as $export) {
                $export->deleteFileDirectory();
                $export->delete();
                $deleted++;
            }
        });

    $this->info("Deleted {$deleted} old export(s).");
})->purpose('Delete old report export files and their records');

Schedule::command('exports:prune')->daily();
