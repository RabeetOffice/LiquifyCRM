<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Modules\Sites\Models\Site;

/**
 * liquify.php, the one file a PHP website needs in its root folder, with the
 * address and secret of that website already filled in. The same file goes
 * on every site; it reads new leads for LiquifyCRM and can also send one.
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

    /**
     * Optional: the lines that send a lead the moment the form saves it,
     * instead of within a minute. Made for the form handler of our websites,
     * where the saved row is $db and its number $db['id'].
     */
    public static function lines(): string
    {
        return "require_once __DIR__ . '/liquify.php';\nliquify_send(\$lead['form_type'], \$db['id'], \$lead);";
    }
}
