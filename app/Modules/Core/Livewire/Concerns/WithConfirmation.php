<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire\Concerns;

use Illuminate\Support\Facades\Hash;

/**
 * A confirm dialog before anything hard to undo, optionally re-asking the
 * password (member removal, database update, rollback).
 */
trait WithConfirmation
{
    /** @var array{method: string, params: array<int, mixed>, title: string, body: ?string, label: string, tone: string, password: bool}|null */
    public ?array $confirm = null;

    public string $confirmPassword = '';

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

        $this->{$confirm['method']}(...$confirm['params']);
    }
}
