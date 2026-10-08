<div class="flex flex-col gap-6">
    <x-ui.page-header title="Distribute leads" :subtitle="$isAdmin ? 'Filter leads, then split them across departments. Each department\'s Manager shares them among their Agents.' : 'Share your department\'s leads among your Agents.'"
        :breadcrumbs="['Leads' => route('brand.leads.index', ['brand' => $brand->slug]), 'Distribute' => null]" />

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

    @unless ($isAdmin)
        <p class="lq-help">To hand out specific leads by hand, tick them in the Leads list and use Assign.</p>
    @endunless
</div>
