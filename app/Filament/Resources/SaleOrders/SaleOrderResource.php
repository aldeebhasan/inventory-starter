<?php

namespace App\Filament\Resources\SaleOrders;

use App\Filament\Resources\SaleOrders\Pages\CreateSaleOrder;
use App\Filament\Resources\SaleOrders\Pages\EditSaleOrder;
use App\Filament\Resources\SaleOrders\Pages\ListSaleOrders;
use App\Filament\Resources\SaleOrders\Pages\ViewSaleOrder;
use App\Filament\Resources\SaleOrders\Schemas\SaleOrderForm;
use App\Filament\Resources\SaleOrders\Schemas\SaleOrderInfolist;
use App\Filament\Resources\SaleOrders\Tables\SaleOrdersTable;
use App\Models\SaleOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class SaleOrderResource extends Resource
{
    protected static ?string $model = SaleOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    public static function form(Schema $schema): Schema
    {
        return SaleOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SaleOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SaleOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSaleOrders::route('/'),
            'create' => CreateSaleOrder::route('/create'),
            'view' => ViewSaleOrder::route('/{record}'),
            'edit' => EditSaleOrder::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
