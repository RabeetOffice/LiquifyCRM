<div class="flex flex-col gap-6">
    @if ($clientsOnly)
        <x-ui.page-header title="How clients are shared" subtitle="Choose how your department shares the clients front sales hands to it. To give out the ones already waiting, tick them on the Clients screen."
            :breadcrumbs="['Clients' => route('brand.clients.index', ['brand' => $brand->slug]), 'Sharing' => null]" />
    @else
        <x-ui.page-header title="Distribute leads" :subtitle="$isAdmin ? 'Choose where the leads of each website and Facebook Page go, how each department shares what it receives, or split the leads you have across departments now.' : 'Choose how your department shares its new leads, or share the leads you have among your Agents now.'"
            :breadcrumbs="['Leads' => route('brand.leads.index', ['brand' => $brand->slug]), 'Distribute' => null]" />
    @endif

    {{-- From the first second: where the leads of each website and Facebook Page go. --}}
    @if ($routeSources !== [])
        <x-ui.card title="Where new leads go" description="Choose for each website and Facebook Page. A lead goes to that department or straight to that person the moment it arrives; anything left on the queue waits for you to assign it by hand." flush>
            <ul class="flex flex-col">
                @foreach ($routeSources as $source)
                    @php
                        $route = $routes->get($source['channel'].':'.$source['id']);
                        $current = $route && $route->department_id ? $route->department_id.($route->user_id ? ':'.$route->user_id : '') : '';
                    @endphp
                    <li class="lq-rank lq-route" wire:key="route-{{ $source['channel'] }}-{{ $source['id'] }}">
                        @if ($source['site'])
                            <x-sites::favicon :site="$source['site']" class="lq-route-icon" />
                        @else
                            <span class="lq-logo-tile lq-logo-sm lq-logo-facebook"><x-ui.brand-icon name="facebook" /></span>
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="lq-cell-title">{{ $source['name'] }}</span>
                            <span class="lq-cell-sub">{{ $source['detail'] }}</span>
                        </span>
                        <x-heroicon-m-arrow-long-right class="lq-route-arrow" />
                        <select class="lq-input lq-input-sm lq-route-select" aria-label="Where leads from {{ $source['name'] }} go" wire:change="setRoute('{{ $source['channel'] }}', {{ $source['id'] }}, $event.target.value)">
                            <option value="" @selected($current === '')>The queue: assign by hand</option>
                            @foreach ($routeTargets as $target)
                                <optgroup label="{{ $target['department']->name }}">
                                    <option value="{{ $target['department']->getKey() }}" @selected($current === (string) $target['department']->getKey())>{{ $target['department']->name }}: {{ $target['department']->assignment_mode === \App\Modules\Core\Enums\AssignmentMode::RoundRobin ? 'next Agent in turn' : 'its Manager assigns' }}</option>
                                    @foreach ($target['people'] as $id => $name)
                                        <option value="{{ $target['department']->getKey() }}:{{ $id }}" @selected($current === $target['department']->getKey().':'.$id)>{{ $name }} · {{ $target['department']->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </li>
                @endforeach
            </ul>
            @if ($routeTargets === [])
                <x-slot:footer>
                    <span class="mr-auto text-sm lq-muted">Create a department first, then choose it here.</span>
                </x-slot:footer>
            @endif
        </x-ui.card>
    @endif

    {{-- From now on: how each department shares the leads it receives. --}}
    @if ($sharing !== [])
        <x-ui.card title="How each department shares" description="Choose once for each department. By hand, what arrives waits for you; round robin gives each new lead (or, in an Upsell department, each new client) to the next person in turn, so everyone gets the same number." flush>
            <ul class="flex flex-col">
                @foreach ($sharing as $row)
                    @php
                        $item = $row['department'];
                        $on = $item->assignment_mode === \App\Modules\Core\Enums\AssignmentMode::RoundRobin;
                        $upsell = $item->isUpsell();
                        $what = $upsell ? 'client' : 'lead';
                    @endphp
                    <li class="lq-rank" wire:key="sharing-{{ $item->getKey() }}">
                        <span @class(['lq-logo-tile lq-logo-sm', 'lq-logo-web' => $on])>
                            @if ($on)
                                <x-heroicon-o-arrow-path-rounded-square />
                            @else
                                <x-heroicon-o-hand-raised />
                            @endif
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="lq-cell-title">{{ $item->name }} @if ($upsell) <x-ui.badge color="success">Upsell</x-ui.badge> @endif</span>
                            <span class="lq-cell-sub">
                                @if ($on && $row['agents'] === 0)
                                    Round robin is on, but this department has no Agent yet, so {{ $what }}s wait.
                                @elseif ($on)
                                    Each new {{ $what }} goes to the next of {{ $row['agents'] }} {{ str('Agent')->plural($row['agents']) }}.
                                @else
                                    New {{ $what }}s wait until you give them out.
                                @endif
                                @if ($row['waiting'] > 0)
                                    · {{ $row['waiting'] }} waiting now
                                @endif
                            </span>
                        </span>
                        @if ($upsell && $row['waiting'] > 0)
                            <x-ui.button size="sm" variant="ghost" :href="route('brand.clients.index', ['brand' => $brand->slug, 'view' => 'waiting'])">See the {{ $row['waiting'] }} waiting</x-ui.button>
                        @elseif ($on && $row['waiting'] > 0 && $row['agents'] > 0)
                            <x-ui.button size="sm" variant="ghost" wire:click="shareWaiting({{ $item->getKey() }})" wire:loading.attr="disabled" wire:target="shareWaiting({{ $item->getKey() }})">Share the {{ $row['waiting'] }} waiting</x-ui.button>
                        @endif
                        <div class="lq-tabs flex-none" role="radiogroup" aria-label="How {{ $item->name }} shares leads">
                            @foreach ($modes as $mode)
                                <button type="button" role="radio" aria-checked="{{ $item->assignment_mode === $mode ? 'true' : 'false' }}" title="{{ $mode->getDescription() }}" wire:click="setSharing({{ $item->getKey() }}, '{{ $mode->value }}')" @class(['lq-tab', 'lq-tab-active' => $item->assignment_mode === $mode])>{{ $mode->getLabel() }}</button>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @unless ($clientsOnly)
    <div class="lq-grid-main">
        <div class="flex flex-col gap-6">
            <x-ui.card title="1. Which leads" description="Open leads only. Oldest leads are handed out first.">
                <div class="lq-form-grid lq-form-grid-2">
                    @unless ($isAdmin)
                        <x-ui.field label="Department" name="department" class="lq-col-full">
                            <x-ui.select wire:model.live="department" name="department">
                                @foreach ($departments as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    @endunless
                    <x-ui.field label="Source" name="source">
                        <x-ui.select wire:model.live="source" name="source" placeholder="Every source">
                            @foreach ($sources as $option)
                                <option value="{{ $option->value }}">{{ $option->getLabel() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field label="Status" name="status">
                        <x-ui.select wire:model.live="status" name="status" placeholder="Every open status">
                            @foreach ($statuses->where('is_won', false)->where('is_lost', false) as $option)
                                <option value="{{ $option->getKey() }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field label="Received from" name="from">
                        <x-ui.input type="date" wire:model.live="from" name="from" />
                    </x-ui.field>
                    <x-ui.field label="Received until" name="until">
                        <x-ui.input type="date" wire:model.live="until" name="until" />
                    </x-ui.field>
                    <x-ui.field name="onlyWaiting" class="lq-col-full">
                        <x-ui.checkbox wire:model.live="onlyWaiting" name="onlyWaiting" :label="$isAdmin ? 'Only leads not sent to a department yet' : 'Only leads no Agent has yet'" />
                    </x-ui.field>
                    @if ($isAdmin && ! $onlyWaiting)
                        <x-ui.field label="Currently in department" name="department" class="lq-col-full" help="Moving a lead to another department takes it away from its Agent.">
                            <x-ui.select wire:model.live="department" name="department" placeholder="Any department">
                                @foreach ($departments as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    @endif
                </div>

                <x-slot:footer>
                    <span class="mr-auto text-sm"><strong class="lq-tabular">{{ number_format($matching) }}</strong> {{ str('lead')->plural($matching) }} match{{ $matching === 1 ? 'es' : '' }}{{ $matching >= $limit ? ' (the first '.number_format($limit).' are handed out in one go)' : '' }}</span>
                </x-slot:footer>
            </x-ui.card>

            <x-ui.card :title="$isAdmin ? '2. To which departments' : '2. To which Agents'" flush>
                <div class="lq-toolbar">
                    <div class="lq-tabs" role="tablist">
                        @foreach (['equal' => 'Equally', 'percent' => 'By percentage', 'count' => 'By count'] as $value => $label)
                            @if (in_array($value, $methods, true))
                                <button type="button" @class(['lq-tab', 'lq-tab-active' => $method === $value]) wire:click="$set('method', '{{ $value }}')" role="tab">{{ $label }}</button>
                            @endif
                        @endforeach
                    </div>
                </div>

                @if ($targets === [])
                    <x-ui.empty :title="$isAdmin ? 'No departments yet' : 'No Agents in this department yet'" :description="$isAdmin ? 'Create a department first.' : 'Add Agents under Members first.'" icon="heroicon-o-user-group" />
                @else
                    <ul class="flex flex-col">
                        @foreach ($targets as $id => $name)
                            <li class="lq-rank" wire:key="target-{{ $id }}">
                                <label class="lq-check flex-1">
                                    <input type="checkbox" wire:model.live="include.{{ $id }}">
                                    <span>{{ $name }}</span>
                                </label>
                                @if ($method !== 'equal' && ($include[$id] ?? false))
                                    <div class="lq-input-group" style="width: 9rem">
                                        <input type="number" min="0" step="1" inputmode="numeric" class="lq-input lq-input-sm lq-tabular" wire:model.live.debounce.300ms="amounts.{{ $id }}" placeholder="0" aria-label="{{ $method === 'percent' ? 'Percent' : 'Leads' }} for {{ $name }}">
                                        <span class="lq-input-prefix">{{ $method === 'percent' ? '%' : 'leads' }}</span>
                                    </div>
                                @endif
                                <span class="lq-pill lq-tabular" style="min-width: 5rem; justify-content: center">{{ isset($preview[$id]) ? number_format($preview[$id]).' '.str('lead')->plural($preview[$id]) : '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <x-slot:footer>
                    <span class="mr-auto text-sm">
                        @if ($previewError)
                            <span class="lq-error">{{ $previewError }}</span>
                        @elseif ($preview !== [])
                            <span class="lq-muted">{{ number_format(array_sum($preview)) }} handed out, {{ number_format($left) }} left in the queue.</span>
                        @endif
                        @error('include')
                            <span class="lq-error">{{ $message }}</span>
                        @enderror
                    </span>
                    <x-ui.button variant="primary" icon="heroicon-o-arrows-right-left" wire:click="distribute" wire:loading.attr="disabled" wire:target="distribute" :disabled="$preview === []">Distribute{{ $preview !== [] ? ' '.number_format(array_sum($preview)).' '.str('lead')->plural(array_sum($preview)) : '' }}</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        </div>

        <x-ui.card title="History" description="Who moved how many leads, to whom." flush>
            @if ($history->isEmpty())
                <p class="lq-help px-6 py-6">Nothing distributed yet.</p>
            @else
                <ul class="flex flex-col">
                    @foreach ($history as $entry)
                        <li class="lq-rank" wire:key="history-{{ $entry->getKey() }}">
                            <span class="min-w-0 flex-1">
                                <span class="lq-cell-title">{{ number_format($entry->lead_count) }} {{ str('lead')->plural($entry->lead_count) }} to {{ $names[$entry->to_type][$entry->to_id] ?? 'someone removed' }}</span>
                                <span class="lq-cell-sub">{{ $entry->from?->name ?? 'System' }} · {{ ['equal' => 'equally', 'percent' => 'by percentage', 'count' => 'by count', 'manual' => 'by hand'][$entry->method] ?? $entry->method }} · {{ $entry->created_at?->diffForHumans() }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
    @endunless

    @unless ($isAdmin || $clientsOnly)
        <p class="lq-help">To hand out specific leads by hand, tick them in the Leads list and use Assign.</p>
    @endunless
</div>
