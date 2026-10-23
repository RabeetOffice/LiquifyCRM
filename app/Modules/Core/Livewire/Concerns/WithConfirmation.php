<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire\Concerns;

use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Locked;
use Livewire\ImplicitlyBoundMethod;

/**
 * A confirm dialog before anything hard to undo, optionally re-asking the
 * password (member removal, database update, rollback).
 *
 * What runs is set on the server only (`$confirm` is locked: the browser
 * cannot name another method or drop the password), and a method that must
 * only run after the dialog starts with `ensureConfirmed()`, so calling it
 * straight from the browser skips nothing.
 */
trait WithConfirmation
{
    /** @var array{method: string, params: array<int, mixed>, title: string, body: ?string, label: string, tone: string, password: bool}|null */
    #[Locked]
    public ?array $confirm = null;

    public string $confirmPassword = '';

    /** True only while runConfirmed() runs the confirmed method, after its password check. */
    private bool $runningConfirmed = false;

    /**
     * @param  array<int, mixed>  $params
     */
    protected function askConfirmation(
        string $method,
        array $params = [],
        string $title = 'Are you sure?',
        ?string $body = null,
        string $label = 'Confirm',
        string $tone = 'primary',
        bool $password = false,
    ): void {
        $this->confirm = compact('method', 'params', 'title', 'body', 'label', 'tone', 'password');
        $this->confirmPassword = '';
        $this->resetErrorBag('confirmPassword');
    }

    public function cancelConfirm(): void
    {
        $this->confirm = null;
        $this->confirmPassword = '';
    }

    public function runConfirmed(): void
    {
        if ($this->confirm === null) {
            return;
        }

        $this->resetErrorBag('confirmPassword');

        if ($this->confirm['password']) {
            $user = auth()->user();

            if ($user === null || ! Hash::check($this->confirmPassword, $user->getAuthPassword())) {
                $this->addError('confirmPassword', 'That is not your current password.');

                return;
            }
        }

        $confirm = $this->confirm;
        $this->confirm = null;
        $this->confirmPassword = '';

        $this->runningConfirmed = true;

        try {
            // Through Livewire's own binder, as a click would: a method that also takes an
            // action (`removeBrand(int $id, RemoveMemberFromBrand $remove)`) gets it injected.
            ImplicitlyBoundMethod::call(app(), [$this, $confirm['method']], $confirm['params']);
        } finally {
            $this->runningConfirmed = false;
        }
    }

    /** A method behind the dialog (and its password) stops when it is called from the browser directly. */
    protected function ensureConfirmed(): void
    {
        abort_unless($this->runningConfirmed, 403, 'Confirm this first.');
    }
}
