<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\Actions\CancelPurchaseOrderAction;
use App\Filament\Resources\PurchaseOrders\Actions\ConfirmPurchaseOrderAction;
use App\Filament\Resources\PurchaseOrders\Actions\ReceivePurchaseOrderAction;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConfirmPurchaseOrderAction::make(),
            ReceivePurchaseOrderAction::make(),
            CancelPurchaseOrderAction::make(),
        ];
    }
}
