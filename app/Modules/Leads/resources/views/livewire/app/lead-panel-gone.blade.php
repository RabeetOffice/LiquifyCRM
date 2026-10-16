<div class="lq-lead-gone">
    @if ($binUrl ?? null)
        <x-ui.empty title="This lead is in the bin" description="It was deleted. You can bring it back, with its notes, follow-ups and history, from the Bin." icon="heroicon-o-trash">
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="heroicon-o-arrow-uturn-left" :href="$binUrl">Open the Bin</x-ui.button>
            </x-slot:actions>
        </x-ui.empty>
    @else
        <x-ui.empty title="This lead is no longer here" description="It was deleted, or joined to another lead of the same person. An Admin can restore a deleted lead from the Bin." icon="heroicon-o-archive-box" />
    @endif
</div>
