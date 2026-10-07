<?php

declare(strict_types=1);

namespace App\Modules\Brands\Filament\App\Resources\MemberResource\Pages;

use App\Modules\Brands\Actions\RemoveMemberFromBrand;
use App\Modules\Brands\Filament\App\Resources\MemberResource;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    public function getTitle(): string
    {
        /** @var Membership $membership */
        $membership = $this->record;

        return $membership->user->name ?? 'Member';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Remove from brand')
                ->modalHeading('Remove from this brand')
                ->modalDescription('Their access ends immediately. Their account stays so history is kept.')
                ->using(function (Membership $record): void {
                    app(RemoveMemberFromBrand::class)->handle($record, MemberResource::currentUser());
                })
                ->successNotificationTitle('Member removed'),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Membership $membership */
        $membership = $this->record;
        $changes = $membership->getChanges();

        if (array_key_exists('role', $changes) || array_key_exists('status', $changes)) {
            Audit::log(
                array_key_exists('role', $changes) ? 'members.role_changed' : 'members.status_changed',
                $membership,
                ['user_id' => $membership->user_id, 'changes' => $changes],
                'Updated access for '.($membership->user->name ?? 'member'),
                logName: 'members',
            );
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
