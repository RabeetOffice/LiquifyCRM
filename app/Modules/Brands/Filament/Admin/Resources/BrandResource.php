<?php

declare(strict_types=1);

namespace App\Modules\Brands\Filament\Admin\Resources;

use App\Modules\Brands\Filament\Admin\Resources\BrandResource\Pages\CreateBrand;
use App\Modules\Brands\Filament\Admin\Resources\BrandResource\Pages\EditBrand;
use App\Modules\Brands\Filament\Admin\Resources\BrandResource\Pages\ListBrands;
use App\Modules\Brands\Models\Brand;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Brand')
                ->description('The workspace that owns websites, Facebook Pages, leads and people.')
                ->schema(self::basicsSchema())
                ->columns(2),
            Section::make('Look')
                ->description('Logo and colours used for the brand chip, chart line and header strip. The app itself stays Liquify Orange.')
                ->schema(self::lookSchema())
                ->columns(2),
            Section::make('Rules')
                ->description('Defaults that apply inside this brand.')
                ->schema(self::rulesSchema())
                ->columns(2)
                ->collapsible(),
        ]);
    }

    /** @return array<int, Component> */
    public static function basicsSchema(): array
    {
        $currencies = config('liquify.currencies');
        $timezones = DateTimeZone::listIdentifiers();

        return [
            TextInput::make('name')
                ->label('Brand name')
                ->placeholder('UK Publishing House')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, Get $get, ?string $state, ?string $old): void {
                    if (blank($get('slug')) || $get('slug') === Str::slug((string) $old)) {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')
                ->label('Address')
                ->prefix(url('/b').'/')
                ->required()
                ->alphaDash()
                ->maxLength(80)
                ->unique(ignoreRecord: true)
                ->helperText('Used in the address bar. Lowercase letters, numbers and dashes.'),
            TextInput::make('website')
                ->label('Website')
                ->placeholder('https://ukpublishinghouse.co.uk')
                ->url()
                ->maxLength(255)
                ->prefixIcon(Heroicon::OutlinedGlobeAlt)
                ->helperText('Favicon and colours will be fetched from here automatically (coming in week 5).'),
            Select::make('currency')
                ->label('Currency')
                ->options(array_combine($currencies, $currencies))
                ->default(config('liquify.default_currency'))
                ->required()
                ->native(false)
                ->helperText('Every sale in this brand is recorded in this currency.'),
            Select::make('timezone')
                ->label('Timezone')
                ->options(array_combine($timezones, $timezones))
                ->default(config('liquify.default_timezone'))
                ->searchable()
                ->required(),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Inactive brands are hidden from everyone and stop receiving leads.'),
        ];
    }

    /** @return array<int, Component> */
    public static function lookSchema(): array
    {
        return [
            FileUpload::make('logo_path')
                ->label('Logo')
                ->image()
                ->disk('public')
                ->directory('brands/logos')
                ->visibility('public')
                ->maxSize(2048)
                ->helperText('SVG or PNG on a transparent background works best.'),
            FileUpload::make('favicon_path')
                ->label('Favicon')
                ->image()
                ->disk('public')
                ->directory('brands/favicons')
                ->visibility('public')
                ->maxSize(1024),
            ColorPicker::make('colors.primary')
                ->label('Primary colour')
                ->default('#FF6B2C'),
            ColorPicker::make('colors.secondary')
                ->label('Secondary colour'),
        ];
    }

    /** @return array<int, Component> */
    public static function rulesSchema(): array
    {
        return [
            TextInput::make('settings.reveal_limit_per_hour')
                ->label('Reveals per Agent per hour')
                ->numeric()
                ->minValue(1)
                ->maxValue(500)
                ->default(config('liquify.reveal_limit_per_hour'))
                ->helperText('How many phone numbers or emails one Agent may reveal in an hour.'),
            TagsInput::make('settings.notification_emails')
                ->label('Notification emails')
                ->placeholder('Add an email and press Enter')
                ->helperText('Who hears about new leads before they are sent to a department.'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('logo_path')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(fn (Brand $record): string => 'https://ui-avatars.com/api/?background='.ltrim($record->primaryColor() ?? '#FF6B2C', '#').'&color=fff&name='.urlencode($record->name))
                    ->size(36),
                TextColumn::make('name')
                    ->label('Brand')
                    ->description(fn (Brand $record): string => '/b/'.$record->slug)
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('website')
                    ->label('Website')
                    ->formatStateUsing(fn (?string $state): string => $state ? (string) parse_url($state, PHP_URL_HOST) : '')
                    ->url(fn (Brand $record): ?string => $record->website, shouldOpenInNewTab: true)
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('currency')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('departments_count')
                    ->label('Departments')
                    ->counts('departments')
                    ->alignEnd(),
                TextColumn::make('memberships_count')
                    ->label('People')
                    ->counts('memberships')
                    ->alignEnd(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Brand $record): string => url('/b/'.$record->slug))
                    ->color('gray'),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBuildingStorefront)
            ->emptyStateHeading('No brands yet')
            ->emptyStateDescription('A brand is a workspace for one business. Create your first one to start collecting leads.')
            ->emptyStateActions([
                CreateAction::make()->label('Add your first brand'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['departments', 'memberships']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBrands::route('/'),
            'create' => CreateBrand::route('/create'),
            'edit' => EditBrand::route('/{record}/edit'),
        ];
    }
}
