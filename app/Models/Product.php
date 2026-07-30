<?php

namespace App\Models;

use Aldeebhasan\Inventorix\Traits\HasInventory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'description', 'image', 'price', 'cost', 'category_id'])]
class Product extends Model
{
    use HasInventory, SoftDeletes;

    protected $casts = [
        'price' => 'float',
        'cost' => 'float',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
