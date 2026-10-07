<?php

declare(strict_types=1);

namespace App\Modules\Brands\Filament\App\Resources;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\InviteMember;
use App\Modules\Brands\Actions\RemoveMemberFromBrand;
use App\Modules\Brands\Filament\App\Resources\MemberResource\Pages\CreateMember;
use App\Modules\Brands\Filament\App\Resources\MemberResource\Pages\EditMember;
use App\Modules\Brands\Filament\App\Resources\MemberResource\Pages\ListMembers;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Members screen: who has access to this brand and with which role.
 * Admins see everyone; a Manager sees the Agents of their departments.
 */
final class MemberResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Team';

    protected static ?string $navigationLabel = 'Members';

    protected static ?string $modelLabel = 'member';

    protected static ?string $pluralModelLabel = 'members';

    protected static ?string $slug = 'members';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Access')
                ->schema([
                    Select::make('role')
                        ->label('Role in this brand')
                        ->options(self::roleOptions())
                        ->required()
                        ->native(false)
                        ->disabled(fn (): bool => ! self::currentUser()->isGlobalAdmin())
                        ->dehydrated(fn (): bool => self::currentUser()->isGlobalAdmin())
                        ->helperText('Managers lead departments and assign leads. Agents work the leads assigned to them.'),
                    Select::make('status')
                        ->options(MembershipStatus::class)
                        ->required()
                        ->native(false)
                        ->helperText('Suspended members cannot sign in to this brand.'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('joined_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Name')
                    ->weight('semibold')
                    ->description(fn (Membership $record): string => $record->user->email ?? '')
                    ->searchable(['users.name', 'users.email'])
                    ->sortable(),
                TextColumn::make('role')
                    ->badge(),
                TextColumn::make('departments')
                    ->label('Departments')
                    ->state(fn (Membership $record): array => self::departmentNamesFor($record))
                    ->badge()
                    ->color('gray')
                    ->placeholder('Not in a department yet'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('user.last_login_at')
                    ->label('Last sign-in')
                    ->since()
                    ->placeholder('never'),
                TextColumn::make('joined_at')
                    ->label('Joined')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')->options(BrandRole::class),
                SelectFilter::make('status')->options(MembershipStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('toggleStatus')
                    ->label(fn (Membership $record): string => $record->isActive() ? 'Suspend' : 'Reactivate')
                    ->icon(fn (Membership $record): Heroicon => $record->isActive() ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
                    ->color(fn (Membership $record): string => $record->isActive() ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->visible(fn (Membership $record): bool => self::currentUser()->can('update', $record))
                    ->action(function (Membership $record): void {
                        $suspending = $record->isActive();
                        $record->forceFill(['status' => $suspending ? MembershipStatus::Suspended : MembershipStatus::Active])->save();

                        Audit::log($suspending ? 'members.suspended' : 'members.reactivated', $record, ['user_id' => $record->user_id], ($suspending ? 'Suspended ' : 'Reactivated ').($record->user->name ?? ''), logName: 'members');

                        Notification::make()->title($suspending ? 'Access suspended' : 'Access restored')->success()->send();
                    }),
                DeleteAction::make()
                    ->label('Remove')
                    ->modalHeading('Remove from this brand')
                    ->modalDescription('Their access ends immediately. Their account stays so history is kept.')
                    ->using(function (Membership $record): void {
                        app(RemoveMemberFromBrand::class)->handle($record, self::currentUser());
                    })
                    ->successNotificationTitle('Member removed'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading('No members yet')
            ->emptyStateDescription('Invite a Manager for each department. Managers then add their own Agents.')
            ->emptyStateActions([
                self::inviteAction(),
                CreateAction::make()->label('Add person with a password'),
            ]);
    }

    public static function inviteAction(): Action
    {
        return Action::make('invite')
            ->label('Invite by email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('primary')
            ->modalHeading('Invite someone to '.(Filament::getTenant()?->getAttribute('name') ?? 'this brand'))
            ->modalDescription('They receive an email with a link to set their password. The link works for '.config('liquify.invitation_expiry_days').' days.')
            ->modalSubmitActionLabel('Send invitation')
            ->schema([
                TextInput::make('email')->email()->required()->maxLength(255),
                TextInput::make('name')->label('Name (optional)')->maxLength(255),
                Select::make('role')
                    ->label('Role in this brand')
                    ->options(self::roleOptions())
                    ->default(BrandRole::Agent->value)
                    ->required()
                    ->native(false),
                Select::make('department_id')
                    ->label('Department')
                    ->options(fn (): array => self::departmentOptions())
                    ->required(fn (): bool => ! self::currentUser()->isGlobalAdmin())
                    ->native(false)
                    ->helperText('Managers lead the department; Agents work in it.'),
            ])
            ->action(function (array $data): void {
                /** @var Brand $brand */
                $brand = Filament::getTenant();
                $role = BrandRole::from($data['role']);
                $department = filled($data['department_id'] ?? null)
                    ? Department::query()->findOrFail($data['department_id'])
                    : null;

                self::guardRoleChoice($role, $department);

                app(InviteMember::class)->handle(
                    $brand,
                    $data['email'],
                    $role,
                    $department,
                    $department ? DepartmentRole::from($role === BrandRole::Manager ? 'manager' : 'agent') : null,
                    $data['name'] ?? null,
                    self::currentUser(),
                );

                Notification::make()->title('Invitation sent to '.$data['email'])->success()->send();
            });
    }

    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        $roles = self::currentUser()->isGlobalAdmin() ? BrandRole::assignable() : [BrandRole::Agent];

        return collect($roles)->mapWithKeys(fn (BrandRole $role): array => [$role->value => $role->getLabel()])->all();
    }

    /** @return array<int, string> */
    public static function departmentOptions(): array
    {
        $user = self::currentUser();
        /** @var Brand $brand */
        $brand = Filament::getTenant();

        $query = Department::query()->orderBy('name');

        if (! $user->isGlobalAdmin()) {
            $query->whereIn('id', $user->managedDepartmentIds($brand));
        }

        return $query->pluck('name', 'id')->all();
    }

    /**
     * Managers may only add Agents, and only into departments they manage.
     */
    public static function guardRoleChoice(BrandRole $role, ?Department $department): void
    {
        $user = self::currentUser();

        if ($user->isGlobalAdmin()) {
            return;
        }

        abort_if($role !== BrandRole::Agent, 403, 'Managers can only add Agents.');
        abort_if($department === null || ! $user->managesDepartment($department), 403, 'Choose one of your own departments.');
    }

    /** @return list<string> */
    private static function departmentNamesFor(Membership $record): array
    {
        $user = $record->user;

        if ($user === null) {
            return [];
        }

        return $user->departments()
            ->acrossBrands()
            ->where('departments.brand_id', $record->brand_id)
            ->orderBy('departments.name')
            ->pluck('departments.name')
            ->all();
    }

    public static function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['user', 'inviter']);
        $user = self::currentUser();
        $brand = Filament::getTenant();

        if ($user->isGlobalAdmin() || ! $brand instanceof Brand) {
            return $query;
        }

        // A Manager sees the Agents of the departments they lead.
        $managed = $user->managedDepartmentIds($brand);

        return $query
            ->where('role', BrandRole::Agent->value)
            ->whereHas('user.departments', fn (Builder $departments): Builder => $departments
                ->withoutGlobalScopes()
                ->whereIn('departments.id', $managed));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }
}
