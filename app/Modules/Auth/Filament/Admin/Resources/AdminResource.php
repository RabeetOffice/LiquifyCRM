<?php

declare(strict_types=1);

namespace App\Modules\Auth\Filament\Admin\Resources;

use App\Modules\Auth\Filament\Admin\Resources\AdminResource\Pages\CreateAdmin;
use App\Modules\Auth\Filament\Admin\Resources\AdminResource\Pages\EditAdmin;
use App\Modules\Auth\Filament\Admin\Resources\AdminResource\Pages\ListAdmins;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Support\Audit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/**
 * Admin accounts (Super Admins and Admins). Super Admin only: an Admin
 * manages Managers and Agents inside brands, never other admins.
 */
final class AdminResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Admin accounts';

    protected static ?string $modelLabel = 'admin account';

    protected static ?string $pluralModelLabel = 'admin accounts';

    protected static ?string $slug = 'admins';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Select::make('role')
                        ->label('Global role')
                        ->options(GlobalRole::class)
                        ->default(GlobalRole::Admin->value)
                        ->required()
                        ->native(false)
                        ->disabled(fn (?User $record): bool => $record !== null && $record->isSuperAdmin())
                        ->dehydrated(fn (?User $record): bool => $record === null || ! $record->isSuperAdmin())
                        ->helperText('Super Admins are protected: nobody else can edit, suspend or remove them.'),
                    Select::make('status')
                        ->options(MembershipStatus::class)
                        ->default(MembershipStatus::Active->value)
                        ->required()
                        ->native(false)
                        ->disabled(fn (?User $record): bool => $record !== null && ($record->isSuperAdmin() || $record->is(auth()->user())))
                        ->dehydrated(fn (?User $record): bool => $record === null || ! ($record->isSuperAdmin() || $record->is(auth()->user()))),
                ])
                ->columns(2),
            Section::make('Password')
                ->description('Set an initial password. Two-factor authentication is required on first sign-in.')
                ->schema([
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->rule(Password::min(12)->letters()->numbers())
                        ->helperText('At least 12 characters with letters and numbers. Leave empty to keep the current password.'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (User $record): string => $record->email),
                TextColumn::make('role')->badge(),
                IconColumn::make('is_protected')
                    ->label('Protected')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedShieldCheck)
                    ->falseIcon(Heroicon::OutlinedNoSymbol)
                    ->trueColor('primary')
                    ->falseColor('gray'),
                IconColumn::make('two_factor')
                    ->label('2FA')
                    ->state(fn (User $record): bool => $record->hasTwoFactorEnabled())
                    ->boolean(),
                TextColumn::make('status')->badge(),
                TextColumn::make('last_login_at')
                    ->label('Last sign-in')
                    ->since()
                    ->placeholder('never')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('suspend')
                    ->label(fn (User $record): string => $record->isSuspended() ? 'Reactivate' : 'Suspend')
                    ->icon(fn (User $record): Heroicon => $record->isSuspended() ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedNoSymbol)
                    ->color(fn (User $record): string => $record->isSuspended() ? 'success' : 'warning')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => auth()->user()?->can('suspend', $record) ?? false)
                    ->action(function (User $record): void {
                        $suspending = ! $record->isSuspended();
                        $record->forceFill(['status' => $suspending ? MembershipStatus::Suspended : MembershipStatus::Active])->save();

                        Audit::log($suspending ? 'members.suspended' : 'members.reactivated', $record, ['email' => $record->email], ($suspending ? 'Suspended ' : 'Reactivated ').$record->name, logName: 'members');

                        Notification::make()->title($suspending ? 'Account suspended' : 'Account reactivated')->success()->send();
                    }),
                DeleteAction::make()
                    ->after(function (User $record): void {
                        Audit::log('members.removed', $record, ['email' => $record->email, 'role' => $record->role?->value], 'Removed '.$record->name, logName: 'members');
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading('No admin accounts yet');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('role', [GlobalRole::SuperAdmin->value, GlobalRole::Admin->value]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdmins::route('/'),
            'create' => CreateAdmin::route('/create'),
            'edit' => EditAdmin::route('/{record}/edit'),
        ];
    }
}
