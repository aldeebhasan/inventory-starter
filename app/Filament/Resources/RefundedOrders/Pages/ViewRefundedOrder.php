<?php

namespace App\Filament\Resources\RefundedOrders\Pages;

use App\Filament\Resources\RefundedOrders\Actions\CancelRefundedOrderAction;
use App\Filament\Resources\RefundedOrders\Actions\CompleteRefundedOrderAction;
use App\Filament\Resources\RefundedOrders\RefundedOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewRefundedOrder extends ViewRecord
{
    protected static string $resource = RefundedOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CompleteRefundedOrderAction::make(),
            CancelRefundedOrderAction::make(),
        ];
    }
}
