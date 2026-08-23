<?php

namespace App\Filament\Resources\TransferOrders\Pages;

use App\Enums\TransferOrderStatus;
use App\Filament\Resources\TransferOrders\TransferOrderResource;
use App\Models\TransferOrder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTransferOrder extends EditRecord
{
    protected static string $resource = TransferOrderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var TransferOrder $record */
        $record = $this->record;
        if ($record->status !== TransferOrderStatus::Draft) {
            abort(403, 'Only draft transfer orders can be edited.');
        }
    }

    protected function getHeaderActions(): array
    {
        /** @var TransferOrder $record */
        $record = $this->record;

        return [
            DeleteAction::make()
                ->visible($record->status === TransferOrderStatus::Draft),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
