<div class="flex flex-col gap-5">
    <nav class="lq-crumbbar" aria-label="Breadcrumb">
        <x-ui.button size="sm" variant="ghost" icon="heroicon-o-arrow-left" :href="route('brand.departments.index', ['brand' => $brand->slug])">Departments</x-ui.button>
        <span class="lq-muted" aria-hidden="true">/</span>
        <span class="truncate">{{ $title }}</span>
    </nav>

    <header class="lq-lead-hero">
        <span class="lq-site-icon lq-site-icon-xl" @if ($color) style="background: color-mix(in srgb, {{ $color }} 16%, transparent); color: {{ $color }}" @endif><x-heroicon-o-rectangle-group /></span>
        <div class="min-w-0 flex-1">
            <h1 class="lq-lead-name lq-lead-name-page">{{ $title }}</h1>
            <div class="lq-lead-meta">
                @if ($department)
                    <span>{{ $people->count() }} {{ str('person')->plural($people->count()) }}</span>
                    <span>· Leads, sales and targets are counted per department</span>
                @else
                    <span>A team inside {{ $brand->name }}. Add its people after creating it.</span>
                @endif
            </div>
        </div>
    </header>

    <div @class(['lq-split' => $department !== null, 'lq-narrow' => $department === null])>
        {{-- Who is in it: the wide side, because this is where the work is. --}}
        @if ($department)
            <div class="lq-split-main">
                <x-ui.card title="People" description="Who leads and who works in this department." flush>
                    @if ($people->isEmpty())
                        <x-ui.empty title="Nobody in this department yet" description="Add a Manager to lead it, then the Agents who work the leads." icon="heroicon-o-user-group" />
                    @else
                        <ul class="lq-linklist">
                            @foreach ($people as $person)
                                @php
                                    // The pivot gives the role as an enum; older rows may still carry the plain word.
                                    $held = $person->pivot->role instanceof \App\Modules\Core\Enums\DepartmentRole ? $person->pivot->role : \App\Modules\Core\Enums\DepartmentRole::from((string) $person->pivot->role);
                                @endphp
                                <li class="lq-linkrow" wire:key="person-{{ $person->getKey() }}">
                                    <span class="lq-person min-w-0 flex-1">
                                        <x-ui.avatar :name="$person->name" :src="$person->avatarUrl()" />
                                        <span class="min-w-0">
                                            <span class="lq-cell-title">{{ $person->name }}</span>
                                            <span class="lq-cell-sub">{{ $person->email }}</span>
                                        </span>
                                    </span>
                                    @if ($canManageMembers)
                                        <select class="lq-input lq-input-sm" style="width: 8.5rem" aria-label="Role of {{ $person->name }}" wire:change="changeRole({{ $person->getKey() }}, $event.target.value)">
                                            @foreach ($roles as $role)
                                                <option value="{{ $role->value }}" @selected($held === $role)>{{ $role->getLabel() }}</option>
                                            @endforeach
                                        </select>
                                        <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmRemoveMember({{ $person->getKey() }})" title="Remove from department" aria-label="Remove {{ $person->name }} from the department"><x-heroicon-o-x-mark /></button>
                                    @else
                                        <x-ui.badge :color="$held->getColor()">{{ $held->getLabel() }}</x-ui.badge>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($canManageMembers)
                        <form wire:submit="addMember" class="lq-addrow">
                            <x-ui.field label="Add person" name="newMemberId" class="lq-addrow-main">
                                <x-ui.select wire:model="newMemberId" name="newMemberId" placeholder="Choose a person…">
                                    @foreach ($candidates as $candidate)
                                        <option value="{{ $candidate->getKey() }}">{{ $candidate->name }} ({{ $candidate->email }})</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>
                            <x-ui.field label="Role" name="newMemberRole">
                                <x-ui.select wire:model="newMemberRole" name="newMemberRole">
                                    @foreach ($roles as $role)
                                        <option value="{{ $role->value }}">{{ $role->getLabel() }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>
                            <x-ui.button type="submit" variant="secondary" icon="heroicon-o-user-plus">Add</x-ui.button>
                            <p class="lq-help lq-addrow-help">Only members of this brand are listed. New people are added under <a class="lq-link" href="{{ route('brand.members.index', ['brand' => $brand->slug]) }}" wire:navigate>Members</a>.</p>
                        </form>
                    @endif
                </x-ui.card>
            </div>
        @endif

        <form wire:submit="save" @class(['lq-split-side' => $department !== null])>
            <x-ui.card title="Details" description="The name and colour people see in lists and charts.">
                <div class="lq-form">
                    <x-ui.field label="Name" name="name" required>
                        <x-ui.input wire:model="name" name="name" placeholder="Sales Team A" required :autofocus="$department === null" />
                    </x-ui.field>
                    <x-ui.field label="Colour" name="color" help="Used for the department chip and chart line.">
                        <div class="lq-swatch">
                            <input type="color" wire:model.live="color" aria-label="Pick a colour">
                            <input type="text" class="lq-swatch-text" wire:model.blur="color" name="color" maxlength="7" placeholder="#FF6B2C">
                        </div>
                    </x-ui.field>
                    <x-ui.field label="What this team does" name="description">
                        <x-ui.textarea wire:model="description" name="description" rows="3" />
                    </x-ui.field>
                </div>

                <x-slot:footer>
                    @unless ($department)
                        <x-ui.button variant="ghost" :href="route('brand.departments.index', ['brand' => $brand->slug])">Cancel</x-ui.button>
                    @endunless
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">{{ $department ? 'Save changes' : 'Create department' }}</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        </form>
    </div>

    <x-ui.confirm :confirm="$confirm" />
</div>
