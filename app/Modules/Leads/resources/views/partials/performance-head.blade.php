{{-- The head of every Performance screen: title, the month picker (when the screen has one) and links between the screens.
     Needs $brand, $title, $subtitle, $current (the screen's route name); $months and $month for the picker. --}}
<x-ui.page-header :title="$title" :subtitle="$subtitle">
    @if (! empty($months))
        <x-slot:actions>
            <label class="lq-sortpick" title="Choose the month">
                <x-heroicon-o-calendar-days />
                <select wire:model.live="month" aria-label="Month">
                    @foreach ($months as $key => $label)
                        <option value="{{ $key }}" @selected($key === $month)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </x-slot:actions>
    @endif
</x-ui.page-header>

<nav class="lq-views lq-perf-tabs" aria-label="Performance screens">
    @foreach (\App\Modules\Leads\Livewire\App\LeadsTaken::screens() as $route => $label)
        <a href="{{ route($route, ['brand' => $brand->slug]) }}" @class(['lq-view', 'lq-view-active' => $route === $current]) wire:navigate @if ($route === $current) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
