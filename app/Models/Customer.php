<?php

namespace App\Models;

use App\Enums\ReturnOrderType;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'email', 'phone', 'address', 'tax_number', 'is_active'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function saleOrders(): HasMany
    {
        return $this->hasMany(SaleOrder::class);
    }

    public function customerReturns(): HasMany
    {
        return $this->hasMany(ReturnOrder::class)->where('type', ReturnOrderType::CustomerReturn);
    }
}
