<?php

namespace App\Filament\Resources\SaleOrders\Actions;

use App\Enums\SaleOrderStatus;
use App\Models\SaleOrder;
use Filament\Actions\Action;
use Illuminate\Support\Facades\DB;

class ShipSaleOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'ship';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
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
            });
    }
}
