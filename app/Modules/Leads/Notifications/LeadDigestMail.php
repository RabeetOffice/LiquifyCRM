<?php

declare(strict_types=1);

namespace App\Modules\Leads\Notifications;

use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The leads email every three hours (LeadDigest): per brand the new leads and
 * how many wait for a person. Mail only, sent by `leads:digest` itself; the
 * bell already told everybody about each lead.
 */
final class LeadDigestMail extends Notification
{
    /**
     * @param  list<array{brand: Brand, leads: Collection<int, Lead>, new: int, waiting: int, url: string}>  $sections
     */
    public function __construct(
        public readonly array $sections,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $zone,
        public readonly ?string $greet = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $new = array_sum(array_column($this->sections, 'new'));
        $waiting = array_sum(array_column($this->sections, 'waiting'));
        $from = $this->from->setTimezone($this->zone);
        $to = $this->to->setTimezone($this->zone);
        $city = str_replace('_', ' ', (string) last(explode('/', $this->zone)));

        $span = $from->isSameDay($to)
            ? 'between '.$from->format('g:i A').' and '.$to->format('g:i A')
            : 'between '.$from->format('D j M, g:i A').' and '.$to->format('D j M, g:i A');

        $intro = ($this->greet !== null ? 'Hello '.$this->greet.', h' : 'H').'ere is what came in '.$span.' ('.$city.' time).'
            .($waiting > 0 ? ' '.$waiting.' '.($waiting === 1 ? 'lead is' : 'leads are').' still waiting for someone to take '.($waiting === 1 ? 'it' : 'them').'.' : ' Every new lead has somebody on it.');

        $body = view('leads::mail.digest-body', [
            'sections' => $this->sections,
            'totals' => ['new' => $new, 'waiting' => $waiting],
            'zone' => $this->zone,
            'sameDay' => $from->isSameDay($to),
        ])->render();

        // One line, so the markdown reader of the message takes it as one block of HTML.
        $body = trim((string) (preg_replace('/\s*\n\s*/', ' ', $body) ?? $body));

        return (new MailMessage)
            ->subject($this->subject($new))
            ->markdown('leads::mail.digest', [
                'headline' => $new.' new '.($new === 1 ? 'lead' : 'leads'),
                'intro' => $intro,
                'body' => $body,
            ]);
    }

    /** "12 new leads: UK Publishing House, Melbourne Print Publish and 2 more". */
    public function subject(int $new): string
    {
        $names = array_map(fn (array $section): string => $section['brand']->name, $this->sections);
        $shown = array_slice($names, 0, 2);
        $rest = count($names) - count($shown);

        $list = $rest > 0 ? implode(', ', $shown).' and '.$rest.' more' : implode(' and ', $shown);

        return $new.' new '.($new === 1 ? 'lead' : 'leads').': '.$list;
    }
}
