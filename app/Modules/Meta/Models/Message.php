<?php

declare(strict_types=1);

namespace App\Modules\Meta\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Live;
use App\Modules\Meta\Enums\MessageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a conversation, in ("in") from the person or out ("out")
 * from the team.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $direction
 * @property string|null $body
 * @property string|null $external_id
 * @property MessageStatus $status
 * @property string|null $error
 * @property int|null $sent_by
 * @property array<string, mixed>|null $raw
 * @property CarbonImmutable|null $created_at
 * @property-read Conversation $conversation
 */
class Message extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    protected $fillable = ['conversation_id', 'direction', 'body', 'external_id', 'status', 'error', 'sent_by', 'raw', 'created_at'];

    protected $attributes = ['status' => 'sent', 'external_id' => null, 'error' => null, 'sent_by' => null, 'raw' => null];

    protected function casts(): array
    {
        return [
            'status' => MessageStatus::class,
            'raw' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // A message that arrives, is sent or fails shows in the open chat at once (see Live).
        static::saved(function (self $message): void {
            $brandId = $message->relationLoaded('conversation')
                ? $message->conversation->brand_id
                : Conversation::query()->withoutGlobalScopes()->whereKey($message->conversation_id)->value('brand_id');

            Live::touch($brandId !== null ? (int) $brandId : null);
        });
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function isOutbound(): bool
    {
        return $this->direction === self::OUT;
    }

    /** A reply the team wrote on Facebook or Instagram itself, not in LiquifyCRM: it arrived as an echo, so nobody here sent it. */
    public function isFromFacebook(): bool
    {
        return $this->isOutbound() && $this->sent_by === null && ! empty($this->raw['message']['is_echo']);
    }

    /**
     * Pictures, videos, voice notes, files and shared links of the message,
     * as Facebook or Instagram sent them. They are shown from Meta's own
     * address and never copied here, so one may stop loading after a long
     * time. Instagram also sends shared posts and reels, a mention in the
     * person's story, and the story a reply answers: those open as links.
     *
     * @return list<array{type: string, url: string, name: string}>
     */
    public function attachments(): array
    {
        $found = [];
        $shared = ['share' => 'A shared post', 'ig_reel' => 'A shared reel', 'reel' => 'A shared reel', 'story_mention' => 'Mentioned you in their story', 'ig_story' => 'A shared story'];

        foreach ((array) ($this->raw['message']['attachments'] ?? []) as $item) {
            $type = (string) ($item['type'] ?? 'file');
            $url = (string) ($item['payload']['url'] ?? $item['url'] ?? '');

            if (! str_starts_with($url, 'https://')) {
                continue;
            }

            $path = (string) parse_url($url, PHP_URL_PATH);
            $title = (string) ($item['title'] ?? $item['payload']['title'] ?? '');

            $found[] = [
                'type' => in_array($type, ['image', 'video', 'audio'], true) ? $type : ($type === 'fallback' || isset($shared[$type]) ? 'link' : 'file'),
                'url' => $url,
                'name' => $title !== '' ? $title : ($shared[$type] ?? (basename($path) !== '' ? urldecode(basename($path)) : 'Attachment')),
            ];
        }

        // An Instagram reply to the business's story names the story it answers.
        $story = (string) ($this->raw['message']['reply_to']['story']['url'] ?? '');

        if (str_starts_with($story, 'https://')) {
            $found[] = ['type' => 'link', 'url' => $story, 'name' => 'Replied to your story'];
        }

        return $found;
    }

    /** True when the text is only the "[2 attachments]" stand-in and the attachments themselves can be shown. */
    public function isOnlyAttachments(): bool
    {
        return $this->attachments() !== [] && preg_match('/^\[\d+ attachments?\]$/', (string) $this->body) === 1;
    }
}
