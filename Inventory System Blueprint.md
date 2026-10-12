# Inventory System — Comprehensive Blueprint

**Version:** 1.0  
**Architecture:** Standalone Inventory Microservice / Platform  
**Backend:** Laravel 12  
**Database:** MySQL 8  
**Cache / Queue:** Redis  
**Message Broker:** RabbitMQ  
**Frontend:** Vue 3  
**Authentication:** Laravel Sanctum + service credentials  
**API Documentation:** OpenAPI / Swagger  
**Deployment:** Docker Compose + Nginx Proxy Manager  

---

## 1. Purpose

The Inventory System is a standalone application that owns all inventory-related data and business rules.

Other applications — such as Billing, CRM, FTTH/Provisioning, Purchasing, Warehouse Mobile Apps, or ERP — integrate with Inventory through documented APIs and events.

### Core principle

> External systems must never access the Inventory database directly.

The Inventory service is the source of truth for:

- Products
- Warehouses and locations
- Stock balances
- Stock movements
- Reservations
- Serialized assets
- Transfers
- Receiving
- Returns
- Adjustments
- Inventory audit history
- Integration requests and events

The Billing System is **not redesigned** by this project. Billing is simply one external consumer of the Inventory API.

---

# 2. High-Level Architecture

```text
                             Internet / Internal Network
                                      │
                                      ▼
                           ┌────────────────────┐
                           │ Nginx Proxy Manager│
                           │ TLS / Reverse Proxy│
                           └─────────┬──────────┘
                                     │
                     ┌───────────────┴───────────────┐
                     │                               │
                     ▼                               ▼
              inventory.example.com           Other Applications
                     │                         Billing / CRM / FTTH
                     ▼                               │
          ┌───────────────────────┐                  │
          │    Inventory API      │◄─────────────────┘
          │      Laravel 12       │       REST API
          └───────────┬───────────┘
                      │
       ┌──────────────┼───────────────────┐
       │              │                   │
       ▼              ▼                   ▼
    MySQL           Redis             RabbitMQ
  persistent DB  cache + jobs       integration events
       │              │                   │
       └──────────────┴───────────────────┘
                      │
                      ▼
               Vue 3 Frontend
```

### Logical boundaries

```text
┌────────────────────────────────────────────────────────────┐
│                    INVENTORY SERVICE                        │
│                                                            │
│  Product │ Warehouse │ Stock │ Reservation │ Asset        │
│  Transfer │ Receiving │ Return │ Adjustment │ Audit        │
│                                                            │
│  REST API │ Webhooks │ RabbitMQ Events │ OpenAPI           │
└────────────────────────────────────────────────────────────┘

          ▲                ▲                  ▲
          │                │                  │
       Billing           CRM                FTTH
```

---

# 3. Architecture Principles

## 3.1 Inventory owns inventory state

Only Inventory changes:

- Stock quantities
- Reservations
- Asset state
- Warehouse balances
- Stock movements

An external application requests an operation through the API.

## 3.2 Database isolation

Inventory gets its own database:

```text
inventory_db
```

No other service should connect directly to this database.

## 3.3 API-first integration

Every integration-capable operation is exposed as an API contract.

## 3.4 Event-driven notifications

Inventory publishes events when important state changes occur.

## 3.5 Idempotent external operations

External applications may retry requests. The same request must not create duplicate stock operations.

## 3.6 Auditability

Every stock-changing operation must be traceable to:

- Who performed it
- Which system initiated it
- Which reference triggered it
- Which warehouse was affected
- Which products/assets changed
- When it happened

---

# 4. Functional Modules

## 4.1 Authentication & User Management

Features:

- User login
- Logout
- Password management
- User profile
- Role management
- Permission management
- API session authentication using Laravel Sanctum

Roles can initially include:

```text
SUPER_ADMIN
ADMIN
INVENTORY_MANAGER
WAREHOUSE_MANAGER
WAREHOUSE_STAFF
AUDITOR
VIEWER
```

Use granular permissions rather than putting all business authorization in roles.

Examples:

```text
products.view
products.create
products.update
warehouses.view
stock.view
stock.receive
stock.adjust
stock.transfer
reservations.create
reservations.release
assets.view
assets.assign
reports.view
integrations.manage
```

---

# 5. Product Domain

## 5.1 Product

Suggested fields:

```text
products
──────────────
id
sku
name
slug
description
category_id
brand_id
unit_id
product_type
track_stock
track_serial
track_batch
minimum_stock
maximum_stock
standard_cost
selling_price
status
metadata
created_at
updated_at
```

### Product types

```text
STOCK_ITEM
SERIALIZED_ITEM
SERVICE_ITEM
CONSUMABLE
ASSET
```

`product_type` is descriptive. `track_stock`, `track_serial`, and `track_batch` determine inventory behavior.

## 5.2 Category

```text
categories
──────────────
id
parent_id
code
name
description
status
```

Supports hierarchical categories.

Example:

```text
Network Equipment
├── ONU / ONT
├── Router
├── Switch
└── SFP

Cabling
├── Drop Cable
├── Patch Cord
└── Fiber Accessories
```

## 5.3 Brand

