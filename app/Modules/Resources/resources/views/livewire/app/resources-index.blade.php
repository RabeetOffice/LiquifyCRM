<div class="flex flex-col gap-6" data-live-refresh>
    <x-ui.page-header title="Resources" :subtitle="'Templates and material of '.$brand->name.' for everyone in the team: open one, or download it and use it.'">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button variant="primary" icon="heroicon-o-plus" wire:click="create">Add resource</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.card flush class="lq-list">
        <div class="lq-viewbar">
            <div class="lq-views" role="tablist" aria-label="Categories">
                <button type="button" @class(['lq-view', 'lq-view-active' => $category === 'all']) wire:click="$set('category', 'all')" role="tab">All @if ($total > 0)<span class="lq-view-count">{{ $total }}</span>@endif</button>
                @foreach ($views as $key => $view)
                    <button type="button" @class(['lq-view', 'lq-view-active' => $category === $key]) wire:click="$set('category', '{{ $key }}')" role="tab">{{ $view['label'] }} <span class="lq-view-count">{{ $view['count'] }}</span></button>
                @endforeach
            </div>
            <div class="lq-viewbar-end">
                <label class="lq-search">
                    <x-heroicon-o-magnifying-glass />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search resources" aria-label="Search resources">
                </label>
            </div>
        </div>

        @if ($groups->isEmpty())
            <x-ui.empty
                :title="$search !== '' ? 'Nothing matches' : 'No resources yet'"
                :description="$search !== '' ? 'Try another word.' : ($canManage ? 'Add the NDA template, the letterhead, the price list, scripts and anything else the team uses, and everyone here can download them.' : 'Your Admin or Manager adds templates and material here for the team.')"
                :icon="$search !== '' ? 'heroicon-o-magnifying-glass' : 'heroicon-o-folder-open'">
                @if ($canManage && $search === '')
                    <x-slot:actions><x-ui.button variant="secondary" icon="heroicon-o-plus" wire:click="create">Add the first one</x-ui.button></x-slot:actions>
                @endif
            </x-ui.empty>
        @else
            <div class="lq-resource-groups">
                @foreach ($groups as $group)
                    <section class="lq-resource-group" wire:key="group-{{ $group['key'] }}">
                        <h2 class="lq-resource-heading">{{ $group['label'] }}</h2>
                        <ul class="lq-files">
                            @foreach ($group['items'] as $resource)
                                @php
                                    $file = $resource->file;
                                    $href = $resource->isLink() ? $resource->url : ($file ? route('brand.resources.file', ['brand' => $brand->slug, 'resource' => $resource->getKey()]) : null);
                                    $opens = $resource->isLink() || ($file?->opensInBrowser() ?? false);
                                @endphp
                                <li class="lq-file" wire:key="resource-{{ $resource->getKey() }}">
                                    <a class="lq-file-main" @if ($href) href="{{ $href }}{{ ! $resource->isLink() && $opens ? '?open=1' : '' }}" @endif @if ($opens) target="_blank" rel="noopener noreferrer" @endif title="{{ $resource->isLink() ? 'Open the link' : ($opens ? 'Open' : 'Download') }}">
                                        <span @class(['lq-file-icon', 'lq-file-icon-'.$resource->category])>@svg($resource->isLink() ? 'heroicon-o-link' : ($file?->icon() ?? 'heroicon-o-document'))</span>
                                        <span class="min-w-0">
                                            <span class="lq-file-name">{{ $resource->title }}</span>
                                            <span class="lq-file-meta">
                                                @if ($resource->description){{ $resource->description }} · @endif
                                                {{ $resource->isLink() ? str($resource->url)->after('://')->before('/') : ($file ? strtoupper($file->extension() ?: 'file').' · '.$file->humanSize() : 'No file') }}
                                                · <span title="{{ $resource->updated_at?->format('j M Y, H:i') }}">{{ $resource->updated_at?->diffForHumans() }}</span>
                                            </span>
                                        </span>
                                    </a>
                                    <div class="lq-row-tools">
                                        @if ($resource->isLink())
                                            <a class="lq-btn lq-btn-ghost lq-btn-icon" href="{{ $resource->url }}" target="_blank" rel="noopener noreferrer" title="Open the link" aria-label="Open {{ $resource->title }}"><x-heroicon-o-arrow-top-right-on-square /></a>
                                        @elseif ($file)
                                            <a class="lq-btn lq-btn-ghost lq-btn-icon" href="{{ $href }}" title="Download" aria-label="Download {{ $resource->title }}"><x-heroicon-o-arrow-down-tray /></a>
                                        @endif
                                        @if ($canManage)
                                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="edit({{ $resource->getKey() }})" title="Change" aria-label="Change {{ $resource->title }}"><x-heroicon-o-pencil-square /></button>
                                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmRemove({{ $resource->getKey() }})" title="Remove" aria-label="Remove {{ $resource->title }}"><x-heroicon-o-trash /></button>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    @if ($canManage)
        <x-ui.modal model="showForm" :title="$editing ? 'Change resource' : 'Add a resource'" description="A file everyone in the brand can download, or a link (a Google Drive folder, a Canva design)." size="sm">
            <form wire:submit="save" class="lq-form" id="resource-form">
                <x-ui.field label="Name" name="title" required>
                    <x-ui.input wire:model="title" name="title" placeholder="NDA template" />
                </x-ui.field>
                <x-ui.field label="Category" name="kind" required>
                    <x-ui.select wire:model="kind" name="kind">
                        @foreach ($categories as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="What it is for" name="description" help="Optional, one line.">
                    <x-ui.input wire:model="description" name="description" placeholder="Send it before the first call" />
                </x-ui.field>
                <x-ui.field :label="$editing ? 'New file' : 'File'" name="upload" :help="'PDF, Word, Excel, PowerPoint, pictures, zip… up to '.$maxMb.' MB.'.($editing ? ' Leave it empty to keep the file it has.' : '')">
                    <input type="file" class="lq-input lq-input-file" wire:model="upload" name="upload" id="upload">
                    <span class="lq-help" wire:loading wire:target="upload">Uploading…</span>
                </x-ui.field>
                <x-ui.field label="Or a link" name="url" help="Instead of a file: a Google Drive, Dropbox or Canva address.">
                    <x-ui.input type="url" wire:model="url" name="url" placeholder="https://" />
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="$set('showForm', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="resource-form" variant="primary" wire:loading.attr="disabled" wire:target="save, upload">{{ $editing ? 'Save' : 'Add resource' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
