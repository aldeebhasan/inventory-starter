<?php

namespace App\Filament\Resources\ReportDefinitions\Widgets;

use Aldeebhasan\Panorama\History\RecordedRun;
use Aldeebhasan\Panorama\History\RunStatus;
use App\Models\ReportDefinition;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class ReportRunsWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    #[Locked]
    public ?Model $record = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Runs')
            ->query(fn (): Builder => $this->runsQuery())
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('user_name')->label('User')->default('—'),
                TextColumn::make('format')->badge()->formatStateUsing(fn (string $state): string => strtoupper($state)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RunStatus $state): string => ucfirst($state->value))
                    ->color(fn (RunStatus $state): string => match ($state) {
                        RunStatus::Succeeded => 'success',
                        RunStatus::Failed => 'danger',
                        RunStatus::Running => 'warning',
                    }),
                TextColumn::make('rows')->numeric()->default('—'),
                TextColumn::make('duration_ms')->label('Duration')->suffix(' ms')->numeric()->default('—'),
            ])
            ->recordActions([
                Action::make('download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (RecordedRun $run): string => route('filament.admin.report-builder.download', $run))
                    ->visible(fn (RecordedRun $run): bool => $run->path !== null
                        && $run->status === RunStatus::Succeeded
                        && (string) $run->user_id === (string) auth()->id()),
            ]);
    }

    /** The record is restored by Livewire, not the resource query, so visibility is checked here again. */
    private function runsQuery(): Builder
    {
        $report = $this->record;

        if (! $report instanceof ReportDefinition || ! $report->isVisibleTo(auth()->user())) {
            return RecordedRun::query()->whereRaw('1 = 0');
        }

        return $report->runs()
            ->select('report_runs.*')
            // Panorama stores user_id as a string; cast it to match users.id on MySQL and SQLite alike.
            ->addSelect(['user_name' => User::query()->select('name')->whereRaw('users.id = cast(report_runs.user_id as unsigned)')->limit(1)]);
    }
}
