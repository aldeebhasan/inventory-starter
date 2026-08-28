<?php

namespace App\Enums;

enum ReturnOrderStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
