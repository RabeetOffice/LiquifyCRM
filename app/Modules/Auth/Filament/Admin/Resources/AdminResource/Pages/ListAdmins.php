<?php

declare(strict_types=1);

namespace App\Modules\Auth\Filament\Admin\Resources\AdminResource\Pages;

use App\Modules\Auth\Filament\Admin\Resources\AdminResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAdmins extends ListRecords
{
    protected static string $resource = AdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add admin'),
        ];
    }
}
