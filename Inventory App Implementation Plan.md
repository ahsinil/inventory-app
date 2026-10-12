# Planning Aplikasi Pencatatan Gudang (Inventory App)

Dokumen ini adalah rencana implementasi berdasarkan [Inventory System Blueprint.md](./Inventory%20System%20Blueprint.md).

---

## 1. Review Blueprint

### Ringkasan
Blueprint mendefinisikan **Inventory Platform** standalone: Laravel 12 + Vue 3 (TS) + MySQL 8 + Redis + RabbitMQ, dengan REST API `/api/v1`, Sanctum (user), service credentials + scope (sistem eksternal), idempotency, outbox, webhook, dan deployment Docker Compose di belakang Nginx Proxy Manager.

### Kekuatan
- Batas tanggung jawab jelas (Inventory = pemilik state stok, DB privat, API = kontrak).
- Ledger `stock_movements` immutable + audit log terpisah.
- Pola reliabilitas matang: transaksi + row lock, idempotency key, outbox.
- Pemisahan Redis queue (job internal) vs RabbitMQ (event antar sistem).
- MVP dan fase 2/3 sudah dipisahkan.

### Risiko / Gap yang perlu diputuskan
| # | Temuan | Rekomendasi |
|---|---|---|
| 1 | Cakupan MVP (bagian 64) sangat besar untuk "aplikasi pencatatan gudang" (RabbitMQ, webhook, outbox, service credentials, OpenAPI sekaligus). | Pecah MVP menjadi **MVP-A (pencatatan gudang inti + UI)** dan **MVP-B (platform integrasi)**. |
| 2 | `stock_balances` unik `(product_id, warehouse_id, location_id)`; `location_id` nullable → MySQL mengizinkan duplikat NULL. | Gunakan lokasi default per gudang (NOT NULL) atau generated column / sentinel. |
| 3 | `quantity_available` disimpan sekaligus dihitung → risiko inkonsisten. | Gunakan generated column MySQL (`on_hand - reserved`) atau hitung saat query. |
| 4 | Serial unik global (`UNIQUE(serial_number)`) belum final. | Putuskan di awal; default unik per `product_id` atau global. |
| 5 | Reservasi tingkat gudang tapi `stock_balances` tingkat lokasi. | Reservasi di level gudang, alokasi lokasi saat fulfil/pick. |
| 6 | Lifecycle reservation (PENDING/RESERVED/CONFIRMED/FULFILLED/RELEASED) ambigu; `confirm` vs `fulfilled`. | Tetapkan state machine eksplisit + tabel transisi sebelum coding. |
| 7 | Tidak ada konsep expiry job untuk reservasi (`expires_at`). | Scheduler per menit untuk melepas reservasi kedaluwarsa. |
| 8 | Tidak ada stock opname/cycle count, padahal inti "pencatatan gudang". | Tambah modul **Stock Count** (Fase 2) — hasil → adjustment. |
| 9 | Multi-unit/konversi satuan, negatif stok belum dibahas. | Default: tanpa konversi, stok negatif dilarang (config). |
| 10 | Endpoint `DELETE /products` vs soft delete & histori. | Soft delete + blokir jika ada stok/histori aktif. |
| 11 | ID contoh (`prod_123`) berprefiks; kolom tabel `id`. | Gunakan ULID/UUID publik + auto-increment internal, atau ULID saja. |
| 12 | Suppliers ada di MVP receipts (`supplier_id`) tapi Supplier ada di Fase 2. | Pindahkan tabel `suppliers` dasar ke MVP-A (nullable di receipt). |

### Keputusan pemilik
| # | Keputusan | Dampak pada rencana |
|---|---|---|
| 1 | Integrasi eksternal tidak dibutuhkan sekarang, tapi **disiapkan dari awal**. | Fondasi integrasi (`external_system`, `external_reference`, ULID publik, response envelope, `/api/v1`, correlation ID, outbox table, idempotency) dibangun sejak Fase 0–3. Fitur berat (RabbitMQ publisher, webhook, UI credential) tetap Fase 6. |
| 2 | Serial number: unik **per company** (global lintas produk dalam satu company). Alasan: lookup `/assets/serial/{serial}` tanpa product, cegah duplikat lintas produk, constraint DB sederhana. | `UNIQUE(company_id, serial_number)`. Jika kelak ada vendor dengan serial bentrok, ubah ke `(company_id, product_id, serial_number)` lewat migrasi. |
| 3 | Stok negatif **boleh lewat toggle**, default **OFF**. | Setting `allow_negative_stock` (per company, bisa override per gudang). Dicek di `StockService`; movement tetap tercatat. Laporan menandai saldo negatif. |
| 4 | **Multi-company**, default **single** (1 company default dibuat via seeder). | Kolom `company_id` di semua tabel bisnis + global scope; user terhubung ke company; UI company switcher disembunyikan saat hanya ada 1 company. Flag `multi_company_enabled` default OFF. |
| 5 | Satu repo monolit (Laravel + Vue di `resources/js`) sesuai bagian 71 blueprint. | Tidak berubah. |

---

## 2. Tujuan & Ruang Lingkup

