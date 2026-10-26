<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;

/**
 * One person, one lead. A repeat enquiry on an open lead joins it: by email
 * or phone, and, for Facebook, by the Messenger contact a lead form names
 * (somebody who fills in the form and also writes to the Page is one
 * person). A Won lead starts a new one. `twins()` finds the pairs that
 * are already there, for a person to merge.
 */
final class LeadMatcher
{
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

        return array_slice(array_values($found), 0, 3);
    }

    /** A name that stands in for a missing one. */
    public static function isPlaceholder(?string $name): bool
    {
        return in_array(mb_strtolower(trim((string) $name)), ['', 'unknown', 'messenger contact', 'instagram contact'], true);
    }
}
