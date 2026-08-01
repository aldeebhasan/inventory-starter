<?php

namespace App\Models;

use Aldeebhasan\Inventorix\Models\Location as BaseLocation;
use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Builder;

class Location extends BaseLocation
{
    public function getTypeAttribute(): ?LocationType
    {
        $type = $this->meta['type'] ?? null;

        return $type ? LocationType::from($type) : null;
    }

    public function scopeWarehouses(Builder $query): Builder
    {
        return $query->where('meta->type', 'warehouse');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
