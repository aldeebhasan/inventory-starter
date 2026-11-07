<?php

namespace App\Filament\Resources\ReportDefinitions;

use App\Filament\Resources\ReportDefinitions\Pages\BuildReport;
use App\Filament\Resources\ReportDefinitions\Pages\CreateReport;
use App\Filament\Resources\ReportDefinitions\Pages\ListReportDefinitions;
use App\Filament\Resources\ReportDefinitions\Tables\ReportDefinitionsTable;
use App\Models\ReportDefinition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ReportDefinitionResource extends Resource
{
    protected static ?string $model = ReportDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Report Builder';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $modelLabel = 'report';

    protected static ?string $slug = 'report-builder/reports';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return ReportDefinitionsTable::configure($table);
    }

    /** Others' private reports never reach a list, a record route or a bulk action. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->scopes(['visibleTo' => [auth()->user()]]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportDefinitions::route('/'),
            'create' => CreateReport::route('/create'),
            'view' => BuildReport::route('/{record}'),
        ];
    }
}
