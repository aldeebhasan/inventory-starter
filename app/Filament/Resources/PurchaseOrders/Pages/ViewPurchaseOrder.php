<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Transaction;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirm')
                ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Draft)
                ->requiresConfirmation()
                ->action(fn (PurchaseOrder $record) => $record->update(['status' => PurchaseOrderStatus::Confirmed])),

            Action::make('receive')
                ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Confirmed)
                ->requiresConfirmation()
                ->action(function (PurchaseOrder $record) {
                    DB::transaction(function () use ($record) {
                        Inventorix::bulk(function (Transaction $tx) use ($record) {
                            foreach ($record->items as $item) {
                                $dto = new StockOperationDto(
                                    transaction: $tx,
                                    transactionType: TransactionType::Purchase,
                                    causable: $record,
                                    reference: $item,
                                    cost: $item->unit_cost,
                                    createdBy: auth()->id(),
                                );
                                $item->product->addStock($item->quantity, $record->location_id, $dto);
                                $item->update(['received_quantity' => $item->quantity]);
                            }
                        });
                        $record->update(['status' => PurchaseOrderStatus::Received, 'received_at' => now()]);
                    });
                }),

            Action::make('cancel')
                ->visible(fn (PurchaseOrder $record) => in_array($record->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Confirmed]))
                ->color('danger')
                ->requiresConfirmation()
                ->action(fn (PurchaseOrder $record) => $record->update(['status' => PurchaseOrderStatus::Cancelled])),
        ];
    }
}
