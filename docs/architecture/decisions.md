# Architecture Decisions Log

## 1. Integrasi Eksternal (MVP-B)
- **Konteks:** Sistem ditujukan untuk diintegrasikan dengan aplikasi eksternal (Billing, CRM, FTTH).
- **Keputusan:** Fitur khusus integrasi seperti RabbitMQ publisher, webhook, dan UI service credentials ditunda ke Fase 6, namun **fondasi integrasi disiapkan sejak awal (MVP-A)**.
- **Konsekuensi:** 
  - Struktur tabel transaksi sudah menyertakan `external_system` dan `external_reference`.
  - ID diekspos sebagai ULID publik.
  - Endpoint menggunakan prefix `/api/v1`.
  - Pola response dan error distandarisasi.
  - Implementasi Outbox (tabel `outbox_events`) diterapkan di semua mutasi stok sejak awal, walau workernya tidak jalan/belum ada di MVP-A.

## 2. Format Serial Number
- **Konteks:** Aset dilacak per unit.
- **Keputusan:** Serial number bersifat **unik per company** (`UNIQUE(company_id, serial_number)`).
- **Konsekuensi:** 
  - Bisa lookup aset hanya dengan kombinasi company + serial number (tanpa ID produk).
  - Mencegah serial yang sama masuk sebagai dua SKU produk berbeda dalam satu company.
  - Jika kelak vendor menggunakan serial yang bentrok antar produk, constraint perlu diubah.

## 3. Negatif Stok
- **Konteks:** Beberapa kondisi operasional mungkin memaksa stok menjadi negatif sebelum receiving tercatat.
- **Keputusan:** Stok negatif **diizinkan melalui toggle**, dengan nilai default **OFF** (dilarang).
- **Konsekuensi:**
  - Ditambahkan konfigurasi `allow_negative_stock` di tingkat company, yang bisa dioverride per gudang.
  - StockService harus mengevaluasi flag ini saat melakukan validasi transaksi yang mengurangi stok.
  - Saldo stok bisa bernilai negatif; movement tetap tercatat. Laporan akan memberikan highlight pada saldo negatif.

## 4. Multi-company & Multi-tenant
- **Konteks:** Potensi sistem digunakan oleh lebih dari satu perusahaan.
- **Keputusan:** Arsitektur menggunakan **multi-company sejak awal**, namun dikonfigurasi berjalan secara **single-company sebagai default**.
- **Konsekuensi:**
  - Semua tabel yang berkaitan dengan domain bisnis memiliki kolom `company_id`.
  - Digunakan global scope pada Eloquent model.
  - Satu company default dibuat saat seeding.
  - Jika `multi_company_enabled` OFF (default), UI untuk berganti company tidak ditampilkan.

## 5. Struktur Repositori
- **Konteks:** Ada frontend (Vue 3) dan backend (Laravel).
- **Keputusan:** Backend dan frontend disatukan dalam **satu repo (monorepo)**, dengan Vue berada di `resources/js`. 
- **Konsekuensi:** Deploy lebih sederhana (satu container/pipeline), namun source code tercampur.
