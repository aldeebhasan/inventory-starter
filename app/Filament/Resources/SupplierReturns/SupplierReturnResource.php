<?php

namespace App\Filament\Resources\SupplierReturns;

use App\Enums\ReturnOrderType;
use App\Filament\Resources\SupplierReturns\Pages\CreateSupplierReturn;
use App\Filament\Resources\SupplierReturns\Pages\EditSupplierReturn;
use App\Filament\Resources\SupplierReturns\Pages\ListSupplierReturns;
use App\Filament\Resources\SupplierReturns\Pages\ViewSupplierReturn;
use App\Filament\Resources\SupplierReturns\Schemas\SupplierReturnForm;
use App\Filament\Resources\SupplierReturns\Schemas\SupplierReturnInfolist;
use App\Filament\Resources\SupplierReturns\Tables\SupplierReturnsTable;
use App\Models\ReturnOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class SupplierReturnResource extends Resource
{
    protected static ?string $model = ReturnOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static string|UnitEnum|null $navigationGroup = 'Purchase';

    protected static ?int $navigationSort = 3;

    protected static ?string $label = 'Supplier Return';

    protected static ?string $pluralLabel = 'Supplier Returns';

    protected static ?string $slug = 'supplier-returns';

    public static function form(Schema $schema): Schema
    {
        return SupplierReturnForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupplierReturnInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplierReturnsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierReturns::route('/'),
            'create' => CreateSupplierReturn::route('/create'),
            'view' => ViewSupplierReturn::route('/{record}'),
            'edit' => EditSupplierReturn::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', ReturnOrderType::SupplierReturn)
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->where('type', ReturnOrderType::SupplierReturn)
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
