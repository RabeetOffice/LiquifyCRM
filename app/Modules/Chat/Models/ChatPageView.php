<?php

declare(strict_types=1);

namespace App\Modules\Chat\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page a browser opened on a brand's website: the address without the part after "?", the page's title and when.
 *
 * @property int $id
 * @property int $chat_session_id
 * @property int $brand_id
 * @property string $url
 * @property string|null $title
 * @property CarbonImmutable $viewed_at
 */
class ChatPageView extends Model
{
    public $timestamps = false;

    protected $fillable = ['chat_session_id', 'brand_id', 'url', 'title', 'viewed_at'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    public function path(): string
    {
        $path = (string) parse_url($this->url, PHP_URL_PATH);

        return $path !== '' ? $path : '/';
    }

    /** @return BelongsTo<ChatSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }
}
