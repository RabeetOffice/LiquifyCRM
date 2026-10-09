<div class="flex flex-col gap-6">
    <x-ui.page-header title="Sales dashboard" subtitle="Revenue, sales and targets for each brand." />

    <x-ui.card>
        <x-ui.empty title="Add your first brand" description="The sales dashboard shows one brand at a time: revenue, targets, the leaderboard and where its leads came from." icon="heroicon-o-building-storefront">
            <x-slot:actions>
                <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('admin.brands.create')">Add brand</x-ui.button>
            </x-slot:actions>
        </x-ui.empty>
    </x-ui.card>
</div>
