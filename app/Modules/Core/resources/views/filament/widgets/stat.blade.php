@php
    use Filament\Support\Enums\IconPosition;

    $url = $getUrl();
    $tag = $url ? 'a' : 'div';
    $value = $getValue();
    $description = $getDescription();
    $descriptionColor = $getDescriptionColor();
    $descriptionIcon = $getDescriptionIcon();
    $descriptionIconPosition = $getDescriptionIconPosition();
    $linkLabel = $getLinkLabel();
    $delta = $getDelta();
    $deltaIsPositive = $getDeltaIsPositive();
@endphp

<{!! $tag !!}
    @if ($url)
        {{ \Filament\Support\generate_href_html($url, $shouldOpenUrlInNewTab()) }}
    @endif
    {{
        $getExtraAttributeBag()->class([
            'lq-stat',
            'lq-stat-hero' => $isHero(),
            'lq-stat-has-url' => filled($url),
        ])
    }}
>
    <div class="lq-stat-head">
        @if ($icon = $getIcon())
            <span class="lq-stat-icon">
                {{ \Filament\Support\generate_icon_html($icon) }}
            </span>
        @endif

        <span class="lq-stat-label">{{ $getLabel() }}</span>
    </div>

    <div class="lq-stat-value-row">
        @if (filled($value))
            <div class="lq-stat-value">{{ $value }}</div>
        @else
            <div class="lq-stat-value lq-stat-placeholder">{{ $getPlaceholder() }}</div>
        @endif

        @if (filled($delta))
            <span @class(['lq-delta', 'lq-delta-up' => $deltaIsPositive === true, 'lq-delta-down' => $deltaIsPositive === false])>
                @if ($deltaIsPositive === true)
                    <x-filament::icon icon="heroicon-m-arrow-trending-up" />
                @elseif ($deltaIsPositive === false)
                    <x-filament::icon icon="heroicon-m-arrow-trending-down" />
                @endif
                {{ $delta }}
            </span>
        @endif
    </div>

    @if (filled($description))
        <div
            @class([
                'lq-stat-sub',
                "lq-stat-sub-{$descriptionColor}" => is_string($descriptionColor) && $descriptionColor !== 'gray',
            ])
        >
            @if ($descriptionIcon && in_array($descriptionIconPosition, [IconPosition::Before, 'before']))
                {{ \Filament\Support\generate_icon_html($descriptionIcon) }}
            @endif

            <span>{{ $description }}</span>

            @if ($descriptionIcon && in_array($descriptionIconPosition, [IconPosition::After, 'after']))
                {{ \Filament\Support\generate_icon_html($descriptionIcon) }}
            @endif
        </div>
    @endif

    @if ($url && filled($linkLabel))
        <div class="lq-stat-foot">
            <span class="lq-stat-link">
                {{ $linkLabel }}
                <x-filament::icon icon="heroicon-m-arrow-right" class="lq-stat-link-icon" />
            </span>
        </div>
    @endif
</{!! $tag !!}>
