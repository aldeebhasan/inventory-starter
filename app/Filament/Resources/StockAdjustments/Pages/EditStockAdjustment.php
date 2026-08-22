<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Enums\StockAdjustmentStatus;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Models\StockAdjustment;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditStockAdjustment extends EditRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var StockAdjustment $record */
        $record = $this->record;
        if ($record->status !== StockAdjustmentStatus::Draft) {
            abort(403, 'Only draft stock adjustments can be edited.');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
