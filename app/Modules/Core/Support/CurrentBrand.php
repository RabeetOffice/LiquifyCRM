<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Brands\Models\Brand;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the brand the current request or job is working in.
 *
 * Inside the brand panel the Filament tenant is the source of truth. Jobs,
 * commands and tests call set() explicitly. Setting a brand also sets the
 * spatie permission team so brand roles resolve correctly.
 */
final class CurrentBrand
{
    private ?Brand $brand = null;

    private bool $explicit = false;

    public function set(?Brand $brand): void
    {
        $this->brand = $brand;
        $this->explicit = true;

        setPermissionsTeamId($brand?->getKey());
    }

    public function clear(): void
    {
        $this->brand = null;
        $this->explicit = false;

        setPermissionsTeamId(null);
    }

    public function get(): ?Brand
    {
        if ($this->explicit) {
            return $this->brand;
        }

        $tenant = Filament::getTenant();

        return $tenant instanceof Brand ? $tenant : null;
    }

    public function id(): ?int
    {
        $id = $this->get()?->getKey();

        return $id === null ? null : (int) $id;
    }

    public function is(Model|int|null $brand): bool
    {
        if ($brand === null) {
            return $this->id() === null;
        }

        $id = $brand instanceof Model ? $brand->getKey() : $brand;

        return $this->id() === (int) $id;
    }

    /**
     * Run a callback inside a brand, restoring the previous state afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function within(?Brand $brand, callable $callback): mixed
    {
        [$previousBrand, $previousExplicit] = [$this->brand, $this->explicit];

        $this->set($brand);

        try {
            return $callback();
        } finally {
            $this->brand = $previousBrand;
            $this->explicit = $previousExplicit;
            setPermissionsTeamId($this->id());
        }
    }
}
