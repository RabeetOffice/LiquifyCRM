<?php

declare(strict_types=1);

namespace App\Modules\Chat\Models;

use App\Modules\Meta\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * A file sent in a website chat, by the visitor or the team. It lies on the private disk under a name made
 * here (never the sender's), and is served only through ChatFileController, to the people the chat shows it to.
 *
 * @property int $id
 * @property int $message_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property CarbonImmutable|null $created_at
 * @property-read Message $message
 */
class ChatAttachment extends Model
{
    protected $fillable = ['message_id', 'disk', 'path', 'original_name', 'mime', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class)->withoutGlobalScopes();
    }

    /** A picture the chat shows in place; anything else is a card to download. */
    public function isImage(): bool
    {
        return in_array($this->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    }

    public function humanSize(): string
    {
        return $this->size >= 1048576 ? round($this->size / 1048576, 1).' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }

    /** The link a visitor's browser gets: it works for six hours and only for this file. */
    public function visitorUrl(): string
    {
        return URL::temporarySignedRoute('chat.widget.file', now()->addHours(6), ['attachment' => $this->getKey()]);
    }
}
