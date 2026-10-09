<?php

declare(strict_types=1);

namespace App\Modules\System\Support;

use Carbon\CarbonImmutable;

/**
 * The list of releases in `resources/changelog.php`, newest first. Its top
 * entry is the version of the code that is running.
 *
 * @phpstan-type Change array{type: string, text: string}
 * @phpstan-type Release array{version: string, date: CarbonImmutable, title: string, changes: list<Change>}
 */
final class Changelog
{
    public const TYPES = [
        'new' => ['label' => 'New', 'color' => 'primary'],
        'improved' => ['label' => 'Improved', 'color' => 'info'],
        'fixed' => ['label' => 'Fixed', 'color' => 'success'],
        'removed' => ['label' => 'Removed', 'color' => 'gray'],
    ];

    /** @var list<Release>|null */
    private static ?array $releases = null;

    /** @return list<Release> */
    public static function releases(): array
    {
        if (self::$releases !== null) {
            return self::$releases;
        }

        $file = resource_path('changelog.php');
        $raw = is_file($file) ? require $file : [];
        $releases = [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['version'] ?? null)) {
                continue;
            }

            $changes = [];

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (is_array($change) && isset($change[0], $change[1])) {
                    $changes[] = ['type' => array_key_exists((string) $change[0], self::TYPES) ? (string) $change[0] : 'improved', 'text' => (string) $change[1]];
                }
            }

            $releases[] = [
                'version' => $entry['version'],
                'date' => CarbonImmutable::parse((string) ($entry['date'] ?? 'now')),
                'title' => (string) ($entry['title'] ?? 'Update'),
                'changes' => $changes,
            ];
        }

        usort($releases, fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return self::$releases = $releases;
    }

    /** The version of the code on this server. */
    public static function latestVersion(): string
    {
        return self::releases()[0]['version'] ?? '0.0.0';
    }

    /**
     * Releases newer than the installed version: what an update brings.
     *
     * @return list<Release>
     */
    public static function since(?string $installed): array
    {
        if ($installed === null) {
            return array_slice(self::releases(), 0, 1);
        }

        return array_values(array_filter(self::releases(), fn (array $release): bool => version_compare($release['version'], $installed, '>')));
    }

    /** Tests read a different file. */
    public static function forget(): void
    {
        self::$releases = null;
    }
}
