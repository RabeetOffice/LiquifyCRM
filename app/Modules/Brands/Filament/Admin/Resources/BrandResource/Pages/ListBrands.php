<?php

declare(strict_types=1);

namespace App\Modules\Brands\Filament\Admin\Resources\BrandResource\Pages;

use App\Modules\Brands\Filament\Admin\Resources\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListBrands extends ListRecords
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add brand'),
        ];
    }
}
