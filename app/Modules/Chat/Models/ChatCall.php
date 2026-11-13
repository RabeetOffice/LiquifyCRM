<?php

declare(strict_types=1);

namespace App\Modules\Chat\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Meta\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A voice call a website visitor made from the chat box (docs/CALLS_PLAN.md). Only the record of it: the sound goes
 * browser to browser and is never kept. `offer` and `answer` are the call's two set-up messages (they hold IP
 * addresses) and are emptied the moment the call is over.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $conversation_id
 * @property string $status
 * @property int|null $answered_by
 * @property string|null $offer
 * @property string|null $answer
 * @property CarbonImmutable|null $rang_at
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $ended_at
 * @property string|null $ended_by
 * @property CarbonImmutable|null $visitor_seen_at
 * @property CarbonImmutable|null $agent_seen_at
 * @property-read Conversation|null $conversation
 * @property-read User|null $answerer
 */
class ChatCall extends Model
{
    use BelongsToBrand;

    /** Ringing in the CRM, nobody answered yet. */
    public const RINGING = 'ringing';

    /** Somebody accepted and their browser is making its answer. */
    public const CONNECTING = 'connecting';

    /** Both sides have what they need: they are talking (or about to). */
    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    public const MISSED = 'missed';

    public const FAILED = 'failed';

    /** A call that is still going on in one way or another. */
    public const LIVE = [self::RINGING, self::CONNECTING, self::ACTIVE];

    /** How long a call rings before it is a missed call. */
    public const RING_SECONDS = 60;

    /** How long the answering browser has to send its answer. */
    public const CONNECT_SECONDS = 40;

    /** A side not heard from for this long has gone (a closed tab, a lost connection). */
    public const SILENT_SECONDS = 25;

    protected $fillable = ['brand_id', 'conversation_id', 'status', 'answered_by', 'offer', 'answer', 'rang_at', 'answered_at', 'ended_at', 'ended_by', 'visitor_seen_at', 'agent_seen_at'];

    /** The set-up messages hold IP addresses: never in an array or JSON of the model. */
    protected $hidden = ['offer', 'answer'];

    protected function casts(): array
    {
        return [
            'rang_at' => 'immutable_datetime',
            'answered_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'visitor_seen_at' => 'immutable_datetime',
            'agent_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    /** How long the two talked, in seconds (nothing for a call nobody answered). */
    public function seconds(): int
    {
        if ($this->answered_at === null || $this->ended_at === null || $this->status !== self::ENDED) {
            return 0;
        }

        return max(0, (int) $this->answered_at->diffInSeconds($this->ended_at));
    }

    /** "3 min 12 s", "48 s". */
    public static function length(int $seconds): string
    {
        return $seconds >= 60 ? intdiv($seconds, 60).' min '.($seconds % 60).' s' : $seconds.' s';
    }
}
