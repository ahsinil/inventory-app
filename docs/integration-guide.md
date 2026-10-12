# Integration Guide (Billing / FTTH / external systems)

## 1. Credentials
Create a service credential in the app (`POST /api/v1/service-credentials`, scopes e.g. `stock:read`, `reservations:write`).
The token (`<key_id>.<secret>`) is shown **once**. Rotate with `POST /service-credentials/{id}/rotate`.
Send it as `Authorization: Bearer <token>`.

## 2. Conventions
- Base path: `/api/v1/external`. Docs: `/api/docs` (spec at `/openapi.yaml`).
- Error envelope: `{ "message", "code", "errors" }`; success: `{ "data", "meta" }`.
- `X-Request-ID`: send your own; echoed in responses and included as `correlation_id` in events.
- Rate limit: 60 req/min per credential (`RATE_LIMIT_EXTERNAL`); `429` when exceeded.
- **Idempotency**: all POSTs need `Idempotency-Key` (max 128 chars). Same key + same body replays the first response
  (`Idempotent-Replayed: true`); same key + different body → `422 IDEMPOTENCY_KEY_REUSED`; in progress → `409`.
  Keys are kept 24h.

## 3. Reservation flow
1. `GET /external/stock?sku=...` – check availability.
2. `POST /external/reservations` with `external_system` + `external_reference` (unique while active).
3. `POST /external/reservations/{id}/release` to cancel; unconfirmed reservations auto-expire via `expires_at`.

## 4. Events
Events are written to `outbox_events` atomically with the business change and delivered at-least-once.
Types: `stock.moved`, `reservation.reserved`, `reservation.released`, `reservation.expired`.
Envelope: `{ event_id, event_type, occurred_at, correlation_id, data }`. Consumers must dedupe by `event_id`.

### RabbitMQ
Set `EVENT_PUBLISHER=rabbitmq` and `RABBITMQ_*` in `.env`, `composer require php-amqplib/php-amqplib`,
then run the worker: `php artisan outbox:process`. Topic exchange `inventory.events`, routing key = event type.

### Webhooks
Register via `POST /api/v1/webhooks` (`events`: `["*"]`, `["reservation.*"]`, ...). Signing secret shown once.
Run the worker: `php artisan webhooks:deliver`.
Headers: `X-Inventory-Event`, `X-Inventory-Delivery`, `X-Inventory-Signature: t=<unix>,v1=<hex>`.
Verify: `hex = HMAC_SHA256(secret, "<t>.<raw body>")`; reject if `t` is older than 5 minutes.
Retries: 30s, 2m, 10m, 1h, 6h (6 attempts), then `FAILED`. Replay: `POST /api/v1/webhook-deliveries/{id}/replay`.
Delivery log: `GET /api/v1/webhooks/{id}/deliveries`.
