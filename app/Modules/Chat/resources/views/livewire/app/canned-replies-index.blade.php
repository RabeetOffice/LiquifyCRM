<div class="flex flex-col gap-6" data-live-refresh>
    <x-ui.page-header title="Saved replies" subtitle="The answers you type again and again. In the message box, type a slash and the first letters of its shortcut (like /price) and pick it.">
        @if ($canCreate)
            <x-slot:actions>
                <x-ui.button variant="primary" icon="heroicon-o-plus" wire:click="create">Add a reply</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($groups->isEmpty())
        <x-ui.card>
            <x-ui.empty title="No saved replies yet" description="Write the answers you give often, like the price list, how long a book takes, or how to send the manuscript. Then one slash and a few letters puts the whole answer in the box." icon="heroicon-o-bolt">
                @if ($canCreate)
                    <x-slot:actions><x-ui.button variant="secondary" icon="heroicon-o-plus" wire:click="create">Add the first one</x-ui.button></x-slot:actions>
                @endif
            </x-ui.empty>
        </x-ui.card>
    @endif

    @foreach ($groups as $scope => $replies)
        <x-ui.card :title="$labels[$scope]" flush wire:key="scope-{{ $scope }}">
            <ul class="lq-files">
                @foreach ($replies as $reply)
                    <li class="lq-file" wire:key="reply-{{ $reply->getKey() }}">
                        <div class="lq-file-main">
                            <span class="lq-file-icon"><x-heroicon-o-bolt /></span>
                            <span class="min-w-0">
                                <span class="lq-file-name"><code class="lq-code">{{ $reply->shortcut }}</code> {{ $reply->title }}</span>
                                <span class="lq-file-meta whitespace-pre-line">{{ \Illuminate\Support\Str::limit($reply->body, 220) }}</span>
                            </span>
                        </div>
                        @if ($mayChange($reply))
                            <div class="lq-row-tools">
                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="edit({{ $reply->getKey() }})" title="Change" aria-label="Change {{ $reply->shortcut }}"><x-heroicon-o-pencil-square /></button>
                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmRemove({{ $reply->getKey() }})" title="Remove" aria-label="Remove {{ $reply->shortcut }}"><x-heroicon-o-trash /></button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endforeach

    @if ($canCreate)
        <x-ui.modal model="showForm" :title="$editing ? 'Change saved reply' : 'Add a saved reply'" description="Type the shortcut in the message box to put the words there. You can still change them before you send." size="sm">
            <form wire:submit="save" class="lq-form" id="saved-reply-form">
                @unless ($editing)
                    @if (count($scopes) > 1)
                        <x-ui.field label="Who can use it" name="scope">
                            <x-ui.select wire:model="scope" name="scope">
                                @foreach ($scopes as $option)
                                    <option value="{{ $option }}">{{ $scopeLabels[$option] }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    @endif
                @endunless
                <x-ui.field label="Shortcut" name="shortcut" required help="A short word after a slash, like /price or /manuscript.">
                    <x-ui.input wire:model="shortcut" name="shortcut" placeholder="/price" />
                </x-ui.field>
                <x-ui.field label="Name" name="title" required help="What it is, so you find it in the list.">
                    <x-ui.input wire:model="title" name="title" placeholder="Price list" />
                </x-ui.field>
                <x-ui.field label="The reply" name="body" required>
                    <x-ui.textarea wire:model="body" name="body" rows="5" placeholder="Hi {visitor_name}, thank you for writing to {brand_name}."></x-ui.textarea>
                    <span class="lq-help">
                        You can use
                        @foreach ($variables as $variable => $means)
                            <code class="lq-code">{{ $variable }}</code> ({{ $means }}){{ $loop->last ? '.' : ',' }}
                        @endforeach
                    </span>
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="$set('showForm', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="saved-reply-form" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ $editing ? 'Save' : 'Add reply' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
