<?php

declare(strict_types=1);

namespace App\Modules\Departments\Filament\App\Resources\DepartmentResource\Pages;

use App\Modules\Departments\Filament\App\Resources\DepartmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDepartments extends ListRecords
{
    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Create department'),
        ];
    }
}
