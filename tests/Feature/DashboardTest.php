<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected Warehouse $warehouse;

    protected WarehouseLocation $location;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::forceCreate([
            'id' => (string) Str::ulid(),
            'code' => 'COMP-01',
            'name' => 'PT Test Company',
        ]);

        $this->user = User::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $unit = Unit::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'code' => 'PCS',
            'name' => 'Pieces',
        ]);

        $this->product = Product::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'sku' => 'ONU-TEST',
            'name' => 'Test ONT Modem',
            'unit_id' => $unit->id,
            'minimum_stock' => 5,
            'cost_price' => 200000,
            'selling_price' => 250000,
            'currency' => 'IDR',
        ]);

        $this->warehouse = Warehouse::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'code' => 'WH-TEST',
            'name' => 'Gudang Pusat',
            'is_active' => true,
        ]);

        $this->location = WarehouseLocation::forceCreate([
            'id' => (string) Str::ulid(),
            'warehouse_id' => $this->warehouse->id,
            'code' => 'LOC-01',
            'name' => 'Rak A1',
            'is_default' => true,
        ]);
    }

    public function test_dashboard_returns_accurate_kpis_and_structure(): void
    {
        Sanctum::actingAs($this->user);

        // Create balance
        StockBalance::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_location_id' => $this->location->id,
            'quantity' => 10,
        ]);

        // Create a movement
        StockMovement::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_location_id' => $this->location->id,
            'type' => 'IN',
            'quantity' => 10,
            'balance_after' => 10,
            'remarks' => 'Inbound Test',
        ]);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'kpi' => [
                        'total_stock_value',
                        'total_physical_stock',
                        'total_products_in_stock',
                        'total_catalog_products',
                        'pending_transfers',
                        'pending_reservations',
                        'low_stock_count',
                    ],
                    'monthly_movements',
                    'low_stock_items',
                    'recent_movements',
                    'warehouses',
                ],
                'meta',
            ]);

        $kpi = $response->json('data.kpi');
        $this->assertEquals(2000000, $kpi['total_stock_value']); // 10 * 200,000
        $this->assertEquals(10, $kpi['total_physical_stock']);
        $this->assertEquals(1, $kpi['total_products_in_stock']);
        $this->assertEquals(1, $kpi['total_catalog_products']);
    }

    public function test_dashboard_supports_warehouse_filtering(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/dashboard?warehouse_id='.$this->warehouse->id);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'kpi',
                    'monthly_movements',
                    'low_stock_items',
                    'recent_movements',
                    'warehouses',
                ],
            ]);
    }
}
