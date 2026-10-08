<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$title" :breadcrumbs="['Departments' => route('brand.departments.index', ['brand' => $brand->slug]), $title => null]" />

    <div class="lq-grid-main">
        <form wire:submit="save">
            <x-ui.card title="Department" description="A team inside this brand. Leads, sales and targets are counted per department.">
                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Name" name="name" required>
                        <x-ui.input wire:model="name" name="name" placeholder="Sales Team A" required autofocus />
                    </x-ui.field>
                    <x-ui.field label="Colour" name="color" help="Used for the department chip and chart line.">
                        <div class="flex items-center gap-2">
                            <input type="color" wire:model.live="color" class="h-10 w-12 cursor-pointer rounded-lg border-0 bg-transparent p-0">
                            <x-ui.input wire:model="color" name="color" class="lq-tabular" />
                        </div>
                    </x-ui.field>
                    <x-ui.field label="What this team does" name="description" class="lq-col-full">
                        <x-ui.textarea wire:model="description" name="description" rows="3" />
                    </x-ui.field>
                </div>

                <x-slot:footer>
                    <x-ui.button variant="ghost" :href="route('brand.departments.index', ['brand' => $brand->slug])">Back</x-ui.button>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">{{ $department ? 'Save changes' : 'Create department' }}</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        </form>

        @if ($department)
            <x-ui.card title="People" description="Who leads and who works in this department." flush>
                @if ($people->isEmpty())
                    <x-ui.empty title="Nobody in this department yet" description="Add a Manager to lead it, then the Agents who work the leads." icon="heroicon-o-user-group" />
                @else
                    <ul class="flex flex-col">
                        @foreach ($people as $person)
                            <li class="flex items-center gap-3 px-6 py-3" style="border-top: 1px solid var(--lq-hairline)" wire:key="person-{{ $person->getKey() }}">
                                <x-ui.avatar :name="$person->name" :src="$person->avatarUrl()" size="sm" />
                                <div class="min-w-0 flex-1">
                                    <span class="lq-cell-title">{{ $person->name }}</span>
                                    <span class="lq-cell-sub">{{ $person->email }}</span>
                                </div>
                                @if ($canManageMembers)
                                    <select class="lq-input lq-input-sm" style="width: 8rem" wire:change="changeRole({{ $person->getKey() }}, $event.target.value)">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->value }}" @selected($person->pivot->role === $role->value)>{{ $role->getLabel() }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.button size="sm" variant="ghost" wire:click="confirmRemoveMember({{ $person->getKey() }})" icon="heroicon-o-x-mark" title="Remove from department" />
                                @else
                                    <x-ui.badge :color="\App\Modules\Core\Enums\DepartmentRole::from($person->pivot->role)->getColor()">{{ \App\Modules\Core\Enums\DepartmentRole::from($person->pivot->role)->getLabel() }}</x-ui.badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canManageMembers)
                    <div class="px-6 py-4" style="border-top: 1px solid var(--lq-hairline)">
                        <form wire:submit="addMember" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <x-ui.field label="Add person" name="newMemberId" class="flex-1" help="Only members of this brand are listed. Invite new people under Members.">
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
                        </form>
                    </div>
                @endif
            </x-ui.card>
        @endif
    </div>

    <x-ui.confirm :confirm="$confirm" />
</div>
