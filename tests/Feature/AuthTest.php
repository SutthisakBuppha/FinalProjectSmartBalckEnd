<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Admin;

class AuthTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_login_success()
    {
        $admin = Admin::factory()->create([
            'password' => bcrypt('testpass')
        ]);

        $res = $this->postJson('/api/login', [
            'username' => $admin->username,
            'password' => 'testpass',
        ]);

        $res->assertStatus(200)
            ->assertJsonStructure(['token', 'admin']);
    }

    public function test_login_wrong_password()
    {
        $admin = Admin::factory()->create([
            'password' => bcrypt('correct')
        ]);

        $this->postJson('/api/login', [
            'username' => $admin->username,
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    public function test_logout()
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertStatus(200);
    }
}
