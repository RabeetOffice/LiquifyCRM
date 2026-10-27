{{-- The body of the leads email (LeadDigestMail), rendered on its own and put into the markdown message in one line,
     so the markdown reader leaves the tables alone. Styles are inline: mail programs drop most stylesheets. --}}
@php
    $font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
@endphp
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 4px 0 26px; border-collapse: separate;">
    <tr>
        @foreach ([['New', $totals['new'], '#1c1917', '#ffffff', '#e7e5e4'], ['Waiting for a person', $totals['waiting'], $totals['waiting'] > 0 ? '#c2410c' : '#1c1917', $totals['waiting'] > 0 ? '#fff4ed' : '#ffffff', $totals['waiting'] > 0 ? '#fed7aa' : '#e7e5e4'], [count($sections) === 1 ? 'Brand' : 'Brands', count($sections), '#1c1917', '#ffffff', '#e7e5e4']] as [$label, $value, $ink, $fill, $line])
            <td width="33%" style="padding: 0 {{ $loop->last ? '0' : '8px' }} 0 0; vertical-align: top;">
                <div style="background: {{ $fill }}; border: 1px solid {{ $line }}; border-radius: 12px; padding: 12px 14px; font-family: {{ $font }};">
                    <div style="font-size: 24px; line-height: 28px; font-weight: 700; color: {{ $ink }};">{{ number_format($value) }}</div>
                    <div style="font-size: 12px; line-height: 16px; color: #78716c; margin-top: 2px;">{{ $label }}</div>
                </div>
            </td>
        @endforeach
    </tr>
</table>

@foreach ($sections as $section)
    @php
        /** @var \App\Modules\Brands\Models\Brand $brand */
        $brand = $section['brand'];
        $colour = $brand->primaryColor() ?? '#ff6b2c';
        $logo = $brand->logoUrl();
        $more = $section['new'] - $section['leads']->count();
    @endphp
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 20px; border: 1px solid #e7e5e4; border-radius: 14px; border-collapse: separate; overflow: hidden; background: #ffffff;">
        <tr>
            <td style="padding: 14px 16px; border-top: 4px solid {{ $colour }}; border-bottom: 1px solid #f0eeeb; background: #fbfaf8; border-radius: 14px 14px 0 0;">
                <table cellpadding="0" cellspacing="0" role="presentation" width="100%">
                    <tr>
                        <td width="44" style="vertical-align: middle; padding-right: 12px;">
                            @if ($logo)
                                <img src="{{ $logo }}" width="40" height="40" alt="" style="display: block; width: 40px; height: 40px; border-radius: 10px; border: 2px solid {{ $colour }}; background: #ffffff; object-fit: contain;">
                            @else
                                <div style="width: 40px; height: 40px; line-height: 40px; border-radius: 10px; background: {{ $colour }}; color: #ffffff; text-align: center; font-family: {{ $font }}; font-weight: 700; font-size: 17px;">{{ $brand->initial() }}</div>
                            @endif
                        </td>
                        <td style="vertical-align: middle; font-family: {{ $font }};">
                            <div style="font-size: 16px; line-height: 20px; font-weight: 700; color: #1c1917;">{{ $brand->name }}</div>
                            <div style="font-size: 13px; line-height: 18px; color: #78716c; margin-top: 2px;">
                                {{ $section['new'] }} new
                                @if ($section['waiting'] > 0)
                                    · <span style="color: #c2410c; font-weight: 600;">{{ $section['waiting'] }} waiting for a person</span>
                                @else
                                    · everyone has an owner
                                @endif
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        @foreach ($section['leads'] as $lead)
            @php
                $site = $lead->relationLoaded('site') ? $lead->getRelation('site') : null;
                $from = $site?->name ?? ($lead->fields['facebook_page'] ?? null);
                $profile = \App\Modules\Leads\Support\ProfileNames::shown($lead);
                $facts = array_filter([
                    $lead->source_type->getLabel().($from ? ': '.$from : ''),
                    $lead->department?->name,
                    $lead->created_at?->copy()->setTimezone($zone)->format($sameDay ? 'g:i A' : 'D j M, g:i A'),
                ]);
            @endphp
            <tr>
                <td style="padding: 11px 16px; border-bottom: 1px solid #f5f4f1; font-family: {{ $font }};">
                    <table cellpadding="0" cellspacing="0" role="presentation" width="100%">
                        <tr>
                            <td style="vertical-align: middle;">
                                <a href="{{ $section['brand']->url('leads/'.$lead->getKey()) }}" style="font-size: 14px; line-height: 19px; font-weight: 600; color: #1c1917; text-decoration: none;">{{ \Illuminate\Support\Str::limit($lead->name, 48) }}</a>
                                @if ($profile)
                                    <span style="font-size: 12px; color: #0369a1;"> · On {{ $profile['where'] }}: {{ $profile['name'] }}</span>
                                @endif
                                <div style="font-size: 12px; line-height: 17px; color: #78716c; margin-top: 2px;">{{ implode(' · ', $facts) }}</div>
                            </td>
                            <td align="right" style="vertical-align: middle; white-space: nowrap; padding-left: 10px;">
                                @if ($lead->assignee)
                                    <span style="display: inline-block; font-size: 12px; line-height: 16px; color: #44403c; background: #f5f4f1; border-radius: 999px; padding: 4px 10px;">{{ $lead->assignee->name }}</span>
                                @elseif ($lead->status?->is_won || $lead->status?->is_lost)
                                    <span style="display: inline-block; font-size: 12px; line-height: 16px; color: #44403c; background: #f5f4f1; border-radius: 999px; padding: 4px 10px;">{{ $lead->status->name }}</span>
                                @else
                                    <span style="display: inline-block; font-size: 12px; line-height: 16px; font-weight: 600; color: #c2410c; background: #fff4ed; border: 1px solid #fed7aa; border-radius: 999px; padding: 3px 10px;">Waiting</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endforeach
        <tr>
            <td style="padding: 12px 16px; font-family: {{ $font }}; font-size: 13px; line-height: 18px;">
                @if ($more > 0)
                    <span style="color: #78716c;">and {{ $more }} more · </span>
                @endif
                <a href="{{ $section['url'] }}" style="color: #c2410c; font-weight: 600; text-decoration: none;">Open {{ $brand->name }} leads &rarr;</a>
            </td>
        </tr>
    </table>
@endforeach
