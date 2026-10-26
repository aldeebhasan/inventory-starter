<?php

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

function createLocation(array $overrides = []): Location
{
    static $counter = 0;
    $counter++;

    return Location::create(array_merge([
        'name' => 'Warehouse '.$counter,
        'code' => 'WH-'.str_pad($counter, 3, '0', STR_PAD_LEFT),
        'is_active' => true,
        'meta' => ['type' => 'warehouse'],
    ], $overrides));
}

/**
 * Number of database queries executed while running the callback.
 *
 * Used by report tests to prove a page's query count does not grow with its row count (no per-row queries).
 */
function countQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();

        return count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}
