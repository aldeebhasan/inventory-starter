<?php

namespace App\Filament\Resources\SaleOrders\Actions;

use App\Enums\SaleOrderStatus;
use App\Models\SaleOrder;
use Filament\Actions\Action;

class FulfillSaleOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'fulfill';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Shipped)
            ->requiresConfirmation()
            ->action(fn (SaleOrder $record) => $record->update(['status' => SaleOrderStatus::Fulfilled]));
    }
}
