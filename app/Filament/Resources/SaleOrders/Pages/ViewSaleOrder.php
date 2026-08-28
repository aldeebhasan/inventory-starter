<?php

namespace App\Filament\Resources\SaleOrders\Pages;

use App\Filament\Resources\SaleOrders\Actions\CancelSaleOrderAction;
use App\Filament\Resources\SaleOrders\Actions\ConfirmSaleOrderAction;
use App\Filament\Resources\SaleOrders\Actions\CreateSaleReturnAction;
use App\Filament\Resources\SaleOrders\Actions\FulfillSaleOrderAction;
use App\Filament\Resources\SaleOrders\Actions\PickSaleOrderAction;
use App\Filament\Resources\SaleOrders\Actions\ShipSaleOrderAction;
use App\Filament\Resources\SaleOrders\SaleOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSaleOrder extends ViewRecord
{
    protected static string $resource = SaleOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConfirmSaleOrderAction::make(),
            PickSaleOrderAction::make(),
            ShipSaleOrderAction::make(),
            FulfillSaleOrderAction::make(),
            CancelSaleOrderAction::make(),
            CreateSaleReturnAction::make(),
        ];
    }
}
