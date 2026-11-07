<?php

namespace App\Jobs;

use Aldeebhasan\Panorama\Facades\Panorama;
use Aldeebhasan\Panorama\History\RecordedRun;
use Aldeebhasan\Panorama\Queue\CompletedReport;
use Aldeebhasan\Panorama\Queue\ReportQueue;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Reports\DefinedReport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Renders a Report Builder report off the request, stores it and tells the user.
 *
 * Panorama's own RunReport job cannot be used: its worker re-resolves the report by registered key or class, and a
 * report defined in the database is neither. The rendering, storage, history and notifications are still Panorama's.
 */
class RunDefinedReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public int $definitionId,
        public array $params,
        public int $userId,
        public string $format,
    ) {}

    public function handle(ReportQueue $queue): void
    {
        $definition = ReportDefinition::query()->find($this->definitionId);
        $user = User::query()->find($this->userId);

        // Re-checked in the worker, so access revoked while the job waited is honoured. Retrying cannot fix it.
        if ($definition === null || $user === null || ! $definition->isVisibleTo($user)) {
            $this->fail(new RuntimeException('The report was deleted or is no longer shared with you.'));

            return;
        }

        $disk = config('panorama.storage.disk');

        $run = Panorama::run(new DefinedReport($definition), $this->params, $user);
        $rendered = $run->render($this->format);
        $path = $rendered->store($disk, config('panorama.storage.path'));

        $user->notify($queue->ready(new CompletedReport(
            $definition->reportKey(),
            $definition->name,
            $this->format,
            $rendered->filename(),
            $rendered->contentType(),
            $disk,
            $path,
            $run->result()->yielded(),
            $rendered->bytes(),
            $run->params()->all(),
        )));

        $recorded = RecordedRun::query()->forReport($definition->reportKey())->where('path', $path)->latestFirst()->first();

        Notification::make()
            ->title("{$definition->name} is ready")
            ->body(strtoupper($this->format).' export finished.')
            ->success()
            ->actions($recorded === null ? [] : [
                Action::make('download')->button()->url(route('filament.admin.report-builder.download', $recorded)),
            ])
            ->sendToDatabase($user);
    }

    public function failed(?Throwable $exception): void
    {
        $user = User::query()->find($this->userId);
        $definition = ReportDefinition::withTrashed()->find($this->definitionId);

        if ($user === null) {
            return;
        }

        $name = $definition->name ?? 'Report';

        // The mail says only that it failed; the reason can name tables or hosts. It stays in the failed job and history.
        $user->notify(app(ReportQueue::class)->failed(
            $definition?->reportKey() ?? "defined-{$this->definitionId}",
            $name,
            $this->format,
            $exception === null ? 'The job failed.' : $exception::class.': '.$exception->getMessage(),
        ));

        Notification::make()
            ->title("{$name} export failed")
            ->body(strtoupper($this->format).' export could not be generated.')
            ->danger()
            ->sendToDatabase($user);
    }
}
