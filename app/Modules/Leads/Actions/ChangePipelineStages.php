<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Exceptions\StageInUse;
use App\Modules\Leads\Models\LeadStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A brand's own pipeline (user request, 12 Nov 2026: "Melbourne needs a
 * Connected stage between Contacted and Qualified, only for that brand").
 * Stages are rows of `lead_statuses`, one set per brand, and every screen
 * reads them in `sort` order, so a stage added here is on the Pipeline, the
 * lead's step bar, the filters and the charts at once.
 *
 * The order always stays New first, the brand's open stages in between, then
 * Won and Lost: resequence() puts it back on every change. A stage that holds
 * a lead (one in the Bin too) is never removed, and the database refuses it
 * as well (`leads.status_id` restricts the delete).
 */
final class ChangePipelineStages
{
    public const NAME_MAX = 40;

    public function add(Brand|int $brand, string $name, string $color, LeadStatus $after, ?User $by = null): LeadStatus
    {
        $brandId = $brand instanceof Brand ? (int) $brand->getKey() : $brand;
        $name = self::cleanName($name);
        $swatch = self::swatch($color);

        if ((int) $after->brand_id !== $brandId || ! $after->isOpen()) {
            throw new InvalidArgumentException('A new stage goes after New or another open stage of the same brand.');
        }

        return DB::transaction(function () use ($brandId, $name, $swatch, $after, $by): LeadStatus {
            $stages = $this->stagesOf($brandId);
            self::ensureNameIsFree($stages, $name);

            $stage = LeadStatus::query()->create([
                'brand_id' => $brandId,
                'name' => $name,
                'slug' => self::slugFor($stages, $name),
                'color' => $swatch['color'],
                'tint' => $swatch['tint'],
                'sort' => $stages->count(),
                'is_default' => false,
                'is_won' => false,
                'is_lost' => false,
            ]);

            $index = $stages->search(fn (LeadStatus $status): bool => $status->getKey() === $after->getKey());
            $ordered = $stages->values()->all();
            array_splice($ordered, $index === false ? count($ordered) : $index + 1, 0, [$stage]);
            $this->resequence(collect($ordered));

            Audit::log(
                'pipeline.stage_added',
                $stage,
                ['name' => $name, 'after' => $after->name],
                "Pipeline stage added: {$name}, after {$after->name}",
                brand: $brandId,
                causer: $by,
                logName: 'leads',
            );

            return $stage->refresh();
        });
    }

    /**
     * Rename a stage or change its colour. Any stage, New, Won and Lost too:
     * the CRM goes by what a stage means, never by its name. A null colour
     * keeps the one it has.
     */
    public function update(LeadStatus $stage, string $name, ?string $color, ?User $by = null): LeadStatus
    {
        $name = self::cleanName($name);
        $swatch = $color !== null ? self::swatch($color) : null;
        $before = $stage->name;

        return DB::transaction(function () use ($stage, $name, $swatch, $before, $by): LeadStatus {
            self::ensureNameIsFree($this->stagesOf((int) $stage->brand_id)->reject(fn (LeadStatus $status): bool => $status->getKey() === $stage->getKey()), $name);

            $stage->fill(['name' => $name, ...($swatch !== null ? ['color' => $swatch['color'], 'tint' => $swatch['tint']] : [])]);

            if (! $stage->isDirty()) {
                return $stage;
            }

            $stage->save();

            Audit::log(
                'pipeline.stage_changed',
                $stage,
                ['from' => $before, 'to' => $name, 'color' => $stage->color],
                $before === $name ? "Pipeline stage {$name}: new colour" : "Pipeline stage renamed: {$before} → {$name}",
                brand: (int) $stage->brand_id,
                causer: $by,
                logName: 'leads',
            );

            return $stage;
        });
    }