```text
brands
──────────────
id
name
code
status
```

## 5.4 Unit

Examples:

```text
PCS
METER
ROLL
BOX
UNIT
SET
```

---

# 6. Warehouse Domain

## 6.1 Warehouse

```text
warehouses
──────────────
id
code
name
address
phone
status
metadata
created_at
updated_at
```

Examples:

```text
MAIN
GUDANG-TGL
NOC
TECH-01
```

## 6.2 Warehouse Locations

Optional but recommended from the beginning.

```text
warehouse_locations
──────────────
id
warehouse_id
parent_id
code
name
type
status
```

Example:

```text
MAIN
├── RACK-A
│   ├── A-01
│   └── A-02
├── RACK-B
└── QUARANTINE
```

This allows physical stock to be tracked more precisely without creating another microservice.

---

# 7. Inventory Model

Inventory should distinguish three concepts:

```text
Physical / On-hand
Reserved
Available
```

Formula:

```text
Available = On-hand - Reserved
```

Example:

```text
On-hand      100
Reserved      20
Available     80
```

## 7.1 Stock Balance

A materialized balance table should be used for fast reads.

```text
stock_balances
──────────────
id
product_id
warehouse_id
location_id
quantity_on_hand
quantity_reserved
quantity_available
version
created_at
updated_at
```

`quantity_available` can be stored for fast reads, but all stock mutations must keep the values consistent inside database transactions.

`version` supports optimistic concurrency if needed.

---

# 8. Stock Movement Ledger

Every inventory mutation creates a movement record.

```text
stock_movements
──────────────
id
movement_number
product_id
warehouse_id
location_id
movement_type
quantity
balance_before
balance_after
unit_cost
reference_type
reference_id
external_system
external_reference
reason
metadata
created_by
created_at
```

## Movement types

```text
RECEIVE
TRANSFER_OUT
TRANSFER_IN
RESERVATION
RESERVATION_RELEASE
ISSUE
RETURN
ADJUSTMENT_IN
ADJUSTMENT_OUT
DAMAGE
LOST
FOUND
```

Never silently update stock without creating a corresponding movement.

---

# 9. Reservation Domain

Reservations protect stock for an external operation without immediately treating it as consumed/sold.

## 9.1 Reservation

```text
stock_reservations
──────────────
id
reservation_number
external_system
external_reference
warehouse_id
status
expires_at
notes
created_by
created_at
updated_at
```

## 9.2 Reservation Items

```text
stock_reservation_items
──────────────
id
reservation_id
product_id
quantity
fulfilled_quantity
released_quantity
```

## Reservation lifecycle

```text
PENDING
   │
   ▼
RESERVED
   ├───────────────┐
   ▼               ▼
CONFIRMED       RELEASED
   │
   ▼
FULFILLED
```

Reservation status names may be simplified during implementation, but the state transitions must be explicit.

## Example

An external system requests:

```text
Product: ONU-ZTE-F670L
Quantity: 5
```

Inventory checks:

```text
On-hand: 30
Reserved: 8
Available: 22
```

The reservation succeeds:

```text
On-hand: 30
Reserved: 13
Available: 17
```

---

# 10. Serialized Asset Domain

For equipment such as ONU, ONT, routers, switches, SFPs, and other individually tracked items, serial-level tracking is required.

## Asset

```text
inventory_assets
──────────────
id
asset_code
product_id
serial_number
batch_number
warehouse_id
location_id
status
condition
assigned_to_type
assigned_to_id
external_system
external_reference
metadata
created_at
updated_at
```

## Asset statuses

```text
AVAILABLE
RESERVED
ASSIGNED
INSTALLED
RETURNED
QUARANTINE
DAMAGED
LOST
DISPOSED
```

## Asset lifecycle

```text
RECEIVED
   │
   ▼
AVAILABLE
   │
   ▼
RESERVED
   │
   ▼
ASSIGNED
   │
   ▼
INSTALLED
   │
   ├──► RETURNED ──► AVAILABLE
   │
   └──► DAMAGED / DISPOSED
```

---

# 11. Receiving

Receiving adds stock into a warehouse.

## Receiving Header

```text
receipts
──────────────
id
receipt_number
warehouse_id
supplier_id
external_system
external_reference
status
received_at
received_by
notes
```

## Receipt Items

```text
receipt_items
──────────────
id
receipt_id
product_id
quantity_expected
quantity_received
unit_cost
batch_number
```

For serialized products, individual assets must be captured with their serial numbers.

Receipt flow:

```text
Expected
   ↓
Receiving
   ↓
Validated
   ↓
Posted
   ↓
Stock + movement created
```

---

# 12. Stock Transfer

Transfers move stock between warehouses or locations.

```text
stock_transfers
──────────────
id
transfer_number
source_warehouse_id
source_location_id
destination_warehouse_id
destination_location_id
status
requested_by
approved_by
shipped_at
received_at
notes
```

Transfer flow:

```text
DRAFT
  ↓
REQUESTED
  ↓
APPROVED
  ↓
IN_TRANSIT
  ↓
RECEIVED
```

For serialized assets, the exact asset IDs are transferred.

---

