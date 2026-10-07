<?php

declare(strict_types=1);

namespace App\Modules\Brands\Filament\App\Resources\MemberResource\Pages;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\AddMemberToBrand;
use App\Modules\Brands\Filament\App\Resources\MemberResource;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Departments\Models\Department;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * "Add person": create the login here with a password (the alternative to
 * an invite link), give them a role and a department in one step.
 */
final class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    protected static ?string $title = 'Add person';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Person')
                ->description('If this email already has a LiquifyCRM account, that account is added to the brand and the password is left unchanged.')
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')->email()->required()->maxLength(255),
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->rule(Password::min(10)->letters()->numbers())
                        ->helperText('At least 10 characters with letters and numbers. Share it with them securely.'),
                ])
                ->columns(2),
            Section::make('Access')
                ->schema([
                    Select::make('role')
                        ->label('Role in this brand')
                        ->options(MemberResource::roleOptions())
                        ->default(BrandRole::Agent->value)
                        ->required()
                        ->native(false),
                    Select::make('department_id')
                        ->label('Department')
                        ->options(fn (): array => MemberResource::departmentOptions())
                        ->required(fn (): bool => ! MemberResource::currentUser()->isGlobalAdmin())
                        ->native(false),
                ])
                ->columns(2),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Brand $brand */
        $brand = Filament::getTenant();
        $role = BrandRole::from($data['role']);
        $department = filled($data['department_id'] ?? null)
            ? Department::query()->findOrFail($data['department_id'])
            : null;

        MemberResource::guardRoleChoice($role, $department);

        return DB::transaction(function () use ($brand, $role, $department, $data): Model {
            $user = User::query()->where('email', mb_strtolower(trim($data['email'])))->first();

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => mb_strtolower(trim($data['email'])),
                    'password' => $data['password'],
                    'created_by' => auth()->id(),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return app(AddMemberToBrand::class)->handle(
                $brand,
                $user,
                $role,
                $department,
                $department ? DepartmentRole::from($role === BrandRole::Manager ? 'manager' : 'agent') : null,
                MemberResource::currentUser(),
            );
        });
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Person added to '.(Filament::getTenant()?->getAttribute('name') ?? 'the brand');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