**Tujuan:** aplikasi web pencatatan gudang: master barang, gudang/lokasi, penerimaan, transfer, penyesuaian, reservasi, aset serial, dan riwayat lengkap, dengan API yang siap diintegrasikan.

**In scope (MVP-A):** Auth + RBAC, Katalog (product/category/brand/unit), Supplier dasar, Warehouse + Location, Stock balance + Movement, Receiving, Adjustment, Transfer, Reservation, Serialized Asset, Audit log, Dashboard + laporan dasar, Docker dev.

**In scope (MVP-B):** Service credentials + scope, OpenAPI/Swagger, Idempotency, Outbox, RabbitMQ events, Webhook, health check, deployment produksi.

**Out of scope:** Purchasing penuh, forecasting, akuntansi, BI, mobile app, barcode (Fase 2–3).

---

## 3. Tech Stack & Konvensi

| Area | Pilihan |
|---|---|
| Backend | Laravel 12, PHP 8.3+ |
| Frontend | Vue 3 + TypeScript + Vite, Pinia, Vue Router |
| DB | MySQL 8 (InnoDB) |
| Cache/Queue | Redis |
| Event bus | RabbitMQ (`vladimir-yuldashev/laravel-queue-rabbitmq` atau php-amqplib langsung) |
| Auth | Sanctum (SPA cookie) + service credentials (bearer token ber-scope) |
| RBAC | `spatie/laravel-permission` |
| Docs | OpenAPI (mis. `dedoc/scramble` atau `l5-swagger`) |
| Test | Pest/PHPUnit, Vitest, Playwright (E2E opsional) |
| Quality | Pint, Larastan, ESLint, vue-tsc, GitHub Actions |
| Infra | Docker Compose: app, nginx, worker, scheduler, frontend(dev), mysql, redis, rabbitmq |

**Konvensi:** struktur `app/Domain/*`, `Application/Actions`, `Infrastructure/*` (bagian 49); semua mutasi stok lewat Action tunggal; response envelope `{data, meta}`; error `{message, code, errors}`; ID publik ULID.

---

## 4. Rencana Fase & Milestone

Estimasi untuk 1 developer full-stack; sesuaikan.

### Fase 0 — Fondasi (±1 minggu)
- [x] Inisialisasi Laravel 12 + Vue 3 TS + Vite di repo
- [x] Docker Compose (mysql, redis, rabbitmq, app, nginx, worker, scheduler)
- [x] `.env.example`, Pint, Larastan, ESLint, CI (lint + test)
- [x] Struktur folder domain, base response/exception handler, ULID trait
- [x] Dokumen keputusan arsitektur (`docs/architecture/decisions.md`) — isi dari tabel gap & keputusan bagian 1
- [x] Multi-company foundation: tabel `companies`, trait `BelongsToCompany` + global scope, seeder company default, flag `multi_company_enabled` (OFF)
- [x] Tabel `settings` (per company/gudang): `allow_negative_stock` = false
- [x] Fondasi integrasi: ULID publik, kolom `external_system`/`external_reference` di tabel transaksi, `outbox_events`, middleware `X-Request-ID`, prefix `/api/v1`

### Fase 1 — Auth & RBAC (±1 minggu)
- [x] Users, login/logout SPA (Sanctum), throttle login
- [x] Roles & permission granular (seeder: 7 role, permission blueprint)
- [x] Middleware/policy, halaman login & profil, guard router Vue
- [x] Audit log dasar (trait/observer)

### Fase 2 — Katalog & Gudang (±1,5 minggu)
- [x] Migrasi + model + API: categories (hierarki), brands, units, products, suppliers
- [x] Migrasi + API: warehouses, warehouse_locations (+ lokasi default)
- [x] Soft delete, validasi unik (SKU, kode gudang)
- [x] UI: list/form/detail produk, kategori tree, gudang & lokasi
- [x] Seeder data demo (kategori jaringan, ONU, kabel)

### Fase 3 — Inti Stok (±2 minggu) ⭐ kritis
- [x] `stock_balances`, `stock_movements` (append-only, DB trigger/guard anti-update)
- [x] `StockService` internal: lock → validasi → update → movement (dalam transaksi)
- [x] Receiving: receipts + items, state Expected→Validated→Posted, `ReceiveStockAction`
- [x] Adjustment: approval workflow, `AdjustStockAction`
- [x] API + UI: stock overview, availability, riwayat movement, receive, adjust
- [x] Test unit: kalkulasi stok, tidak boleh negatif, movement selalu tercipta
- [x] Test konkurensi dasar

### Fase 4 — Alur Operasional (±2,5 minggu)
- [x] Serialized assets: `inventory_assets`, state machine, riwayat aset, input serial saat receiving
- [x] Transfer: Draft→Requested→Approved→In Transit→Received (stok & aset)
- [x] Reservation: state machine, confirm/release, expiry via scheduler
- [x] Returns: inspeksi (Good/Damaged/Disposed)
- [x] UI: asset list + pencarian serial, transfer, reservation, return
- [x] Test konkurensi: dua klien reservasi unit terakhir

