<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAndSettingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::forceCreate([
            'id' => (string) Str::ulid(),
            'code' => 'COMP-TEST',
            'name' => 'PT Solusi Inventaris',
        ]);

        $this->user = User::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'name' => 'Admin Utama',
            'email' => 'admin@test.com',
            'password' => bcrypt('password123'),
        ]);

        $this->user->assignRole('SUPER_ADMIN');
    }

    public function test_can_list_roles(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/roles');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.name', 'SUPER_ADMIN')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['name', 'description'],
                ],
            ]);
    }

    public function test_can_list_users_with_search_and_role_filter(): void
    {
        Sanctum::actingAs($this->user);

        $staffUser = User::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'name' => 'Budi Staff',
            'email' => 'budi@test.com',
            'password' => bcrypt('password123'),
        ]);
        $staffUser->assignRole('WAREHOUSE_STAFF');

        $response = $this->getJson('/api/v1/users');
        $response->assertStatus(200)
            ->assertJsonPath('data.total', 2);

        // Search test
        $searchResponse = $this->getJson('/api/v1/users?search=Budi');
        $searchResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.email', 'budi@test.com');

        // Role filter test
        $roleResponse = $this->getJson('/api/v1/users?role=SUPER_ADMIN');
        $roleResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.email', 'admin@test.com');
    }

    public function test_can_create_new_user_and_assign_role(): void
    {
        Sanctum::actingAs($this->user);

        $payload = [
            'name' => 'Dewi Auditor',
            'email' => 'dewi@test.com',
            'password' => 'secret123',
            'role' => 'AUDITOR',
        ];

        $response = $this->postJson('/api/v1/users', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Dewi Auditor')
            ->assertJsonPath('data.email', 'dewi@test.com');

        $newUser = User::where('email', 'dewi@test.com')->first();
        $this->assertNotNull($newUser);
        $this->assertTrue($newUser->hasRole('AUDITOR'));
        $this->assertTrue(Hash::check('secret123', $newUser->password));
    }

    public function test_can_update_user_details_and_role(): void
    {
        Sanctum::actingAs($this->user);

        $targetUser = User::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'name' => 'Target User',
            'email' => 'target@test.com',
            'password' => bcrypt('password123'),
        ]);
        $targetUser->assignRole('VIEWER');

        $payload = [
            'name' => 'Target User Updated',
            'email' => 'target.updated@test.com',
            'password' => 'newpassword123',
            'role' => 'WAREHOUSE_MANAGER',
        ];

        $response = $this->putJson("/api/v1/users/{$targetUser->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Target User Updated')
            ->assertJsonPath('data.email', 'target.updated@test.com');

        $targetUser->refresh();
        $this->assertEquals('Target User Updated', $targetUser->name);
        $this->assertEquals('target.updated@test.com', $targetUser->email);
        $this->assertTrue($targetUser->hasRole('WAREHOUSE_MANAGER'));
        $this->assertTrue(Hash::check('newpassword123', $targetUser->password));
    }

    public function test_can_delete_other_user(): void
    {
        Sanctum::actingAs($this->user);

        $targetUser = User::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'name' => 'To Delete',
            'email' => 'delete@test.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->deleteJson("/api/v1/users/{$targetUser->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $targetUser->id]);
    }

    public function test_cannot_delete_own_active_account(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("/api/v1/users/{$this->user->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_can_get_and_update_settings(): void
    {
        Sanctum::actingAs($this->user);

        $getResponse = $this->getJson('/api/v1/settings');
        $getResponse->assertStatus(200)
            ->assertJsonPath('data.company.name', 'PT Solusi Inventaris')
            ->assertJsonPath('data.settings.allow_negative_stock', 'false');

        $updatePayload = [
            'company' => [
                'name' => 'PT Solusi Inventaris Baru',
                'code' => 'COMP-NEW',
            ],
            'settings' => [
                'allow_negative_stock' => true,
                'company_address' => 'Gedung Cyber 2 Lantai 10, Jakarta Selatan',
                'timezone' => 'Asia/Makassar',
            ],
        ];

        $postResponse = $this->postJson('/api/v1/settings', $updatePayload);
        $postResponse->assertStatus(200);

        $this->company->refresh();
        $this->assertEquals('PT Solusi Inventaris Baru', $this->company->name);
        $this->assertEquals('COMP-NEW', $this->company->code);

        $this->assertDatabaseHas('settings', [
            'company_id' => $this->company->id,
            'key' => 'allow_negative_stock',
            'value' => 'true',
        ]);
        $this->assertDatabaseHas('settings', [
            'company_id' => $this->company->id,
            'key' => 'timezone',
            'value' => 'Asia/Makassar',
        ]);
    }

    public function test_can_update_profile_and_password(): void
    {
        Sanctum::actingAs($this->user);

        // Update name and email without password change
        $profilePayload = [
            'name' => 'Admin Baru',
            'email' => 'admin.baru@test.com',
        ];

        $response = $this->putJson('/api/v1/profile', $profilePayload);
        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Admin Baru')
            ->assertJsonPath('data.email', 'admin.baru@test.com');

        // Update password with wrong current password should fail
        $wrongPassPayload = [
            'name' => 'Admin Baru',
            'email' => 'admin.baru@test.com',
            'current_password' => 'wrongpassword',
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ];

        $failResponse = $this->putJson('/api/v1/profile', $wrongPassPayload);
        $failResponse->assertStatus(422);

        // Update password with correct current password
        $correctPassPayload = [
            'name' => 'Admin Baru',
            'email' => 'admin.baru@test.com',
            'current_password' => 'password123',
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ];

        $successPassResponse = $this->putJson('/api/v1/profile', $correctPassPayload);
        $successPassResponse->assertStatus(200);

        $this->user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $this->user->password));
    }
}
