<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received for a sale: what was paid with the sale itself, and each
 * part paid later. The sale's amount is the whole deal; its payments say how
 * much of it is in.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $sale_id
 * @property string $amount
 * @property CarbonImmutable $paid_at
 * @property string|null $note
 * @property int|null $recorded_by
 * @property-read User|null $recorder
 */
class SalePayment extends Model
{
    use BelongsToBrand;

    protected $fillable = ['brand_id', 'sale_id', 'amount', 'paid_at', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
