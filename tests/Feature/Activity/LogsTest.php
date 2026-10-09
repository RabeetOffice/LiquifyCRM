<?php

declare(strict_types=1);

use App\Modules\Activity\Jobs\BuildLogExport;
use App\Modules\Activity\Jobs\DeleteLogsBefore;
use App\Modules\Activity\Livewire\LogsPage;
use App\Modules\Activity\Models\Activity;
use App\Modules\Activity\Models\LogArchive;
use App\Modules\Activity\Models\LogExport;
use App\Modules\Activity\Settings\LogSettings;
use App\Modules\Activity\Support\LogArchiver;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sites\Models\InboundEvent;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Week 6: the Logs screen, downloads, nightly archives, retention and the
 * Super Admin's "delete before a date".
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->other = brand(['name' => 'ANZ Publishing House', 'slug' => 'anz']);
    $this->teamA = department($this->brand, ['name' => 'Sales Team A']);
    $this->teamB = department($this->brand, ['name' => 'Consultants']);
    $this->manager = managerOf($this->teamA);
    $this->agent = agentOf($this->teamA);
    $this->admin = admin();
    $this->super = superAdmin();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->teamA->getKey(), 'name' => 'Sarah Lee', 'email' => 'sarah@example.com']);

    /** Write an entry and move it back in time. */
    $this->entry = function (string $description, int $daysAgo, array $options = []): Activity {
        $activity = Audit::log($options['event'] ?? 'members.invited', $options['subject'] ?? null, [], $description, brand: $options['brand'] ?? $this->brand, department: $options['department'] ?? null, causer: $options['causer'] ?? $this->admin, logName: $options['area'] ?? 'members');
        $activity->forceFill(['created_at' => now()->subDays($daysAgo)->setTime(10, 0)])->save();

        return $activity;
    };
});

it('shows Admins every brand, with filters and search by lead', function (): void {
    ($this->entry)('Invited Omar to the brand', 1);
    ($this->entry)('Lead assigned to Aisha', 2, ['subject' => $this->lead, 'area' => 'leads', 'event' => 'leads.assigned']);
    ($this->entry)('ANZ site added', 1, ['brand' => $this->other, 'area' => 'sites', 'event' => 'sites.created']);

    $this->actingAs($this->admin)->get('/admin/logs')
        ->assertOk()
        ->assertSee('Invited Omar to the brand')
        ->assertSee('ANZ site added')
        ->assertSee('Storage');

    Livewire::actingAs($this->admin)
        ->test(LogsPage::class)
        ->set('brandFilter', (string) $this->brand->getKey())
        ->assertDontSee('ANZ site added')
        ->set('area', 'leads')
        ->assertSee('Lead assigned to Aisha')
        ->assertDontSee('Invited Omar to the brand')
        ->set('area', '')
        ->set('search', 'sarah@')
        ->assertSee('Lead assigned to Aisha')
        ->assertDontSee('Invited Omar to the brand')
        ->call('clearFilters')
        ->set('until', now()->subDays(2)->toDateString())
        ->assertSee('Lead assigned to Aisha')
        ->assertDontSee('ANZ site added');
});

it('holds a Manager to their own department and keeps Agents out', function (): void {
    ($this->entry)('Note added in Team A', 1, ['department' => $this->teamA, 'area' => 'leads']);
    ($this->entry)('Note added in Consultants', 1, ['department' => $this->teamB, 'area' => 'leads']);
    ($this->entry)('Brand settings changed', 1, ['area' => 'brands']);

    $this->actingAs($this->manager)->get('/b/ukph/logs')
        ->assertOk()
        ->assertSee('Note added in Team A')
        ->assertDontSee('Note added in Consultants')
        ->assertDontSee('Brand settings changed')
        ->assertDontSee('Delete logs')
        ->assertDontSee('Archive size');

    $this->actingAs($this->manager)->get('/admin/logs')->assertForbidden();

    $this->flushSession();
    $this->actingAs($this->agent)->get('/b/ukph/logs')->assertForbidden();
});

