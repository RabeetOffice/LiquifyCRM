<?php

declare(strict_types=1);

namespace App\Modules\Departments\Filament\App\Resources;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Departments\Filament\App\Resources\DepartmentResource\Pages\CreateDepartment;
use App\Modules\Departments\Filament\App\Resources\DepartmentResource\Pages\EditDepartment;
use App\Modules\Departments\Filament\App\Resources\DepartmentResource\Pages\ListDepartments;
use App\Modules\Departments\Filament\App\Resources\DepartmentResource\RelationManagers\MembersRelationManager;
use App\Modules\Departments\Models\Department;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Departments inside the current brand. Admins create, edit and remove;
 * Managers edit the profile of the departments they lead.
 */
final class DepartmentResource extends Resource
{
    protected static ?string $model = Department::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Team';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $brand = Filament::getTenant();

        if (! $user instanceof User || ! $brand instanceof Brand) {
            return false;
        }

        return $user->isGlobalAdmin() || $user->isManagerIn($brand);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Department')
                ->description('A team inside this brand. Leads, sales and targets are counted per department.')
                ->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->placeholder('Sales Team A')
                        ->required()
                        ->maxLength(120)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('brand_id', Filament::getTenant()?->getKey()),
                        ),
                    ColorPicker::make('color')
                        ->label('Colour')
                        ->helperText('Used for the department chip and chart line.'),
                    Textarea::make('description')
                        ->label('What this team does')
                        ->rows(3)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ColorColumn::make('color')->label('')->width('2.5rem'),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Department $record): ?string => $record->description ? str($record->description)->limit(80)->toString() : null),
                TextColumn::make('managers.name')
                    ->label('Managers')
                    ->badge()
                    ->color('info')
                    ->placeholder('No Manager yet'),
                TextColumn::make('agents_count')
                    ->label('Agents')
                    ->counts('agents')
                    ->alignEnd(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedRectangleGroup)
            ->emptyStateHeading('No departments yet')
            ->emptyStateDescription('Create a team such as Sales Team A, then add its Manager and Agents.')
            ->emptyStateActions([
                CreateAction::make()->label('Create a department'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('managers');
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDepartments::route('/'),
            'create' => CreateDepartment::route('/create'),
            'edit' => EditDepartment::route('/{record}/edit'),
        ];
    }
}
