{{-- The lead slide-over of the list and the pipeline. Escape closes it, unless a dialog on top of it is open. --}}
@if ($previewLead)
    <div
        class="lq-sheet-backdrop"
        x-data
        x-on:keydown.escape.window="[...document.querySelectorAll('.lq-modal-backdrop')].some((dialog) => getComputedStyle(dialog).display !== 'none') || $wire.closeLead()"
        x-on:click.self="$wire.closeLead()"
    >
        <div class="lq-sheet lq-sheet-wide" role="dialog" aria-modal="true" aria-label="{{ $previewLead->name }}">
            <header class="lq-sheet-bar">
                <span class="lq-sheet-bar-label"><x-heroicon-o-identification /> Lead</span>
                <x-ui.button size="sm" variant="ghost" class="ml-auto" icon="heroicon-o-arrow-top-right-on-square" :href="route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $previewLead->getKey()])">Open full page</x-ui.button>
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="closeLead" title="Close (Esc)"><x-heroicon-o-x-mark class="h-5 w-5" /></button>
            </header>
            <div class="lq-modal-body">
                @livewire(\App\Modules\Leads\Livewire\App\LeadPanel::class, ['leadId' => $previewLead->getKey()], key('lead-panel-'.$previewLead->getKey()))
            </div>
        </div>
    </div>
@endif
