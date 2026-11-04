<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\ChatContacts;

/**
 * What a person wrote in the chat fills the lead's missing email and phone
 * (user request, 4 Nov 2026), so the lead of their form with the same email
 * or number is offered for merging ("This person has another lead"). What the
 * lead already has stays. The timeline names what was taken, never the
 * value: a colleague who sees contacts masked reads the timeline too.
 */
final class TakeContactFromChat
{
    /** @return list<string> what was taken: 'email', 'phone' */
    public function handle(Lead $lead, string $text, Brand $brand): array
    {
        if (trim($text) === '' || (filled($lead->email) && filled($lead->phone))) {
            return [];
        }

        $found = ChatContacts::in($text, $brand);
        $email = blank($lead->email) && $found['email'] !== null;
        $phone = blank($lead->phone) && $found['phone'] !== null;

        if (! $email && ! $phone) {
            return [];
        }

        if ($email) {
            $lead->email = $found['email'];
        }

        if ($phone) {
            $lead->phone = $found['phone'];
        }

        $lead->save();

        $taken = [...($email ? ['email'] : []), ...($phone ? ['phone'] : [])];
        $what = match (true) {
            $email && $phone => 'Email address and phone number',
            $email => 'Email address',
            default => 'Phone number',
        };

        Audit::log('leads.contact_from_chat', $lead, ['taken' => $taken], $what.' taken from the chat', brand: $brand, logName: 'leads');

        return $taken;
    }
}
