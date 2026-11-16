<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\Ai\Ai;
use App\Modules\Core\Support\Ai\AiUnavailable;
use App\Modules\Core\Support\Ai\ContactMask;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\KnownSchema;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What the assistant works out about a lead (user request, 16 Nov 2026):
 *
 * - how ready to buy a new enquiry sounds (`leads.heat`: hot, warm or cold, with a line of why), so the team rings the
 *   hot ones first;
 * - why a lost lead was lost (`leads.lost_reason`, one of LOST, with a line of why), for the "Why leads were lost"
 *   numbers. A person can choose the reason themselves, and theirs is never replaced by the assistant's;
 * - whether a note just written names a day to get back to the person, offered as a follow-up to add.
 *
 * The columns arrive with "Update system" (`ready()`); the assistant's part also needs its key. Text leaves only
 * through `ContactMask`. A guess is a guess: the screens say "AI" beside it, and nothing here changes a lead's
 * stage, owner or follow-ups by itself.
 */
final class LeadInsights
{
    public const HEAT = ['hot' => 'Hot', 'warm' => 'Warm', 'cold' => 'Cold'];

    public const LOST = [
        'price' => 'Too expensive',
        'no_reply' => 'Stopped answering',
        'elsewhere' => 'Went with someone else',
        'not_ready' => 'Not ready yet',
        'not_fit' => 'Not what we offer',
        'bad_contact' => 'Wrong or no contact details',
        'other' => 'Something else',
    ];

