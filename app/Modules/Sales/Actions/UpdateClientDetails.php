<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Sales\Models\Client;

/**
 * A client's name, phone and email, corrected by the people who work the
 * client (user request, 27 Oct 2026). Only the client changes: the leads it
 * came from keep what they were sent, and an invoice already made keeps the
 * name it was made out to. The log says which fields changed, not to what.
 */
final class UpdateClientDetails
{
    private const FIELDS = ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email'];

    /**
     * Only the keys that are present are changed; an empty value clears one
     * (except the name: a client always has one).
     *
     * @param  array<string, string|null>  $details  name, phone, email
     */
    public function handle(Client $client, array $details, ?User $by = null): Client
    {
        $changed = [];

        foreach (self::FIELDS as $key => $label) {
            if (! array_key_exists($key, $details)) {
                continue;
            }

            $value = trim((string) $details[$key]);
            $value = $value === '' ? null : ($key === 'email' ? mb_strtolower($value) : $value);

            if (($key === 'name' && $value === null) || $client->getAttribute($key) === $value) {
                continue;
            }

            $client->setAttribute($key, $value);
            $changed[] = $label;
        }

        if ($changed === []) {
            return $client;
        }

        $client->save();

        Audit::log('clients.details_updated', $client, ['changed' => $changed], 'Details updated: '.mb_strtolower(implode(', ', $changed)), brand: (int) $client->brand_id, department: $client->department_id, causer: $by, logName: 'sales');

        return $client;
    }
}
