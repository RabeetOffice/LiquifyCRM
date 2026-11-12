<?php

declare(strict_types=1);

use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\VisitSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadEnquiry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * Where a visit came from (user request, 12 Nov 2026): the ad network and campaign from the landing page's tags, else the
 * website that sent them, readable, beside the chat, on Live visitors and on the lead the chat makes.
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
    $this->brand = brand(['name' => 'Australian Web Builders', 'slug' => 'awb']);
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'AWB site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['australianwebbuilders.com.au'],
    ]);
    inBrand($this->brand);
});

it('names the source in plain words', function (): void {
    expect(VisitSource::from(null, ['gclid' => '1', 'utm_campaign' => 'spring_sale']))->toBe(['source' => 'Google Ads', 'campaign' => 'spring sale'])
        ->and(VisitSource::from('l.facebook.com', ['fbclid' => '1'])['source'])->toBe('Facebook / Instagram')
        ->and(VisitSource::from(null, ['utm_source' => 'newsletter', 'utm_medium' => 'email'])['source'])->toBe('newsletter (email)')
        ->and(VisitSource::from('www.google.com', [])['source'])->toBe('Google search')
        ->and(VisitSource::from('chatgpt.com', [])['source'])->toBe('ChatGPT')
        ->and(VisitSource::from('blog.example.org', [])['source'])->toBe('blog.example.org')
        ->and(VisitSource::from(null, []))->toBe(['source' => null, 'campaign' => null]);
});

it('keeps only the tags, works the source out when the visit starts, and puts it on the lead', function (): void {
    $vid = (string) Str::uuid();
    $this->call('POST', '/chat/api/ping', [], [], [], ['HTTP_ORIGIN' => 'https://australianwebbuilders.com.au', 'CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/130'],
        json_encode(['key' => $this->widget->widget_key, 'vid' => $vid, 'url' => 'https://australianwebbuilders.com.au/pricing', 'referrer' => 'https://www.google.com/',
            'tags' => ['gclid' => '1', 'utm_campaign' => 'Web design AU', 'email' => 'someone@example.com']]))->assertOk();

    $session = ChatSession::query()->sole();
    expect($session->source)->toBe('Google Ads')->and($session->campaign)->toBe('Web design AU')
        ->and($session->cameFrom())->toBe('Google Ads · Web design AU')
        ->and(json_encode($session->getAttributes()))->not->toContain('someone@example.com');

    $this->actingAs(admin())->get('/b/awb/live-chat/visitors')->assertOk()->assertSee('Google Ads · Web design AU');
    auth()->logout();

    // The visitor chats and gives an email: the lead says where they came from.
    $creds = $this->postJson('/chat/api/init', ['vid' => $vid], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    $headers = ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $creds->json('token'), 'X-Chat-Sig' => $creds->json('sig')];
    $this->postJson('/chat/api/messages', ['body' => 'How much is a website?'], $headers)->assertOk();
    $this->postJson('/chat/api/details', ['name' => 'Sam Lee', 'email' => 'sam.lee@example.com'], $headers)->assertOk();

    $lead = Lead::query()->sole();
    expect($lead->fields)->toMatchArray(['came_from' => 'Google Ads · Web design AU', 'campaign' => 'Web design AU', 'page_url' => 'https://australianwebbuilders.com.au/pricing'])
        ->and(collect(LeadEnquiry::source($lead))->pluck('label')->all())->toContain('Came from');
});
