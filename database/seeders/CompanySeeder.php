<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['code' => 'DEFAULT'],
            [
                'name' => 'Default Company',
                'is_active' => true,
            ]
        );

        Setting::updateOrCreate(
            ['company_id' => $company->id, 'key' => 'allow_negative_stock'],
            ['value' => 'false']
        );
    }
}
