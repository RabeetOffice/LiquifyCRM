<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice made from a sale, as it was when it was made: who it was for,
 * the lines, the total and what had been paid. Its PDF is a client file.
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $sale_id
 * @property int $client_id
 * @property string $number
 * @property int $year
 * @property int $sequence
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable|null $due_on
 * @property array{name: string, email: ?string, phone: ?string, address: ?string} $bill_to
 * @property list<array{description: string, amount: float}> $items
 * @property string $total
 * @property string $paid
 * @property string $currency
 * @property string|null $note
 * @property int|null $file_id
 * @property int|null $created_by
 * @property-read ClientFile|null $file
 * @property-read Sale|null $sale
 */
class Invoice extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'brand_id', 'sale_id', 'client_id', 'number', 'year', 'sequence', 'issued_on', 'due_on', 'bill_to', 'items',
        'total', 'paid', 'currency', 'note', 'file_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'bill_to' => 'array',
            'items' => 'array',
            'total' => 'decimal:2',
            'paid' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<ClientFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(ClientFile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function balance(): float
    {
        return max(0.0, round((float) $this->total - (float) $this->paid, 2));
    }
}
