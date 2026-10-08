<div class="lq-toasts" x-data aria-live="polite">
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div class="lq-toast" :class="'lq-toast-' + toast.type" x-transition.opacity.duration.200ms>
            <span class="lq-toast-icon">
                <x-heroicon-o-check-circle x-show="toast.type === 'success'" />
                <x-heroicon-o-information-circle x-show="toast.type === 'info'" />
                <x-heroicon-o-exclamation-triangle x-show="toast.type === 'warning'" />
                <x-heroicon-o-x-circle x-show="toast.type === 'danger'" />
            </span>
            <div class="lq-toast-text">
                <div class="lq-toast-title" x-text="toast.title"></div>
                <template x-if="toast.body">
                    <div class="lq-toast-body" x-text="toast.body"></div>
                </template>
                <template x-if="toast.undo">
                    <button type="button" class="lq-link text-sm mt-1" x-on:click="window.Livewire.dispatch(toast.undo.event, toast.undo.params); $store.toasts.remove(toast.id)">Undo</button>
                </template>
            </div>
            <button type="button" class="lq-toast-close" x-on:click="$store.toasts.remove(toast.id)" title="Dismiss">
                <x-heroicon-m-x-mark />
            </button>
        </div>
    </template>
</div>
