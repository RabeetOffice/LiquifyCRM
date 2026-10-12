<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Text kept with a client: a login they shared, a link, instructions. The
 * body is encrypted in the database. One marked secret stays hidden on the
 * screen until somebody asks to see it, and that is written to the log.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $client_id
 * @property string $title
 * @property string $body
 * @property bool $is_secret
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $author
 */
class ClientNote extends Model
{
    use BelongsToBrand;

    protected $fillable = ['brand_id', 'client_id', 'title', 'body', 'is_secret', 'created_by', 'updated_by'];

    protected $hidden = ['body'];

    protected $attributes = ['is_secret' => false, 'created_by' => null, 'updated_by' => null];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'is_secret' => 'boolean',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
