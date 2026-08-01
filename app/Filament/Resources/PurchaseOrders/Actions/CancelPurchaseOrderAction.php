<?php

namespace App\Filament\Resources\PurchaseOrders\Actions;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;

class CancelPurchaseOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'cancel';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (PurchaseOrder $record) => in_array($record->status, [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::Confirmed,
            ]))
            ->color('danger')
            ->requiresConfirmation()
            ->action(fn (PurchaseOrder $record) => $record->update(['status' => PurchaseOrderStatus::Cancelled]));
    }
}
