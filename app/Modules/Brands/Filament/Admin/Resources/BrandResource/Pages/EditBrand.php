<?php

declare(strict_types=1);

namespace App\Modules\Brands\Filament\Admin\Resources\BrandResource\Pages;

use App\Modules\Brands\Filament\Admin\Resources\BrandResource;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\Audit;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

final class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Open workspace')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (Brand $record): string => url('/b/'.$record->slug)),
            DeleteAction::make()
                ->after(function (Brand $record): void {
                    Audit::log('brands.deleted', $record, ['name' => $record->name], 'Brand deleted', brand: $record->getKey(), logName: 'brands');
                }),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Brand $brand */
        $brand = $this->record;

        Audit::log('brands.updated', $brand, ['changes' => $brand->getChanges()], 'Brand updated', brand: $brand->getKey(), logName: 'brands');
    }
}
