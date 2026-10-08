<?php

declare(strict_types=1);

namespace App\Modules\Activity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One day of the audit trail as a compressed file in private storage.
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property int $rows
 * @property string $path
 * @property int $size_bytes
 * @property string $checksum
 */
class LogArchive extends Model
{
    protected $fillable = ['date', 'rows', 'path', 'size_bytes', 'checksum'];

    protected function casts(): array
    {
        return ['date' => 'date', 'rows' => 'integer', 'size_bytes' => 'integer'];
    }
}
