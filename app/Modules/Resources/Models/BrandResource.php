<?php

declare(strict_types=1);

namespace App\Modules\Resources\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Sales\Models\ClientFile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * One of a brand's templates or pieces of material: a file everyone in the
 * brand downloads (its row in the files table, the same table as client
 * files), or a link.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $title
 * @property string $category
 * @property string|null $description
 * @property string|null $url
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ClientFile|null $file
 * @property-read User|null $creator
 */
class BrandResource extends Model
{
    use BelongsToBrand;

    protected $table = 'resources';

    protected $fillable = ['brand_id', 'title', 'category', 'description', 'url', 'created_by'];

    /** What a resource is, in the words of the team, in the order the screen shows them. */
    public const CATEGORIES = [
        'contracts' => 'Contracts and agreements',
        'branding' => 'Letterheads and branding',
        'sales' => 'Sales material',
        'scripts' => 'Scripts and guides',
        'forms' => 'Forms',
        'other' => 'Other',
    ];

    /** The same, short enough for the views above the list. */
    public const SHORT = [
        'contracts' => 'Contracts',
        'branding' => 'Branding',
        'sales' => 'Sales material',
        'scripts' => 'Scripts',
        'forms' => 'Forms',
        'other' => 'Other',
    ];

    /** @return MorphOne<ClientFile, $this> */
    public function file(): MorphOne
    {
        return $this->morphOne(ClientFile::class, 'fileable');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Other';
    }

    public function isLink(): bool
    {
        return filled($this->url);
    }
}
