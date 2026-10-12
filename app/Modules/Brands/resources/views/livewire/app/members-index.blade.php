<div class="flex flex-col gap-6" data-live-refresh>
    <x-ui.page-header title="Members" subtitle="Who has access to this brand and with which role.">
        <x-slot:actions>
            @if ($canInvite)
                <x-ui.button variant="secondary" icon="heroicon-o-user-plus" :href="route('brand.members.create', ['brand' => $brand->slug])">Add person</x-ui.button>
                <x-ui.button variant="primary" icon="heroicon-o-envelope" wire:click="openInvite">Invite by email</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card flush>
        <div class="lq-toolbar">
            <div class="relative w-full sm:w-72">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 lq-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" class="lq-input lq-input-sm" style="padding-inline-start: 2.25rem" placeholder="Search by name or email…">
            </div>
            <div class="lq-toolbar-end">
                <select wire:model.live="role" class="lq-input lq-input-sm" style="width: 9rem">
                    <option value="">All roles</option>
                    @foreach ($allRoleOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="status" class="lq-input lq-input-sm" style="width: 9rem">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($members->isEmpty())
            <x-ui.empty title="No members yet" description="Invite a Manager for each department. Managers then add their own Agents." icon="heroicon-o-users">
                <x-slot:actions>
                    @if ($canInvite)
                        <x-ui.button variant="primary" wire:click="openInvite">Invite by email</x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty>
        @else
            <div class="lq-table-wrap">
                <table class="lq-table lq-table-rows">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <x-ui.sort-th field="role" :sort="$sort" :direction="$direction" label="Role" />
                            <th>Departments</th>
                            <x-ui.sort-th field="status" :sort="$sort" :direction="$direction" label="Status" />
                            <x-ui.sort-th field="joined_at" :sort="$sort" :direction="$direction" label="Joined" />
                            <th class="lq-table-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($members as $membership)
                            <tr wire:key="member-{{ $membership->getKey() }}">
                                <td class="lq-td-person">
                                    <span class="lq-person">
                                        <x-ui.avatar :name="$membership->user?->name ?? '?'" :src="$membership->user?->avatarUrl()" />
                                        <span class="min-w-0">
                                            <span class="lq-cell-title">{{ $membership->user?->name }}</span>
                                            <span class="lq-cell-sub">{{ $membership->user?->email }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td><x-ui.badge :color="$membership->role->getColor()">{{ $membership->role->getLabel() }}</x-ui.badge></td>
                                <td>
                                    @forelse ($departmentsByUser[$membership->user_id] ?? [] as $name)
                                        <x-ui.badge>{{ $name }}</x-ui.badge>
                                    @empty
                                        <span class="lq-muted">Not in a department yet</span>
                                    @endforelse
                                </td>
                                <td><x-ui.badge :color="$membership->status->getColor()">{{ $membership->status->getLabel() }}</x-ui.badge></td>
                                <td class="whitespace-nowrap">
                                    <span title="{{ $membership->joined_at?->format('j M Y') }}">{{ $membership->joined_at?->diffForHumans() ?? '—' }}</span>
                                    <span class="lq-cell-sub">{{ $membership->user?->last_login_at ? 'Signed in '.$membership->user->last_login_at->diffForHumans() : 'Has not signed in yet' }}</span>
                                </td>
                                <td class="lq-table-end">
                                    <div class="lq-row-tools">
                                        @can('update', $membership)
                                            @php $toggle = $membership->isActive() ? 'Suspend' : 'Reactivate'; @endphp
                                            <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $membership->getKey() }})" icon="heroicon-o-pencil-square">Edit</x-ui.button>
                                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="confirmToggle({{ $membership->getKey() }})" title="{{ $toggle }}" aria-label="{{ $toggle }} {{ $membership->user?->name }}">@svg($membership->isActive() ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')</button>
                                        @endcan
                                        @can('delete', $membership)
                                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmRemove({{ $membership->getKey() }})" title="Remove" aria-label="Remove {{ $membership->user?->name }}"><x-heroicon-o-trash /></button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $members->links('pagination.lq') }}
        @endif
    </x-ui.card>

    @if ($invitations->isNotEmpty())
        <x-ui.card title="Pending invitations" description="Invitations you send appear here until they are accepted." flush>
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th>Invited by</th>
                            <th>Expires</th>
                            <th class="lq-table-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invitations as $invitation)
                            <tr wire:key="invitation-{{ $invitation->getKey() }}">
                                <td><span class="lq-cell-title">{{ $invitation->email }}</span>@if ($invitation->name)<span class="lq-cell-sub">{{ $invitation->name }}</span>@endif</td>
                                <td><x-ui.badge :color="$invitation->role->getColor()">{{ $invitation->role->getLabel() }}</x-ui.badge></td>
                                <td>{{ $invitation->department?->name ?? '—' }}</td>
                                <td>{{ $invitation->inviter?->name ?? '—' }}</td>
                                <td class="lq-muted">{{ $invitation->expires_at?->diffForHumans() }}</td>
                                <td class="lq-table-end">
                                    <div class="flex justify-end gap-1">
                                        <x-ui.button size="sm" variant="ghost" wire:click="resendInvitation({{ $invitation->getKey() }})" icon="heroicon-o-paper-airplane">Resend</x-ui.button>
                                        @can('delete', $invitation)
                                            <x-ui.button size="sm" variant="ghost" wire:click="revokeInvitation({{ $invitation->getKey() }})" icon="heroicon-o-x-mark">Revoke</x-ui.button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    <x-ui.modal model="showInvite" :title="'Invite someone to '.$brand->name" :description="'They receive an email with a link to set their password. The link works for '.config('liquify.invitation_expiry_days').' days.'">
        <form wire:submit="sendInvite" class="lq-form" id="invite-form">
            <x-ui.field label="Email address" name="inviteEmail" required>
                <x-ui.input type="email" wire:model="inviteEmail" name="inviteEmail" required autofocus />
            </x-ui.field>
            <x-ui.field label="Name (optional)" name="inviteName">
                <x-ui.input wire:model="inviteName" name="inviteName" />
            </x-ui.field>
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Role in this brand" name="inviteRole" required help="Managers lead the department; Agents work in it.">
                    <x-ui.select wire:model="inviteRole" name="inviteRole">
                        @foreach ($roleOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Department" name="inviteDepartment" :required="! auth()->user()->isGlobalAdmin()">
                    <x-ui.select wire:model="inviteDepartment" name="inviteDepartment" placeholder="Choose later">
                        @foreach ($departmentOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showInvite', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="invite-form" variant="primary" wire:loading.attr="disabled">Send invitation</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @if ($editingMembership)
        <x-ui.modal model="editing" :title="'Access for '.($editingMembership->user?->name ?? 'member')" size="sm">
            <form wire:submit="saveEdit" class="lq-form" id="edit-form">
                <x-ui.field label="Role in this brand" name="editRole" required help="Managers lead departments and assign leads. Agents work the leads assigned to them.">
                    <x-ui.select wire:model="editRole" name="editRole" :disabled="! auth()->user()->isGlobalAdmin()">
                        @foreach ($allRoleOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Status" name="editStatus" required help="Suspended members cannot sign in to this brand.">
                    <x-ui.select wire:model="editStatus" name="editStatus">
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="$set('editing', null)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="edit-form" variant="primary" wire:loading.attr="disabled">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
