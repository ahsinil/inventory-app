<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::where('code', 'DEFAULT')->first();
        if (! $company) {
            return;
        }

        // Units
        $unitPcs = Unit::firstOrCreate(['company_id' => $company->id, 'code' => 'PCS'], ['name' => 'Pieces']);
        $unitRoll = Unit::firstOrCreate(['company_id' => $company->id, 'code' => 'ROLL'], ['name' => 'Roll']);
        $unitMeter = Unit::firstOrCreate(['company_id' => $company->id, 'code' => 'METER'], ['name' => 'Meter']);

        // Brands
        $brandZte = Brand::firstOrCreate(['company_id' => $company->id, 'code' => 'ZTE'], ['name' => 'ZTE']);
        $brandHuawei = Brand::firstOrCreate(['company_id' => $company->id, 'code' => 'HUAWEI'], ['name' => 'Huawei']);
        $brandGeneric = Brand::firstOrCreate(['company_id' => $company->id, 'code' => 'GENERIC'], ['name' => 'Generic']);

        // Categories
        $catNetwork = Category::firstOrCreate(['company_id' => $company->id, 'code' => 'NET'], ['name' => 'Network Equipment']);
        $catOnu = Category::firstOrCreate(['company_id' => $company->id, 'code' => 'ONU'], ['name' => 'Optical Network Unit (ONU)', 'parent_id' => $catNetwork->id]);
        $catCable = Category::firstOrCreate(['company_id' => $company->id, 'code' => 'CABLE'], ['name' => 'Cables', 'parent_id' => $catNetwork->id]);

        // Products
        Product::firstOrCreate(['company_id' => $company->id, 'sku' => 'ONU-ZTE-F609'], [
            'name' => 'ZTE F609 GPON ONT',
            'category_id' => $catOnu->id,
            'brand_id' => $brandZte->id,
            'unit_id' => $unitPcs->id,
            'has_serial_number' => true,
            'minimum_stock' => 10,
        ]);

        Product::firstOrCreate(['company_id' => $company->id, 'sku' => 'ONU-HW-EG8141'], [
            'name' => 'Huawei EG8141A5 GPON ONT',
            'category_id' => $catOnu->id,
            'brand_id' => $brandHuawei->id,
            'unit_id' => $unitPcs->id,
            'has_serial_number' => true,
            'minimum_stock' => 5,
        ]);

        Product::firstOrCreate(['company_id' => $company->id, 'sku' => 'CBL-FO-1CORE-1000M'], [
            'name' => 'Drop Core Fiber Optic 1 Core 1000M',
            'category_id' => $catCable->id,
            'brand_id' => $brandGeneric->id,
            'unit_id' => $unitRoll->id,
            'has_serial_number' => false,
            'minimum_stock' => 2,
        ]);

        // Warehouses
        $mainWarehouse = Warehouse::firstOrCreate(['company_id' => $company->id, 'code' => 'WH-MAIN'], [
            'name' => 'Main Warehouse Jakarta',
            'address' => 'Jl. Jend. Sudirman No. 1, Jakarta',
        ]);

        // Warehouse Locations
        WarehouseLocation::firstOrCreate(['warehouse_id' => $mainWarehouse->id, 'code' => 'WH-MAIN-DEFAULT'], [
            'name' => 'Default Staging Area',
            'is_default' => true,
        ]);
        WarehouseLocation::firstOrCreate(['warehouse_id' => $mainWarehouse->id, 'code' => 'RACK-A1'], [
            'name' => 'Rack A1 - ONUs',
            'is_default' => false,
        ]);

        // Suppliers
        Supplier::firstOrCreate(['company_id' => $company->id, 'code' => 'SUP-001'], [
            'name' => 'PT. Global Network Supply',
            'email' => 'sales@globalnetwork.example.com',
        ]);
    }
}
