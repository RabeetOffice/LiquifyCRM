<?php

declare(strict_types=1);

namespace App\Modules\Auth\Filament\Admin\Resources\AdminResource\Pages;

use App\Modules\Auth\Filament\Admin\Resources\AdminResource;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Support\Audit;
use Filament\Resources\Pages\CreateRecord;

final class CreateAdmin extends CreateRecord
{
    protected static string $resource = AdminResource::class;

    protected static ?string $title = 'Add admin';

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $role = GlobalRole::tryFrom((string) ($data['role'] ?? ''));

        abort_unless(auth()->user()?->can('assignGlobalRole', [User::class, $role]), 403);

        $data['is_protected'] = $role === GlobalRole::SuperAdmin;
        $data['created_by'] = auth()->id();
        $data['email_verified_at'] = now();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->record;

        Audit::log(
            $user->isSuperAdmin() ? 'members.super_admin_created' : 'members.admin_created',
            $user,
            ['email' => $user->email, 'role' => $user->role?->value],
            'Created '.$user->role?->getLabel().' '.$user->name,
            logName: 'members',
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
