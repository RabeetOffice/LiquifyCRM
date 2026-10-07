<?php

declare(strict_types=1);

namespace App\Modules\Departments\Filament\App\Resources\DepartmentResource\Pages;

use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Filament\App\Resources\DepartmentResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateDepartment extends CreateRecord
{
    protected static string $resource = DepartmentResource::class;

    protected static ?string $title = 'Create department';

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        Audit::log('departments.created', $this->record, ['name' => $this->record->getAttribute('name')], 'Department created', logName: 'departments');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
