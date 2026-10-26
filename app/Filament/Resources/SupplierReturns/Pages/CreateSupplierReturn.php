<?php

namespace App\Filament\Resources\SupplierReturns\Pages;

use App\Enums\ReturnOrderType;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateSupplierReturn extends CreateRecord
{
    protected static string $resource = SupplierReturnResource::class;

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill(
            Arr::only(request()->query(), ['purchase_order_id', 'supplier_id', 'location_id']),
        );

        $this->callHook('afterFill');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = ReturnOrderType::SupplierReturn->value;
        $data['created_by'] = auth()->id();

        return $data;
    }
}