    /**
     * One place up or down among the brand's open stages. New, Won and Lost
     * keep their places, so the first open stage cannot go above New.
     */
    public function move(LeadStatus $stage, string $direction, ?User $by = null): LeadStatus
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException('A stage moves up or down.');
        }

        if ($stage->isFixed()) {
            return $stage;
        }

        return DB::transaction(function () use ($stage, $direction, $by): LeadStatus {
            $stages = $this->stagesOf((int) $stage->brand_id);
            $middle = $stages->reject(fn (LeadStatus $status): bool => $status->isFixed())->values();
            $index = $middle->search(fn (LeadStatus $status): bool => $status->getKey() === $stage->getKey());
            $target = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index === false || ! $middle->has($target)) {
                return $stage;
            }

            $order = $middle->all();
            [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

            $this->resequence($stages->filter(fn (LeadStatus $status): bool => $status->is_default)->concat($order)->concat($stages->filter(fn (LeadStatus $status): bool => ! $status->isOpen())));

            $neighbour = $middle[$target];
            Audit::log(
                'pipeline.stage_moved',
                $stage,
                ['name' => $stage->name, 'direction' => $direction, 'past' => $neighbour->name],
                "Pipeline stage {$stage->name} moved ".($direction === 'up' ? 'before' : 'after')." {$neighbour->name}",
                brand: (int) $stage->brand_id,
                causer: $by,
                logName: 'leads',
            );

            return $stage->refresh();
        });
    }

    /**
     * Remove a stage nobody is in.
     *
     * @throws StageInUse
     */
    public function remove(LeadStatus $stage, ?User $by = null): void
    {
        if ($stage->isFixed()) {
            throw StageInUse::fixed($stage->name);
        }

        DB::transaction(function () use ($stage, $by): void {
            // The stage rows are locked first: a lead being moved into this stage right now holds the
            // database's check on the stage row, so it is either counted here or refused once it is gone.
            $stages = $this->stagesOf((int) $stage->brand_id);
            $held = LeadStatus::leadCountsFor((int) $stage->brand_id)[$stage->getKey()] ?? null;

            if ($held !== null && $held['total'] > 0) {
                throw StageInUse::holding($stage->name, $held['total'], $held['bin']);
            }

            $stage->delete();

            $this->resequence($stages->reject(fn (LeadStatus $status): bool => $status->getKey() === $stage->getKey()));

            Audit::log(
                'pipeline.stage_removed',
                null,
                ['stage_id' => $stage->getKey(), 'name' => $stage->name],
                "Pipeline stage removed: {$stage->name}",
                brand: (int) $stage->brand_id,
                causer: $by,
                logName: 'leads',
            );
        });
    }

    /**
     * Number the stages 0, 1, 2… in this order, with New first and Won and
     * Lost last whatever order they came in.
     *
     * @param  Collection<int, LeadStatus>  $stages
     */
    private function resequence(Collection $stages): void
    {
        $ordered = $stages->filter(fn (LeadStatus $status): bool => $status->is_default)
            ->concat($stages->reject(fn (LeadStatus $status): bool => $status->isFixed()))
            ->concat($stages->filter(fn (LeadStatus $status): bool => $status->is_won && ! $status->is_default))
            ->concat($stages->filter(fn (LeadStatus $status): bool => $status->is_lost && ! $status->is_default && ! $status->is_won))
            ->values();

        foreach ($ordered as $sort => $stage) {
            if ($stage->sort !== $sort) {
                $stage->forceFill(['sort' => $sort])->save();
            }
        }
    }

    /**
     * The brand's stages in order, locked until the change is saved, so two
     * people adding at the same moment never get the same place.
     *
     * @return Collection<int, LeadStatus>
     */
    private function stagesOf(int $brandId): Collection
    {
        return LeadStatus::query()->forBrand($brandId)->ordered()->lockForUpdate()->get();
    }

    private static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give the stage a name.']);
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            throw ValidationException::withMessages(['name' => 'Keep the name to '.self::NAME_MAX.' characters.']);
        }

        return $name;
    }

    /** @return array{label: string, color: string, tint: string} */
    private static function swatch(string $key): array
    {
        return LeadStatus::PALETTE[$key] ?? throw ValidationException::withMessages(['color' => 'Choose one of the colours.']);
    }

    /** @param  Collection<int, LeadStatus>  $others */
    private static function ensureNameIsFree(Collection $others, string $name): void
    {
        $taken = $others->first(fn (LeadStatus $status): bool => mb_strtolower($status->name) === mb_strtolower($name));

        if ($taken !== null) {
            throw ValidationException::withMessages(['name' => "This brand already has a stage called {$taken->name}."]);
        }
    }

    /** @param  Collection<int, LeadStatus>  $stages */
    private static function slugFor(Collection $stages, string $name): string
    {
        $base = Str::limit(Str::slug($name), 50, '') ?: 'stage';
        $slugs = $stages->pluck('slug')->all();
        $slug = $base;

        for ($n = 2; in_array($slug, $slugs, true); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
