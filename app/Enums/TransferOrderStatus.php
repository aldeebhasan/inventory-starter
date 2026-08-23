<?php

namespace App\Enums;

enum TransferOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Sending = 'sending';
    case InTransit = 'in_transit';
    case PartiallySent = 'partially_sent';
    case Receiving = 'receiving';
    case Completed = 'completed';
    case PartiallyCompleted = 'partially_completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Sending => 'Sending',
            self::InTransit => 'In Transit',
            self::PartiallySent => 'Partially Sent',
            self::Receiving => 'Receiving',
            self::Completed => 'Completed',
            self::PartiallyCompleted => 'Partially Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Confirmed, self::Sending, self::Receiving => 'primary',
            self::InTransit => 'info',
            self::Completed => 'success',
            self::PartiallySent, self::PartiallyCompleted => 'warning',
            self::Cancelled => 'danger',
        };
    }
}
