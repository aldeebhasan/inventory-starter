<?php

namespace App\Filament\Resources\SaleOrders\Actions;

use App\Enums\SaleOrderStatus;
use App\Models\SaleOrder;
use Filament\Actions\Action;
use Illuminate\Support\Facades\DB;

class CancelSaleOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'cancel';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (SaleOrder $record) => in_array($record->status, [
                SaleOrderStatus::Draft,
                SaleOrderStatus::Confirmed,
                SaleOrderStatus::Picked,
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
            });
    }
}
