@php
    use App\Modules\Sales\Support\Money;

    // The flat rows become blocks: the brand on its own, then each department with its people.
    $groups = [];

    foreach ($rows as $row) {
        if ($row['level'] < 2) {
            $groups[] = ['head' => $row, 'people' => []];
        } elseif ($groups !== []) {
            $groups[array_key_last($groups)]['people'][] = $row;
        }
    }

    $people = collect($rows)->where('level', 2);
    $withTarget = $people->filter(fn (array $row): bool => $row['progress']->hasTarget())->count();
    $brandRow = collect($rows)->firstWhere('level', 0);

    $left = (int) now()->startOfDay()->diffInDays($month->endOfMonth()->startOfDay());

    $daysLeft = match (true) {
        $month->isSameMonth(now()) => $left === 0 ? 'Last day' : $left.' '.str('day')->plural($left).' left',
        $month->isPast() => 'Month closed',
        default => 'Not started yet',
    };
@endphp
<div class="flex flex-col gap-6">
    <x-ui.page-header title="Targets" subtitle="Monthly goals for the brand, each department and each person.">
        <x-slot:actions>
            <div class="lq-month lq-month-pill">
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="shift(-1)" title="Previous month"><x-heroicon-o-chevron-left class="h-5 w-5" /></button>
                <span class="lq-month-label">{{ $month->format('F Y') }}</span>
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="shift(1)" title="Next month"><x-heroicon-o-chevron-right class="h-5 w-5" /></button>
            </div>
            @if ($canEdit)
                <x-ui.button variant="secondary" icon="heroicon-o-document-duplicate" wire:click="copyLastMonth" wire:loading.attr="disabled" wire:target="copyLastMonth">Copy last month</x-ui.button>
                <x-ui.button variant="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">Save targets</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($rows === [])
        <x-ui.card>
            <x-ui.empty title="No departments yet" description="Create a department and add its people; then set their targets here." icon="heroicon-o-flag">
                <x-slot:actions>
                    <x-ui.button variant="secondary" :href="route('brand.departments.index', ['brand' => $brand->slug])">Open Departments</x-ui.button>
                </x-slot:actions>
            </x-ui.empty>
        </x-ui.card>
    @else
        <div class="lq-minis">
            @if ($brandRow)
                <x-ui.mini label="Revenue this month" :value="Money::format($brandRow['progress']->revenue, $brand->currency)" icon="heroicon-o-banknotes" :hint="$brandRow['progress']->hasTarget() ? $brandRow['progress']->label() : 'No brand target set'" />
                <x-ui.mini label="Sales closed" :value="$brandRow['progress']->count" icon="heroicon-o-trophy" />
            @endif
            <x-ui.mini label="People with a target" :value="$withTarget.' of '.$people->count()" icon="heroicon-o-flag" :tone="$people->count() > 0 && $withTarget === $people->count() ? 'ok' : null" />
            <x-ui.mini label="{{ $month->format('F') }}" :value="$daysLeft" icon="heroicon-o-calendar-days" />
        </div>

        <form wire:submit="save" class="flex flex-col gap-5">
            {{-- What each column is: the two fields are the month's targets, the bar is how it is going. --}}
            <div class="lq-thead" aria-hidden="true">
                <span>Brand, team or person</span>
                <span class="lq-thead-fields">
                    <span class="lq-trow-value">Value of sales ({{ $brand->currency }})</span>
                    <span class="lq-trow-count">Number of sales</span>
                </span>
                <span>{{ $month->format('F') }} so far</span>
            </div>

            @foreach ($groups as $group)
                <section class="lq-tgroup" wire:key="group-{{ $group['head']['key'] }}">
                    @include('sales::livewire.app.partials.target-row', ['row' => $group['head']])

                    @foreach ($group['people'] as $row)
                        @include('sales::livewire.app.partials.target-row', ['row' => $row])
                    @endforeach

                    @if ($group['head']['level'] === 1 && $group['people'] === [])
                        <p class="lq-tgroup-empty">Nobody in this department yet. <a class="lq-link" href="{{ route('brand.members.index', ['brand' => $brand->slug]) }}" wire:navigate>Add people</a></p>
                    @endif
                </section>
            @endforeach

            @error('values.*')
                <p class="lq-error">{{ $message }}</p>
            @enderror

            {{-- A reminder at the bottom while typed targets are not saved yet. --}}
            @if ($canEdit)
                <div class="lq-floatbar-hold" wire:dirty wire:target="values">
                    <div class="lq-floatbar lq-floatbar-quiet">
                        <span class="lq-floatbar-count">Targets changed, not saved yet</span>
                        <span class="lq-floatbar-sep" aria-hidden="true"></span>
                        <button type="submit" class="lq-floatbar-btn lq-floatbar-go"><x-heroicon-m-check /> Save targets</button>
                    </div>
                </div>
            @endif
        </form>

        <div class="lq-help lq-thelp">
            <p><strong>Value of sales</strong> is how much the person (or team, or brand) should sell this month, in {{ $brand->currency }}: the total of the deals they mark as Won and the upsells they record. Sales made in another currency count in {{ $brand->currency }} at that day's rate.</p>
            <p><strong>Number of sales</strong> is optional: fill it in only if you also count how many deals are closed. When both are filled, the bar follows the value.</p>
            <p>Progress moves by itself as sales are recorded. A team or the brand without its own target is measured against what its people's targets add up to. Above 100% the bar becomes an Over-achieved badge.</p>
        </div>
    @endif
</div>
