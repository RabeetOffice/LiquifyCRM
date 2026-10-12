{{-- The files chosen for a client, before they are saved. Choosing again adds to the list, so each one can be taken out. --}}
@props(['files' => [], 'name'])
@if (count($files) > 0)
    <ul class="lq-picked">
        @foreach ($files as $index => $upload)
            <li wire:key="picked-{{ $name }}-{{ $upload->getFilename() }}" @class(['lq-picked-bad' => $errors->has($name.'.'.$index)])>
                <x-heroicon-o-document />
                <span class="min-w-0 flex-1">
                    <span class="lq-file-name">{{ $upload->getClientOriginalName() }}</span>
                    @error($name.'.'.$index)
                        <span class="lq-error">{{ $message }}</span>
                    @enderror
                </span>
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" x-data x-on:click="$wire.$removeUpload(@js($name), @js($upload->getFilename()))" title="Take out" aria-label="Take out {{ $upload->getClientOriginalName() }}"><x-heroicon-o-x-mark /></button>
            </li>
        @endforeach
    </ul>
@endif
