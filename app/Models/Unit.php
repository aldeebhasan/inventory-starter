<?php

namespace App\Models;

use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $abbreviation
 * @property int|null $base_unit_id
 * @property float $conversion_factor
 * @property-read Unit|null $baseUnit
 * @property-read Collection<int, Unit> $derivedUnits
 * @property-read Collection<int, Product> $products
 */
#[Fillable(['name', 'abbreviation', 'base_unit_id', 'conversion_factor'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected $casts = [
        'conversion_factor' => 'float',
    ];

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function derivedUnits(): HasMany
    {
        return $this->hasMany(Unit::class, 'base_unit_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Convert a quantity expressed in this unit to the base unit quantity.
     * If this is already the base unit (no base_unit_id), returns qty unchanged.
     */
    public function convertQty(float $qty): float
    {
        if (! $this->base_unit_id) {
            return $qty;
        }

        return $qty * $this->conversion_factor;
    }
}
