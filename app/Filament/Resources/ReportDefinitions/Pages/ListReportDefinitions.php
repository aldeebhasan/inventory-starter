<?php

namespace App\Filament\Resources\ReportDefinitions\Pages;

use App\Filament\Resources\ReportDefinitions\ReportDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReportDefinitions extends ListRecords
{
    protected static string $resource = ReportDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
