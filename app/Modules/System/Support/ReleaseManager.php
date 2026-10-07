<?php

declare(strict_types=1);

namespace App\Modules\System\Support;

use RuntimeException;

/**
 * Works with the Deployer layout on the server:
 *
 *   ~/crm/releases/1, ~/crm/releases/2, ...   one folder per deploy
 *   ~/crm/current -> releases/2                 symlink switched on deploy
 *
 * Rolling back re-points "current" at the previous release. Locally there is
 * no releases folder, so enabled() is false and the rollback tools stay hidden.
 */
final class ReleaseManager
{
    public function enabled(): bool
    {
        $path = $this->deployPath();

        return $path !== null && is_dir($path.'/releases') && is_link($path.'/current');
    }

    public function deployPath(): ?string
    {
        $path = config('liquify.deploy_path');

        return is_string($path) && $path !== '' ? rtrim($path, '/\\') : null;
    }

    public function currentRelease(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $target = readlink($this->deployPath().'/current');

        return $target === false ? null : basename($target);
    }

    /**
     * Releases newest first.
     *
     * @return list<array{name: string, path: string, deployed_at: int, is_current: bool}>
     */
    public function releases(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $current = $this->currentRelease();
        $dirs = glob($this->deployPath().'/releases/*', GLOB_ONLYDIR) ?: [];

        $releases = array_map(fn (string $dir): array => [
            'name' => basename($dir),
            'path' => $dir,
            'deployed_at' => (int) filemtime($dir),
            'is_current' => basename($dir) === $current,
        ], $dirs);

        usort($releases, fn (array $a, array $b): int => $b['deployed_at'] <=> $a['deployed_at']);

        return $releases;
    }

    public function previousRelease(): ?string
    {
        $releases = $this->releases();

        foreach ($releases as $index => $release) {
            if ($release['is_current']) {
                return $releases[$index + 1]['name'] ?? null;
            }
        }

        return null;
    }

    /**
     * Atomically switch "current" to another release and return its name.
     */
    public function switchTo(string $release): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Release folders are not available on this host.');
        }

        $path = $this->deployPath();
        $target = $path.'/releases/'.basename($release);

        if (! is_dir($target)) {
            throw new RuntimeException("Release {$release} does not exist.");
        }

        $temporary = $path.'/current.tmp-'.bin2hex(random_bytes(3));

        if (! @symlink('releases/'.basename($release), $temporary)) {
            throw new RuntimeException('Could not create the release symlink.');
        }

        if (! @rename($temporary, $path.'/current')) {
            @unlink($temporary);

            throw new RuntimeException('Could not switch the current release.');
        }

        return basename($release);
    }

    public function rollback(): string
    {
        $previous = $this->previousRelease();

        if ($previous === null) {
            throw new RuntimeException('There is no previous release to roll back to.');
        }

        return $this->switchTo($previous);
    }
}
