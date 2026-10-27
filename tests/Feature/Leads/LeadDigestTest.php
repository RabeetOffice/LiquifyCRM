<?php

declare(strict_types=1);

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Notifications\LeadDigestMail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
 * New leads by email every three hours (user request, 27 Oct 2026: "a long
 * inbox"): one summary per person, per brand the new leads and how many still
 * wait for a person, instead of an email a lead. The bell stays instant.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->uk = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->melbourne = brand(['name' => 'Melbourne Print Publish', 'slug' => 'mpp', 'settings' => ['notification_emails' => ['sales@mpp.example']]]);
    $this->front = department($this->uk, ['name' => 'Front Sales']);
    $this->other = department($this->uk, ['name' => 'Dublin Desk']);

    $this->admin = admin(['name' => 'Rabeet Ahmed']);
    $this->manager = managerOf($this->front, ['name' => 'Maya Manager']);
    $this->agent = agentOf($this->front, ['name' => 'Ava Agent']);
});

it('sends one summary per person instead of an email a lead, and the bell at once', function (): void {
    inBrand($this->uk);
    app(CreateLead::class)->handle($this->uk, ['name' => 'Braddon Webster', 'email' => 'braddon@example.com', 'source_type' => LeadSource::Website]);

    // The bell, but no email for the lead itself.
    Notification::assertSentTo($this->admin, AppNotification::class, fn (AppNotification $notification, array $channels): bool => str_contains($notification->title, 'Braddon Webster') && ! in_array('mail', $channels, true));
    Notification::assertNotSentTo(new AnonymousNotifiable, AppNotification::class);

    Lead::factory()->create(['brand_id' => $this->uk->getKey(), 'department_id' => $this->front->getKey(), 'name' => 'Sylvia Kaya', 'assigned_to' => $this->agent->getKey(), 'created_at' => now()->subHour()]);
    Lead::factory()->create(['brand_id' => $this->uk->getKey(), 'department_id' => $this->other->getKey(), 'name' => 'Gabriel Isgro', 'created_at' => now()->subHours(2)]);
    Lead::factory()->create(['brand_id' => $this->melbourne->getKey(), 'name' => 'Warwick Morgan', 'source_type' => LeadSource::Facebook, 'created_at' => now()->subMinutes(30)]);
    // Older than the window: not news any more.
    Lead::factory()->create(['brand_id' => $this->uk->getKey(), 'name' => 'Old Enquiry', 'created_at' => now()->subHours(5)]);

    // A summary covers whole minutes: the lead of this minute goes into the next one, so a minute passes first.
    $this->travel(1)->minutes();
    $this->artisan('leads:digest')->assertSuccessful();

    // The Admin: every brand.
    Notification::assertSentTo($this->admin, LeadDigestMail::class, function (LeadDigestMail $mail): bool {
        $names = array_map(fn (array $section): string => $section['brand']->name, $mail->sections);
        $uk = collect($mail->sections)->firstWhere('brand.name', 'UK Publishing House');

        return $names === ['Melbourne Print Publish', 'UK Publishing House']
            && $uk['new'] === 3
            && $uk['leads']->pluck('name')->sort()->values()->all() === ['Braddon Webster', 'Gabriel Isgro', 'Sylvia Kaya']
            && $mail->subject(4) === '4 new leads: Melbourne Print Publish and UK Publishing House';
    });

    // The Manager: only the team they lead. The Agent: nothing.
    Notification::assertSentTo($this->manager, LeadDigestMail::class, fn (LeadDigestMail $mail): bool => count($mail->sections) === 1 && $mail->sections[0]['leads']->pluck('name')->all() === ['Sylvia Kaya']);
    Notification::assertNotSentTo($this->agent, LeadDigestMail::class);

    // A brand's notification address: that brand alone.
    Notification::assertSentOnDemand(LeadDigestMail::class, fn (LeadDigestMail $mail, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === ['sales@mpp.example'] && $mail->sections[0]['brand']->name === 'Melbourne Print Publish');

    // The next run starts where this one ended: nothing new, no email.
    Notification::fake();
    $this->travel(3)->hours();
    $this->artisan('leads:digest')->assertSuccessful();

    Notification::assertNothingSent();
});

it('writes a styled email with the brands, the leads and who is waiting', function (): void {
    $this->uk->update(['colors' => ['primary' => '#ffffff', 'secondary' => '#1d4ed8']]);

    Lead::factory()->create(['brand_id' => $this->uk->getKey(), 'department_id' => $this->front->getKey(), 'name' => 'Sylvia Kaya', 'assigned_to' => $this->agent->getKey(), 'created_at' => now()->subHour()]);
    Lead::factory()->create(['brand_id' => $this->uk->getKey(), 'department_id' => $this->front->getKey(), 'name' => 'Thien Hoang', 'source_type' => LeadSource::Facebook, 'fields' => ['facebook_page' => 'UKPH Page'], 'created_at' => now()->subMinutes(20)]);

    $this->artisan('leads:digest')->assertSuccessful();

    $html = '';
    Notification::assertSentTo($this->admin, LeadDigestMail::class, function (LeadDigestMail $mail) use (&$html): bool {
        $html = (string) $mail->toMail($this->admin)->render();

        return true;
    });

    expect($html)->toContain('2 new leads')
        ->toContain('Hello Rabeet')
        ->toContain('UK Publishing House')
        ->toContain('Thien Hoang')
        ->toContain('Facebook: UKPH Page')
        ->toContain('Ava Agent')
        ->toContain('Waiting')
        ->toContain('1 waiting for a person')
        ->toContain('/b/ukph/leads?tab=unassigned')
        ->not->toContain('&lt;table');
});