# 13. Stock Adjustment

Adjustments must be controlled and audited.

```text
stock_adjustments
──────────────
id
adjustment_number
warehouse_id
reason
status
requested_by
approved_by
approved_at
posted_at
notes
```

Reasons might include:

```text
COUNT_CORRECTION
DAMAGE
LOST
FOUND
SYSTEM_CORRECTION
OPENING_BALANCE
```

For sensitive adjustments, use approval workflow.

---

# 14. Returns

Returned stock needs a condition check before becoming available again.

```text
RETURNED
   ↓
INSPECTION
   ├── GOOD ──► AVAILABLE
   ├── DAMAGED ──► QUARANTINE
   └── DISPOSED ──► DISPOSED
```

The inventory record should preserve the complete asset history.

---

# 15. Supplier Domain

Suppliers can remain inside Inventory initially.

```text
suppliers
──────────────
id
code
name
contact_name
phone
email
address
tax_identifier
status
metadata
```

Purchasing can later become a separate service if the business grows.

---

# 16. Audit Log

The audit log is separate from the stock movement ledger.

Stock movement answers:

> What happened to inventory?

Audit log answers:

> Who changed what in the application?

```text
audit_logs
──────────────
id
user_id
service_credential_id
action
resource_type
resource_id
old_values
new_values
ip_address
user_agent
created_at
```

---

# 17. Integration Architecture

The Inventory service exposes three primary integration mechanisms.

```text
1. REST API
2. Webhooks
3. RabbitMQ Events
```

### REST API

Use for immediate request/response operations.

Examples:

- Check stock
- Create reservation
- Release reservation
- Get asset
- Receive stock
- Transfer stock

### Webhooks

Use when Inventory needs to notify a specific external application.

### RabbitMQ

Use for asynchronous, decoupled system events.

---

# 18. Service Credentials

Human users authenticate with Laravel Sanctum.

External systems authenticate with dedicated service credentials.

Example:

```text
Billing
  └── svc_billing_inventory

CRM
  └── svc_crm_inventory

FTTH
  └── svc_ftth_inventory
```

Each credential has scopes.

Example:

```text
products.read
stock.read
reservations.create
reservations.read
reservations.release
assets.read
assets.assign
```

Never give every external system full administrative permissions.

## Credential table

```text
service_credentials
──────────────
id
name
client_id
secret_hash
status
last_used_at
expires_at
created_at
updated_at
```

The secret must only be displayed once at creation time.

---

# 19. External References

Inventory should never require another application's internal database ID.

Every integration request should support:

```text
external_system
external_reference
```

Example:

```text
external_system: billing
external_reference: ORDER-2026-000123
```

or:

```text
external_system: ftth
external_reference: INSTALL-2026-000456
```

This allows Inventory to remain independent of the external application's database design.

---

# 20. API Versioning

Use versioned APIs from the beginning.

```text
/api/v1/...
```

Future versions:

```text
/api/v2/...
```

Do not break existing integrations when introducing a new version.

---

# 21. REST API Structure

Base URL:

```text
https://inventory.example.com/api/v1
```

## Products

```http
GET    /products
GET    /products/{product}
POST   /products
PATCH  /products/{product}
DELETE /products/{product}
```

## Categories / brands / units

```http
GET /categories
POST /categories
PATCH /categories/{category}

GET /brands
POST /brands
PATCH /brands/{brand}

GET /units
```

## Warehouses

```http
GET /warehouses
GET /warehouses/{warehouse}
POST /warehouses
PATCH /warehouses/{warehouse}
```

## Stock

```http
GET /stock
GET /stock/availability
GET /stock/{product}
GET /stock/movements
GET /stock/movements/{movement}
```

## Reservations

```http
POST /reservations
GET /reservations
GET /reservations/{reservation}
POST /reservations/{reservation}/confirm
POST /reservations/{reservation}/release
```

## Assets

```http
GET /assets
GET /assets/{asset}
GET /assets/serial/{serial}
POST /assets/{asset}/assign
POST /assets/{asset}/return
```

## Receiving

```http
POST /receipts
GET /receipts
GET /receipts/{receipt}
POST /receipts/{receipt}/post
```

## Transfer

```http
POST /transfers
GET /transfers
GET /transfers/{transfer}
POST /transfers/{transfer}/approve
POST /transfers/{transfer}/receive
```

## Adjustments

```http
POST /adjustments
GET /adjustments
GET /adjustments/{adjustment}
POST /adjustments/{adjustment}/approve
POST /adjustments/{adjustment}/post
```

---

# 22. API Request Standard

Example request:

```json
{
  "warehouse_id": "wh_main",
  "items": [
    {
      "product_id": "prod_123",
      "quantity": 5
    }
  ],
  "external_system": "billing",
  "external_reference": "ORDER-2026-00123"
}
```

Example response:

```json
{
  "data": {
    "id": "res_123",
    "status": "RESERVED",
    "external_system": "billing",
    "external_reference": "ORDER-2026-00123"
  },
  "meta": {}
}
```

Use a consistent response envelope across the API.

---

# 23. Error Standard

Use HTTP status codes consistently.

