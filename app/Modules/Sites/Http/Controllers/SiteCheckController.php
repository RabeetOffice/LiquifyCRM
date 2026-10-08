<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Controllers;

use App\Modules\Core\Support\Audit;
use App\Modules\Sites\Jobs\RunSiteChecks;
use App\Modules\Sites\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** POST /admin/sites/{site}/check: queue the health checks of one website now. */
final class SiteCheckController
{
    public function __invoke(Request $request, int $site): JsonResponse|RedirectResponse
    {
        $model = Site::query()->acrossBrands()->with('brand')->findOrFail($site);

        RunSiteChecks::dispatch((int) $model->getKey());

        Audit::log('sites.check_requested', $model, [], 'Health checks requested for '.$model->name, brand: $model->brand_id, logName: 'sites');

        if ($request->expectsJson()) {
            return response()->json(['status' => 'queued', 'site' => $model->getKey()], 202);
        }

        return redirect()->to($model->brand->url('sites/'.$model->getKey()));
    }
}
