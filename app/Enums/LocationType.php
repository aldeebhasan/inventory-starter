<?php

namespace App\Enums;

enum LocationType: string
{
    case Warehouse = 'warehouse';
    case Store = 'store';
    case Transit = 'transit';
    case Virtual = 'virtual';

    public function label(): string
    {
        return match ($this) {
            self::Warehouse => 'Warehouse',
            self::Store => 'Store',
            self::Transit => 'Transit',
            self::Virtual => 'Virtual',
        };
    }
}
