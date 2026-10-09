{{-- Admins only: new code is on the server and waits to be applied. Hidden on the Updates screen itself. --}}
@php
    $updateFlag = auth()->user()?->isGlobalAdmin() && ! request()->routeIs('admin.updates')
        ? \App\Modules\System\Support\SystemUpdater::flag()
        : null;
@endphp
@if ($updateFlag !== null && $updateFlag['available'])
    <div class="lq-updatebar" role="status">
        <x-heroicon-o-arrow-up-circle class="lq-updatebar-icon" />
        <span class="min-w-0 flex-1">
            <strong>Version {{ $updateFlag['version'] }} is ready.</strong>
            @if ($updateFlag['pending'] > 0)
                {{ $updateFlag['pending'] }} database {{ str('change')->plural($updateFlag['pending']) }} wait to be applied; some screens may not work until then.
            @else
                See what changed and apply it.
            @endif
        </span>
        <a href="{{ route('admin.updates') }}" class="lq-btn lq-btn-primary lq-btn-sm" wire:navigate>Update system</a>
    </div>
@endif
