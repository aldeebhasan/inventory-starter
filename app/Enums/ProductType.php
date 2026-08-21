<?php

namespace App\Enums;

enum ProductType: string
{
    case Inventory = 'inventory';
    case NonInventory = 'non_inventory';

    public function label(): string
    {
        return match ($this) {
            self::Inventory => 'Inventory',
            self::NonInventory => 'Non-Inventory',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Inventory => 'success',
            self::NonInventory => 'gray',
        };
    }
}
