<div class="flex flex-col gap-5" data-live-refresh>
    <nav class="lq-crumbbar" aria-label="Breadcrumb">
        <x-ui.button size="sm" variant="ghost" icon="heroicon-o-arrow-left" :href="route('brand.leads.index', ['brand' => $brand->slug])">Leads</x-ui.button>
        <span class="lq-muted" aria-hidden="true">/</span>
        <span class="truncate">{{ $lead->name }}</span>
    </nav>

    @livewire(\App\Modules\Leads\Livewire\App\LeadPanel::class, ['leadId' => $lead->getKey(), 'page' => true], key('lead-panel-page-'.$lead->getKey()))
</div>