it('builds a download in the queue, links it for 24 hours and logs the download', function (): void {
    ($this->entry)('Note added in Team A', 3, ['department' => $this->teamA, 'area' => 'leads']);
    ($this->entry)('Note added in Consultants', 3, ['department' => $this->teamB, 'area' => 'leads']);
    ($this->entry)('Too old for seven days', 20, ['department' => $this->teamA, 'area' => 'leads']);

    Livewire::actingAs($this->manager)->test(LogsPage::class, ['brand' => $this->brand])->call('export', 7)->assertSee('Your downloads');

    $export = LogExport::query()->sole();

    expect($export->status)->toBe('ready')
        // Team A's note plus the two "added to the department" entries from the setup; nothing from Consultants.
        ->and($export->rows)->toBe(3)
        ->and($export->department_ids)->toBe([$this->teamA->getKey()])
        ->and($export->expires_at->isAfter(now()->addHours(23)))->toBeTrue()
        ->and($export->path)->toEndWith('.zip');

    $path = (string) $export->path;
    Storage::disk('local')->assertExists($path);
    Notification::assertSentTo($this->manager, fn (AppNotification $notification): bool => str_contains($notification->title, 'log download is ready'));

    $this->actingAs($this->manager)->get('/logs/exports/'.$export->getKey())->assertOk()->assertDownload();
    expect(Activity::query()->where('event', 'system.logs_downloaded')->count())->toBe(1);

    // Somebody else's link does not exist; an expired one is gone.
    $this->flushSession();
    $this->actingAs($this->admin)->get('/logs/exports/'.$export->getKey())->assertNotFound();

    $this->flushSession();
    $export->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($this->manager)->get('/logs/exports/'.$export->getKey())->assertStatus(410);

    $this->artisan('logs:archive')->assertSuccessful();
    expect($export->refresh()->status)->toBe('expired');
    Storage::disk('local')->assertMissing($path);

    Queue::fake();
    Livewire::actingAs($this->agent)->test(LogsPage::class, ['brand' => $this->brand])->assertForbidden();
    Queue::assertNotPushed(BuildLogExport::class);
});

