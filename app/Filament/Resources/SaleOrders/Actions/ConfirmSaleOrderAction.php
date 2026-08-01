<?php

namespace App\Filament\Resources\SaleOrders\Actions;

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use App\Enums\SaleOrderStatus;
use App\Models\SaleOrder;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConfirmSaleOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'confirm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Draft)
            ->requiresConfirmation()
            ->action(function (SaleOrder $record) {
                DB::transaction(function () use ($record) {
                    foreach ($record->items as $item) {
                        $dto = new StockOperationDto(
                            transactionType: TransactionType::Sale,
                            causable: $record,
                            reference: $item,
                            createdBy: Auth::id(),
                        );
                        $reservation = $item->product->reserve($item->convertedQuantity(), $record->location_id, $dto);
                        $item->update(['reservation_id' => $reservation->id]);
                    }
                    $record->update(['status' => SaleOrderStatus::Confirmed]);
                });
            });
    }
}
