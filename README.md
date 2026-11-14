# Masuk Sukses Pertama Warehouse (MSP Group)

Sistem Manajemen Inventori & Pergudangan Terintegrasi (*Warehouse Management System* - WMS) berbasis **PHP Native + MySQLi + Bootstrap 5**, dirancang khusus untuk mendukung model bisnis **Multi-Company (Holding & Subsidiaries)** dengan arsitektur **Gudang Bersama (*Shared Warehouse*)** dan **Zero-Composer Dependency**.

Sistem ini telah disesuaikan dengan profil bisnis riil **PT Masuk Sukses Pertama** ([mspgrup.co.id](https://mspgrup.co.id)) sebagai distributor ban kendaraan berat (*Truck & Bus Radial*, *Off The Road*, *Solid Tire Forklift*, serta *Inner Tube & Flap*).

---

## 🌟 Fitur Utama & Keunggulan

### 1. Arsitektur Multi-PT, Isolasi Data & Gudang Bersama (*Shared Warehouse*)
- **Struktur Korporasi Holding & Anak PT**:
  - **PT Masuk Sukses Pertama (MSP)** — Induk Holding (`is_holding = 1`) & Importir Utama.
  - **PT Sukses Logistik Nusantara (SLN)** — Anak Perusahaan Distribusi & Operasional Tambang/Logistik Kalimantan.
  - **PT Prima Distribusi Mandiri (PDM)** — Anak Perusahaan Distribusi Retail & Sub-Dealer Jawa Timur.
- **Shared Warehouse / Rak Bersama**: Lokasi rak fisik yang sama (misal `RAK-A1`, `RAK-B1`) dapat digunakan bersama oleh beberapa PT dengan saldo stok yang terisolasi aman secara logis (`stock.company_id`).
- **Master Barang Terpusat (*Centralized SKU Catalog*) & Kepemilikan Stok per PT**:
  - Setiap kode barang (`items`) cukup didaftarkan **1 kali** pada katalog grup, sementara saldo stok & transaksi tercatat terpisah per PT pada tabel `stock` (contoh: item `MSP-OPL-178` bisa dimiliki di `MSP` 100 pcs, `SLN` 1.500 pcs, dan `PDM` 15 pcs secara bersamaan).
  - Halaman detail barang menampilkan **Kepemilikan Stok per PT** sekaligus rincian per lokasi untuk PT aktif.
- **Company Switcher Instan & Isolasi IDOR Lintas PT**:
  - Pengguna dengan akses multi-PT dapat berpindah entitas bisnis tanpa logout melalui topbar desktop maupun header [Portal Mobile PWA](pages/portal.php).
  - Seluruh endpoint detail, edit, approval, penerimaan PO, cetak dokumen, export, dan pembatalan/hapus dilindungi validasi kepemilikan `company_id` (`require_company_access()` / `require_any_company_access()`, HTTP `403` untuk PT di luar hak akses).
- **Transfer Stok Antar-PT (*Inter-Company Transfer*)**: Pemindahan kepemilikan stok dari PT asal ke PT tujuan dengan audit mutasi ganda otomatis (`transfer_out` dan `transfer_in`).
- **Mode Laporan Konsolidasi Holding**: Mendukung filter **"Semua PT (Konsolidasi)"** sesuai hak akses pengguna maupun filter spesifik per PT.
- **Penomoran Dokumen Khusus per PT**: Nomor referensi transaksi otomatis membawa kode PT (`SI-MSP-...`, `SO-SLN-...`, `PO-PDM-...`, `ADJ-MSP-...`, `SR-MSP-...`).

### 2. Dashboard Analytics & Manajemen Transaksi Operasional
- **Dashboard Eksekutif & Operasional (`pages/dashboard.php`)**:
  - KPI ringkas dengan angka terkompresi (`compact_idr`) + tooltip nilai penuh, aksi cepat (*Quick Actions*), daftar **Tugas Tertunda** (PO menunggu approval, adjustment draft, opname draft, dan stok menipis), perbandingan stok antar-PT, serta grafik interaktif **7 / 30 hari** yang sinkron dengan tema terang/gelap.
- **Purchase Order (PO), Approval & Cetak Resmi A4**:
  - Alur status: `draft` ➔ `pending` (menunggu persetujuan) ➔ `approved` ➔ `partial` (diterima sebagian) / `completed` (diterima penuh), lengkap dengan halaman cetak PO resmi (`pages/purchase-orders/print.php`).
  - Keamanan konkurensi penerimaan barang dengan *Database Row Locking* (`FOR UPDATE`) untuk mencegah duplikasi stok masuk.
- **Barang Masuk, Barang Keluar, Retur Supplier & Stock Opname**:
  - Input multi-baris, validasi stok per lokasi & PT, pembatalan retur aman, serta sesi Stock Opname (`draft` ➔ hitung fisik ➔ laporan variance + export PDF/Excel ➔ finalisasi menjadi Stock Adjustment).

### 3. Pure-PHP Export Engine (PDF, Excel `.xlsx`, CSV & Print) + Import
- **Tanpa Composer / `ext-zip`**: Dibangun menggunakan mesin PDF 1.4 vektor murni & OOXML ZIP builder internal ([`includes/export.php`](includes/export.php) & [`includes/item-import.php`](includes/item-import.php)).
- **Export Terpadu PDF, Excel, CSV (CWE-1236 formula-safe) & Tampilan Cetak** tersedia pada:
  - Seluruh **6 Data Master**: Perusahaan (PT), Barang, Kategori, Satuan, Supplier, dan Lokasi.
  - Seluruh **5 Laporan**: Laporan Stok, Mutasi Barang, Barang Masuk, Barang Keluar, dan Performa Supplier.
  - Modul Operasional & Admin: **Cek Stok** (`pages/stock/`), **Peringatan Stok** (`pages/stock/alerts.php`), **Laporan Variance Stock Opname**, **Audit Log**, dan **Manajemen Pengguna** (tanpa membocorkan hash kata sandi/token).
- **Import Barang Massal**: Mendukung unggah `.xlsx` dan `.csv` dengan pratinjau validasi sebelum disimpan.

### 4. Pemindaian Barcode, Dark Mode Merata, Self-Service Profil & PWA
- **Barcode / QR Scanner & Label SVG**: Pemindaian via kamera menggunakan pustaka lokal `html5-qrcode` dan cetak label barcode Code 39 berbasis SVG.
- **Dark Mode Merata & Otomatis**: Mendukung preferensi OS (`prefers-color-scheme:dark`) maupun toggle `#darkToggle` (`mw_theme`) pada [Landing Page](index.php), seluruh halaman otentikasi (`login`, `forgot-password`, `reset-password`, `verify-email`), aplikasi desktop, dan [Portal Mobile PWA](pages/portal.php).
- **Portal Mobile PWA**: Tampilan ringkas mobile untuk *Staff Gudang* & *Viewer* dengan *Multi-PT Switcher*, pencarian katalog server/klien hingga 500+ item, badge status stok, dan tautan cepat ubah profil/kata sandi.
- **Self-Service Akun & *Ingat saya***: Pengguna dapat memperbarui nama lengkap dan mengganti kata sandi secara mandiri melalui [`pages/profile.php`](pages/profile.php), serta menikmati login persisten 30 hari (*Ingat saya*) yang aman dengan rotasi token di tabel `user_remember_tokens`.

---

## 🛠️ Tech Stack

- **Backend**: PHP 8.2+ (Native, Zero-Composer dependency)
- **Database**: MySQL 5.7+ / MariaDB 10.4+ (*MySQLi Prepared Statements, Transactions & `FOR UPDATE` Row Locking*, engine **InnoDB** `utf8mb4`)
- **Frontend**:
  - Bootstrap 5.3.8 + Bootstrap Icons 1.13.2
  - Tom Select 2.3.1 (Autocomplete dropdown dengan `dropdownParent: 'body'`)
  - Chart.js 4.5.1 (Grafik tren inventori 7/30 hari & dark-mode theme sync)
  - HTML5-QRCode 2.3.8 (Lokal di `assets/vendor/`)
- **Web Server & Otomasi**: Apache 2.4+ (XAMPP / cPanel Linux), Cron Bash (`.sh`), & Windows Task Scheduler PowerShell (`.ps1`)

---

## 🚀 Panduan Instalasi & Menjalankan di XAMPP Lokal

### 1. Kloning / Letakkan Proyek
Pastikan folder proyek berada di direktori `htdocs` XAMPP Anda:
```
C:\xampp\htdocs\masuk-sukses-pertama-warehouse\
```

### 2. Import Database Tunggal
Database dikemas menjadi **satu file installer mandiri** yang berisi struktur `30` tabel inti, `48` index performa, serta data dummy riil operasional PT Masuk Sukses Pertama:
- **Lokasi File**: [`database/schema.sql`](database/schema.sql)
- **Cara Import via Command Prompt / PowerShell**:
  ```powershell
  mysql -u root db_msp_warehouse < C:\xampp\htdocs\masuk-sukses-pertama-warehouse\database\schema.sql
  ```
- **Verifikasi Kesiapan Skema & Index Produksi**:
  ```powershell
  C:\xampp\php\php.exe cron\verify_production_db.php --apply-missing
  ```

### 3. Konfigurasi Lingkungan & Port Web Server
Aplikasi mendukung override konfigurasi tanpa menyentuh kode inti melalui file `.env` (salin dari [`.env.example`](.env.example)) atau [`config/config.local.php`](config/config.local.example.php), yang dimuat otomatis oleh [`config/env.php`](config/env.php):
- **[`config/database.php`](config/database.php)**: Default mengarah ke `localhost:3306`, user `root`, database `db_msp_warehouse` (`utf8mb4`).
- **[`config/config.php`](config/config.php)**: `APP_URL` dideteksi otomatis (`msp_detect_app_url()`) mengikuti host, protokol HTTP/HTTPS, serta port server yang aktif (misal `http://localhost/masuk-sukses-pertama-warehouse` atau `http://localhost:8080/masuk-sukses-pertama-warehouse`), kecuali ditetapkan eksplisit di `.env` / `config.local.php`.

### 4. Akun Pengguna Default untuk Pengujian

Semua akun dummy pada `database/schema.sql` menggunakan password standar: **`P@ssw0rd`**

| Username | Role / Peran | Hak Akses Perusahaan (Multi-PT) | Deskripsi |
| :--- | :--- | :--- | :--- |
| **`superadmin`** | Super Admin | **Semua PT** (MSP, SLN, PDM) | Kontrol penuh, kelola PT, user, role, audit log, approval, dan laporan konsolidasi |
| **`admin`** | Admin Operasional | **Semua PT** (MSP, SLN, PDM) | Kelola data master, transaksi barang, PO, audit log, dan export laporan |
| **`staffgudang`** | Staff Gudang | PT Masuk Sukses Pertama & SLN | Portal mobile PWA, input barang masuk/keluar, transfer, retur, dan hitung opname |
| **`viewer`** | Viewer Auditor | PT Masuk Sukses Pertama | Portal mobile PWA, akses baca (*Read-only*) & unduh laporan yang diizinkan |

---

## 📦 Data Dummy Riil mspgrup.co.id

Database telah terisi data riil profil [mspgrup.co.id](https://mspgrup.co.id):

### Katalog Produk Ban & Komponen (17 Item Terpusat)
Seluruh PT berbagi katalog SKU terpusat di bawah ini sementara saldo stok tercatat terpisah per PT pada tabel `stock`:
- **Ban Dalam, Flap & Aksesoris**: `SHR-TUB-01`, `SHR-TUB-02`, `SHR-FLP-01`, `SHR-VAL-01`, `SHR-ORG-01`
- **Seri OPALS TBR & OTR**: `MSP-OPL-232`, `MSP-OPL-225`, `MSP-OPL-359`, `MSP-OPL-195`, `MSP-OPL-178` *(contoh stok multi-PT: MSP 100, SLN 1.500, PDM 15)*
- **Seri WANBO Mining OTR & Solid Forklift**: `SLN-WNB-699`, `SLN-WNB-625`, `SLN-WNB-711`, `SLN-WNB-SLD`
- **Seri Ban Distribusi Kargo & Light Truck**: `PDM-OPL-366`, `PDM-OPL-265`, `PDM-WNB-650`

### Lokasi Rak Bersama & Gudang Wilayah
- `RAK-A1`, `RAK-A2`, `RAK-B1`, `RAK-B2`, `RAK-C1`, `GUDANG-BJM`, `GUDANG-UTAMA`, `AREA-TRANSIT`

---

## 📁 Struktur Direktori Proyek

```
masuk-sukses-pertama-warehouse/
├── .env.example                    # Template environment variables (.env)
├── .htaccess                       # Aturan keamanan Apache & proteksi file sensitif lokal
├── .htaccess.production            # Template .htaccess untuk server produksi / cPanel HTTPS
├── index.php                       # Landing page publik + entry point aplikasi (mendukung dark mode)
├── manifest.json                   # Web App Manifest untuk instalasi PWA
├── sw.js                           # Service worker PWA untuk caching & offline support
├── offline.html                    # Halaman fallback saat offline
├── PRD.md                          # Product Requirement Document (PRD) Multi-PT
├── README.md                       # Dokumentasi resmi proyek
├── config/
│   ├── env.php                     # Loader .env dan config.local.php
│   ├── config.local.example.php    # Template override konfigurasi PHP produksi
│   ├── config.php                  # Pengaturan aplikasi, auto APP_URL, session cookie, timezone
│   └── database.php                # Koneksi MySQLi singleton (utf8mb4)
├── includes/
│   ├── auth.php                    # Login, RBAC, Multi-PT isolation, Remember-Me tokens, lockout
│   ├── auth-layout.php             # Layout terstandarisasi halaman otentikasi (+ dark mode)
│   ├── export.php                  # Engine pure-PHP Vector PDF 1.4 & OOXML Excel (.xlsx)
│   ├── item-import.php             # Parser murni CSV & XLSX untuk import barang
│   ├── functions.php               # CSRF, format rupiah, grid helpers, theme scripts, audit_log()
│   ├── header.php                  # Topbar navigasi, dark mode switch, company switcher, menu profil
│   ├── sidebar.php                 # Menu samping dinamis sesuai izin peran (RBAC)
│   ├── footer.php                  # Inisialisasi library client-side (TomSelect, Bootstrap)
│   ├── purchase_orders.php         # Logika transaksi PO & penerimaan parsial
│   ├── supplier_returns.php        # Logika retur supplier & pembatalan aman
│   ├── stock_opname.php            # Logika stock opname & finalisasi adjustment
│   ├── low_stock_alerts.php        # Mesin peringatan stok menipis & rekomendasi PO
│   └── scanner-modal.php           # Komponen modal kamera pemindai barcode/QR
├── auth/
│   ├── login.php                   # Form login + brute-force guard + "Ingat saya" (30 hari)
│   ├── logout.php                  # Pembersihan sesi & pencabutan token remember-me
│   ├── switch-company.php          # Handler perpindahan PT aktif (+ validasi redirect aman)
│   ├── forgot-password.php         # Permintaan reset kata sandi
│   ├── reset-password.php          # Pembaharuan kata sandi via token
│   └── verify-email.php            # Verifikasi aktivasi akun email
├── pages/
│   ├── dashboard.php               # Metrik KPI, Quick Actions, Tugas Tertunda, grafik 7/30d, Multi-PT
│   ├── portal.php                  # Portal Mobile PWA Staff Gudang & Viewer (Multi-PT + Dark Mode)
│   ├── profile.php                 # Self-service ubah nama profil & ganti kata sandi mandiri
│   ├── audit-logs/                 # Riwayat jejak aktivitas aplikasi + export PDF/Excel/CSV
│   ├── companies/                  # Manajemen PT (Holding & Anak) + export PDF/Excel/CSV
│   ├── items/                      # Master barang, label barcode SVG, import XLSX/CSV, export PDF/Excel
│   ├── categories/                 # Master kategori barang + export PDF/Excel/CSV
│   ├── units/                      # Master satuan ukuran + export PDF/Excel/CSV
│   ├── suppliers/                  # Master prinsipal/supplier + export PDF/Excel/CSV
│   ├── locations/                  # Master lokasi rak gudang + export PDF/Excel/CSV
│   ├── stock/                      # Cek stok & peringatan stok menipis + export PDF/Excel/CSV
│   ├── purchase-orders/            # PO, approval, receive parsial/penuh, cetak PO resmi A4
│   ├── stock-in/                   # Barang masuk & cetak bukti penerimaan A4
│   ├── stock-out/                  # Barang keluar & cetak surat jalan A4
│   ├── transfers/                  # Transfer antar-lokasi & antar-PT + cetak bukti A4
│   ├── stock-opname/               # Sesi opname, hitung fisik, variance report + export PDF/Excel/CSV
│   ├── adjustments/                # Penyesuaian stok selisih opname & approval
│   ├── supplier-returns/           # Retur unit cacat ke supplier & pembatalan aman
│   ├── reports/                    # 5 laporan analitik + export terpadu PDF/Excel/CSV/Print
│   ├── users/                      # Manajemen akun, multi-PT, auto_verify, verify_user + export
│   └── roles/                      # Editor matriks permission RBAC per peran
├── cron/
│   ├── verify_production_db.php    # CLI pemeriksa/migrasi 30 tabel InnoDB & 48 index produksi
│   ├── backup_db.ps1               # Backup database otomatis harian + ZIP (Windows Task Scheduler)
│   ├── backup_db.sh                # Backup database otomatis harian + gzip (Linux cron)
│   ├── low_stock_alert.ps1         # Pengirim email peringatan stok menipis (Windows Task Scheduler)
│   └── low_stock_alert.sh          # Pengirim email peringatan stok menipis (Linux cron)
├── docs/
│   ├── import-template.csv         # Contoh template CSV untuk import data barang
│   └── UAT_DAN_OPERASIONAL_CHECKLIST.md # Checklist deployment produksi, Task Scheduler & UAT
├── assets/
│   ├── css/style.css               # Styling custom, tema gelap, dan layout toolbar/grid terstandar
│   ├── js/app.js                   # Interaksi dinamis, kalkulasi live, dan scanner
│   └── vendor/html5-qrcode/        # Library pemindai barcode lokal tanpa CDN
├── database/
│   └── schema.sql                  # Skema database terpadu (30 tabel + 48 index + data dummy riil)
└── tests/                          # 13 suite pengujian otomatis PHP native (zero PHPUnit)
    ├── dropdown_overflow_test.php  # Verifikasi TomSelect dropdownParent & overflow fix
    ├── feature_self_check.php      # Pemeriksaan integritas fitur & DDL dasar
    ├── grid_pentest_test.php       # Uji standarisasi toolbar/grid, XSS, SQLi, & CWE-1236
    ├── integration_test.php        # Uji transaksi PO, row locking, barcode SVG, & low stock
    ├── location_stock_test.php     # Validasi ketersediaan stok fisik per lokasi & PT
    ├── low_stock_alert_test.php    # Uji kalkulasi stok menipis & template email alert
    ├── master_export_test.php      # Uji export PDF & Excel OOXML di seluruh 6 modul master
    ├── multi_company_test.php      # Uji lengkap skenario multi-PT & shared warehouse
    ├── partial_receive_test.php    # Uji penerimaan bertahap purchase order
    ├── phase5_improvements_test.php# Uji end-to-end IDOR lintas PT, remember-me, profile, PO print, opname/stock/users export, portal, & audit log
    ├── stock_opname_test.php       # Uji alur draft, hitung fisik, variance, dan finalisasi
    ├── supplier_returns_test.php   # Uji pengurangan stok retur & pemulihan saat dibatalkan
    └── uat_roles_workflow_test.php # Simulasi UAT penuh 4 peran & siklus operasional lengkap
```

---

## 🧪 Pengujian Otomatis (*Test Suites*) & Otomasi Terjadwal

Aplikasi ini dilengkapi **13 skrip pengujian otomatis** berbasis PHP native (tanpa PHPUnit). Jalankan seluruh suite sekaligus dari PowerShell:

```powershell
$failed = 0
Get-ChildItem tests\*.php | Sort-Object Name | ForEach-Object {
    Write-Host "`n>>> RUN $($_.Name)"
    & "C:\xampp\php\php.exe" $_.FullName
    if ($LASTEXITCODE -ne 0) { $failed++ }
}
Write-Host "`nTOTAL_FAILED_SUITES=$failed"
```

Hasil verifikasi penuh (`0` gagal di seluruh suite):
- ✅ `dropdown_overflow_test.php`: **Passed (6 / 6)**
- ✅ `feature_self_check.php`: **Passed (16 checks)**
- ✅ `grid_pentest_test.php`: **Passed (40 / 40)**
- ✅ `integration_test.php`: **Passed (29 / 29)**
- ✅ `location_stock_test.php`: **Passed (5 / 5)**
- ✅ `low_stock_alert_test.php`: **Passed (33 / 33)**
- ✅ `master_export_test.php`: **Passed (31 / 31)**
- ✅ `multi_company_test.php`: **Passed (29 / 29)**
- ✅ `partial_receive_test.php`: **Passed (20 / 20)**
- ✅ `phase5_improvements_test.php`: **Passed (50 / 50)**
- ✅ `stock_opname_test.php`: **Passed (14 / 14)**
- ✅ `supplier_returns_test.php`: **Passed (20 / 20)**
- ✅ `uat_roles_workflow_test.php`: **Passed (31 / 31)**
- **Total: 324 Pengecekan / Assertions Lulus 100% tanpa regresi.**

### Skrip Penjadwalan (*Cron / Task Scheduler*)
- **Windows Backup Harian (02:00)**: `powershell -ExecutionPolicy Bypass -File cron/backup_db.ps1`
- **Windows Low-Stock Alert Harian (08:00)**: `powershell -ExecutionPolicy Bypass -File cron/low_stock_alert.ps1 -AdminEmail admin@mspgrup.co.id`
- **Linux / cPanel**: Lihat panduan lengkap registrasi cron dan checklist go-live di [`docs/UAT_DAN_OPERASIONAL_CHECKLIST.md`](docs/UAT_DAN_OPERASIONAL_CHECKLIST.md).

---

## 🔒 Standar Keamanan & Best Practices

1. **SQL Injection Prevention**: 100% query database menggunakan MySQLi prepared statements dengan parameter binding (`bind_param`).
2. **Multi-PT Isolation (Anti-IDOR)**: Validasi kepemilikan `company_id` pada seluruh endpoint transaksi, cetak dokumen, export laporan, serta POST spoofing lintas perusahaan.
3. **Password Hashing & Token Persisten**: Password di-hash menggunakan `PASSWORD_BCRYPT` (`cost => 12`), token *Ingat saya* disimpan sebagai SHA-256 hash (`selector:validator`) dengan rotasi otomatis dan dicabut saat logout/ubah sandi/reset sandi.
4. **Brute-Force Protection & Audit Trail**: Pembatasan 5 kali percobaan gagal masuk (`HTTP 429` selama 5 menit) serta pencatatan seluruh aktivitas penting & security event ke `audit_logs`.
5. **CSRF & XSS Protection**: Token CSRF unik per sesi pada setiap aksi POST (`verify_csrf()`), output di-escape dengan `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`, dan seluruh export CSV/XLSX disanitasi terhadap injeksi formula (CWE-1236).
6. **Session Hardening & Granular RBAC**: Cookie sesi memakai `HttpOnly`, `SameSite=Lax`, serta otorisasi berbasis izin (`require_perm()`) di tingkat endpoint.
7. **Optimasi Performa End-to-End**:
   - **Frontend**: `html5-qrcode.min.js` (~375 KB) dimuat kondisional hanya pada halaman dengan modal scanner, serta `dns-prefetch` & `preconnect` ke `https://cdn.jsdelivr.net`.
   - **Database & Query**: Filter tanggal `audit_logs` memakai perbandingan rentang *SARGable* (`idx_audit_created_id`), penghapusan *correlated subqueries* serta penggabungan query agregasi satu pass (*single-pass*) pada Dashboard, Stok, Peringatan Stok, serta Laporan Masuk/Keluar (*deferred join*).
   - **Konkurensi & HTTP**: `release_session_lock()` (`session_write_close()`) dipanggil sebelum export PDF/XLSX/CSV & pencarian JSON agar tab lain tidak terblokir, ditambah kompresi `mod_deflate` dan cache header `mod_expires` pada [`.htaccess`](.htaccess) dan [`.htaccess.production`](.htaccess.production).

---

## 📝 Catatan Deployment ke cPanel / Server Produksi

1. Unggah seluruh file ke folder `public_html/` atau subdomain target, dan gunakan [`.htaccess.production`](.htaccess.production) sesuai kebutuhan HTTPS produksi.
2. Buat file `.env` (berdasarkan [`.env.example`](.env.example)) atau `config/config.local.php` untuk kredensial DB, SMTP, dan `APP_URL` HTTPS (wajib HTTPS agar kamera scanner barcode berjalan di browser).
3. Impor [`database/schema.sql`](database/schema.sql), lalu jalankan `php cron/verify_production_db.php --apply-missing` untuk memastikan keutuhan 30 tabel & 48 index.
4. Aktifkan penjadwalan `cron/backup_db.sh` / `.ps1` dan `cron/low_stock_alert.sh` / `.ps1`, serta ikuti checklist di [`docs/UAT_DAN_OPERASIONAL_CHECKLIST.md`](docs/UAT_DAN_OPERASIONAL_CHECKLIST.md).
5. Atur izin direktori standar Linux: folder `755`, file `644` (pastikan `.env` dan `config.local.php` tidak dapat diakses via HTTP).

---

© 2026 PT Masuk Sukses Pertama. All Rights Reserved.
