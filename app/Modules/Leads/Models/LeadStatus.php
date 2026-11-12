<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Per-brand pipeline step. The document's six defaults are created for
 * every brand; `color` is the pill text and `tint` its soft background.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property string $tint
 * @property int $sort
 * @property bool $is_default
 * @property bool $is_won
 * @property bool $is_lost
 */
class LeadStatus extends Model
{
    use BelongsToBrand;

    /** @var list<array{name: string, slug: string, color: string, tint: string, is_default?: bool, is_won?: bool, is_lost?: bool}> */
    public const DEFAULTS = [
        ['name' => 'New', 'slug' => 'new', 'color' => '#C2410C', 'tint' => '#FFF4ED', 'is_default' => true],
        ['name' => 'Contacted', 'slug' => 'contacted', 'color' => '#0369A1', 'tint' => '#F0F9FF'],
        ['name' => 'Qualified', 'slug' => 'qualified', 'color' => '#6D28D9', 'tint' => '#F5F3FF'],
        ['name' => 'Proposal sent', 'slug' => 'proposal-sent', 'color' => '#4338CA', 'tint' => '#EEF2FF'],
        ['name' => 'Won', 'slug' => 'won', 'color' => '#15803D', 'tint' => '#F0FDF4', 'is_won' => true],
        ['name' => 'Lost', 'slug' => 'lost', 'color' => '#57534E', 'tint' => '#F5F5F4', 'is_lost' => true],
    ];

    /**
     * The colours a stage can take on Pipeline stages: the pill's text and its soft background,
     * the defaults' own colours first. A pair, so a new stage looks like the others in light and dark.
     *
     * @var array<string, array{label: string, color: string, tint: string}>
     */
    public const PALETTE = [
        'orange' => ['label' => 'Orange', 'color' => '#C2410C', 'tint' => '#FFF4ED'],
        'blue' => ['label' => 'Blue', 'color' => '#0369A1', 'tint' => '#F0F9FF'],
        'violet' => ['label' => 'Violet', 'color' => '#6D28D9', 'tint' => '#F5F3FF'],
        'indigo' => ['label' => 'Indigo', 'color' => '#4338CA', 'tint' => '#EEF2FF'],
        'teal' => ['label' => 'Teal', 'color' => '#0F766E', 'tint' => '#F0FDFA'],
        'cyan' => ['label' => 'Cyan', 'color' => '#0E7490', 'tint' => '#ECFEFF'],
        'amber' => ['label' => 'Amber', 'color' => '#B45309', 'tint' => '#FFFBEB'],
        'pink' => ['label' => 'Pink', 'color' => '#BE185D', 'tint' => '#FDF2F8'],
        'red' => ['label' => 'Red', 'color' => '#B91C1C', 'tint' => '#FEF2F2'],
        'green' => ['label' => 'Green', 'color' => '#15803D', 'tint' => '#F0FDF4'],
        'grey' => ['label' => 'Grey', 'color' => '#57534E', 'tint' => '#F5F5F4'],
    ];

    protected $fillable = ['brand_id', 'name', 'slug', 'color', 'tint', 'sort', 'is_default', 'is_won', 'is_lost'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_default' => 'boolean',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
        ];
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'status_id');
    }

    /**
     * New, Won and Lost carry the CRM's meaning (new leads land in New, a sale makes Won, Lost closes):
     * they keep their place and are never removed. Every other stage is the brand's own (Pipeline stages).
     */
    public function isFixed(): bool
    {
        return $this->is_default || $this->is_won || $this->is_lost;
    }

    /** Neither Won nor Lost: a lead here is still being worked. */
    public function isOpen(): bool
    {
        return ! $this->is_won && ! $this->is_lost;
    }

    /** The palette key of this stage's colour, null for a colour that is not in it. */
    public function paletteKey(): ?string
    {
        foreach (self::PALETTE as $key => $swatch) {
            if (strcasecmp($swatch['color'], $this->color) === 0) {
                return $key;
            }
        }

        return null;
    }

    /**
     * How many leads each stage of a brand holds, those in the Bin included
     * (restoring one puts it back in its stage), keyed by stage id.
     *
     * @return array<int, array{total: int, bin: int}>
     */
    public static function leadCountsFor(int $brandId): array
    {
        return DB::table('leads')
            ->where('brand_id', $brandId)
            ->selectRaw('status_id, COUNT(*) as total, SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as bin')
            ->groupBy('status_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->status_id => ['total' => (int) $row->total, 'bin' => (int) $row->bin]])
            ->all();
    }

    /** @param  Builder<static>  $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    /**
     * Give a brand the document's pipeline if it has no statuses yet.
     */
    public static function createDefaultsFor(Brand|int $brand): void
    {
        $brandId = $brand instanceof Brand ? (int) $brand->getKey() : $brand;

        if (static::query()->forBrand($brandId)->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $sort => $status) {
            static::query()->create([
                ...$status,
                'brand_id' => $brandId,
                'sort' => $sort,
            ]);
        }
    }

    public static function defaultFor(Brand|int $brand): ?self
    {
        $brandId = $brand instanceof Brand ? (int) $brand->getKey() : $brand;

        return static::query()->forBrand($brandId)->where('is_default', true)->first()
            ?? static::query()->forBrand($brandId)->ordered()->first();
    }

    public static function wonFor(Brand|int $brand): ?self
    {
        $brandId = $brand instanceof Brand ? (int) $brand->getKey() : $brand;

        return static::query()->forBrand($brandId)->where('is_won', true)->first();
    }
}
