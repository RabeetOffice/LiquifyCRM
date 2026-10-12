<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Sales\Models\Client;

/**
 * The work for a client is over: they are closed and leave the active list.
 * Everything stays (sales, files, saved text); only new upsells stop until
 * somebody reopens the client.
 */
final class CloseClient
{
    public function close(Client $client, ?string $note, User $by): Client
    {
        if ($client->isClosed()) {
            return $client;
        }

        $note = filled($note) ? mb_substr(trim((string) $note), 0, 500) : null;

        $client->forceFill(['closed_at' => now(), 'closed_by' => $by->getKey(), 'closed_note' => $note])->save();

        Audit::log('clients.closed', $client, ['note' => $note], 'Client closed'.($note !== null ? ': '.$note : ''), brand: (int) $client->brand_id, department: $client->department_id, causer: $by, logName: 'sales');

        return $client;
    }

    public function reopen(Client $client, User $by): Client
    {
        if (! $client->isClosed()) {
            return $client;
        }

        $client->forceFill(['closed_at' => null, 'closed_by' => null, 'closed_note' => null])->save();

        Audit::log('clients.reopened', $client, [], 'Client reopened', brand: (int) $client->brand_id, department: $client->department_id, causer: $by, logName: 'sales');

        return $client;
    }
}
