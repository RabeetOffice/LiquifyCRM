<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Core\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;

/**
 * A monthly goal (revenue, number of sales, or both) for a brand, a
 * department or one person inside a brand.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $targetable_type
 * @property int $targetable_id
 * @property string $period
 * @property string|null $revenue_target
 * @property int|null $sales_count_target
 * @property int|null $set_by
 */
class Target extends Model
{
    use BelongsToBrand;

    public const BRAND = 'brand';

    public const DEPARTMENT = 'department';

    public const USER = 'user';

    protected $fillable = ['brand_id', 'targetable_type', 'targetable_id', 'period', 'revenue_target', 'sales_count_target', 'set_by'];

    protected $attributes = ['revenue_target' => null, 'sales_count_target' => null, 'set_by' => null];

    protected function casts(): array
    {
        return [
            'revenue_target' => 'decimal:2',
            'sales_count_target' => 'integer',
        ];
    }

    public static function key(string $type, int $id): string
    {
        return $type.':'.$id;
    }
}
