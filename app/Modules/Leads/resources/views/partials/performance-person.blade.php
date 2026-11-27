{{-- A person's cell on a Performance table. --}}
<td class="lq-td-person">
    <span class="lq-person">
        <x-ui.avatar :name="$row['name']" :src="$row['picture']" />
        <span class="min-w-0">
            <span class="lq-cell-title">{{ $row['name'] }}@if ($row['me']) <span class="lq-muted">(you)</span>@endif</span>
            @if ($row['team'] !== '')
                <span class="lq-cell-sub">{{ $row['team'] }}</span>
            @endif
        </span>
    </span>
</td>
