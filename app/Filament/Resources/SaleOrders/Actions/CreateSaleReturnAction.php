<?php

namespace App\Filament\Resources\SaleOrders\Actions;

use App\Enums\SaleOrderStatus;
use App\Filament\Resources\RefundedOrders\RefundedOrderResource;
use App\Models\SaleOrder;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class CreateSaleReturnAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createReturn';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Create Refund')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (SaleOrder $record) => in_array($record->status, [
                SaleOrderStatus::Shipped,
                SaleOrderStatus::Fulfilled,
            ]))
            ->url(fn (SaleOrder $record) => RefundedOrderResource::getUrl('create', [
                'sale_order_id' => $record->id,
                'customer_id' => $record->customer_id,
            ]));
    }
}
