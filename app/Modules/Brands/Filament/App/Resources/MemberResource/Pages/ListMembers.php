<?php

declare(strict_types=1);

namespace App\Modules\Brands\Filament\App\Resources\MemberResource\Pages;

use App\Modules\Brands\Filament\App\Resources\MemberResource;
use App\Modules\Brands\Filament\App\Widgets\PendingInvitationsWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MemberResource::inviteAction(),
            CreateAction::make()
                ->label('Add person')
                ->icon(Heroicon::OutlinedUserPlus)
                ->color('gray'),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            PendingInvitationsWidget::class,
        ];
    }
}
