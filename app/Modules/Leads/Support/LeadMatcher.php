<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One person, one lead. A repeat enquiry on an open lead joins it: by email
 * or phone, and, for Facebook, by the Messenger contact a lead form names
 * (somebody who fills in the form and also writes to the Page is one
 * person). A Won lead starts a new one. `twins()` finds the pairs that
 * are already there, for a person to merge.
 */
final class LeadMatcher
{
    /**
     * A lead form and a Messenger chat of the same Page under exactly the same
     * full name, this close together, are one person (user decision, 4 Nov
     * 2026). Seen on live: most forms do not name the chat (2 in 14), and the
     * person writes on Messenger a few minutes after the form.
     */
    public const SAME_NAME_MINUTES = 60;

    /** A close name (Rob Alexander, Robert James Alexander) this close together is a hint only. */
    public const CLOSE_NAME_HOURS = 24;

    /** Words before a name that are not the name. */
    private const TITLES = ['mr', 'mrs', 'ms', 'miss', 'mx', 'dr', 'prof', 'sir'];

    public static function find(Brand|int $brand, ?string $email, ?string $phone, ?int $exceptId = null): ?Lead
    {
        $email = filled($email) ? mb_strtolower(trim((string) $email)) : null;
        $phone = filled($phone) ? trim((string) $phone) : null;

        if ($email === null && $phone === null) {
            return null;
        }

        return Lead::query()
            ->forBrand($brand)
            ->open()
            ->when($exceptId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptId))
            ->where(function (Builder $query) use ($email, $phone): void {
                if ($email !== null) {
                    $query->orWhere('email', $email);
                }

                if ($phone !== null) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * The Messenger contact a Facebook lead form names. Facebook adds a link
     * to the person's chat to every lead ("inbox_url"), and the number in it
     * is the id Messenger gives the same person on that Page:
     * https://business.facebook.com/latest/28269190652763012?nav_ref=thread_view_by_psid
     *
     * @param  Lead|array<string, mixed>|null  $from  a lead, or its extra fields
     */
    public static function messengerId(Lead|array|null $from): ?string
    {
        $fields = $from instanceof Lead ? (array) $from->fields : (array) $from;
        $url = is_scalar($fields['inbox_url'] ?? null) ? (string) $fields['inbox_url'] : '';

        if ($url !== '' && (preg_match('~/latest/(\d{6,})~', $url, $match) || preg_match('~[?&](?:psid|selected_item_id)=(\d{6,})~', $url, $match))) {
            return $match[1];
        }

        return null;
    }

    /** The open lead whose form named this Messenger contact. */
    public static function byMessenger(Brand|int $brand, string $psid): ?Lead
    {
        if (! preg_match('/^\d{6,}$/', $psid)) {
            return null;
        }

        return Lead::query()
            ->forBrand($brand)
            ->open()
            ->where('source_type', LeadSource::Facebook->value)
            ->where('fields', 'like', '%'.$psid.'%')
            ->latest('id')
            ->limit(20)
            ->get()
            ->first(fn (Lead $lead): bool => self::messengerId($lead) === $psid);
    }

    /**
     * Other open leads the person may see that look like the same person as
     * this one, the surest first: the same Messenger chat, email or phone,
     * then the same name where one of the two came with nothing but a name
     * (a chat). At most three.
     *
     * @return list<array{lead: Lead, why: string, sure: bool}>
     */
    public static function twins(Lead $lead, User $user): array
    {
        if (! $lead->isOpen()) {
            return [];
        }

        $others = fn (): Builder => Lead::query()->visibleTo($user)->open()->whereKeyNot($lead->getKey())->with(['status', 'assignee'])->latest('id')->limit(5);
        $found = [];

        $add = function (iterable $leads, string $why, bool $sure) use (&$found): void {
            foreach ($leads as $other) {
                $found[$other->getKey()] ??= ['lead' => $other, 'why' => $why, 'sure' => $sure];
            }
        };

        // The form named a chat, or this lead's chat is named by another lead's form.
        $psid = self::messengerId($lead);

        if ($psid !== null) {
            $chatLeads = Conversation::query()->where('contact_external_id', $psid)->whereNotNull('lead_id')->pluck('lead_id')->all();
            $add($chatLeads !== [] ? $others()->whereKey($chatLeads)->get() : [], 'the same Messenger chat', true);
        }

        foreach (Conversation::query()->where('lead_id', $lead->getKey())->pluck('contact_external_id') as $contact) {
            if (preg_match('/^\d{6,}$/', (string) $contact)) {
                $add($others()->where('fields', 'like', '%'.$contact.'%')->get()->filter(fn (Lead $other): bool => self::messengerId($other) === (string) $contact), 'the same Messenger chat', true);
            }
        }

        if (filled($lead->email)) {
            $add($others()->where('email', $lead->email)->get(), 'the same email', true);
        }

        if (filled($lead->phone)) {
            $add($others()->where('phone', $lead->phone)->get(), 'the same phone number', true);
        }

        // A name alone proves nothing, so it is only a hint, and only against a lead that has nothing else.
        $name = trim($lead->name);

        if ($name !== '' && ! self::isPlaceholder($name)) {
            $bare = blank($lead->email) && blank($lead->phone);

            $add($others()->where('name', $name)->get()->filter(fn (Lead $other): bool => $bare || (blank($other->email) && blank($other->phone))), 'the same name', false);
        }

        // A lead form and a Messenger chat of the same Page under a close name: what the person typed
        // in the form ("Rob Alexander") is often not the name on their profile ("Robert James Alexander").
        $pair = self::pairedSource($lead);

        if ($pair !== null && $lead->created_at !== null) {
            $at = CarbonImmutable::instance($lead->created_at);

            $close = $others()
                ->where('source_type', $pair->value)
                ->where('source_id', $lead->source_id)
                ->whereBetween('created_at', [$at->subHours(self::CLOSE_NAME_HOURS), $at->addHours(self::CLOSE_NAME_HOURS)])
                ->get()
                ->filter(fn (Lead $other): bool => self::closeName($lead->name, $other->name) && ! self::differentContact($other, $lead->email, $lead->phone));

            foreach ($close as $other) {
                $add([$other], 'a similar name, '.self::apart($lead->created_at, $other->created_at).' on the same Facebook Page', false);
            }
        }

        return array_slice(array_values($found), 0, 3);
    }

    /**
     * The other half of a form and its chat: the open lead of `$kind` (a lead
     * form for a chat, a Messenger chat for a form) from the same Page, under
     * exactly this full name, that arrived within an hour of `$at`. Never when
     * their email or phone differ, or when either names another Messenger chat.
     */
    public static function sameNameOnPage(Brand|int $brand, int $pageId, LeadSource $kind, string $name, CarbonInterface $at, ?string $email = null, ?string $phone = null, ?string $psid = null): ?Lead
    {
        if (self::isPlaceholder($name) || count(self::words($name)) < 2) {
            return null;
        }

        return self::nearOnPage($brand, $pageId, $kind, $at)
            ->first(fn (Lead $lead): bool => self::sameName($lead->name, $name) && ! self::differentContact($lead, $email, $phone) && ! self::otherChat($lead, $psid));
    }

    /** As sameNameOnPage(), under a close name instead: never joined, only kept with the same person. */
    public static function closeNameOnPage(Brand|int $brand, int $pageId, LeadSource $kind, string $name, CarbonInterface $at, ?string $psid = null): ?Lead
    {
        if (self::isPlaceholder($name)) {
            return null;
        }

        return self::nearOnPage($brand, $pageId, $kind, $at)
            ->first(fn (Lead $lead): bool => self::closeName($lead->name, $name) && ! self::otherChat($lead, $psid));
    }

    /** "Amy Wong" and "amy  wong" are the same full name; one word is not enough. */
    public static function sameName(string $one, string $other): bool
    {
        $words = self::words($one);

        return count($words) >= 2 && $words === self::words($other);
    }

    /**
     * The same surname, and a first name that is the other's or starts it
     * (Rob, Robert), but not the very same name.
     */
    public static function closeName(string $one, string $other): bool
    {
        $a = self::words($one);
        $b = self::words($other);

        if (count($a) < 2 || count($b) < 2 || $a === $b || end($a) !== end($b) || mb_strlen((string) end($a)) < 2) {
            return false;
        }

        [$first, $second] = mb_strlen($a[0]) <= mb_strlen($b[0]) ? [$a[0], $b[0]] : [$b[0], $a[0]];

        return $first === $second || (mb_strlen($first) >= 3 && str_starts_with($second, $first));
    }

    /** "4 minutes apart", for the timeline and the hint. */
    public static function apart(CarbonInterface $one, CarbonInterface $other): string
    {
        $minutes = (int) abs($one->diffInMinutes($other));

        return match (true) {
            $minutes < 2 => 'a minute apart',
            $minutes < 90 => $minutes.' minutes apart',
            default => (int) round($minutes / 60).' hours apart',
        };
    }

    /** A form's partner is a Messenger chat of the same Page, and a chat's a form. */
    private static function pairedSource(Lead $lead): ?LeadSource
    {
        if ($lead->source_id === null) {
            return null;
        }

        return match ($lead->source_type) {
            LeadSource::Facebook => LeadSource::Messenger,
            LeadSource::Messenger => LeadSource::Facebook,
            default => null,
        };
    }

    /** @return Collection<int, Lead> */
    private static function nearOnPage(Brand|int $brand, int $pageId, LeadSource $kind, CarbonInterface $at): Collection
    {
        $at = CarbonImmutable::instance($at);

        return Lead::query()
            ->forBrand($brand)
            ->open()
            ->where('source_type', $kind->value)
            ->where('source_id', $pageId)
            ->whereBetween('created_at', [$at->subMinutes(self::SAME_NAME_MINUTES), $at->addMinutes(self::SAME_NAME_MINUTES)])
            ->latest('id')
            ->limit(10)
            ->get()
            ->toBase();
    }

    /** Both have an email, or both a phone, and they are not the same: two people. */
    private static function differentContact(Lead $lead, ?string $email, ?string $phone): bool
    {
        $email = filled($email) ? mb_strtolower(trim((string) $email)) : null;

        return ($email !== null && filled($lead->email) && mb_strtolower((string) $lead->email) !== $email)
            || (filled($phone) && filled($lead->phone) && (string) $lead->phone !== (string) $phone);
    }

    /** The lead already belongs to a Messenger chat that is not `$psid`'s, or its form names another chat. */
    private static function otherChat(Lead $lead, ?string $psid): bool
    {
        $named = self::messengerId($lead);
        $chats = Conversation::query()->where('lead_id', $lead->getKey())->where('channel', Conversation::CHANNEL_MESSENGER)->pluck('contact_external_id')->map(fn (mixed $id): string => (string) $id)->all();
        $known = array_values(array_unique(array_filter([$named, ...$chats])));

        return $psid !== null ? $known !== [] && ! in_array($psid, $known, true) : false;
    }

    /**
     * The words of a name, lower case, without a title in front.
     *
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $words = preg_split('/[^\p{L}\p{M}\']+/u', mb_strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        while ($words !== [] && in_array($words[0], self::TITLES, true)) {
            array_shift($words);
        }

        return $words;
    }

    /** A name that stands in for a missing one. */
    public static function isPlaceholder(?string $name): bool
    {
        return in_array(mb_strtolower(trim((string) $name)), ['', 'unknown', 'messenger contact', 'instagram contact'], true);
    }
}
