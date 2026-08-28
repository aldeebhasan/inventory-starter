<?php

namespace App\Filament\Resources\RefundedOrders\Pages;

use App\Enums\ReturnOrderStatus;
use App\Filament\Resources\RefundedOrders\RefundedOrderResource;
use App\Models\ReturnOrder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditRefundedOrder extends EditRecord
{
    protected static string $resource = RefundedOrderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var ReturnOrder $record */
        $record = $this->record;
        if ($record->status !== ReturnOrderStatus::Draft) {
            abort(403, 'Only draft refunded orders can be edited.');
        }
    }

    protected function getHeaderActions(): array
    {
        /** @var ReturnOrder $record */
        $record = $this->record;

        return [
            DeleteAction::make()
                ->visible($record->status === ReturnOrderStatus::Draft),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
