@props(['title' => config('app.name')])
<x-layouts.guest :title="$title">
    {{ $slot }}
</x-layouts.guest>
