@props(['name'])
{{-- Marks of the services LiquifyCRM connects to, as inline SVG so they take the tile's colour. --}}
@switch($name)
    @case('facebook')
        <svg {{ $attributes }} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 21.9v-8.02h2.7l.4-3.13h-3.1V8.76c0-.9.25-1.52 1.55-1.52h1.66V4.44A22 22 0 0 0 14.3 4.3c-2.4 0-4.03 1.46-4.03 4.14v2.31H7.55v3.13h2.72v8.02z"/></svg>
        @break
    @case('messenger')
        <svg {{ $attributes }} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.2c-5.52 0-9.8 4.04-9.8 9.5 0 2.86 1.17 5.33 3.08 7.03.16.14.26.34.26.56l.06 1.74a.78.78 0 0 0 1.1.7l1.94-.86a.78.78 0 0 1 .52-.04c.9.25 1.85.38 2.84.38 5.52 0 9.8-4.04 9.8-9.5S17.52 2.2 12 2.2Zm5.88 7.31-2.88 4.57a1.47 1.47 0 0 1-2.13.4l-2.29-1.72a.59.59 0 0 0-.7 0l-3.1 2.35c-.41.31-.95-.18-.67-.62l2.88-4.57a1.47 1.47 0 0 1 2.13-.4l2.29 1.72c.2.16.5.16.7 0l3.1-2.35c.41-.31.95.18.67.62Z"/></svg>
        @break
    @case('instagram')
        <svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="3.1" y="3.1" width="17.8" height="17.8" rx="5.2"/><circle cx="12" cy="12" r="4.1"/><circle cx="17.4" cy="6.6" r="1.25" fill="currentColor" stroke="none"/></svg>
        @break
    @default
        <x-heroicon-o-globe-alt {{ $attributes }} />
@endswitch
