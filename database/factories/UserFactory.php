<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'employee_id' => 'E'.fake()->unique()->numerify('#####'),
            'category' => 'staff',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            $user->forceFill([
                'username' => $user->username ?? Str::lower(Str::random(3)).fake()->unique()->numerify('####'),
            ]);
        });
    }

    public function local(string $password = 'Local-Password-2026'): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['auth_source' => 'local', 'password' => $password]));
    }

    public function inactive(): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['is_active' => false]));
    }
}