```text
200 OK
201 Created
202 Accepted
204 No Content
400 Bad Request
401 Unauthorized
403 Forbidden
404 Not Found
409 Conflict
422 Unprocessable Entity
429 Too Many Requests
500 Internal Server Error
```

Example:

```json
{
  "message": "Insufficient stock",
  "code": "INSUFFICIENT_STOCK",
  "errors": {
    "product_id": [
      "Available quantity is 3 but 5 was requested."
    ]
  }
}
```

Machine-readable error codes are important for integrations.

---

# 24. Idempotency

Every integration write operation should support:

```http
Idempotency-Key: billing-ORDER-2026-00123-reservation
```

Store idempotency records:

```text
idempotency_keys
──────────────
id
key
service_credential_id
request_hash
response_status
response_body
expires_at
created_at
```

If the same valid request arrives again, return the original response rather than executing the stock operation twice.

This protects against network retries and duplicate messages.

---

# 25. RabbitMQ Event Architecture

RabbitMQ is the asynchronous integration bus.

Suggested exchange:

```text
inventory.events
```

Event routing keys:

```text
inventory.stock.received
inventory.stock.adjusted
inventory.stock.transferred
inventory.stock.reserved
inventory.stock.released
inventory.asset.assigned
inventory.asset.returned
inventory.transfer.completed
```

Example event:

```json
{
  "event_id": "evt_01KXYZ",
  "event_type": "inventory.asset.assigned",
  "occurred_at": "2026-09-24T09:30:00Z",
  "source": "inventory-service",
  "version": 1,
  "data": {
    "asset_id": "asset_123",
    "product_id": "prod_456",
    "serial_number": "ZTE123456789",
    "external_system": "ftth",
    "external_reference": "INSTALL-2026-00129"
  }
}
```

---

# 26. Outbox Pattern

Inventory should use an Outbox table for reliable event publishing.

```text
Database Transaction
        │
        ├── stock update
        ├── movement insert
        └── outbox event insert
                 │
                 ▼
             Commit
                 │
                 ▼
          Queue Worker
                 │
                 ▼
             RabbitMQ
```

This prevents the failure scenario:

```text
Stock changed successfully
BUT
RabbitMQ event was never published
```

Outbox table:

```text
outbox_events
──────────────
id
event_id
event_type
aggregate_type
aggregate_id
payload
status
attempts
published_at
available_at
last_error
created_at
```

---

# 27. Webhooks

Webhooks are useful when an external application wants direct HTTP notifications.

## Webhook subscription

```text
webhook_subscriptions
──────────────
id
service_credential_id
url
events
secret
status
last_success_at
last_failure_at
created_at
updated_at
```

Sign every webhook using a secret.

Example header:

```text
X-Inventory-Signature: sha256=...
```

External systems must validate the signature before processing the message.

Webhook delivery should support:

- Retry
- Exponential backoff
- Delivery logs
- Timeout
- Disable after repeated failures
- Replay

---

# 28. Integration Example — Billing

Billing remains unchanged internally.

It only needs to call Inventory.

## Step 1 — Check availability

```http
GET /api/v1/stock/availability?product_id=prod_123&warehouse_id=wh_main
```

## Step 2 — Reserve

```http
POST /api/v1/reservations
```

```json
{
  "warehouse_id": "wh_main",
  "external_system": "billing",
  "external_reference": "ORDER-2026-00123",
  "items": [
    {
      "product_id": "prod_123",
      "quantity": 1
    }
  ]
}
```

## Step 3 — Confirm or release

If the external business process succeeds:

```http
POST /api/v1/reservations/res_123/confirm
```

If it is cancelled:

```http
POST /api/v1/reservations/res_123/release
```

Inventory remains responsible for its own stock state.

---

# 29. Integration Example — FTTH

An FTTH application can reserve and assign an ONU.

```text
FTTH installation
      │
      ▼
Check ONU availability
      │
      ▼
Reserve ONU
      │
      ▼
Assign serial-numbered asset
      │
      ▼
Install equipment
```

Inventory stores:

```text
external_system = ftth
external_reference = INSTALL-2026-00129
```

The FTTH system remains the owner of its installation workflow; Inventory remains the owner of the physical equipment state.

---

# 30. Vue 3 Frontend Architecture

Recommended structure:

```text
resources/frontend/
├── app/
│   ├── router/
│   ├── stores/
│   ├── services/
│   ├── composables/
│   └── types/
│
├── layouts/
├── components/
│   ├── ui/
│   ├── forms/
│   ├── tables/
│   └── inventory/
│
├── modules/
│   ├── dashboard/
│   ├── products/
│   ├── warehouses/
│   ├── stock/
│   ├── reservations/
│   ├── assets/
│   ├── transfers/
│   ├── receiving/
│   ├── adjustments/
│   ├── reports/
│   └── integrations/
│
└── App.vue
```

Recommended supporting libraries:

```text
Vue Router
Pinia
Axios or fetch-based API client
TypeScript
```

---

# 31. Frontend Screens

## Dashboard

Show:

- Total products
- Total warehouses
- On-hand units
- Reserved units
- Low-stock products
- Pending reservations
- Pending transfers
- Recent movements
- Asset status overview

