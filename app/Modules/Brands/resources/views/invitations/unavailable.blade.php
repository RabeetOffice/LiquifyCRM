<x-core::guest-layout title="Invitation unavailable">
    <div class="lq-card lq-center">
        <div class="lq-icon-circle">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="lq-icon">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
        </div>
        <h1 class="lq-title">This invitation can't be used</h1>
        <p class="lq-muted">
            @if ($invitation?->isAccepted())
                It has already been accepted. Sign in to continue.
            @elseif ($invitation?->isExpired())
                It has expired. Ask the person who invited you to send a new one.
            @else
                The link is not valid. Ask the person who invited you to send a new one.
            @endif
        </p>
        <a href="{{ url('/b/login') }}" class="lq-btn-primary">Go to sign in</a>
    </div>
</x-core::guest-layout>
