<?php

namespace Tests\Feature;

use App\Console\Commands\DeliverWebhooksCommand;
use App\Console\Commands\ProcessOutboxCommand;
use App\Contracts\EventPublisher;
use App\Models\Company;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\ServiceCredential;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WebhookSubscription;
use App\Services\Integration\WebhookDeliveryService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected $company;

    protected $product;

    protected $warehouse;

    protected $location;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::forceCreate(['id' => (string) Str::ulid(), 'code' => 'T', 'name' => 'T']);
        $unit = Unit::forceCreate(['id' => (string) Str::ulid(), 'company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'PCS']);
        $this->product = Product::forceCreate([
            'id' => (string) Str::ulid(), 'company_id' => $this->company->id, 'sku' => 'P1', 'name' => 'P1',
            'unit_id' => $unit->id, 'has_serial_number' => false, 'minimum_stock' => 0,
        ]);
        $this->warehouse = Warehouse::forceCreate([
            'id' => (string) Str::ulid(), 'company_id' => $this->company->id, 'code' => 'W1', 'name' => 'W1',
        ]);
        $this->location = WarehouseLocation::forceCreate([
            'id' => (string) Str::ulid(), 'warehouse_id' => $this->warehouse->id,
            'code' => 'L1', 'name' => 'L1', 'is_default' => true,
        ]);

        $secret = 'secret123';
        ServiceCredential::create([
            'company_id' => $this->company->id, 'name' => 'billing', 'key_id' => 'svc_test',
            'secret_hash' => hash('sha256', $secret), 'scopes' => ['stock:read', 'reservations:write', 'reservations:read'],
        ]);
        $this->token = 'svc_test.'.$secret;

        $ref = Warehouse::first();
        app(StockService::class)->receive(
            $this->company->id, $this->product->id, $this->warehouse->id, $this->location->id, 10, $ref
        );
    }

    protected function payload(string $ref = 'INV-1'): array
    {
        return [
            'external_system' => 'billing', 'external_reference' => $ref, 'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 3]],
        ];
    }

    public function test_requires_valid_credential_and_scope(): void
    {
        $this->getJson('/api/v1/external/stock')->assertStatus(401);
        $this->withToken('svc_test.wrong')->getJson('/api/v1/external/stock')->assertStatus(401);

        ServiceCredential::where('key_id', 'svc_test')->update(['scopes' => json_encode(['stock:read'])]);
        $this->withToken($this->token)->getJson('/api/v1/external/stock')->assertOk();
        $this->withToken($this->token)->postJson('/api/v1/external/reservations', $this->payload(), ['Idempotency-Key' => 'a'])
            ->assertStatus(403);
    }

    public function test_idempotent_reservation_replays_response(): void
    {
        $h = ['Idempotency-Key' => 'k1'];
        $first = $this->withToken($this->token)->postJson('/api/v1/external/reservations', $this->payload(), $h);
        $first->assertStatus(201);

        $second = $this->withToken($this->token)->postJson('/api/v1/external/reservations', $this->payload(), $h);
        $second->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $this->withToken($this->token)->postJson('/api/v1/external/reservations', $this->payload('OTHER'), $h)
            ->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $this->withToken($this->token)->postJson('/api/v1/external/reservations', $this->payload('X'))
            ->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->withToken($this->token)->getJson('/api/v1/external/stock?sku=P1')
            ->assertJsonPath('data.data.0.available', 7);
    }

    public function test_duplicate_external_reference_is_rejected(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/external/reservations', $this->payload(), ['Idempotency-Key' => 'a'])->assertStatus(201);
        $this->withToken($this->token)->postJson('/api/v1/external/reservations', $this->payload(), ['Idempotency-Key' => 'b'])
            ->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REFERENCE');
    }

    public function test_outbox_published_and_webhook_delivered_with_signature(): void
    {
        $sub = WebhookSubscription::create([
            'company_id' => $this->company->id, 'name' => 'w', 'url' => 'https://example.test/hook',
            'secret' => 'whsec_x', 'events' => ['reservation.*'],
        ]);
        $this->withToken($this->token)->postJson('/api/v1/external/reservations', $this->payload(), ['Idempotency-Key' => 'a'])->assertStatus(201);
        $this->assertTrue(OutboxEvent::where('event_type', 'stock.moved')->exists());

        $published = [];
        $publisher = new class($published) implements EventPublisher
        {
            public function __construct(public array &$log) {}

            public function publish(string $routingKey, array $payload, string $eventId): void
            {
                $this->log[] = $routingKey;
            }
        };
        app(ProcessOutboxCommand::class)->processBatch($publisher);

        $this->assertContains('reservation.reserved', $published);
        $this->assertSame(0, OutboxEvent::where('status', 'PENDING')->count());
        $this->assertSame(1, $sub->deliveries()->count()); // only reservation.* matched

        Http::fake(['example.test/*' => Http::response('ok', 200)]);
        app(DeliverWebhooksCommand::class)->deliverBatch(app(WebhookDeliveryService::class));

        Http::assertSent(function ($request) {
            if (! preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $request->header('X-Inventory-Signature')[0] ?? '', $m)) {
                return false;
            }

            return hash_equals(hash_hmac('sha256', $m[1].'.'.$request->body(), 'whsec_x'), $m[2]);
        });
        $this->assertSame('SUCCESS', $sub->deliveries()->first()->status);
    }

    public function test_webhook_retries_with_backoff_then_fails(): void
    {
        $sub = WebhookSubscription::create([
            'company_id' => $this->company->id, 'name' => 'w', 'url' => 'https://example.test/hook',
            'secret' => 's', 'events' => ['*'],
        ]);
        $delivery = $sub->deliveries()->create([
            'event_id' => 'e1', 'event_type' => 'stock.moved', 'payload' => ['a' => 1], 'next_attempt_at' => now(),
        ]);
        Http::fake(['*' => Http::response('err', 500)]);

        $service = app(WebhookDeliveryService::class);
        $service->attempt($delivery);
        $this->assertSame('PENDING', $delivery->status);
        $this->assertTrue($delivery->next_attempt_at->isFuture());

        for ($i = 1; $i < config('integration.webhooks.max_attempts'); $i++) {
            $service->attempt($delivery);
        }
        $this->assertSame('FAILED', $delivery->status);
    }

    public function test_credential_secret_shown_once(): void
    {
        $user = User::factory()->create();
        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/service-credentials', [
            'name' => 'FTTH', 'scopes' => ['stock:read'],
        ]);
        $res->assertStatus(201);
        $token = $res->json('data.token');
        $this->assertStringStartsWith('svc_', $token);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/service-credentials')->assertOk()
            ->assertDontSee(explode('.', $token)[1]);
        $this->withToken($token)->getJson('/api/v1/external/stock')->assertOk();
    }
}
