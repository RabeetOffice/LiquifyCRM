<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$lead->name" :breadcrumbs="['Leads' => route('brand.leads.index', ['brand' => $brand->slug]), $lead->name => null]" />

    <x-ui.card>
        @livewire(\App\Modules\Leads\Livewire\App\LeadPanel::class, ['leadId' => $lead->getKey()], key('lead-panel-page-'.$lead->getKey()))
    </x-ui.card>
</div>
