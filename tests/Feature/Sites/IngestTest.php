<?php

declare(strict_types=1);

use App\Modules\Brands\Jobs\SendAutoReply;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sites\Enums\InboundStatus;
use App\Modules\Sites\Jobs\IngestLead;
use App\Modules\Sites\Models\InboundEvent;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\IngestSignature;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * Week 3: the signed entry point for website leads and the IngestLead job
 * that turns the stored copy into a lead of the right brand.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->site = Site::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'UKPH', 'url' => 'https://ukpublishinghouse.co.uk'])->refresh();
});

/**
 * Post a lead the way liquify.php does.
 *
 * @param  array<string, mixed>  $payload
 */
function sendLead(Site $site, array $payload, ?string $secret = null, ?int $timestamp = null)
{
    $body = (string) json_encode($payload);
    $timestamp = (string) ($timestamp ?? time());

    return test()->call('POST', '/api/v1/ingest/site/'.$site->site_key, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_LIQUIFY_TIMESTAMP' => $timestamp,
        'HTTP_X_LIQUIFY_SIGNATURE' => IngestSignature::sign($secret ?? $site->secret, $timestamp, $body),
    ], $body);
}

function samplePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'form' => 'popup',
        'external_id' => '1842',
        'submitted_at' => now()->subMinute()->toIso8601String(),
        'fields' => [
            'name' => 'Sarah Lee',
            'email' => 'Sarah@Example.com',
            'phone' => '07700 900123',
            'country' => 'United Kingdom',
            'service' => 'Publishing',
            'message' => 'I want to publish my first novel',
        ],
        'meta' => [
            'page_url' => 'https://ukpublishinghouse.co.uk/publishing.php',
            'ip' => '81.2.69.160',
            'utm_source' => 'google',
            'utm_campaign' => 'autumn',
        ],
    ], $overrides);
}

it('accepts a signed lead, answers 202 and puts it in the brand queue', function (): void {
    sendLead($this->site, samplePayload())
        ->assertStatus(202)
        ->assertJson(['status' => 'accepted', 'duplicate' => false]);

    $lead = Lead::query()->forBrand($this->brand)->sole();

    expect($lead->source_type)->toBe(LeadSource::Website)
        ->and($lead->source_id)->toBe($this->site->getKey())
        ->and($lead->name)->toBe('Sarah Lee')
        ->and($lead->email)->toBe('sarah@example.com')
        ->and($lead->phone)->toBe('+447700900123')
        ->and($lead->message)->toBe('I want to publish my first novel')
        ->and($lead->assigned_to)->toBeNull()
        ->and($lead->utm)->toMatchArray(['utm_source' => 'google', 'utm_campaign' => 'autumn'])
        ->and($lead->fields)->toMatchArray(['website' => 'UKPH', 'service' => 'Publishing', 'country' => 'United Kingdom', 'form' => 'popup']);

    $event = InboundEvent::query()->sole();

    expect($event->status)->toBe(InboundStatus::Done)
        ->and($event->lead_id)->toBe($lead->getKey())
        ->and($event->payload['fields']['name'])->toBe('Sarah Lee')
        ->and($this->site->refresh()->last_lead_at)->not->toBeNull()
        ->and($this->site->connectionState())->toBe('connected');
});

it('only stores the request and leaves the work to the queue', function (): void {
    Queue::fake();

    sendLead($this->site, samplePayload())->assertStatus(202);

    Queue::assertPushed(IngestLead::class);
    expect(InboundEvent::query()->sole()->status)->toBe(InboundStatus::Pending)
        ->and(Lead::query()->acrossBrands()->count())->toBe(0);
});

it('refuses a wrong signature, an old timestamp and an unknown website', function (): void {
    sendLead($this->site, samplePayload(), secret: 'not-the-secret')->assertStatus(401);
    sendLead($this->site, samplePayload(), timestamp: time() - 301)->assertStatus(401);

    $this->postJson('/api/v1/ingest/site/'.$this->site->site_key, samplePayload())->assertStatus(401);
    $this->postJson('/api/v1/ingest/site/nobody_12345678', samplePayload())->assertStatus(404);

    $this->site->update(['is_active' => false]);
    sendLead($this->site, samplePayload())->assertStatus(404);

    expect(InboundEvent::query()->count())->toBe(0);
});

