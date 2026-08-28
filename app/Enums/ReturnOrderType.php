<?php

namespace App\Enums;

enum ReturnOrderType: string
{
    case CustomerReturn = 'customer_return';
    case SupplierReturn = 'supplier_return';

    public function label(): string
    {
        return match ($this) {
            self::CustomerReturn => 'Customer Return',
            self::SupplierReturn => 'Supplier Return',
        };
    }
}
