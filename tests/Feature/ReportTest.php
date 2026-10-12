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

class ReportTest extends TestCase
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
            'code' => 'COMP-REP',
            'name' => 'PT Report Test',
        ]);

        $this->user = User::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'name' => 'Report Admin',
            'email' => 'report@example.com',
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
            'sku' => 'ONU-REP-01',
            'name' => 'Optical Network Terminal',
            'unit_id' => $unit->id,
            'minimum_stock' => 5,
            'cost_price' => 200000,
            'selling_price' => 280000,
            'currency' => 'IDR',
        ]);

        $this->warehouse = Warehouse::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'code' => 'WH-REP',
            'name' => 'Gudang Utama Report',
            'is_active' => true,
        ]);

        $this->location = WarehouseLocation::forceCreate([
            'id' => (string) Str::ulid(),
            'warehouse_id' => $this->warehouse->id,
            'code' => 'LOC-REP',
            'name' => 'Staging A',
            'is_default' => true,
        ]);
    }

    public function test_reports_chart_returns_valid_series(): void
    {
        Sanctum::actingAs($this->user);

        StockMovement::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_location_id' => $this->location->id,
            'type' => 'IN',
            'quantity' => 50,
            'balance_after' => 50,
            'remarks' => 'Inbound Report Test',
        ]);

        $response = $this->getJson('/api/v1/reports/chart?period=8w');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'max_volume',
                    'series' => [
                        '*' => ['label', 'in', 'out', 'volume'],
                    ],
                ],
                'meta',
            ]);
    }

    public function test_reports_export_streams_csv_for_all_types(): void
    {
        Sanctum::actingAs($this->user);

        StockBalance::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_location_id' => $this->location->id,
            'quantity' => 10,
        ]);

        $types = ['valuation', 'movements', 'low_stock', 'stock_balance'];

        foreach ($types as $type) {
            $response = $this->get('/api/v1/reports/export?type='.$type);

            $response->assertOk();
            $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
            $this->assertStringContainsString('attachment; filename=', (string) $response->headers->get('Content-Disposition'));
        }
    }
}
