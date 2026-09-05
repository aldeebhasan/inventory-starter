<?php

namespace App\Filament\Resources\PurchaseOrders\Actions;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;

class ConfirmPurchaseOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'confirm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Draft)
            ->requiresConfirmation()
            ->action(function (PurchaseOrder $record) {
                $record->update(['status' => PurchaseOrderStatus::Confirmed]);

                foreach ($record->items as $item) {
                    if ($item->unit_cost !== null) {
                        $item->product->suppliers()->syncWithoutDetaching([
                            $record->supplier_id => [
                                'unit_cost' => $item->unit_cost,
                                'unit_id' => $item->unit_id,
                            ],
                        ]);
                    }
                }
            });
    }
}
