<?php

declare(strict_types=1);

namespace App\Modules\Brands\Jobs;

use App\Modules\Brands\Support\BrandMailer;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The optional auto-reply to the customer after a website lead arrives,
 * sent through the brand's own SMTP and noted on the lead's timeline.
 */
final class SendAutoReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $leadId) {}

    public function handle(BrandMailer $mailer): void
    {
        $lead = Lead::query()->acrossBrands()->with('brand')->find($this->leadId);

        if ($lead === null || $lead->email === null || ! $lead->brand->autoReplyEnabled()) {
            return;
        }

        $brand = $lead->brand;
        $settings = $brand->mailSettings();
        $replace = ['{name}' => $lead->name, '{brand}' => $brand->name];

        $mailer->send($brand, $lead->email, strtr($settings['auto_reply_subject'], $replace), strtr($settings['auto_reply_body'], $replace));

        Audit::log('leads.auto_reply_sent', $lead, ['subject' => $settings['auto_reply_subject']], 'Auto-reply sent to the customer', brand: $brand, logName: 'leads');
    }
}
