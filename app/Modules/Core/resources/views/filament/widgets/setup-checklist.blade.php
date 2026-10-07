@php
    $checklist = $this->getChecklist();
    $percent = $checklist['total'] > 0 ? (int) round($checklist['done'] / $checklist['total'] * 100) : 0;
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Set up {{ $checklist['brand']->name }}</x-slot>
        <x-slot name="description">
            {{ $checklist['done'] }} of {{ $checklist['total'] }} steps done. Each one ticks itself when finished.
        </x-slot>

        <div class="mb-5 h-2 w-full overflow-hidden rounded-full" style="background: var(--lq-orange-tint)">
            <div class="h-full rounded-full transition-all duration-500" style="width: {{ $percent }}%; background: var(--lq-orange)"></div>
        </div>

        <ol class="grid gap-3 md:grid-cols-2">
            @foreach ($checklist['steps'] as $step)
                <li class="flex items-start gap-3 rounded-xl p-3" style="border: 1px solid var(--lq-hairline); {{ $step['soon'] ? 'opacity:.6' : '' }}">
                    <span class="mt-0.5 flex h-6 w-6 flex-none items-center justify-center rounded-full"
                          style="{{ $step['done'] ? 'background: var(--lq-orange); color: #1c1917' : 'background: var(--lq-orange-tint); color: var(--lq-orange-deep)' }}">
                        @if ($step['done'])
                            <x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />
                        @else
                            <span class="text-xs font-semibold lq-tabular">{{ $loop->iteration }}</span>
                        @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($step['url'] && ! $step['done'])
                                <a href="{{ $step['url'] }}" class="font-medium hover:underline" style="color: var(--lq-ink)">{{ $step['title'] }}</a>
                            @else
                                <span class="font-medium">{{ $step['title'] }}</span>
                            @endif
                            @if ($step['soon'])
                                <x-filament::badge color="gray" size="sm">Coming in {{ $step['soon'] }}</x-filament::badge>
                            @endif
                        </div>
                        <p class="mt-0.5 text-sm" style="color: var(--lq-muted)">{{ $step['description'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </x-filament::section>
</x-filament-widgets::widget>
