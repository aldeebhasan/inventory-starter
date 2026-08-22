<?php

namespace App\Enums;

enum StockAdjustmentStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case Applied = 'applied';
    case PartiallyApplied = 'partially_applied';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Processing => 'Processing',
            self::Applied => 'Applied',
            self::PartiallyApplied => 'Partially Applied',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Processing => 'primary',
            self::Applied => 'success',
            self::PartiallyApplied => 'warning',
            self::Cancelled => 'danger',
        };
    }
}
