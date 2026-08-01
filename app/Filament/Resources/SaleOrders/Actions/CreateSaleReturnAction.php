<?php

namespace App\Filament\Resources\SaleOrders\Actions;

use App\Enums\SaleOrderStatus;
use App\Models\SaleOrder;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class CreateSaleReturnAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createReturn';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Create Return')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Shipped)
            ->disabled(); // CustomerReturnResource not yet implemented
    }
}
