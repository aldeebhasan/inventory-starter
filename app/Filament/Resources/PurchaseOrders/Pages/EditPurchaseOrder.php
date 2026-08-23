<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var PurchaseOrder $record */
        $record = $this->record;
        if ($record->status !== PurchaseOrderStatus::Draft) {
            abort(403, 'Only draft purchase orders can be edited.');
        }
    }

    protected function getHeaderActions(): array
    {
        /** @var PurchaseOrder $record */
        $record = $this->record;

        return [
            DeleteAction::make()
                ->visible($record->status === PurchaseOrderStatus::Draft),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
