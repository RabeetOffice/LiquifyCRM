<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Modules\Sites\Models\Site;

/**
 * The liquify.php file from the architecture document, with the address and
 * secret of one website already filled in. The same file goes on every site.
 */
final class LiquifySnippet
{
    public static function file(Site $site): string
    {
        return strtr((string) file_get_contents(app_path('Modules/Sites/resources/stubs/liquify.php.stub')), [
            '{{DOMAIN}}' => $site->domain,
            '{{URL}}' => addcslashes($site->ingestUrl(), "'\\"),
            '{{SECRET}}' => addcslashes($site->secret, "'\\"),
        ]);
    }

    /** The 2 lines pasted into the existing form handler, right after the lead is saved. */
    public static function lines(): string
    {
        return "require_once __DIR__ . '/includes/liquify.php';\nliquify_send(\$lead['form_type'], \$leadId, \$lead);";
    }

    /** Optional retry cron for sites with few submissions. */
    public static function cron(): string
    {
        return "<?php\nif (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // cron only, never from a browser\nrequire __DIR__ . '/liquify.php';\nliquify_flush(50);\n";
    }
}