it('archives each finished day to a compressed file and applies the retention', function (): void {
    ($this->entry)('Forty days old', 40);
    ($this->entry)('Also forty days old', 40);
    $timeline = ($this->entry)('Old lead timeline entry', 40, ['subject' => $this->lead, 'area' => 'leads', 'event' => 'leads.assigned']);
    ($this->entry)('Five days old', 5);
    ($this->entry)('Today', 0);

    LogArchive::query()->create(['date' => now()->subDays(200)->toDateString(), 'rows' => 3, 'path' => 'logs/old/very-old.jsonl.gz', 'size_bytes' => 10, 'checksum' => str_repeat('a', 64)]);
    Storage::disk('local')->put('logs/old/very-old.jsonl.gz', 'x');

    $this->artisan('logs:archive')->assertSuccessful();

    $day = now()->subDays(40);
    $archive = LogArchive::query()->whereDate('date', $day)->sole();
    $lines = array_filter(explode("\n", (string) gzdecode(Storage::disk('local')->get($archive->path))));

    expect($archive->path)->toBe('logs/'.$day->format('Y/m').'/'.$day->toDateString().'.jsonl.gz')
        ->and($archive->rows)->toBe(3)
        ->and($lines)->toHaveCount(3)
        ->and(json_decode($lines[0], true)['description'])->toBe('Forty days old')
        ->and($archive->checksum)->toBe(hash('sha256', (string) Storage::disk('local')->get($archive->path)))
        // Past the 30 days: gone from the database, except the lead's own timeline.
        ->and(Activity::query()->where('description', 'Forty days old')->exists())->toBeFalse()
        ->and(Activity::query()->whereKey($timeline->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->where('description', 'Five days old')->exists())->toBeTrue()
        ->and(LogArchive::query()->whereDate('date', now()->subDays(5))->exists())->toBeTrue()
        ->and(LogArchive::query()->whereDate('date', now())->exists())->toBeFalse()
        // Archive files past 180 days are removed.
        ->and(LogArchive::query()->whereDate('date', now()->subDays(200))->exists())->toBeFalse();

    Storage::disk('local')->assertMissing('logs/old/very-old.jsonl.gz');
});

it('lets Admins and Super Admins change retention and delete, never the last 7 days, and no Manager', function (): void {
    ($this->entry)('Twelve days old', 12);
    ($this->entry)('Three days old', 3);

    // Since 9 October an Admin has the same control as a Super Admin.
    Livewire::actingAs($this->admin)->test(LogsPage::class)->assertSee('Delete logs');
    Livewire::actingAs($this->manager)->test(LogsPage::class, ['brand' => $this->brand])->assertDontSee('Delete logs')->call('saveRetention')->assertForbidden();

    Livewire::actingAs($this->super)
        ->test(LogsPage::class)
        ->set('databaseDays', 60)
        ->set('archiveDays', 365)
        ->call('saveRetention')
        ->assertHasNoErrors()
        ->set('databaseDays', 45)
        ->call('saveRetention')
        ->assertHasErrors('databaseDays');

    expect(app(LogSettings::class)->refresh()->database_days)->toBe(60)
        ->and(app(LogSettings::class)->archive_days)->toBe(365);

    // Inside the protected week: refused.
    Livewire::actingAs($this->super)
        ->test(LogsPage::class)
        ->set('deleteBefore', now()->subDays(2)->toDateString())
        ->call('confirmDelete')
        ->assertHasErrors('deleteBefore');

    // Needs the password, archives first, then deletes.
    Livewire::actingAs($this->super)
        ->test(LogsPage::class)
        ->set('deleteBefore', now()->subDays(8)->toDateString())
        ->call('confirmDelete')
        ->set('confirmPassword', 'wrong')
        ->call('runConfirmed')
        ->assertHasErrors('confirmPassword')
        ->set('confirmPassword', 'password')
        ->call('runConfirmed')
        ->assertHasNoErrors();

    expect(Activity::query()->where('description', 'Twelve days old')->exists())->toBeFalse()
        ->and(Activity::query()->where('description', 'Three days old')->exists())->toBeTrue()
        ->and(LogArchive::query()->whereDate('date', now()->subDays(12))->sole()->rows)->toBe(1)
        ->and(Activity::query()->where('event', 'system.logs_deleted')->count())->toBe(1);

    // The job itself refuses a day whose archive file has gone missing.
    ($this->entry)('Ten days old', 10);
    app(LogArchiver::class)->archivePending();
    Storage::disk('local')->delete(LogArchive::query()->whereDate('date', now()->subDays(10))->sole()->path);
    LogArchive::query()->whereDate('date', now()->subDays(10))->update(['rows' => 1]);
    ($this->entry)('Ten days old, written later', 10);
    Storage::disk('local')->delete(LogArchive::query()->whereDate('date', now()->subDays(10))->sole()->path);

    (new DeleteLogsBefore(now()->subDays(8)->toDateString(), $this->super->getKey()))->handle(app(LogArchiver::class));
    expect(Activity::query()->where('description', 'Ten days old')->exists())->toBeFalse()
        ->and(LogArchive::query()->whereDate('date', now()->subDays(10))->sole()->rows)->toBe(2);
});

it('prunes received events: processed after 14 days, failed after 30', function (): void {
    $make = fn (string $status, int $daysAgo): InboundEvent => tap(InboundEvent::query()->create(['source' => 'site', 'payload' => [], 'status' => $status]), fn (InboundEvent $event) => $event->forceFill(['created_at' => now()->subDays($daysAgo)])->save());

    $oldDone = $make('done', 15);
    $recentDone = $make('done', 10);
    $failed = $make('failed', 20);
    $oldFailed = $make('failed', 31);

    $this->artisan('model:prune', ['--model' => [InboundEvent::class]])->assertSuccessful();

    expect(InboundEvent::query()->pluck('id')->all())->toEqualCanonicalizing([$recentDone->getKey(), $failed->getKey()])
        ->and(InboundEvent::query()->whereKey([$oldDone->getKey(), $oldFailed->getKey()])->count())->toBe(0);
});
