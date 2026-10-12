<?php

namespace Tests\Feature;

use App\Actions\Inventory\ReserveStockAction;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\AssetService;
use App\Services\StockService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected $company;

    protected $product;

    protected $warehouse;

    protected $location;

    protected ReserveStockAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::forceCreate(['id' => (string) Str::ulid(), 'code' => 'TEST', 'name' => 'Test Company']);
        $unit = Unit::forceCreate(['id' => (string) Str::ulid(), 'company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'PCS']);

        $this->product = Product::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'sku' => 'TEST-01',
            'name' => 'Test Product',
            'unit_id' => $unit->id,
            'has_serial_number' => false,
            'minimum_stock' => 0,
        ]);

        $this->warehouse = Warehouse::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'code' => 'WH-1',
            'name' => 'Warehouse 1',
        ]);

        $this->location = WarehouseLocation::forceCreate([
            'id' => (string) Str::ulid(),
            'warehouse_id' => $this->warehouse->id,
            'code' => 'LOC-1',
            'is_default' => true,
        ]);

        $stockService = new StockService;
        $assetService = new AssetService;
        $this->action = new ReserveStockAction($stockService, $assetService);

        // Inject initial stock of 10
        StockBalance::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_location_id' => $this->location->id,
            'quantity' => 10,
        ]);
    }

    public function test_can_reserve_stock_and_decrements_available()
    {
        $reservation = StockReservation::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'reservation_number' => 'RES-001',
            'warehouse_id' => $this->warehouse->id,
            'status' => 'DRAFT',
        ]);

        $reservation->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 0,
        ]);

        $this->action->execute($reservation, 'user123', [
            ['product_id' => $this->product->id, 'quantity' => 10],
        ]);

        $balance = StockBalance::where('product_id', $this->product->id)->first();
        $this->assertEquals(0, $balance->quantity);
    }

    public function test_fails_to_over_reserve_stock()
    {
        $reservation = StockReservation::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'reservation_number' => 'RES-002',
            'warehouse_id' => $this->warehouse->id,
            'status' => 'DRAFT',
        ]);

        $reservation->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 0,
        ]);

        $this->expectException(Exception::class);
        $this->action->execute($reservation, 'user123', [
            ['product_id' => $this->product->id, 'quantity' => 11], // we only have 10
        ]);
    }
}