    /** A note is sent to the assistant only when it has a word about time in it: most notes never leave. */
    private const TIME_WORDS = '/\b(tomorrow|tonight|today|later|mon|tues?|wed(?:nes)?|thur?s?|fri|sat(?:ur)?|sun)(?:day)?\b|\bnext (?:week|month)\b|\bin (?:\d+|a|an|two|three|few) (?:day|week|hour|month)s?\b|\b\d{1,2}(?::\d{2})?\s?(?:am|pm)\b|\b\d{1,2}(?:st|nd|rd|th)\b|\bcall(?:ing)? ?(?:back|again)\b|\bfollow.?up\b|\bremind|\bget back\b|\bweek\b|\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b/i';

    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || KnownSchema::column('leads', 'heat');
    }

    /** Whether the assistant does its part: the columns are there and it has a key. */
    public static function assisted(): bool
    {
        return self::ready() && Ai::enabled();
    }

    /**
     * How ready to buy the enquiry sounds, kept on the lead. Null when there is nothing to read or the assistant
     * cannot answer now (the lead simply has no tag).
     *
     * @return array{heat: string, reason: string}|null
     */
    public static function rate(Lead $lead, ?User $by = null): ?array
    {
        if (! self::assisted()) {
            return null;
        }

        $mask = LeadSummary::mask($lead);
        $asked = LeadSummary::asked($lead, $mask);

        if ($asked === '') {
            return null;
        }

        try {
            $answer = Ai::ask(
                'You sort new sales enquiries for '.self::brandName($lead).' so the team rings the most promising first. Rate the enquiry HOT, WARM or COLD. HOT: a clear need and ready to act now (asks for a price, a call, a start date, or says the work is ready). WARM: real interest, but vague, early or in no hurry. COLD: says next to nothing, only browsing, looks like spam, or wants something a company like this does not sell. Answer in exactly this form and nothing else: the rating, then " | ", then the reason in at most twelve words.'.LeadSummary::KEEP,
                'Source: '.$lead->source_type->getLabel()."\n".$asked,
                $by,
                60,
            );
        } catch (AiUnavailable) {
            return null;
        }

        if (preg_match('/\b(hot|warm|cold)\b\W*(.*)$/isu', $answer, $found) !== 1) {
            return null;
        }

        $rated = ['heat' => mb_strtolower($found[1]), 'reason' => self::line($mask->show(self::why($answer, $found[2])))];
        $lead->forceFill(['heat' => $rated['heat'], 'heat_reason' => $rated['reason'] !== '' ? $rated['reason'] : null, 'heat_at' => now()])->saveQuietly();

        return $rated;
    }

    /**
     * Why the lead was lost, as the assistant reads it from the notes and the chat. Never over a reason a person
     * chose, and only for a lead that is lost.
     */
    public static function whyLost(Lead $lead): void
    {
        $lead->loadMissing('status');

        if (! self::assisted() || ! $lead->status->is_lost || $lead->getAttribute('lost_reason_by') !== null) {
            return;
        }

        $mask = LeadSummary::mask($lead);
        $parts = LeadSummary::material($lead, $mask);

        if (count($parts) === 1) {
            return;
        }

        $choices = collect(self::LOST)->map(fn (string $label, string $key): string => $key.' ('.$label.')')->implode(', ');

        try {
            $answer = Ai::ask(
                'A sales lead was marked lost. From the notes and the chat, choose the one reason that fits best: '.$choices.'. Choose "other" when nothing says why. Answer in exactly this form and nothing else: the reason\'s key, then " | ", then what happened in at most twelve words.'.LeadSummary::KEEP,
                implode("\n\n", $parts),
                null,
                60,
            );
        } catch (AiUnavailable) {
            return;
        }

        if (preg_match('/\b('.implode('|', array_keys(self::LOST)).')\b\W*(.*)$/isu', $answer, $found) !== 1) {
            return;
        }

        $note = self::line($mask->show(self::why($answer, $found[2])));
        $lead->forceFill(['lost_reason' => mb_strtolower($found[1]), 'lost_reason_note' => $note !== '' ? $note : null, 'lost_reason_by' => null])->saveQuietly();
    }

    /** A person says why the lead was lost (or takes the reason away with an empty choice). */
    public static function setLostReason(Lead $lead, string $reason, User $by): void
    {
        if (! self::ready()) {
            return;
        }

        $reason = isset(self::LOST[$reason]) ? $reason : null;
        $lead->forceFill(['lost_reason' => $reason, 'lost_reason_note' => null, 'lost_reason_by' => $reason !== null ? $by->getKey() : null])->saveQuietly();

        Audit::log('leads.lost_reason', $lead, ['reason' => $reason], $reason !== null ? 'Reason for Lost: '.self::LOST[$reason] : 'Reason for Lost removed', causer: $by, logName: 'leads');
    }

    /** A lead that is no longer lost has no reason for it. */
    public static function forgetLostReason(Lead $lead): void
    {
        if (self::ready() && $lead->getAttribute('lost_reason') !== null) {
            $lead->forceFill(['lost_reason' => null, 'lost_reason_note' => null, 'lost_reason_by' => null])->saveQuietly();
        }
    }

    /** Whether a note is worth asking about: it has a word about a day or a time in it. */
    public static function mentionsTime(string $note): bool
    {
        return mb_strlen(trim($note)) >= 8 && preg_match(self::TIME_WORDS, $note) === 1;
    }

    /**
     * The day and time a note says to get back to the person, in the viewer's time zone, with a short title. Null when
     * the note names none, the day is past or too far off, or the assistant cannot answer.
     *
     * @return array{at: CarbonImmutable, title: string}|null
     */
    public static function followUpIn(string $note, Lead $lead, User $by): ?array
    {
        if (! Ai::enabled() || ! self::mentionsTime($note)) {
            return null;
        }

        $mask = LeadSummary::mask($lead);
        $now = CarbonImmutable::instance(ViewerTime::now());

        try {
            $answer = Ai::ask(
                'A sales agent wrote a note about a customer. Find when the team should next get in touch. Right now it is '.$now->format('l j F Y, H:i').' where the agent is, and the coming days are: '.self::calendar($now).'. Take the date of a named day from that list and never work it out yourself. Answer NONE when the note names no day or time to get back to the customer, or only says what already happened. Otherwise answer in exactly this form and nothing else: YYYY-MM-DD HH:MM | a title of at most six words, such as "Call back about the quote". A day name means the next such day; use 10:00 when the note gives a day but no time.'.LeadSummary::KEEP,
                "The note:\n".$mask->hide(mb_substr(trim($note), 0, 1200)),
                $by,
                60,
            );
        } catch (AiUnavailable) {
            return null;
        }

        if (preg_match('/(\d{4}-\d{2}-\d{2})[ T](\d{1,2}:\d{2})\s*\|?\s*(.*)$/su', $answer, $found) !== 1) {
            return null;
        }

        try {
            $at = CarbonImmutable::createFromFormat('Y-m-d H:i', $found[1].' '.$found[2], $now->getTimezone());
        } catch (Throwable) {
            return null;
        }

        // The assistant is poor at calendars: a note that names one weekday gets that weekday, whatever came back.
        if ($at instanceof CarbonImmutable && ($day = self::namedDay($note)) !== null && $at->dayOfWeekIso !== $day) {
            $at = $now->startOfDay()->next($day % 7)->setTime($at->hour, $at->minute);
        }

        if (! $at instanceof CarbonImmutable || $at->lessThanOrEqualTo($now) || $at->greaterThan($now->addYear())) {
            return null;
        }

        $title = self::line($mask->show($found[3]), 80);

        return ['at' => $at, 'title' => $title !== '' ? $title : 'Follow up'];
    }

    /** The words after the bar of "key | why"; with no bar, whatever followed the key. */
    private static function why(string $answer, string $rest): string
    {
        return str_contains($answer, '|') ? (string) substr($answer, (int) strpos($answer, '|') + 1) : $rest;
    }

    /** "Mon 16 Nov = 2026-11-16, Tue 17 Nov = 2026-11-17, …" for the next two weeks. */
    private static function calendar(CarbonImmutable $from): string
    {
        return collect(range(0, 14))->map(fn (int $days): string => $from->addDays($days)->format('D j M').' = '.$from->addDays($days)->format('Y-m-d'))->implode(', ');
    }

    /** The weekday (1 Monday … 7 Sunday) a note names, when it names exactly one; "today" and "tomorrow" are none. */
    private static function namedDay(string $note): ?int
    {
        $days = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
        preg_match_all('/\b(mon|tue|wed|thu|fri|sat|sun)(?:s|sday|nesday|rsday|urday|day)?\b/i', $note, $found);
        $named = array_values(array_unique(array_map(fn (string $day): int => $days[mb_strtolower($day)], $found[1])));

        return count($named) === 1 ? $named[0] : null;
    }

    /** One clean line: no quotation marks, no line breaks, no full stop at the end. */
    private static function line(string $text, int $max = 180): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text), " \t\n\r\"'“”`.|-");

        return mb_substr($text, 0, $max);
    }

    private static function brandName(Lead $lead): string
    {
        return Brand::query()->whereKey($lead->brand_id)->value('name') ?? 'the company';
    }
}
