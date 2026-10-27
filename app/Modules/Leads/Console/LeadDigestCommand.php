<?php

declare(strict_types=1);

namespace App\Modules\Leads\Console;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Notifications\LeadDigestMail;
use App\Modules\Leads\Support\LeadDigest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every three hours: one email per person with the leads that came in since
 * the last one, per brand (LeadDigest), instead of an email a lead (user
 * request, 27 Oct 2026). A brand's notification addresses get that brand's
 * part. Nobody gets an email when nothing came in for them.
 */
final class LeadDigestCommand extends Command
{
    /** When the last email went out (a timestamp); without it, the last three hours. */
    public const LAST = 'leads-digest:last';

    protected $signature = 'leads:digest {--hours=3 : How far back to look when no email went out before}';

    protected $description = 'Email the leads that came in since the last summary, per brand';

    public function handle(): int
    {
        $to = CarbonImmutable::now()->startOfMinute();
        $last = Cache::get(self::LAST);
        $from = is_numeric($last) ? CarbonImmutable::createFromTimestamp((int) $last) : $to->subHours(max(1, (int) $this->option('hours')));

        // After a long pause (the scheduler stopped for days) one email covers a day at most.
        $from = $from->max($to->subDay());

        if ($from->gte($to)) {
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        $send = function (object $notifiable, LeadDigestMail $mail) use (&$sent, &$failed): void {
            try {
                Notification::sendNow($notifiable, $mail);
                $sent++;
            } catch (Throwable $exception) {
                // One address the mail server refuses does not stop the others.
                report($exception);
                $failed++;
            }
        };

        $people = User::query()->where('status', MembershipStatus::Active->value)->whereNotNull('email')->orderBy('id')->get();

        foreach ($people as $person) {
            $sections = LeadDigest::forUser($person, $from, $to);

            if ($sections !== []) {
                $send($person, new LeadDigestMail($sections, $from, $to, ViewerTime::zone($person), Str::before(trim($person->name), ' ')));
            }
        }

        foreach (Brand::query()->where('is_active', true)->orderBy('name')->get() as $brand) {
            $addresses = $brand->notificationEmails();
            $sections = $addresses !== [] ? LeadDigest::forBrand($brand, $from, $to) : [];

            if ($sections !== []) {
                $send(Notification::route('mail', $addresses), new LeadDigestMail($sections, $from, $to, $brand->timezone));
            }
        }

        Cache::put(self::LAST, $to->getTimestamp(), now()->addDays(7));

        $this->info('Sent '.$sent.' '.Str::plural('summary', $sent).($failed > 0 ? ', '.$failed.' refused' : '').'.');

        return self::SUCCESS;
    }
}
