<?php

namespace App\Enums;

enum ReturnOrderStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Approved => 'info',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
