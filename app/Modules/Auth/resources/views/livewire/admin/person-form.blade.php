<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$title" :breadcrumbs="['People' => route('admin.people.index'), $title => null]"
        :subtitle="$account ? null : 'Create the login here, choose what they can do, and place them in a brand and a department in one step.'" />

    <form wire:submit="save" class="flex flex-col gap-6">
        @if ($account === null)
            <x-ui.card title="What will they do?" description="This decides what they see after signing in.">
                <div class="lq-choices">
                    @foreach ([
                        'agent' => ['Agent', 'Works the leads assigned to them inside one department.', 'heroicon-o-user'],
                        'manager' => ['Manager', 'Leads a department: shares leads among its Agents and sets their targets.', 'heroicon-o-user-group'],
                        'admin' => ['Admin', 'Sees every brand and manages brands, people, integrations and settings.', 'heroicon-o-shield-check'],
                    ] as $value => [$label, $hint, $icon])
                        <label @class(['lq-choice', 'lq-choice-active' => $kind === $value])>
                            <input type="radio" wire:model.live="kind" value="{{ $value }}" name="kind">
                            <span class="flex items-center gap-2 lq-choice-title">@svg($icon, 'h-4 w-4') {{ $label }}</span>
                            <span class="lq-choice-desc">{{ $hint }}</span>
                        </label>
                    @endforeach
                </div>
                @error('kind') <p class="lq-error mt-2">{{ $message }}</p> @enderror
            </x-ui.card>
        @endif

        <x-ui.card title="Person" :description="$account ? null : 'If this email already has a LiquifyCRM account, that account is added to the brand and its password is left unchanged.'">
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Full name" name="name" required>
                    <x-ui.input wire:model="name" name="name" required autofocus placeholder="Aisha Khan" />
                </x-ui.field>
                <x-ui.field label="Designation" name="designation" help="Their job title, shown on their profile and in lists.">
                    <x-ui.input wire:model="designation" name="designation" placeholder="Senior Sales Executive" />
                </x-ui.field>
                <x-ui.field label="Email address" name="email" required help="They sign in with this.">
                    <x-ui.input type="email" wire:model="email" name="email" required placeholder="aisha@company.com" />
                </x-ui.field>
                <x-ui.field label="Phone" name="phone">
                    <x-ui.input type="tel" wire:model="phone" name="phone" autocomplete="off" placeholder="+44 7700 900123" />
                </x-ui.field>
                <x-ui.field :label="$account ? 'New password' : 'Password'" name="password" :required="$account === null"
                    :help="$account ? 'Leave empty to keep the current password.' : 'At least '.($kind === 'admin' ? '12' : '10').' characters with letters and numbers. Share it with them securely.'">
                    <x-ui.input type="password" wire:model="password" name="password" autocomplete="new-password" />
                </x-ui.field>

                @if ($account !== null)
                    <x-ui.field label="Status" name="status" required :help="$locked ? ($account->isSuperAdmin() ? 'A Super Admin is protected: nobody else can suspend or remove them.' : 'You cannot suspend your own account.') : null">
                        <x-ui.select wire:model="status" name="status" :disabled="$locked">
                            @foreach ($statuses as $option)
                                <option value="{{ $option->value }}">{{ $option->getLabel() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif
            </div>
        </x-ui.card>

        @if ($account === null && $kind !== 'admin')
            <x-ui.card title="Where they work" description="The brand whose leads they see, and the team they belong to. To let them work in more brands, open Edit after adding them.">
                @if ($brands->isEmpty())
                    <x-ui.empty title="Add a brand first" description="Managers and Agents work inside a brand." icon="heroicon-o-building-storefront">
                        <x-ui.button variant="secondary" :href="route('admin.brands.create')">Add brand</x-ui.button>
                    </x-ui.empty>
                @else
                    <div class="lq-form-grid lq-form-grid-2">
                        <x-ui.field label="Brand" name="brandId" required>
                            <x-ui.select wire:model.live="brandId" name="brandId" placeholder="Choose a brand">
                                @foreach ($brands as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field label="Department" name="departmentId" :help="$brand === null ? 'Choose the brand first.' : ($departments === [] ? 'This brand has no departments yet. You can add them later.' : ($kind === 'manager' ? 'The team they will lead.' : 'The team they will work in.'))">
                            <x-ui.select wire:model="departmentId" name="departmentId" placeholder="Choose later" :disabled="$brand === null || $departments === []">
                                @foreach ($departments as $id => $departmentName)
                                    <option value="{{ $id }}">{{ $departmentName }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field label="Monthly revenue target{{ $brand ? ' ('.$brand->currency.')' : '' }}" name="targetRevenue" help="For {{ now()->format('F') }}. Leave empty for none; change it any time under Targets.">
                            <x-ui.input type="number" min="0" step="1" inputmode="decimal" wire:model="targetRevenue" name="targetRevenue" placeholder="5000" />
                        </x-ui.field>
                        <x-ui.field label="Monthly sales target" name="targetCount" help="Number of sales to close.">
                            <x-ui.input type="number" min="0" step="1" inputmode="numeric" wire:model="targetCount" name="targetCount" placeholder="10" />
                        </x-ui.field>
                    </div>
                @endif
            </x-ui.card>
        @endif

        @if ($account !== null && $account->isGlobalAdmin())
            <x-ui.card title="Access" description="Admins and Super Admins see every brand.">
                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Role" name="kind" required help="A Super Admin is protected: nobody else can edit, suspend or remove one.">
                        <x-ui.select wire:model="kind" name="kind" :disabled="$locked || ! $canMakeSuperAdmin">
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </x-ui.select>
                    </x-ui.field>
                </div>
            </x-ui.card>
        @elseif ($account !== null)
            {{-- The brands they work in, each with its own role and department. Only Admins change this. --}}
            <x-ui.card title="Brands" description="Where they work. The role and the department are chosen for each brand on its own: Manager in one brand and Agent in another is fine. A change applies at once.">
                @if ($shared === [])
                    <p class="lq-help">Not in any brand yet{{ $canShare ? ': give them one below.' : '.' }}</p>
                @else
                    <ul class="lq-access">
                        @foreach ($shared as $row)
                            @php
                                $membership = $row['membership'];
                                $rowBrand = $row['brand'];
                            @endphp
                            <li class="lq-access-row" wire:key="access-{{ $membership->getKey() }}-{{ $membership->role->value }}-{{ implode('.', $row['current']) }}">
                                <span class="lq-access-brand">
                                    <x-ui.avatar :name="$rowBrand->name" :src="$rowBrand->avatarUrl()" :color="$rowBrand->primaryColor()" :tile="$rowBrand->tileColor()" size="sm" brand />
                                    <span class="min-w-0">
                                        <a href="{{ route('brand.members.index', ['brand' => $rowBrand->slug]) }}" class="lq-cell-title truncate" wire:navigate title="Open the Members screen of {{ $rowBrand->name }}">{{ $rowBrand->name }}</a>
                                        @unless ($membership->isActive())
                                            <span class="lq-cell-sub">{{ $membership->status->getLabel() }} in this brand</span>
                                        @endunless
                                    </span>
                                </span>

                                @if ($canShare)
                                    <select class="lq-input lq-input-sm" aria-label="Role in {{ $rowBrand->name }}" wire:change="changeBrandRole({{ $membership->getKey() }}, $event.target.value)" wire:loading.attr="disabled">
                                        @foreach ($roles as $option)
                                            <option value="{{ $option->value }}" @selected($membership->role === $option)>{{ $option->getLabel() }}</option>
                                        @endforeach
                                    </select>

                                    @if (count($row['current']) > 1)
                                        <span class="lq-access-note" title="Change it on the Departments screen of {{ $rowBrand->name }}">{{ collect($row['current'])->map(fn (int $id): string => $row['departments'][$id] ?? '')->filter()->implode(', ') }}</span>
                                    @elseif ($row['departments'] === [])
                                        <span class="lq-access-note">No departments yet</span>
                                    @else
                                        <select class="lq-input lq-input-sm" aria-label="Department in {{ $rowBrand->name }}" wire:change="changeBrandDepartment({{ $membership->getKey() }}, $event.target.value)" wire:loading.attr="disabled">
                                            <option value="" @selected($row['current'] === [])>No department</option>
                                            @foreach ($row['departments'] as $id => $departmentName)
                                                <option value="{{ $id }}" @selected(in_array((int) $id, $row['current'], true))>{{ $departmentName }}</option>
                                            @endforeach
                                        </select>
                                    @endif

                                    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-sm lq-access-remove" wire:click="confirmRemoveBrand({{ $membership->getKey() }})" title="Remove from {{ $rowBrand->name }}" aria-label="Remove from {{ $rowBrand->name }}">
                                        <x-heroicon-o-x-mark />
                                    </button>
                                @else
                                    <x-ui.badge :color="$membership->role->getColor()">{{ $membership->role->getLabel() }}</x-ui.badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canShare && $otherBrands->isNotEmpty())
                    <div class="lq-access-add">
                        <p class="lq-access-add-title"><x-heroicon-o-plus-circle /> Give access to another brand</p>
                        <div class="lq-access-row lq-access-row-add">
                            <x-ui.select wire:model.live="newBrandId" name="newBrandId" class="lq-input-sm" placeholder="Choose a brand" aria-label="Brand">
                                @foreach ($otherBrands as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.select wire:model="newBrandRole" name="newBrandRole" class="lq-input-sm" aria-label="Role in that brand">
                                @foreach ($roles as $option)
                                    <option value="{{ $option->value }}">{{ $option->getLabel() }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.select wire:model="newDepartmentId" name="newDepartmentId" class="lq-input-sm" :placeholder="$newBrandId === '' ? 'Department' : ($newDepartments === [] ? 'No departments yet' : 'No department')" :disabled="$newDepartments === []" aria-label="Department in that brand">
                                @foreach ($newDepartments as $id => $departmentName)
                                    <option value="{{ $id }}">{{ $departmentName }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.button size="sm" icon="heroicon-o-plus" wire:click="addBrand" wire:loading.attr="disabled" wire:target="addBrand">Add</x-ui.button>
                        </div>
                        @foreach (['newBrandId', 'newBrandRole', 'newDepartmentId'] as $field)
                            @error($field) <p class="lq-error">{{ $message }}</p> @enderror
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endif

        <div class="flex items-center justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.people.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">{{ $account ? 'Save changes' : 'Add person' }}</x-ui.button>
        </div>
    </form>

    <x-ui.confirm :confirm="$confirm" />
</div>
