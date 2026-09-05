<?php

namespace App\Filament\Resources\RefundedOrders;

use App\Enums\ReturnOrderType;
use App\Filament\Resources\RefundedOrders\Pages\CreateRefundedOrder;
use App\Filament\Resources\RefundedOrders\Pages\EditRefundedOrder;
use App\Filament\Resources\RefundedOrders\Pages\ListRefundedOrders;
use App\Filament\Resources\RefundedOrders\Pages\ViewRefundedOrder;
use App\Filament\Resources\RefundedOrders\Schemas\RefundedOrderForm;
use App\Filament\Resources\RefundedOrders\Schemas\RefundedOrderInfolist;
use App\Filament\Resources\RefundedOrders\Tables\RefundedOrdersTable;
use App\Models\ReturnOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class RefundedOrderResource extends Resource
{
    protected static ?string $model = ReturnOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

    protected static ?string $label = 'Refunded Order';

    protected static ?string $pluralLabel = 'Refunded Orders';

    protected static ?string $slug = 'refunded-orders';

    public static function form(Schema $schema): Schema
    {
        return RefundedOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RefundedOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RefundedOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRefundedOrders::route('/'),
            'create' => CreateRefundedOrder::route('/create'),
            'view' => ViewRefundedOrder::route('/{record}'),
            'edit' => EditRefundedOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', ReturnOrderType::CustomerReturn)
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
