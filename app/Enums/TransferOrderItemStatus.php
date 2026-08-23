<?php

namespace App\Enums;

enum TransferOrderItemStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Receiving = 'receiving';
    case Received = 'received';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Receiving => 'Receiving',
            self::Received => 'Received',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Sending, self::Receiving => 'primary',
            self::Sent => 'info',
            self::Received => 'success',
            self::Failed => 'danger',
        };
    }
}
