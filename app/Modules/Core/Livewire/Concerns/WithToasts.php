<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire\Concerns;

/**
 * Toasts after every action, with an optional Undo for 10 seconds.
 */
trait WithToasts
{
    /**
     * @param  array{event: string, params: array<string, mixed>}|null  $undo
     */
    protected function toast(string $title, ?string $body = null, string $type = 'success', ?array $undo = null, ?int $duration = null): void
    {
        $this->dispatch('toast', title: $title, body: $body, type: $type, undo: $undo, duration: $duration ?? ($undo ? 10000 : 5000));
    }

    protected function toastSuccess(string $title, ?string $body = null): void
    {
        $this->toast($title, $body, 'success');
    }

    protected function toastWarning(string $title, ?string $body = null): void
    {
        $this->toast($title, $body, 'warning');
    }

    protected function toastDanger(string $title, ?string $body = null): void
    {
        $this->toast($title, $body, 'danger');
    }
}
