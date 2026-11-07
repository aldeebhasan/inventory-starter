<?php

use Aldeebhasan\Panorama\History\RecordedRun;
use Aldeebhasan\Panorama\Notifications\ReportFailed;
use Aldeebhasan\Panorama\Notifications\ReportReady;
use Aldeebhasan\Panorama\Queue\ReportQueue;
use App\Jobs\RunDefinedReportJob;
use App\Models\Product;
use App\Models\ReportDefinition;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('panorama.storage.disk'));
    Product::factory()->count(3)->create();
    $this->owner = User::factory()->create();
    $this->report = ReportDefinition::factory()->for($this->owner)->create();
});

it('stores the export, records it and notifies by mail and bell', function () {
    Notification::fake();

    (new RunDefinedReportJob($this->report->id, [], $this->owner->id, 'csv'))->handle(app(ReportQueue::class));

    $run = RecordedRun::query()->forReport($this->report->reportKey())->sole();

    expect($run->status->value ?? $run->status)->toBe('succeeded')
        ->and((int) $run->rows)->toBe(3)
        ->and($run->user_id)->toBe((string) $this->owner->id);
    Storage::disk(config('panorama.storage.disk'))->assertExists($run->path);
    Notification::assertSentTo($this->owner, ReportReady::class);
    Notification::assertSentTo($this->owner, DatabaseNotification::class);
});

it('fails without retrying when access is gone by the time it runs', function () {
    $job = (new RunDefinedReportJob($this->report->id, [], User::factory()->create()->id, 'csv'))->withFakeQueueInteractions();

    $job->handle(app(ReportQueue::class));

    $job->assertFailed();
    expect(RecordedRun::query()->count())->toBe(0);
});

it('fails without retrying when the report was deleted', function () {
    $job = (new RunDefinedReportJob($this->report->id, [], $this->owner->id, 'csv'))->withFakeQueueInteractions();
    $this->report->delete();

    $job->handle(app(ReportQueue::class));

    $job->assertFailed();
});

it('tells the user when the last attempt fails', function () {
    Notification::fake();

    (new RunDefinedReportJob($this->report->id, [], $this->owner->id, 'xlsx'))->failed(new RuntimeException('boom'));

    Notification::assertSentTo($this->owner, ReportFailed::class, fn (ReportFailed $mail): bool => $mail->format === 'xlsx');
    Notification::assertSentTo($this->owner, DatabaseNotification::class);
});

it('lets only the run owner download the stored file', function () {
    (new RunDefinedReportJob($this->report->id, [], $this->owner->id, 'csv'))->handle(app(ReportQueue::class));
    $run = RecordedRun::query()->forReport($this->report->reportKey())->sole();
    $url = route('filament.admin.report-builder.download', $run);

    $this->actingAs($this->owner)->get($url)->assertOk()->assertDownload($run->filename);
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
});
