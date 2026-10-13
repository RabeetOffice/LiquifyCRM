{{-- The lead slide-over of the list and the pipeline. It is always in the page: a click shows it at once
     (`$wire.open` is set in the browser first) and the lead fills in when the answer arrives.
     Escape closes it, unless a dialog on top of it is open. --}}
@php $sheetLeadId = $previewLead?->getKey(); @endphp
<div
    class="lq-sheet-backdrop"
    x-data
    x-show="$wire.open !== null"
    x-cloak
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    x-on:keydown.escape.window="$wire.open !== null && ! [...document.querySelectorAll('.lq-modal-backdrop, .lq-assign')].some((dialog) => getComputedStyle(dialog).display !== 'none') && ($wire.open = null, $wire.closeLead())"
    x-on:click.self="$wire.open = null; $wire.closeLead()"
>
    <div class="lq-sheet lq-sheet-wide" role="dialog" aria-modal="true" aria-label="{{ $previewLead?->name ?? 'Lead' }}">
        <header class="lq-sheet-bar">
            <span class="lq-sheet-bar-label"><x-heroicon-o-identification /> Lead</span>
            <span class="ml-auto"></span>
            {{-- Always in its place, and its address follows the lead that was clicked: it does not appear a moment
                 after the lead does, and never leads to the lead that was open before. --}}
            <a class="lq-btn lq-btn-ghost lq-btn-sm" title="Open this lead on its own page"
                x-bind:href="$wire.open === null ? '#' : @js(route('brand.leads.index', ['brand' => $brand->slug])) + '/' + $wire.open"
                x-on:click="if (! ($event.ctrlKey || $event.metaKey || $event.shiftKey)) { $event.preventDefault(); if ($wire.open !== null) window.Livewire.navigate($el.href) }">
                <x-heroicon-o-arrow-top-right-on-square /> Open full page
            </a>
            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" x-on:click="$wire.open = null; $wire.closeLead()" title="Close (Esc)"><x-heroicon-o-x-mark class="h-5 w-5" /></button>
        </header>
        <div class="lq-modal-body">
            @if ($previewLead)
                {{-- Hidden the moment another lead is clicked, so the old one is never shown under the new name. --}}
                <div wire:key="sheet-lead-{{ $sheetLeadId }}" x-show="$wire.open === {{ $sheetLeadId }}">
                    @livewire(\App\Modules\Leads\Livewire\App\LeadPanel::class, ['leadId' => $sheetLeadId], key('lead-panel-'.$sheetLeadId))
                </div>
            @endif

            <div class="lq-sheet-wait" wire:key="sheet-wait-{{ $sheetLeadId ?? 'none' }}" x-show="$wire.open !== {{ $sheetLeadId ?? 'null' }}" aria-hidden="true">
                <div class="lq-sheet-wait-head">
                    <span class="lq-bone lq-bone-circle"></span>
                    <span class="flex-1">
                        <span class="lq-bone" style="width: 46%; height: 1.375rem"></span>
                        <span class="lq-bone" style="width: 68%; margin-top: 0.625rem"></span>
                    </span>
                </div>
                <span class="lq-bone" style="height: 2.75rem"></span>
                <span class="lq-bone" style="height: 5rem"></span>
                <span class="lq-bone" style="height: 9rem"></span>
                <span class="lq-bone" style="height: 11rem"></span>
            </div>
        </div>
    </div>
</div>
