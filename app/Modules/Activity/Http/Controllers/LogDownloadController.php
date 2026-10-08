<?php

declare(strict_types=1);

namespace App\Modules\Activity\Http\Controllers;

use App\Modules\Activity\Models\LogArchive;
use App\Modules\Activity\Models\LogExport;
use App\Modules\Activity\Support\LogArchiver;
use App\Modules\Core\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands out log files from private storage: a person's own export while its
 * 24 hours last, and (Admins only) a daily archive file. Every download is logged.
 */
final class LogDownloadController
{
    public function export(Request $request, int $export): StreamedResponse
    {
        $model = LogExport::query()->findOrFail($export);

        abort_unless($model->user_id === $request->user()?->getKey(), 404);
        abort_unless($model->isReady() && Storage::disk(LogArchiver::DISK)->exists((string) $model->path), 410, 'This download has expired. Ask for a new one on the Logs screen.');

        Audit::log('system.logs_downloaded', null, ['export_id' => $model->getKey(), 'days' => $model->range_days, 'rows' => $model->rows], 'Logs of the last '.$model->range_days.' days downloaded', brand: $model->brand_id, logName: 'system');

        return Storage::disk(LogArchiver::DISK)->download((string) $model->path, basename((string) $model->path));
    }

    public function archive(Request $request, int $archive): StreamedResponse
    {
        abort_unless((bool) $request->user()?->isGlobalAdmin(), 403);

        $model = LogArchive::query()->findOrFail($archive);
        abort_unless(Storage::disk(LogArchiver::DISK)->exists($model->path), 404);

        Audit::log('system.logs_downloaded', null, ['archive' => $model->date->toDateString(), 'rows' => $model->rows], 'Log archive of '.$model->date->toDateString().' downloaded', logName: 'system');

        return Storage::disk(LogArchiver::DISK)->download($model->path, basename($model->path));
    }
}
