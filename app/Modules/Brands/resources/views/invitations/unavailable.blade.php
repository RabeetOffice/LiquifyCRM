<x-core::guest-layout title="Invitation unavailable">
    <div class="lq-glass lq-errorcard">
        <span class="lq-icon-circle"><x-heroicon-o-envelope-open class="lq-icon" /></span>
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
        <div class="lq-errorcard-actions">
            <a href="{{ route('login') }}" class="lq-btn lq-btn-primary lq-btn-block">Go to sign in</a>
        </div>
    </div>
</x-core::guest-layout>
