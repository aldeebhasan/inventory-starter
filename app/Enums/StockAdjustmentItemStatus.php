<?php

namespace App\Enums;

enum StockAdjustmentItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Applied = 'applied';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Applied => 'Applied',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'primary',
            self::Applied => 'success',
            self::Failed => 'danger',
        };
    }
}
