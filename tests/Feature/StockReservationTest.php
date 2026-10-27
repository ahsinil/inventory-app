<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected Warehouse $warehouse;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::forceCreate([
            'id' => (string) Str::ulid(),
            'code' => 'COMP-TEST',
            'name' => 'PT Test Company',
        ]);

        $this->user = User::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'name' => 'Tester',
            'email' => 'tester@example.com',
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
            'sku' => 'SKU-001',
            'name' => 'Test Product',
            'unit_id' => $unit->id,
            'has_serial_number' => false,
            'minimum_stock' => 0,
        ]);

        $this->warehouse = Warehouse::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Gudang Utama',
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_can_create_reservation_with_custom_reservation_date(): void
    {
        $response = $this->postJson('/api/v1/reservations', [
            'warehouse_id' => $this->warehouse->id,
            'reservation_date' => '2026-05-15',
            'expires_at' => '2026-05-20',
            'remarks' => 'Custom reservation date test',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $reservation = StockReservation::where('remarks', 'Custom reservation date test')->first();
        $this->assertNotNull($reservation);
        $this->assertEquals('2026-05-15', $reservation->reservation_date->toDateString());
    }

    public function test_reservation_date_defaults_to_today_when_omitted(): void
    {
        $response = $this->postJson('/api/v1/reservations', [
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $reservation = StockReservation::latest()->first();
        $this->assertNotNull($reservation);
        $this->assertEquals(now()->toDateString(), $reservation->reservation_date->toDateString());
    }

    public function test_filter_reservations_by_reservation_date(): void
    {
        StockReservation::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'reservation_number' => 'RSV-001',
            'warehouse_id' => $this->warehouse->id,
            'status' => 'DRAFT',
            'reservation_date' => '2026-01-10',
        ]);

        StockReservation::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'reservation_number' => 'RSV-002',
            'warehouse_id' => $this->warehouse->id,
            'status' => 'DRAFT',
            'reservation_date' => '2026-02-15',
        ]);

        $response = $this->getJson('/api/v1/reservations?start_date=2026-02-01&end_date=2026-02-28');
        $response->assertStatus(200);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('RSV-002', $data[0]['reservation_number']);
    }

    public function test_can_create_reservation_with_multiple_items(): void
    {
        $secondProduct = Product::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'sku' => 'SKU-002',
            'name' => 'Second Product',
            'unit_id' => $this->product->unit_id,
            'has_serial_number' => false,
            'minimum_stock' => 0,
        ]);

        $response = $this->postJson('/api/v1/reservations', [
            'warehouse_id' => $this->warehouse->id,
            'reservation_date' => '2026-06-01',
            'remarks' => 'Multi-item reservation',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                ],
                [
                    'product_id' => $secondProduct->id,
                    'quantity' => 25,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $reservation = StockReservation::where('remarks', 'Multi-item reservation')->first();
        $this->assertNotNull($reservation);
        $this->assertCount(2, $reservation->items);
        $this->assertEquals(35, $reservation->items->sum('quantity'));
    }
}
