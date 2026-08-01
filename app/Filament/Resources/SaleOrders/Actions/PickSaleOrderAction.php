<?php

namespace App\Filament\Resources\SaleOrders\Actions;

use App\Enums\SaleOrderStatus;
use App\Models\SaleOrder;
use Filament\Actions\Action;

class PickSaleOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'pick';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Confirmed)
            ->action(fn (SaleOrder $record) => $record->update(['status' => SaleOrderStatus::Picked]));
    }
}
