<?php

namespace App\Filament\Resources\PurchaseOrders\Actions;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class CreateSupplierReturnAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createSupplierReturn';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Create Supplier Return')
            ->icon(Heroicon::OutlinedArrowUturnRight)
            ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Received)
            ->url(fn (PurchaseOrder $record) => SupplierReturnResource::getUrl('create', [
                'purchase_order_id' => $record->id,
                'supplier_id' => $record->supplier_id,
                'location_id' => $record->location_id,
            ]));
    }
}
