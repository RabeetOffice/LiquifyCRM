<?php

declare(strict_types=1);

namespace App\Modules\Departments\Filament\App\Resources\DepartmentResource\RelationManagers;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Who leads and who works in this department. People must already be
 * members of the brand (see Members); here they get a seat and a role.
 */
final class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'People';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('manageMembers', $ownerRecord) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('role')
                ->label('Role in this department')
                ->options(DepartmentRole::class)
                ->required()
                ->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->weight('semibold')
                    ->description(fn (User $record): string => $record->email)
                    ->searchable(),
                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => DepartmentRole::tryFrom((string) $state)?->getLabel() ?? (string) $state)
                    ->color(fn (mixed $state): string => DepartmentRole::tryFrom((string) $state)?->getColor() ?? 'gray'),
                TextColumn::make('last_login_at')
                    ->label('Last sign-in')
                    ->since()
                    ->placeholder('never'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add person')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(function (Builder $query): Builder {
                        $brandId = Filament::getTenant()?->getKey();

                        return $query->whereHas('memberships', fn (Builder $memberships): Builder => $memberships
                            ->withoutGlobalScopes()
                            ->where('brand_id', $brandId)
                            ->where('status', 'active'));
                    })
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()->label('Person')->helperText('Only members of this brand are listed. Invite new people under Members.'),
                        Select::make('role')
                            ->label('Role in this department')
                            ->options(DepartmentRole::class)
                            ->default(DepartmentRole::Agent->value)
                            ->required()
                            ->native(false),
                    ])
                    ->after(function (User $record, array $data): void {
                        /** @var Department $department */
                        $department = $this->getOwnerRecord();

                        Audit::log('departments.member_added', $department, ['user_id' => $record->getKey(), 'role' => $data['role'] ?? null], "Added {$record->name} to {$department->name}", logName: 'departments');
                    }),
            ])
            ->recordActions([
                EditAction::make()->label('Change role'),
                DetachAction::make()
                    ->label('Remove')
                    ->after(function (User $record): void {
                        /** @var Department $department */
                        $department = $this->getOwnerRecord();

                        Audit::log('departments.member_removed', $department, ['user_id' => $record->getKey()], "Removed {$record->name} from {$department->name}", logName: 'departments');
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedUserGroup)
            ->emptyStateHeading('Nobody in this department yet')
            ->emptyStateDescription('Add a Manager to lead it, then the Agents who work the leads.');
    }
}
