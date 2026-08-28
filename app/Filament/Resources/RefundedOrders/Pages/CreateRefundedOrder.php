<?php

namespace App\Filament\Resources\RefundedOrders\Pages;

use App\Enums\ReturnOrderType;
use App\Filament\Resources\RefundedOrders\RefundedOrderResource;
use App\Models\SaleOrder;
use Filament\Resources\Pages\CreateRecord;

class CreateRefundedOrder extends CreateRecord
{
    protected static string $resource = RefundedOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = ReturnOrderType::CustomerReturn->value;
        $data['created_by'] = auth()->id();

        if (isset($data['original_order_id']) && $data['original_order_id']) {
            $data['original_order_type'] = SaleOrder::class;
        }

        return $data;
    }
}