## Product Management

- Product list
- Create product
- Edit product
- Category
- Brand
- Unit
- Stock settings
- Serial tracking
- Batch tracking

## Warehouse

- Warehouse list
- Locations
- Current stock
- Transfers

## Stock

- Stock overview
- Availability
- Movement history
- Receive
- Adjust
- Transfer
- Return

## Assets

- Asset list
- Serial search
- Asset detail
- Assignment
- Return
- History

## Reservations

- Reservation list
- Reservation detail
- Status
- External reference
- Expiration

## Integrations

- Service credentials
- Permissions/scopes
- Webhooks
- Event logs
- API logs
- Delivery history

---

# 32. API Documentation

Use OpenAPI as the contract source.

Recommended structure:

```text
docs/
├── openapi.yaml
├── integration-guide.md
├── authentication.md
├── products.md
├── stock.md
├── reservations.md
├── assets.md
├── webhooks.md
└── events.md
```

Swagger UI should expose the API documentation through the application, for example:

```text
https://inventory.example.com/api/docs
```

The OpenAPI document should define:

- Authentication
- Endpoints
- Parameters
- Request schemas
- Response schemas
- Error schemas
- Pagination
- Filters
- Sorting
- Idempotency
- Webhooks

---

# 33. Pagination, Filtering, Sorting

Every large collection endpoint must support pagination.

Example:

```http
GET /api/v1/products?page=1&per_page=25
```

Filtering:

```http
GET /api/v1/products?category_id=cat_1&status=active
```

Sorting:

```http
GET /api/v1/products?sort=name&direction=asc
```

Search:

```http
GET /api/v1/products?search=zte
```

Do not return unbounded result sets.

---

# 34. Concurrency and Stock Safety

Stock operations must happen inside database transactions.

For critical operations:

```text
BEGIN TRANSACTION
   │
   ├── lock/read current stock
   ├── validate available quantity
   ├── update stock balance
   ├── create movement
   ├── create reservation/asset change
   └── create outbox event
COMMIT
```

Use row-level locking when required.

For example, reservation should prevent two simultaneous requests from both consuming the same last unit.

---

# 35. Redis Responsibilities

Redis should be used for:

- Cache
- Laravel queues where appropriate
- Rate-limiting support
- Temporary locks
- Short-lived integration state
- Optional application sessions if desired

Examples of cached data:

```text
product:{id}
warehouse:{id}
stock-summary:{warehouse}:{product}
```

Do not use Redis as the system of record for inventory quantities.

MySQL remains the source of truth.

---

# 36. Queue Architecture

Use Laravel queue workers for application jobs such as:

```text
ProcessWebhook
PublishOutboxEvent
GenerateInventoryReport
SendLowStockNotification
ProcessImport
ProcessExport
RecalculateStock
```

Use Redis-backed Laravel queues for application/background jobs.

Use RabbitMQ for cross-system integration events.

The distinction should remain clear:

```text
Redis Queue
= internal application jobs

RabbitMQ
= integration / domain events across systems
```

---

# 37. Docker Architecture

Suggested services:

```text
docker-compose.yml

services:
  app
  nginx
  worker
  scheduler
  frontend
  mysql
  redis
  rabbitmq
```

Nginx Proxy Manager stays outside or in a separate infrastructure compose project.

Logical flow:

```text
NPM
 │
 ▼
Inventory Nginx
 │
 ├── Vue static assets
 └── Laravel application
          │
          ├── MySQL
          ├── Redis
          └── RabbitMQ
```

---

# 38. Production Docker Considerations

Do not run development tooling in production unnecessarily.

Separate:

```text
Development
Staging
Production
```

Production should include:

- Environment variables via secrets/secure configuration
- Persistent MySQL volume
- Persistent RabbitMQ volume where required
- Redis persistence only if the selected use cases require it
- Application logs
- Health checks
- Worker restart policies
- Database backups

---

# 39. Nginx Proxy Manager

Suggested host:

```text
inventory.example.com
```

NPM responsibilities:

- TLS certificates
- HTTPS termination
- Reverse proxy
- Public hostname

Internal traffic can remain Docker-network based.

External integrations should use HTTPS only.

---

# 40. Environment Variables

Example:

```env
APP_NAME=Inventory
APP_ENV=production
APP_URL=https://inventory.example.com

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=inventory
DB_USERNAME=inventory
DB_PASSWORD=...

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PORT=6379

RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_VHOST=/inventory
RABBITMQ_USER=inventory
RABBITMQ_PASSWORD=...

OPENAPI_ENABLED=true
```

Secrets must not be committed to Git.

---

# 41. Security

## User security

- Laravel Sanctum
- Password hashing
- Session/token expiration policy
- Role/permission authorization
- Login throttling
- Audit logging

## API security

- HTTPS only
- Service credentials
- Scope-based authorization
- Rate limiting
- Idempotency
- Request validation
- Signed webhooks

## Infrastructure security

- MySQL private network only
- Redis private network only
- RabbitMQ private network only
- No direct public ports for databases/brokers
- Restrict container networks

Public exposure should normally be limited to:

```text
443
```

