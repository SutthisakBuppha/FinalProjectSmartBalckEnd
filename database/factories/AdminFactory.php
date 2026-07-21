<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Admin>
 */
class AdminFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username'   => $this->faker->unique()->userName(),
            'email'      => $this->faker->unique()->safeEmail(),
            'password'   => Hash::make('password'), // รหัสผ่านเริ่มต้นสำหรับสุ่มเทส
            'full_name'  => $this->faker->name(),
            'role_label' => 'admin',
            'avatar_url' => $this->faker->imageUrl(),
        ];
    }
}
