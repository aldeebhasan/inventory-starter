<?php

namespace App\Filament\Resources\SupplierReturns\Actions;

use App\Enums\ReturnOrderStatus;
use App\Models\ReturnOrder;
use Filament\Actions\Action;

class ApproveSupplierReturnAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approve';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->color('info')
            ->visible(fn (ReturnOrder $record) => $record->status === ReturnOrderStatus::Draft)
            ->requiresConfirmation()
            ->action(fn (ReturnOrder $record) => $record->update(['status' => ReturnOrderStatus::Approved]));
    }
}