through Nginx Proxy Manager.

---

# 42. Rate Limiting

Different limits should exist for:

```text
Human API requests
Internal service requests
Webhook endpoints
Authentication endpoints
```

Example policy:

```text
Public API:         60 req/min
Service API:       600 req/min
Authentication:     10 req/min
```

Actual limits should be tuned after observing real traffic.

---

# 43. Observability

Every important request should have a correlation ID.

Example:

```text
X-Request-ID: req_01KXYZ
```

Pass correlation IDs through:

```text
HTTP API
  ↓
Laravel Job
  ↓
RabbitMQ Event
  ↓
Webhook
```

Logs should include:

```text
request_id
event_id
service
user_id
service_credential_id
external_system
external_reference
operation
status
duration
```

---

# 44. Health Checks

Provide:

```http
GET /health
GET /health/live
GET /health/ready
```

Readiness should verify necessary dependencies such as:

- MySQL
- Redis
- RabbitMQ

The exact checks should be lightweight and avoid causing load.

---

# 45. Testing Strategy

## Unit tests

Test:

- Stock calculations
- Reservation rules
- Asset state transitions
- Permission rules
- Movement creation

## Feature/API tests

Test:

- Authentication
- Product API
- Warehouse API
- Stock API
- Reservation API
- Asset API
- Integration credentials

## Integration tests

Test:

```text
Laravel ↔ MySQL
Laravel ↔ Redis
Laravel ↔ RabbitMQ
Webhook delivery
Outbox publishing
```

## Concurrency tests

Especially test:

```text
Two clients reserve the last unit simultaneously
```

Only one should succeed when stock is insufficient for both.

---

# 46. Reporting

Initial reports:

```text
Stock by warehouse
Stock by product
Stock movement history
Low-stock report
Reserved stock report
Asset inventory
Asset assignment history
Transfer history
Adjustment history
Receiving report
Returns report
```

Later:

```text
Stock valuation
Inventory aging
Dead stock
Fast-moving items
Slow-moving items
Consumption trends
```

Reporting queries should not modify operational inventory data.

---

# 47. Import / Export

Useful initial features:

```text
CSV import products
CSV import opening stock
CSV import asset serial numbers
CSV export stock
CSV export movements
CSV export assets
```

Large imports should be queued rather than processed in a single HTTP request.

---

# 48. Notifications

The inventory application can later support:

```text
Low stock
Reservation expiration
Transfer pending
Receiving pending
Adjustment approval
Webhook failure
Integration credential expiration
```

Notification channels can include:

```text
Email
WhatsApp integration later
Internal notification
Webhook
```

The notification subsystem should not be tightly coupled to core stock logic.

---

# 49. Suggested Laravel Project Structure

```text
app/
├── Domain/
│   ├── Catalog/
│   │   ├── Models/
│   │   ├── Services/
│   │   └── Policies/
│   │
│   ├── Warehouse/
│   ├── Inventory/
│   ├── Reservation/
│   ├── Asset/
│   ├── Receiving/
│   ├── Transfer/
│   ├── Adjustment/
│   └── Integration/
│
├── Application/
│   ├── Commands/
│   ├── Queries/
│   ├── DTOs/
│   └── Actions/
│
├── Infrastructure/
│   ├── RabbitMQ/
│   ├── Webhooks/
│   ├── Cache/
│   └── Persistence/
│
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   ├── Resources/
│   └── Middleware/
│
└── Support/
```

This keeps business logic away from controllers.

---

# 50. Core Application Services

Suggested application actions:

```text
ReceiveStockAction
ReserveStockAction
ReleaseReservationAction
ConfirmReservationAction
AdjustStockAction
TransferStockAction
ReceiveTransferAction
AssignAssetAction
ReturnAssetAction
DisposeAssetAction
```

These actions should enforce the domain rules and create the necessary movements/audit records/events.

---

# 51. Example Transaction — Reservation

```text
POST /reservations
        │
        ▼
Validate service credential
        │
        ▼
Validate payload
        │
        ▼
BEGIN TRANSACTION
        │
        ├── lock stock balance
        ├── calculate available
        ├── reject if insufficient
        ├── increase reserved quantity
        ├── create reservation
        ├── create movement
        └── create outbox event
        │
        ▼
COMMIT
        │
        ▼
Return reservation response
```

RabbitMQ publication happens after the database transaction through the Outbox worker.

---

# 52. Data Relationships

Core relational model:

```text
categories
    │
    └── products
          │
          ├── stock_balances ─── warehouses
          │                     │
          │                     └── warehouse_locations
          │
          ├── stock_movements
          ├── stock_reservation_items ─── stock_reservations
          └── inventory_assets

warehouses
   ├── stock_balances
   ├── stock_movements
   ├── stock_reservations
   ├── receipts
   └── stock_transfers
```

---

# 53. Database Rules

Important constraints:

### Product SKU

```text
UNIQUE(sku)
```

### Warehouse code

```text
UNIQUE(code)
```

### Asset serial number

Usually:

```text
UNIQUE(serial_number)
```

unless the business explicitly permits the same serial in different manufacturers/domains.

### Stock balance

