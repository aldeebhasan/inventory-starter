<?php

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
