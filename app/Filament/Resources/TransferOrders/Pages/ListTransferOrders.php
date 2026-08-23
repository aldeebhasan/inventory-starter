<?php

namespace App\Filament\Resources\TransferOrders\Pages;

use App\Filament\Resources\TransferOrders\TransferOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTransferOrders extends ListRecords
{
    protected static string $resource = TransferOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
