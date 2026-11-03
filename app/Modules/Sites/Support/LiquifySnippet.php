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
    /** The version inside the file; a website answering with an older one is told to download it again. */
    public const VERSION = '2.1';

    public static function file(Site $site): string
    {
        return strtr((string) file_get_contents(app_path('Modules/Sites/resources/stubs/liquify.php.stub')), [
            '{{DOMAIN}}' => $site->domain,
            '{{URL}}' => addcslashes($site->ingestUrl(), "'\\"),
            '{{SECRET}}' => addcslashes($site->secret, "'\\"),
        ]);
    }

    /** A website runs a file older than this one, which may not find settings this one finds. */
    public static function isOutdated(?string $version): bool
    {
        return $version !== null && $version !== '' && version_compare($version, self::VERSION, '<');
    }

    /**
     * Optional: the lines that send a lead the moment the form saves it,
     * instead of within a minute. Made for the form handler of our websites,
     * where $db is what saving the lead returned: its row id, or the row.
     */
    public static function lines(): string
    {
        return "require_once __DIR__ . '/liquify.php';\nliquify_send(\$lead['form_type'], \$db, \$lead);";
    }
}
