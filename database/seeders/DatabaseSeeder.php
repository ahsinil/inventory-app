<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            RolePermissionSeeder::class,
            CatalogSeeder::class,
        ]);

        $user = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'company_id' => Company::where('code', 'DEFAULT')->first()->id,
        ]);

        $user->assignRole('SUPER_ADMIN');
    }
}
