@php
    $checklist = $this->getChecklist();
    $percent = $checklist['total'] > 0 ? (int) round($checklist['done'] / $checklist['total'] * 100) : 0;
@endphp

<x-filament-widgets::widget>
    <section class="lq-setup" aria-labelledby="lq-setup-title">
        <div class="lq-setup-main">
            <div class="lq-setup-header">
                <div>
                    <h2 id="lq-setup-title" class="lq-setup-title">Set up {{ $checklist['brand']->name }}</h2>
                    <p class="lq-setup-desc">Each step ticks itself when it is finished.</p>
                </div>

                <span class="lq-pill lq-pill-orange lq-tabular">{{ $checklist['done'] }} of {{ $checklist['total'] }} done</span>
            </div>

            <div class="lq-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="Setup progress">
                <div class="lq-progress-bar" style="width: {{ $percent }}%"></div>
            </div>

            <ol class="lq-steps">
                @foreach ($checklist['steps'] as $step)
                    <li @class(['lq-step', 'lq-step-done' => $step['done'], 'lq-step-soon' => filled($step['soon'])])>
                        <span class="lq-step-mark" aria-hidden="true">
                            @if ($step['done'])
                                <x-filament::icon icon="heroicon-m-check" class="lq-step-check" />
                            @else
                                {{ $loop->iteration }}
                            @endif
                        </span>

                        <div class="lq-step-body">
                            <div class="lq-step-title">
                                <span>{{ $step['title'] }}</span>

                                @if ($step['soon'])
                                    <span class="lq-pill">Coming in {{ $step['soon'] }}</span>
                                @endif
                            </div>

                            <p class="lq-step-desc">{{ $step['description'] }}</p>
                        </div>

                        @if ($step['url'])
                            <div class="lq-step-action">
                                <x-filament::button tag="a" :href="$step['url']" color="gray" size="sm">
                                    {{ $step['done'] ? 'View' : 'Open' }}
                                </x-filament::button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>

        <div class="lq-setup-art" aria-hidden="true">
            <img src="{{ asset('images/brand/onboarding-welcome.webp') }}" alt="" loading="lazy" width="1200" height="800">
        </div>
    </section>
</x-filament-widgets::widget>
