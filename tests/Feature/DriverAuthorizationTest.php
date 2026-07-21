<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Driver;
use Illuminate\Support\Str;
use Tests\TestCase;

class DriverAuthorizationTest extends TestCase
{
    public function test_driver_token_can_access_own_driver_scoped_routes(): void
    {
        $driver = $this->createDriver();
        $token = $driver->createToken('driver-token')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/drivers/{$driver->driver_id}/dashboard")
            ->assertStatus(200);

        $this->withToken($token)
            ->getJson("/api/app/drivers/{$driver->driver_id}")
            ->assertStatus(200);
    }

    public function test_driver_token_cannot_access_another_driver_scoped_routes(): void
    {
        $driver = $this->createDriver();
        $otherDriver = $this->createDriver();
        $token = $driver->createToken('driver-token')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/drivers/{$otherDriver->driver_id}/dashboard")
            ->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->withToken($token)
            ->getJson("/api/app/drivers/{$otherDriver->driver_id}")
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_driver_token_cannot_access_admin_routes(): void
    {
        $driver = $this->createDriver();
        $token = $driver->createToken('driver-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/drivers')
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_admin_token_can_access_admin_routes(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('admin-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/drivers')
            ->assertStatus(200);
    }

    private function createDriver(): Driver
    {
        return Driver::create([
            'username' => 'driver_' . Str::lower(Str::random(20)),
            'password' => 'driverpass123',
            'name' => 'Test Driver',
            'status' => 'ปฏิบัติงานปกติ',
        ]);
    }
}
