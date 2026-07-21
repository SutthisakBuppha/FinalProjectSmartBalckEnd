<?php

namespace Tests\Feature;

use App\Models\Driver;
use Illuminate\Support\Str;
use Tests\TestCase;

class DriverAuthTest extends TestCase
{
    public function test_driver_login_success(): void
    {
        $password = 'driverpass123';
        $driver = Driver::create([
            'username' => 'driver_' . Str::lower(Str::random(10)),
            'password' => $password,
            'name' => 'Test Driver',
            'status' => 'ปฏิบัติงานปกติ',
        ]);

        $response = $this->postJson('/api/driver/login', [
            'username' => $driver->username,
            'password' => $password,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'driver_id' => $driver->driver_id,
                'name' => 'Test Driver',
            ])
            ->assertJsonStructure([
                'success',
                'token',
                'driver_id',
                'name',
                'avatar_url',
                'status',
            ]);
    }

    public function test_driver_login_wrong_password(): void
    {
        $driver = Driver::create([
            'username' => 'driver_' . Str::lower(Str::random(10)),
            'password' => 'driverpass123',
            'name' => 'Test Driver',
            'status' => 'ปฏิบัติงานปกติ',
        ]);

        $this->postJson('/api/driver/login', [
            'username' => $driver->username,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }
}
