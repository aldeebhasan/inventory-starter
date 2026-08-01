<?php

namespace App\Filament\Resources\SaleOrders\Pages;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use App\Enums\SaleOrderStatus;
use App\Filament\Resources\SaleOrders\SaleOrderResource;
use App\Models\SaleOrder;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class ViewSaleOrder extends ViewRecord
{
    protected static string $resource = SaleOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirm')
                ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Draft)
                ->requiresConfirmation()
                ->action(function (SaleOrder $record) {
                    DB::transaction(function () use ($record) {
                        foreach ($record->items as $item) {
                            $dto = new StockOperationDto(
                                transactionType: TransactionType::Sale,
                                causable: $record,
                                reference: $item,
                                createdBy: auth()->id(),
                            );
                            $reservation = $item->product->reserve($item->quantity, $record->location_id, $dto);
                            $item->update(['reservation_id' => $reservation->id]);
                        }
                        $record->update(['status' => SaleOrderStatus::Confirmed]);
                    });
                }),

            Action::make('pick')
                ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Confirmed)
                ->action(fn (SaleOrder $record) => $record->update(['status' => SaleOrderStatus::Picked])),

            Action::make('ship')
                ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Picked)
                ->requiresConfirmation()
                ->action(function (SaleOrder $record) {
                    DB::transaction(function () use ($record) {
                        foreach ($record->items as $item) {
                            if ($item->reservation_id) {
                                $item->product->fulfillReservation($item->reservation_id);
                            }
                        }
                        $record->update(['status' => SaleOrderStatus::Shipped, 'shipped_at' => now()]);
                    });
                }),

            Action::make('cancel')
                ->visible(fn (SaleOrder $record) => in_array($record->status, [
                    SaleOrderStatus::Draft, SaleOrderStatus::Confirmed, SaleOrderStatus::Picked,
                ]))
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (SaleOrder $record) {
                    DB::transaction(function () use ($record) {
                        foreach ($record->items as $item) {
                            if ($item->reservation_id) {
                                $item->product->releaseReservation($item->reservation_id);
                                $item->update(['reservation_id' => null]);
                            }
                        }
                        $record->update(['status' => SaleOrderStatus::Cancelled]);
                    });
                }),

            Action::make('createReturn')
                ->label('Create Return')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Shipped)
                ->disabled(), // CustomerReturnResource not yet implemented
        ];
    }
}
