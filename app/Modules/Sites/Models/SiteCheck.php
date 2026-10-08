<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

use App\Modules\Sites\Enums\CheckStatus;
use App\Modules\Sites\Enums\CheckType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One health check result for a website. Rows are kept as history.
 *
 * @property int $id
 * @property int $site_id
 * @property CheckType $type
 * @property CheckStatus $status
 * @property string|null $message
 * @property int|null $response_ms
 * @property CarbonImmutable $checked_at
 */
class SiteCheck extends Model
{
    public $timestamps = false;

    protected $fillable = ['site_id', 'type', 'status', 'message', 'response_ms', 'checked_at'];

    protected function casts(): array
    {
        return [
            'type' => CheckType::class,
            'status' => CheckStatus::class,
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The newest result of each check type for the given websites.
     *
     * @param  iterable<int>  $siteIds
     * @return array<int, array<string, self>> site id => type => check
     */
    public static function latestFor(iterable $siteIds): array
    {
        $siteIds = collect($siteIds)->all();

        if ($siteIds === []) {
            return [];
        }

        $latestIds = self::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('site_id', $siteIds)
            ->groupBy('site_id', 'type')
            ->pluck('id');

        $latest = [];

        foreach (self::query()->whereIn('id', $latestIds)->get() as $check) {
            $latest[$check->site_id][$check->type->value] = $check;
        }

        return $latest;
    }
}
