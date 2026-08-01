<?php

namespace App\Enums;

enum SaleOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Picked = 'picked';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Confirmed => 'warning',
            self::Picked => 'info',
            self::Shipped => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Picked => 'Picked',
            self::Shipped => 'Shipped',
            self::Cancelled => 'Cancelled',
        };
    }
}
