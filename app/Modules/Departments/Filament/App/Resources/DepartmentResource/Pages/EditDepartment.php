<?php

declare(strict_types=1);

namespace App\Modules\Departments\Filament\App\Resources\DepartmentResource\Pages;

use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Filament\App\Resources\DepartmentResource;
use App\Modules\Departments\Models\Department;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditDepartment extends EditRecord
{
    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->after(function (Department $record): void {
                    Audit::log('departments.deleted', $record, ['name' => $record->name], 'Department deleted', logName: 'departments');
                }),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Department $department */
        $department = $this->record;

        Audit::log('departments.updated', $department, ['changes' => $department->getChanges()], 'Department updated', logName: 'departments');
    }
}
