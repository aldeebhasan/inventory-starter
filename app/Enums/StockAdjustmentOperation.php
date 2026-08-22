<?php

namespace App\Enums;

enum StockAdjustmentOperation: string
{
    case Increase = 'increase';
    case Decrease = 'decrease';
    case Adjust = 'adjust';

    public function label(): string
    {
        return match ($this) {
            self::Increase => 'Increase',
            self::Decrease => 'Decrease',
            self::Adjust => 'Set Exact',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Increase => 'success',
            self::Decrease => 'danger',
            self::Adjust => 'warning',
        };
    }
}
