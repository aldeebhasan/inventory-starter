<?php

namespace App\Filament\Resources\SaleOrders\Pages;

use App\Enums\SaleOrderStatus;
use App\Filament\Resources\SaleOrders\SaleOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditSaleOrder extends EditRecord
{
    protected static string $resource = SaleOrderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->status !== SaleOrderStatus::Draft) {
            abort(403, 'Only draft sale orders can be edited.');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
