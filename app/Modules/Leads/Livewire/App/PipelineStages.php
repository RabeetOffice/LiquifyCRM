<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Leads\Actions\ChangePipelineStages;
use App\Modules\Leads\Exceptions\StageInUse;
use App\Modules\Leads\Models\LeadStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pipeline stages (user request, 12 Nov 2026): the steps a lead goes through
 * in one brand. Admins and the brand's Managers add a stage between two others
 * ("Connected" between Contacted and Qualified, for Melbourne only), rename it,
 * give it a colour, move it, and remove it once no lead is in it. Every screen
 * of the brand reads the same list, so the Pipeline, the lead's step bar, the
 * filters and the charts follow at once.
 */
#[Layout('components.layouts.app')]
#[Title('Pipeline stages')]
final class PipelineStages extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithToasts;

    public Brand $brand;

    public bool $showForm = false;

    #[Locked]
    public ?int $editing = null;

    public string $name = '';

    public string $color = '';

    /** The stage a new one comes after (adding only). */
    public ?int $after = null;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', LeadStatus::class);
    }

    public function create(?int $afterId = null): void
    {
        $this->authorize('create', LeadStatus::class);

        $stages = $this->stages();
        $open = $stages->filter(fn (LeadStatus $status): bool => $status->isOpen());

        $this->reset('editing', 'name');
        $this->after = $open->firstWhere('id', $afterId)?->getKey() ?? $open->last()?->getKey();
        // A colour the brand's stages do not use yet, so the new column stands out.
        $used = $stages->map(fn (LeadStatus $status): ?string => $status->paletteKey())->filter()->all();
        $this->color = collect(array_keys(LeadStatus::PALETTE))->first(fn (string $key): bool => ! in_array($key, $used, true)) ?? 'teal';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $stageId): void
    {
        $stage = LeadStatus::query()->findOrFail($stageId);
        $this->authorize('update', $stage);

        $this->editing = $stageId;
        $this->name = $stage->name;
        $this->color = $stage->paletteKey() ?? '';
        $this->after = null;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(ChangePipelineStages $stages): void
    {
        $stage = $this->editing !== null ? LeadStatus::query()->findOrFail($this->editing) : null;

        if ($stage !== null) {
            $this->authorize('update', $stage);
        } else {
            $this->authorize('create', LeadStatus::class);
        }

        $this->validate([
            'name' => ['required', 'string', 'max:'.ChangePipelineStages::NAME_MAX],
            'color' => [$stage !== null ? 'nullable' : 'required', 'in:'.implode(',', array_keys(LeadStatus::PALETTE))],
            'after' => [$stage !== null ? 'nullable' : 'required', 'integer'],
        ], ['after.required' => 'Choose where the stage goes.', 'color.required' => 'Choose a colour.'], ['after' => 'place']);

        if ($stage !== null) {
            $saved = $stages->update($stage, $this->name, $this->color !== '' ? $this->color : null, $this->user());
            $this->showForm = false;
            $this->toastSuccess('Stage saved', $saved->name.' is updated on every screen of '.$this->brand->name.'.');

            return;
        }

        $after = LeadStatus::query()->find((int) $this->after);

        if ($after === null || ! $after->isOpen()) {
            $this->addError('after', 'Choose where the stage goes.');

            return;
        }

        $saved = $stages->add($this->brand, $this->name, $this->color, $after, $this->user());
        $next = LeadStatus::query()->ordered()->where('sort', '>', $saved->sort)->first();

        $this->showForm = false;
        $this->reset('name', 'after');
        $this->toastSuccess(
            'Stage added',
            $saved->name.' now sits '.($next !== null ? 'between '.$after->name.' and '.$next->name : 'after '.$after->name).' on the Pipeline and every lead of '.$this->brand->name.'.',
        );
    }

    public function move(int $stageId, string $direction, ChangePipelineStages $stages): void
    {
        $stage = LeadStatus::query()->findOrFail($stageId);
        $this->authorize('update', $stage);

        if (! in_array($direction, ['up', 'down'], true)) {
            return;
        }

        $stages->move($stage, $direction, $this->user());
    }

    public function confirmRemove(int $stageId): void
    {
        $stage = LeadStatus::query()->findOrFail($stageId);
        $this->authorize('delete', $stage);

        $held = LeadStatus::leadCountsFor((int) $this->brand->getKey())[$stage->getKey()] ?? ['total' => 0, 'bin' => 0];

        if ($held['total'] > 0) {
            $this->toastWarning('This stage has leads', StageInUse::holding($stage->name, $held['total'], $held['bin'])->getMessage());

            return;
        }

        $this->askConfirmation('remove', [$stageId], 'Remove '.$stage->name.'?', 'It leaves the pipeline of '.$this->brand->name.'. No lead is in it, so nothing else changes. You can add it again later.', 'Remove stage', 'danger');
    }

    public function remove(int $stageId, ChangePipelineStages $stages): void
    {
        $stage = LeadStatus::query()->find($stageId);

        if ($stage === null) {
            return;
        }

        $this->authorize('delete', $stage);

        try {
            $stages->remove($stage, $this->user());
        } catch (StageInUse $exception) {
            $this->toastWarning('This stage has leads', $exception->getMessage());

            return;
        }

        $this->toastSuccess('Stage removed', $stage->name.' is no longer in the pipeline of '.$this->brand->name.'.');
    }

    public function render(): View
    {
        $stages = $this->stages();
        $middle = $stages->reject(fn (LeadStatus $status): bool => $status->isFixed())->values();

        return view('leads::livewire.app.pipeline-stages', [
            'stages' => $stages,
            'counts' => LeadStatus::leadCountsFor((int) $this->brand->getKey()),
            'firstMiddle' => $middle->first()?->getKey(),
            'lastMiddle' => $middle->last()?->getKey(),
            'openStages' => $stages->filter(fn (LeadStatus $status): bool => $status->isOpen())->values(),
            'palette' => LeadStatus::PALETTE,
            'editingStage' => $this->editing !== null ? $stages->firstWhere('id', $this->editing) : null,
        ]);
    }

    /** @return Collection<int, LeadStatus> */
    private function stages(): Collection
    {
        return LeadStatus::query()->ordered()->get();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
