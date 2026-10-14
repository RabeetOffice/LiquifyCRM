<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Modules\Auth\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser that gets desktop alerts with LiquifyCRM closed (Web Push). The
 * endpoint is the address the browser maker's push service gave it; the two
 * keys encrypt what is sent there, so that service cannot read it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 */
class PushSubscription extends Model
{
    /** Browsers kept per person; the oldest goes when another one is added. */
    public const PER_PERSON = 10;

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding', 'last_used_at'];

    protected $hidden = ['public_key', 'auth_token'];

    protected $attributes = ['content_encoding' => 'aes128gcm', 'last_used_at' => null];

    protected function casts(): array
    {
        return [
            'public_key' => 'encrypted',
            'auth_token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
