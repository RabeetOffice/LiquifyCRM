<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientNote;

/**
 * Keep a piece of text with a client: a login they shared, a link, what
 * they asked for. The text itself never goes into the log, only its title.
 */
final class SaveClientNote
{
    public function handle(Client $client, ?ClientNote $note, string $title, string $body, bool $secret, User $by): ClientNote
    {
        $title = mb_substr(trim($title), 0, 190);

        if ($note === null) {
            $note = ClientNote::query()->create([
                'brand_id' => $client->brand_id,
                'client_id' => $client->getKey(),
                'title' => $title,
                'body' => $body,
                'is_secret' => $secret,
                'created_by' => $by->getKey(),
            ]);

            Audit::log('clients.note_added', $client, ['note_id' => $note->getKey(), 'title' => $title, 'secret' => $secret], 'Saved text added: '.$title, brand: (int) $client->brand_id, causer: $by, logName: 'sales');

            return $note;
        }

        $note->update(['title' => $title, 'body' => $body, 'is_secret' => $secret, 'updated_by' => $by->getKey()]);

        Audit::log('clients.note_updated', $client, ['note_id' => $note->getKey(), 'title' => $title, 'secret' => $secret], 'Saved text changed: '.$title, brand: (int) $client->brand_id, causer: $by, logName: 'sales');

        return $note;
    }

    public function delete(ClientNote $note, User $by): void
    {
        $client = Client::query()->forBrand($note->brand_id)->find($note->client_id);
        $note->delete();

        Audit::log('clients.note_removed', $client, ['note_id' => $note->getKey(), 'title' => $note->title], 'Saved text removed: '.$note->title, brand: $note->brand_id, causer: $by, logName: 'sales');
    }

    /** Somebody asked to see a secret: who and when is on the record. */
    public function viewed(ClientNote $note, User $by): void
    {
        Audit::log('clients.secret_viewed', Client::query()->forBrand($note->brand_id)->find($note->client_id), ['note_id' => $note->getKey(), 'title' => $note->title], 'Looked at hidden text: '.$note->title, brand: $note->brand_id, causer: $by, logName: 'sales');
    }
}