### Fase 5 — Dashboard & Laporan (±1 minggu)
- [x] Dashboard (KPI, low stock, pending transfer/reservation, movement terbaru)
- [x] Laporan: stok per gudang/produk, movement, low-stock, reserved, aset, transfer, adjustment, receiving
- [x] Export CSV (queue)

> **Checkpoint — MVP-A selesai:** aplikasi pencatatan gudang bisa dipakai internal.

### Fase 6 — Platform Integrasi / MVP-B (±3 minggu)
- [x] `service_credentials` + scope middleware + UI manajemen (secret tampil sekali)
- [x] Endpoint v1 untuk sistem eksternal (`external_system`, `external_reference`)
- [x] Idempotency middleware (`Idempotency-Key`, `idempotency_keys`)
- [x] Outbox + worker publisher → RabbitMQ (exchange `inventory.events`)
- [x] Webhook: subscription, signature HMAC, retry/backoff, delivery log, replay
- [x] OpenAPI + Swagger UI (`/api/docs`), panduan integrasi (Billing/FTTH)
- [x] Rate limiting per tipe klien, correlation ID (`X-Request-ID`)

### Fase 7 — Hardening & Produksi (±1,5 minggu)
- [x] Health check (`/health`, `/live`, `/ready`)
- [x] Docker produksi, secrets, restart policy, jaringan privat
- [x] Backup MySQL + uji restore, runbook DR
- [x] Monitoring (queue depth, failed jobs, outbox backlog), logging terstruktur
- [x] Load & concurrency test, review keamanan
- [x] Setup NPM (TLS, hostname)

**Total estimasi:** ±14 minggu (MVP-A ≈ 9 minggu).

---

## 5. Arsitektur Teknis Inti

### 5.1 Pola mutasi stok (wajib)
```text
BEGIN
  SELECT ... FROM stock_balances WHERE ... FOR UPDATE
  validasi available
  update balance (version++)
  insert stock_movement (before/after)
  insert perubahan domain (reservation/asset/dll.)
  insert outbox_event (MVP-B)
COMMIT
```
Semua jalur (UI, API eksternal, job) memanggil Action yang sama.

### 5.2 State machine (didefinisikan di kode sebagai enum + transition map)
- Reservation, Asset, Transfer, Receipt, Adjustment, Return.
- Transisi tidak valid → `409 Conflict` dengan `code` mesin-baca.

### 5.3 Skema database — urutan migrasi
1. users, roles/permissions
2. units, brands, categories, products, suppliers
3. warehouses, warehouse_locations
4. stock_balances, stock_movements
5. receipts, receipt_items
6. stock_adjustments (+items)
7. inventory_assets (+ asset_histories)
8. stock_transfers (+items)
9. stock_reservations, stock_reservation_items
10. audit_logs
11. service_credentials, idempotency_keys, outbox_events, webhook_subscriptions, webhook_deliveries

> Catatan: blueprint belum mendefinisikan tabel item untuk adjustments/transfers, dan histori aset — perlu ditambahkan.

### 5.4 Struktur frontend (bagian 30)
Modul: dashboard, products, warehouses, stock, reservations, assets, transfers, receiving, adjustments, reports, integrations. Komponen UI dan form bersama, API client typed, Pinia store per modul.

---

## 6. Strategi Pengujian

| Level | Fokus |
|---|---|
| Unit | Kalkulasi stok, state machine, permission, pembuatan movement |
| Feature/API | Auth, katalog, stok, reservasi, aset, credential |
| Integration | MySQL, Redis, RabbitMQ, outbox, webhook |
| Concurrency | Reservasi unit terakhir paralel, double-submit idempotency |
| Frontend | Vitest untuk store/composable; E2E alur utama (receive → transfer → adjust) |

Target: cakupan Action/Service inti ≥ 90%.

---

## 7. Definition of Done (per fitur)
- Migrasi, model, Action, policy, API resource, validasi
- Test lulus (unit + feature) dan CI hijau
- Movement + audit log tercipta
- UI + loading/error/empty state
- OpenAPI diperbarui (untuk endpoint eksternal)

**Inventory core siap integrasi** jika seluruh poin Definition of Done bagian 68 blueprint terpenuhi.

---

## 8. Risiko & Mitigasi
| Risiko | Mitigasi |
|---|---|
| Race condition stok | Row lock + test konkurensi sejak Fase 3 |
| Scope creep | Kunci MVP-A, fitur lain ke backlog Fase 2/3 |
| Kompleksitas RabbitMQ di awal | Tunda ke Fase 6; outbox tetap ditulis lebih awal bila memungkinkan |
| Data inkonsisten saldo vs ledger | Job `RecalculateStock` + verifikasi berkala |
| Perubahan kontrak API | Versioning `/api/v1`, aturan kompatibilitas bagian 58 |

---

## 9. Langkah Berikutnya
1. Keputusan bagian 1 sudah dikonfirmasi (lihat tabel keputusan pemilik).
2. Mulai **Fase 0**: scaffold Laravel 12 + Vue 3 + Docker Compose.
3. Buat `docs/architecture/decisions.md` dari hasil keputusan.
