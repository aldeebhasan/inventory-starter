<?php

namespace App\Models;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Models\Location;
use Aldeebhasan\Inventorix\Models\Reservation;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Traits\HasInventory;
use App\Enums\ProductType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $image
 * @property float $price
 * @property float $cost
 * @property ProductType $type
 * @property int|null $brand_id
 * @property int|null $unit_id
 * @property-read Brand|null $brand
 * @property-read Unit|null $unit
 * @property-read Collection<int, Category> $categories
 * @property-read Collection<int, Addon> $addons
 * @property-read Collection<int, Supplier> $suppliers
 */
#[Fillable(['name', 'description', 'image', 'price', 'cost', 'type', 'brand_id', 'unit_id'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes, HasInventory;


    protected $casts = [
        'price' => 'float',
        'cost' => 'float',
        'type' => ProductType::class,
    ];

    public function isInventory(): bool
    {
        return $this->type === ProductType::Inventory;
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'product_addon');
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_supplier')
            ->withPivot(['unit_cost', 'supplier_sku']);
    }
}
