<x-core::guest-layout :title="'Join '.$invitation->brand->name">
    <div class="lq-glass">
        <div class="lq-card-header-stack">
            @if ($invitation->brand->logoUrl())
                {{-- A logo is drawn for a white page, so it keeps one here: the card is dark in dark mode. --}}
                <div class="lq-logo-strip">
                    <img src="{{ $invitation->brand->logoUrl() }}" alt="{{ $invitation->brand->name }}">
                </div>
            @else
                <div class="lq-brand-chip" style="--brand-color: {{ $invitation->brand->primaryColor() ?? '#FF6B2C' }}">
                    {{ mb_substr($invitation->brand->name, 0, 1) }}
                </div>
            @endif
            <p class="lq-eyebrow">You're invited</p>
            <h1 class="lq-title">Join {{ $invitation->brand->name }}</h1>
            <p class="lq-muted">
                {{ $invitation->inviter?->name ?? 'Your team' }} invited you as a
                <strong>{{ $invitation->role->getLabel() }}</strong>@if ($invitation->department) in <strong>{{ $invitation->department->name }}</strong>@endif.
            </p>
        </div>

        <form method="POST" action="{{ route('invitations.accept', $invitation->token) }}" class="lq-form">
            @csrf

            <div class="lq-field">
                <label class="lq-label">Email</label>
                <input type="email" value="{{ $invitation->email }}" class="lq-input" disabled>
            </div>

            @if ($existingUser)
                <p class="lq-muted">An account with this email already exists. Accept to add {{ $invitation->brand->name }} to it.</p>
                <label class="lq-check">
                    <input type="checkbox" name="accept" value="1" @checked(old('accept'))>
                    <span>Add this brand to my existing account</span>
                </label>
                @error('accept') <p class="lq-error">{{ $message }}</p> @enderror
            @else
                <div class="lq-field">
                    <label for="name" class="lq-label">Your name</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $invitation->name) }}" class="lq-input" required autofocus autocomplete="name">
                    @error('name') <p class="lq-error">{{ $message }}</p> @enderror
                </div>

                <div class="lq-field">
                    <label for="password" class="lq-label">Choose a password</label>
                    <input id="password" name="password" type="password" class="lq-input" required autocomplete="new-password">
                    <p class="lq-hint">At least 10 characters with letters and numbers.</p>
                    @error('password') <p class="lq-error">{{ $message }}</p> @enderror
                </div>

                <div class="lq-field">
                    <label for="password_confirmation" class="lq-label">Repeat password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" class="lq-input" required autocomplete="new-password">
                </div>
            @endif

            <button type="submit" class="lq-btn lq-btn-primary lq-btn-block">
                {{ $existingUser ? 'Join brand' : 'Create my account' }}
            </button>

            <p class="lq-hint lq-center">This invitation expires {{ $invitation->expires_at->diffForHumans() }}.</p>
        </form>
    </div>
</x-core::guest-layout>
