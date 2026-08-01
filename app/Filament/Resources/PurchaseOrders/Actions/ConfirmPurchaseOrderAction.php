<?php

namespace App\Filament\Resources\PurchaseOrders\Actions;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;

class ConfirmPurchaseOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'confirm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Draft)
            ->requiresConfirmation()
            ->action(fn (PurchaseOrder $record) => $record->update(['status' => PurchaseOrderStatus::Confirmed]));
    }
}
