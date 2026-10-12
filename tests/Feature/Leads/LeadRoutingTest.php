<?php

declare(strict_types=1);

use App\Modules\Activity\Models\Activity;
use App\Modules\Brands\Actions\RemoveMemberFromBrand;
use App\Modules\Core\Enums\AssignmentMode;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\LeadRoute;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Sales\Livewire\App\DistributePage;
use App\Modules\Sites\Models\Site;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Where new leads go (12 October): an Admin chooses, for each website and
 * Facebook Page, the department or the one person its leads go to the moment
 * they arrive. Everything else still waits in the queue to be assigned by hand.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->front);
    $this->ali = agentOf($this->front, ['name' => 'Ali Website']);
    $this->sara = agentOf($this->front, ['name' => 'Sara Facebook']);
    $this->admin = admin();

    inBrand($this->brand);

    $this->site = Site::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'UKPH website'])->refresh();
    $this->page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token'])->refresh();

    $this->arrive = fn (LeadSource $source, int $sourceId, string $name, array $extra = []) => app(CreateLead::class)->handle($this->brand, [
        'source_type' => $source, 'source_id' => $sourceId, 'name' => $name, 'email' => str($name)->slug('.').'@example.com', ...$extra,
    ])->lead;
});

it('lets an Admin choose where the leads of each website and Page go', function (): void {
    Livewire::actingAs($this->admin)
        ->test(DistributePage::class, ['brand' => $this->brand])
        ->assertSee('Where new leads go')
        ->assertSee('UKPH website')
        ->assertSee('UKPH Page')
        ->call('setRoute', 'website', $this->site->getKey(), $this->front->getKey().':'.$this->ali->getKey())
        ->call('setRoute', 'facebook', $this->page->getKey(), $this->front->getKey().':'.$this->sara->getKey())
        ->assertHasNoErrors();

    expect(LeadRoute::query()->count())->toBe(2)
        ->and(LeadRoute::query()->where('channel', 'website')->sole()->user_id)->toBe($this->ali->getKey());

    // A Manager shares their own department's leads; where a source sends them is the Admin's choice.
    Livewire::actingAs($this->manager)
        ->test(DistributePage::class, ['brand' => $this->brand])
        ->assertDontSee('Where new leads go')
        ->call('setRoute', 'website', $this->site->getKey(), '')
        ->assertForbidden();

    Livewire::actingAs($this->admin)
        ->test(DistributePage::class, ['brand' => $this->brand])
        ->call('setRoute', 'website', $this->site->getKey(), '');

    expect(LeadRoute::query()->where('channel', 'website')->exists())->toBeFalse();
});

it('sends a website lead and a Facebook lead straight to the person chosen for each', function (): void {
    LeadRoute::query()->create(['brand_id' => $this->brand->getKey(), 'channel' => 'website', 'source_id' => $this->site->getKey(), 'department_id' => $this->front->getKey(), 'user_id' => $this->ali->getKey()]);
    LeadRoute::query()->create(['brand_id' => $this->brand->getKey(), 'channel' => 'facebook', 'source_id' => $this->page->getKey(), 'department_id' => $this->front->getKey(), 'user_id' => $this->sara->getKey()]);

    $web = ($this->arrive)(LeadSource::Website, $this->site->getKey(), 'Wendy Web');
    $form = ($this->arrive)(LeadSource::Facebook, $this->page->getKey(), 'Fiona Form');
    $chat = ($this->arrive)(LeadSource::Messenger, $this->page->getKey(), 'Mona Messenger');

    expect($web->assigned_to)->toBe($this->ali->getKey())
        ->and($web->department_id)->toBe($this->front->getKey())
        ->and($web->assigned_at)->not->toBeNull()
        ->and($form->assigned_to)->toBe($this->sara->getKey())
        ->and($chat->assigned_to)->toBe($this->sara->getKey())
        ->and(Activity::query()->where('event', 'leads.routed')->where('subject_id', $web->getKey())->value('description'))->toContain('Ali Website in Front Sales');

    Notification::assertSentTo($this->ali, AppNotification::class, fn (AppNotification $notification): bool => $notification->title === 'Lead assigned to you: Wendy Web');
    Notification::assertNotSentTo($this->manager, AppNotification::class);

    // A lead added by hand, a source without a rule and an import of old leads are left alone.
    $other = Site::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Second website']);

    expect(($this->arrive)(LeadSource::Manual, $this->site->getKey(), 'Hana Hand')->assigned_to)->toBeNull()
        ->and(($this->arrive)(LeadSource::Website, $other->getKey(), 'Nadia Norule')->department_id)->toBeNull()
        ->and(($this->arrive)(LeadSource::Website, $this->site->getKey(), 'Olga Old', ['silent' => true])->assigned_to)->toBeNull();
});

it('lets the department share a routed lead its own way, and skips a person who left', function (): void {
    LeadRoute::query()->create(['brand_id' => $this->brand->getKey(), 'channel' => 'website', 'source_id' => $this->site->getKey(), 'department_id' => $this->front->getKey()]);

    // By hand: the lead lands in the department and its Manager is told.
    $first = ($this->arrive)(LeadSource::Website, $this->site->getKey(), 'First Lead');

    expect($first->department_id)->toBe($this->front->getKey())->and($first->assigned_to)->toBeNull();
    Notification::assertSentTo($this->manager, AppNotification::class);

    // Round robin: the next Agent in turn gets it.
    $this->front->update(['assignment_mode' => AssignmentMode::RoundRobin]);

    expect(($this->arrive)(LeadSource::Website, $this->site->getKey(), 'Second Lead')->refresh()->assigned_to)->toBe($this->ali->getKey())
        ->and(($this->arrive)(LeadSource::Website, $this->site->getKey(), 'Third Lead')->refresh()->assigned_to)->toBe($this->sara->getKey());

    // The person named in a rule left the brand: their leads go to the department instead of to nobody.
    $this->front->update(['assignment_mode' => AssignmentMode::Manual]);
    LeadRoute::query()->where('channel', 'website')->update(['user_id' => $this->sara->getKey()]);
    app(RemoveMemberFromBrand::class)->handle($this->sara->membershipFor($this->brand), $this->admin);

    $late = ($this->arrive)(LeadSource::Website, $this->site->getKey(), 'Late Lead');

    expect($late->assigned_to)->toBeNull()->and($late->department_id)->toBe($this->front->getKey());
});