Unique by:

```text
(product_id, warehouse_id, location_id)
```

### External reference

Use indexed fields rather than assuming global uniqueness unless business rules require it.

---

# 54. Soft Deletes

Use soft deletes carefully.

Good candidates:

```text
products
categories
warehouses
brands
suppliers
```

Avoid deleting historical records such as:

```text
stock_movements
reservations
asset history
audit logs
```

Those should remain immutable.

---

# 55. Immutable History

The following records should generally be append-only:

```text
stock_movements
asset history
outbox events
audit logs
integration delivery logs
```

Corrections should create a new record rather than editing historical truth.

---

# 56. Integration Guide Template

Every external system should receive a guide containing:

```text
1. Base URL
2. Authentication
3. Required scopes
4. Rate limits
5. API version
6. Product lookup
7. Stock lookup
8. Reservation flow
9. Release flow
10. Asset lookup
11. Asset assignment
12. Webhook handling
13. Event handling
14. Idempotency
15. Error handling
16. Retry rules
17. Example requests
18. Example responses
19. Sandbox/testing environment
```

This becomes the standard contract for all future integrations.

---

# 57. Example Integration Contract

An external developer should be able to implement integration with only this information:

```text
Base URL:
https://inventory.example.com/api/v1

Authentication:
Service credential

Scopes:
stock.read
reservations.create
reservations.release

Workflow:
1. Check availability
2. Create reservation
3. Store reservation_id
4. Confirm or release
5. Consume webhook/event notifications
```

No access to Laravel source code should be necessary.

---

# 58. API Compatibility Rules

Once another system depends on the API:

- Do not rename response fields casually.
- Do not change meanings of status values without versioning.
- Do not remove an endpoint from v1 without a migration plan.
- Add fields in backward-compatible ways where possible.
- Document breaking changes.
- Version event schemas.

---

# 59. Development Environments

## Local

```text
Docker Compose
MySQL
Redis
RabbitMQ
Laravel
Vue/Vite
```

## Staging

Separate:

```text
staging database
staging Redis
staging RabbitMQ
staging credentials
```

Never let staging credentials reach production resources.

## Production

```text
inventory.example.com
inventory database
production Redis
production RabbitMQ
production service credentials
```

---

# 60. Backup Strategy

Minimum backup plan:

```text
MySQL
├── Daily full backup
└── Retention policy

Application
└── Git repository

RabbitMQ
└── Persistent configuration / definitions backup

Uploaded files
└── Separate backup strategy
```

The MySQL backup is the critical business backup because it contains inventory truth and historical records.

---

# 61. Disaster Recovery

Recovery should follow:

```text
1. Restore MySQL
2. Restore application
3. Restore integration configuration
4. Restore RabbitMQ definitions if needed
5. Start workers
6. Verify queues
7. Verify stock balances
8. Verify API health
9. Reprocess pending outbox events
```

The system should be designed so that events can safely be replayed where possible.

---

# 62. Monitoring

Monitor at minimum:

```text
Application uptime
HTTP error rate
API latency
MySQL health
MySQL storage
Redis health
RabbitMQ health
Queue depth
Failed jobs
Failed webhooks
Outbox backlog
Low-stock count
```

Business alerts can include:

```text
RabbitMQ queue growing continuously
Outbox backlog growing continuously
Webhook failure rate high
Database storage low
```

---

# 63. Security Boundaries

```text
PUBLIC
  │
  ▼
Nginx Proxy Manager
  │
  ▼
Laravel API
  │
  ├── MySQL (private)
  ├── Redis (private)
  └── RabbitMQ (private)
```

Do not expose:

```text
3306
6379
5672
15672
```

publicly unless there is a specific controlled administrative requirement.

RabbitMQ management UI should normally remain private.

---

# 64. Recommended Initial MVP

The first production-capable release should contain:

```text
Authentication
RBAC / Permissions
Product
Category
Brand
Unit
Warehouse
Warehouse Location
Stock Balance
Stock Movement
Receiving
Reservation
Serialized Asset
Transfer
Adjustment
Audit Log
REST API
Service Credentials
API documentation
Idempotency
Redis queue
RabbitMQ events
Outbox
Webhook foundation
Docker deployment
```

Avoid implementing complex purchasing, forecasting, accounting, or BI in the first release unless required by the actual business process.

---

# 65. Phase 2

After the MVP:

```text
Supplier
Purchase Order
Advanced Returns
Batch / Expiry
Stock Valuation
Advanced Reports
Import / Export
Webhook management UI
Event monitoring UI
Notification system
```

---

# 66. Phase 3

Later extensions:

```text
Barcode / QR scanning
Mobile warehouse application
Technician inventory
Field stock
Inventory forecasting
Reorder suggestions
Multi-company
Multi-tenant architecture
Advanced analytics
GIS-aware asset tracking
External ERP integrations
```

These should be added without changing the fundamental API ownership model.

---

# 67. Suggested Implementation Sequence

### Step 1 — Project foundation

```text
Laravel 12
Vue 3 + TypeScript
MySQL
Redis
RabbitMQ
Docker Compose
CI pipeline
```

### Step 2 — Authentication

