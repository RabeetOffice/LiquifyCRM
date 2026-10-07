<?php

declare(strict_types=1);

namespace App\Modules\Brands\Filament\Admin\Resources\BrandResource\Pages;

use App\Modules\Brands\Filament\Admin\Resources\BrandResource;
use App\Modules\Core\Support\Audit;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;

/**
 * "Add brand" wizard: Basics, Look, Rules. One of the two wizards the
 * document asks for; the website identity fetch joins step 2 in week 5.
 */
final class CreateBrand extends CreateRecord
{
    use HasWizard;

    protected static string $resource = BrandResource::class;

    protected static ?string $title = 'Add brand';

    /** @return array<int, Step> */
    protected function getSteps(): array
    {
        return [
            Step::make('Basics')
                ->description('Name, address and currency')
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->schema(BrandResource::basicsSchema())
                ->columns(2),
            Step::make('Look')
                ->description('Logo and colours')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->schema(BrandResource::lookSchema())
                ->columns(2),
            Step::make('Rules')
                ->description('Reveal limit and notifications')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->schema(BrandResource::rulesSchema())
                ->columns(2),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        Audit::log('brands.created', $this->record, ['name' => $this->record->getAttribute('name')], 'Brand created', brand: $this->record->getKey(), logName: 'brands');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
