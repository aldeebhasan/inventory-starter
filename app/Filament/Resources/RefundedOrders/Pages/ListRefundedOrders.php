<?php

namespace App\Filament\Resources\RefundedOrders\Pages;

use App\Filament\Resources\RefundedOrders\RefundedOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRefundedOrders extends ListRecords
{
    protected static string $resource = RefundedOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
