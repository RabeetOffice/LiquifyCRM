<?php

declare(strict_types=1);

namespace App\Modules\Auth\Filament\Admin\Resources\AdminResource\Pages;

use App\Modules\Auth\Filament\Admin\Resources\AdminResource;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditAdmin extends EditRecord
{
    protected static string $resource = AdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->after(function (User $record): void {
                    Audit::log('members.removed', $record, ['email' => $record->email], 'Removed '.$record->name, logName: 'members');
                }),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $record */
        $record = $this->record;

        if (array_key_exists('role', $data)) {
            $role = GlobalRole::tryFrom((string) $data['role']);

            if ($role !== $record->role) {
                abort_unless(auth()->user()?->can('assignGlobalRole', [User::class, $role]), 403);
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var User $record */
        $record = $this->record;

        Audit::log('members.updated', $record, ['changes' => array_diff_key($record->getChanges(), ['password' => 1, 'updated_at' => 1])], 'Updated '.$record->name, logName: 'members');
    }
}
