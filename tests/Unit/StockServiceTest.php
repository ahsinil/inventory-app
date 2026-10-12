<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\StockService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $company;

    protected $product;

    protected $warehouse;

    protected $location;

    protected $receipt;

    protected StockService $stockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stockService = new StockService;

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
        ]);

        $this->receipt = Receipt::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'receipt_number' => 'RC-001',
            'warehouse_id' => $this->warehouse->id,
        ]);
    }

    public function test_can_receive_stock()
    {
        $this->stockService->receive(
            $this->company->id,
            $this->product->id,
            $this->warehouse->id,
            $this->location->id,
            10,
            $this->receipt
        );

        $balance = StockBalance::where('product_id', $this->product->id)->first();
        $this->assertEquals(10, $balance->quantity);

        $movement = StockMovement::where('product_id', $this->product->id)->first();
        $this->assertNotNull($movement);
        $this->assertEquals('IN', $movement->type);
        $this->assertEquals(10, $movement->quantity);
        $this->assertEquals(10, $movement->balance_after);
    }

    public function test_cannot_issue_negative_stock()
    {
        $this->expectException(Exception::class);
        $this->stockService->issue(
            $this->company->id,
            $this->product->id,
            $this->warehouse->id,
            $this->location->id,
            10,
            $this->receipt
        );
    }
}
