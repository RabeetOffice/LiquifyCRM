@php
    $params = ['brand' => $brand->slug];
    $own = $stages->reject(fn ($stage) => $stage->isFixed())->count();
    $inPipeline = collect($counts)->sum(fn ($count) => $count['total'] - $count['bin']);
@endphp
<div class="flex flex-col gap-6" data-live-refresh>
    <x-ui.page-header
        title="Pipeline stages"
        :subtitle="'The steps a lead goes through in '.$brand->name.'. Every screen of this brand follows this list: the Pipeline, each lead\'s steps, the filters and the charts.'"
        :breadcrumbs="['Pipeline' => route('brand.leads.pipeline', $params), 'Pipeline stages' => null]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="heroicon-o-view-columns" :href="route('brand.leads.pipeline', $params)">Open Pipeline</x-ui.button>
            <x-ui.button variant="primary" icon="heroicon-o-plus" wire:click="create">Add stage</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="lq-minis">
        <x-ui.mini label="Stages" :value="$stages->count()" icon="heroicon-o-queue-list" />
        <x-ui.mini label="Between New and Won" :value="$own" icon="heroicon-o-arrows-right-left" />
        <x-ui.mini label="Leads in the pipeline" :value="number_format($inPipeline)" icon="heroicon-o-inbox-arrow-down" />
    </div>

    <x-ui.card flush>
        <ol class="lq-stages" aria-label="Stages of {{ $brand->name }}, in order">
            @foreach ($stages as $stage)
                @php
                    $count = $counts[$stage->getKey()] ?? ['total' => 0, 'bin' => 0];
                    $live = $count['total'] - $count['bin'];
                    $next = $stages->get($loop->index + 1);
                    $meaning = match (true) {
                        $stage->is_default => 'New leads start here',
                        $stage->is_won => 'A recorded sale moves a lead here',
                        $stage->is_lost => 'Closed without a sale',
                        default => null,
                    };
                @endphp
                <li class="lq-stage" wire:key="stage-{{ $stage->getKey() }}" style="--pill-color: {{ $stage->color }}; --pill-tint: {{ $stage->tint }}">
                    <span class="lq-stage-step" aria-hidden="true">{{ $loop->iteration }}</span>
                    <div class="lq-stage-main">
                        <span class="lq-stage-title">
                            <x-ui.status-pill :status="$stage" />
                            @if ($stage->isFixed())
                                <span class="lq-stage-lock" title="Always in the pipeline: you can rename it or change its colour"><x-heroicon-m-lock-closed /> <span class="sr-only">Always in the pipeline</span></span>
                            @endif
                        </span>
                        <span class="lq-stage-meta">
                            @if ($meaning){{ $meaning }} · @endif
                            {{ number_format($live) }} {{ str('lead')->plural($live) }}
                            @if ($count['bin'] > 0) · {{ $count['bin'] }} in the Bin @endif
                        </span>
                    </div>
                    <div class="lq-row-tools">
                        @unless ($stage->isFixed())
                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="move({{ $stage->getKey() }}, 'up')" title="Move up" aria-label="Move {{ $stage->name }} up" @disabled($stage->getKey() === $firstMiddle)><x-heroicon-o-arrow-up /></button>
                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="move({{ $stage->getKey() }}, 'down')" title="Move down" aria-label="Move {{ $stage->name }} down" @disabled($stage->getKey() === $lastMiddle)><x-heroicon-o-arrow-down /></button>
                        @endunless
                        <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="edit({{ $stage->getKey() }})" title="Rename or change the colour" aria-label="Change {{ $stage->name }}"><x-heroicon-o-pencil-square /></button>
                        @unless ($stage->isFixed())
                            <button
                                type="button"
                                @class(['lq-btn lq-btn-ghost lq-btn-icon', 'lq-btn-icon-danger' => $count['total'] === 0, 'lq-stage-held' => $count['total'] > 0])
                                wire:click="confirmRemove({{ $stage->getKey() }})"
                                title="{{ $count['total'] > 0 ? $count['total'].' '.str('lead')->plural($count['total']).' in this stage: move them first' : 'Remove' }}"
                                aria-label="Remove {{ $stage->name }}"
                            ><x-heroicon-o-trash /></button>
                        @endunless
                    </div>
                </li>
                @if ($stage->isOpen() && $next !== null)
                    <li class="lq-stage-gap" wire:key="gap-{{ $stage->getKey() }}">
                        <button type="button" class="lq-stage-insert" wire:click="create({{ $stage->getKey() }})">
                            <x-heroicon-m-plus /> Add a stage between {{ $stage->name }} and {{ $next->name }}
                        </button>
                    </li>
                @endif
            @endforeach
        </ol>
    </x-ui.card>

    <p class="lq-help">A stage with leads in it cannot be removed: move its leads to another stage first (leads in the Bin count too). New, Won and Lost always stay, because new leads, sales and closed leads depend on them.</p>

    <x-ui.modal model="showForm" :title="$editing ? 'Change stage' : 'Add a stage'" :description="$editing ? 'The new name and colour show on every screen of '.$brand->name.' at once.' : 'Only for '.$brand->name.'. It shows on the Pipeline and on every lead at once.'" size="sm">
        <form wire:submit="save" class="lq-form" id="stage-form" x-data="{ palette: @js($palette) }">
            <x-ui.field label="Name" name="name" required>
                <x-ui.input wire:model="name" name="name" id="name" :maxlength="\App\Modules\Leads\Actions\ChangePipelineStages::NAME_MAX" placeholder="Connected" autocomplete="off" />
            </x-ui.field>

            @unless ($editing)
                <x-ui.field label="Where it goes" name="after" required>
                    <x-ui.select wire:model="after" name="after" id="after">
                        @foreach ($openStages as $option)
                            @php $following = $stages->get($stages->search(fn ($stage) => $stage->is($option)) + 1); @endphp
                            <option value="{{ $option->getKey() }}">After {{ $option->name }}@if ($following) (before {{ $following->name }})@endif</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endunless

            <x-ui.field label="Colour" name="color" :required="! $editing">
                <div class="lq-stage-swatches" role="radiogroup" aria-label="Colour">
                    @foreach ($palette as $key => $swatch)
                        <label class="lq-stage-swatch" style="--swatch: {{ $swatch['color'] }}" title="{{ $swatch['label'] }}" wire:key="swatch-{{ $key }}">
                            <input type="radio" wire:model="color" name="color" value="{{ $key }}">
                            <span class="lq-stage-swatch-dot" aria-hidden="true"></span>
                            <span class="sr-only">{{ $swatch['label'] }}</span>
                        </label>
                    @endforeach
                </div>
            </x-ui.field>

            <div class="lq-stage-preview" aria-hidden="true">
                <span class="lq-help">How it looks</span>
                <span
                    class="lq-status-pill"
                    :style="palette[$wire.color] ? '--pill-color:' + palette[$wire.color].color + ';--pill-tint:' + palette[$wire.color].tint : '--pill-color: {{ $editingStage?->color ?? '#57534E' }}; --pill-tint: {{ $editingStage?->tint ?? '#F5F5F4' }}'"
                    x-text="($wire.name || '').trim() || 'New stage'"
                ></span>
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showForm', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="stage-form" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ $editing ? 'Save' : 'Add stage' }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.confirm :confirm="$confirm" />
</div>
