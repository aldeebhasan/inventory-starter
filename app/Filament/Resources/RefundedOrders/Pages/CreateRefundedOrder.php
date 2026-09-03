<?php

namespace App\Filament\Resources\RefundedOrders\Pages;

use App\Enums\ReturnOrderType;
use App\Filament\Resources\RefundedOrders\RefundedOrderResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateRefundedOrder extends CreateRecord
{
    protected static string $resource = RefundedOrderResource::class;

    protected function getDefaultFormData(): array
    {
        return Arr::only(request()->query(), ['sale_order_id', 'customer_id']);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = ReturnOrderType::CustomerReturn->value;
        $data['created_by'] = auth()->id();

        return $data;
    }
}