it('ignores a lead that arrives twice', function (): void {
    sendLead($this->site, samplePayload())->assertStatus(202);
    sendLead($this->site, samplePayload())->assertStatus(202)->assertJson(['duplicate' => true]);

    expect(InboundEvent::query()->count())->toBe(1)
        ->and(Lead::query()->forBrand($this->brand)->count())->toBe(1);
});

it('adds a repeat enquiry to the existing lead instead of creating a second one', function (): void {
    sendLead($this->site, samplePayload())->assertStatus(202);
    sendLead($this->site, samplePayload(['external_id' => '1900', 'fields' => ['message' => 'Any update?']]))->assertStatus(202);

    $lead = Lead::query()->forBrand($this->brand)->sole();

    expect(InboundEvent::query()->where('status', 'done')->count())->toBe(2)
        ->and($lead->activities()->where('event', 'leads.repeat_enquiry')->count())->toBe(1);
});

it('keeps the same row number of two websites apart', function (): void {
    $second = Site::factory()->create(['brand_id' => $this->brand->getKey()])->refresh();

    sendLead($this->site, samplePayload())->assertStatus(202);
    sendLead($second, samplePayload(['fields' => ['name' => 'Tom Hall', 'email' => 'tom@example.com', 'phone' => '07700 900456']]))->assertStatus(202);

    expect(Lead::query()->forBrand($this->brand)->count())->toBe(2);
});

it('keeps a lead that cannot be processed and lets it be retried', function (): void {
    sendLead($this->site, samplePayload(['fields' => ['name' => '', 'email' => '', 'phone' => '']]))->assertStatus(202);

    $event = InboundEvent::query()->sole();

    expect($event->status)->toBe(InboundStatus::Failed)
        ->and($event->error)->toContain('nobody to contact')
        ->and(Lead::query()->acrossBrands()->count())->toBe(0);

    $event->update(['payload' => samplePayload()]);
    IngestLead::dispatchSync($event->getKey());

    expect($event->refresh()->status)->toBe(InboundStatus::Done)
        ->and($event->attempts)->toBe(2)
        ->and(Lead::query()->forBrand($this->brand)->count())->toBe(1);
});

it('treats a filled hidden field as spam', function (): void {
    sendLead($this->site, samplePayload(['fields' => ['website_url_hp' => 'http://spam.test']]))->assertStatus(202);

    expect(InboundEvent::query()->sole()->status)->toBe(InboundStatus::Failed)
        ->and(Lead::query()->acrossBrands()->count())->toBe(0);
});

it('accepts an Elementor webhook with the token address', function (): void {
    $this->site->update(['platform' => 'elementor']);

    $this->post('/api/v1/ingest/site/'.$this->site->site_key.'?token=wrong', ['Name' => 'Amy'])->assertStatus(401);

    $this->post($this->site->webhookUrl(), [
        'Name' => 'Amy Wong',
        'Email' => 'amy@example.com',
        'Your Message' => 'Please call me',
        'form_name' => 'Contact form',
        'Page URL' => 'https://ukpublishinghouse.co.uk/contact',
    ])->assertStatus(202);

    $lead = Lead::query()->forBrand($this->brand)->sole();

    expect($lead->name)->toBe('Amy Wong')
        ->and($lead->email)->toBe('amy@example.com')
        ->and($lead->message)->toBe('Please call me')
        ->and($lead->fields)->toMatchArray(['form' => 'Contact form', 'page_url' => 'https://ukpublishinghouse.co.uk/contact']);
});

it('queues the auto-reply only when the brand switched it on', function (): void {
    Queue::fake([SendAutoReply::class]);

    sendLead($this->site, samplePayload())->assertStatus(202);
    Queue::assertNotPushed(SendAutoReply::class);

    $this->brand->update(['mail_settings' => ['host' => 'smtp.example.com', 'from_address' => 'hello@ukph.test', 'auto_reply' => true]]);

    sendLead($this->site, samplePayload(['external_id' => '2000', 'fields' => ['name' => 'Tom Hall', 'email' => 'tom@example.com', 'phone' => '07700 900456']]))->assertStatus(202);
    Queue::assertPushed(SendAutoReply::class, 1);
});

it('limits each website to 120 requests a minute', function (): void {
    config(['liquify.ingest.rate_limit' => 2]);

    sendLead($this->site, samplePayload(['external_id' => 'a']))->assertStatus(202);
    sendLead($this->site, samplePayload(['external_id' => 'b']))->assertStatus(202);
    sendLead($this->site, samplePayload(['external_id' => 'c']))->assertStatus(429);
});
