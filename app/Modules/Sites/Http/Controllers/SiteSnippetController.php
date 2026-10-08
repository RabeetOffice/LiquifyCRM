<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Controllers;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\Audit;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\LiquifySnippet;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Download liquify.php with the address and secret of one website filled in. */
final class SiteSnippetController
{
    public function __invoke(Brand $brand, Site $site): Response
    {
        Gate::authorize('update', $site);

        Audit::log('sites.snippet_downloaded', $site, [], 'liquify.php downloaded for '.$site->domain, logName: 'sites');

        return response(LiquifySnippet::file($site), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="liquify.php"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