```text
Sanctum
Users
Roles
Permissions
```

### Step 3 — Catalog

```text
Products
Categories
Brands
Units
```

### Step 4 — Warehouse

```text
Warehouses
Locations
```

### Step 5 — Inventory core

```text
Stock balances
Stock movements
Receiving
Adjustments
```

### Step 6 — Operational workflows

```text
Reservations
Transfers
Returns
Serialized Assets
```

### Step 7 — Integration platform

```text
Service credentials
REST API
OpenAPI
Idempotency
Outbox
RabbitMQ
Webhooks
```

### Step 8 — Reporting / operations

```text
Dashboard
Reports
Monitoring
Integration logs
```

### Step 9 — Production hardening

```text
Backups
Observability
Security review
Load/concurrency testing
Disaster recovery testing
```

---

# 68. Definition of Done for Inventory Core

Inventory core is ready for external integration when:

- Stock cannot become negative unless explicitly permitted by business rules.
- Every stock mutation creates an immutable movement.
- Reservation operations are transaction-safe.
- Serialized assets have unique and traceable identities.
- Transfers preserve source/destination history.
- External systems never require database access.
- Every integration request can be authenticated and authorized.
- Duplicate requests do not duplicate stock operations.
- Important state changes can generate events.
- API contracts are documented in OpenAPI.
- Audit logs identify the actor/system and reference.
- Database backups are tested.
- Health checks and worker monitoring exist.

---

# 69. Final Target Architecture

```text
                         ┌──────────────────────┐
                         │ Nginx Proxy Manager  │
                         │       HTTPS/TLS      │
                         └──────────┬───────────┘
                                    │
                                    ▼
                  ┌────────────────────────────────┐
                  │        INVENTORY PLATFORM      │
                  │                                │
                  │ Laravel 12 API                 │
                  │ Vue 3 Frontend                 │
                  │ Sanctum                        │
                  │ Service Credentials            │
                  │ OpenAPI / Swagger              │
                  │                                │
                  │ Product                        │
                  │ Warehouse                      │
                  │ Stock                          │
                  │ Reservation                   │
                  │ Asset                          │
                  │ Transfer                       │
                  │ Receiving                      │
                  │ Adjustment                     │
                  │ Audit                          │
                  └───────────┬────────────────────┘
                              │
             ┌────────────────┼──────────────────┐
             │                │                  │
             ▼                ▼                  ▼
          MySQL            Redis             RabbitMQ
        source of truth   cache/jobs        domain events
             │                │                  │
             └────────────────┼──────────────────┘
                              │
                        Integration Layer
                              │
           ┌──────────────────┼───────────────────┐
           │                  │                   │
           ▼                  ▼                   ▼
        Billing              CRM                FTTH
        System              System             System
```

The Inventory Platform is intentionally independent of all three systems.

---

# 70. Key Architectural Decision

The most important decision in this blueprint is:

```text
Inventory = owner of stock state

External systems = consumers/requesters of inventory capabilities

Database = private
API = public contract
Events = asynchronous notifications
```

This allows future integrations to be added without redesigning the Inventory core.

---

# 71. Recommended Repository Layout

A practical repository can start as:

```text
inventory-platform/
├── app/
├── bootstrap/
├── config/
├── database/
├── routes/
├── resources/
│   └── js/
├── tests/
├── docs/
│   ├── openapi.yaml
│   ├── integration/
│   │   ├── authentication.md
│   │   ├── billing.md
│   │   ├── crm.md
│   │   └── ftth.md
│   └── architecture/
│       └── decisions.md
├── docker/
├── docker-compose.yml
├── Dockerfile
├── .env.example
└── README.md
```

Keep integration guides in the Inventory repository so API behavior and documentation evolve together.

---

# 72. Architecture Decision Summary

| Area | Decision |
|---|---|
| Backend | Laravel 12 |
| Frontend | Vue 3 + TypeScript |
| Database | MySQL 8 / InnoDB |
| Cache | Redis |
| Internal jobs | Redis-backed Laravel queues |
| Integration events | RabbitMQ |
| User authentication | Laravel Sanctum |
| Machine-to-machine auth | Service credentials + scopes |
| API | REST, versioned `/api/v1` |
| API docs | OpenAPI / Swagger |
| External notifications | Webhooks + RabbitMQ events |
| Reliability | Transactions + idempotency + outbox |
| Deployment | Docker Compose |
| Public ingress | Nginx Proxy Manager |
| Database ownership | Inventory only |
| Billing architecture | Out of scope; integrate through API/events |
| Source of truth | Inventory MySQL |

---

# 73. Guiding Rule for Future Integrations

When a new system needs Inventory, follow this process:

```text
1. Identify what the external system needs.
2. Map the requirement to an existing Inventory API capability.
3. Create a dedicated service credential.
4. Grant only required scopes.
5. Use external_system + external_reference.
6. Use idempotency for writes.
7. Consume webhooks/events when asynchronous notification is required.
8. Never access Inventory MySQL directly.
9. Never duplicate Inventory stock logic inside the external application.
10. Version the contract when a breaking change is necessary.
```

This is the core integration philosophy of the platform.
