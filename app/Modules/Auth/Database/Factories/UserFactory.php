<?php

declare(strict_types=1);

namespace App\Modules\Auth\Database\Factories;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'remember_token' => Str::random(10),
            'role' => null,
            'is_protected' => false,
            'status' => MembershipStatus::Active,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (): array => ['role' => GlobalRole::SuperAdmin, 'is_protected' => true]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => GlobalRole::Admin]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => MembershipStatus::Suspended]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (): array => ['app_authentication_secret' => Str::random(32)]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }
}
