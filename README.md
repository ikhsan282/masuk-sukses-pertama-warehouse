# Masuk Sukses Pertama Warehouse (MSP Group)

Sistem Manajemen Inventori & Pergudangan Terintegrasi (*Warehouse Management System* - WMS) berbasis **PHP Native + MySQLi + Bootstrap 5**, dirancang khusus untuk mendukung model bisnis **Multi-Company (Holding & Subsidiaries)** dengan arsitektur **Gudang Bersama (*Shared Warehouse*)**.

Sistem ini telah disesuaikan dengan profil bisnis riil **PT Masuk Sukses Pertama** ([mspgrup.co.id](https://mspgrup.co.id)) sebagai distributor ban kendaraan berat (*Truck & Bus Radial*, *Off The Road*, *Solid Tire Forklift*, serta *Inner Tube & Flap*).

---

## 🌟 Fitur Utama & Keunggulan

### 1. Arsitektur Multi-PT & Gudang Bersama (*Shared Warehouse*)
- **Struktur Korporasi Holding & Anak PT**:
  - **PT Masuk Sukses Pertama (MSP)** — Induk Holding (`is_holding = 1`) & Importir Utama.
  - **PT Sukses Logistik Nusantara (SLN)** — Anak Perusahaan Distribusi & Operasional Tambang/Logistik Kalimantan.
  - **PT Prima Distribusi Mandiri (PDM)** — Anak Perusahaan Distribusi Retail & Sub-Dealer Jawa Timur.
- **Shared Warehouse / Rak Bersama**: Lokasi rak fisik yang sama (misal `RAK-A1`, `RAK-B1`) dapat digunakan bersama oleh beberapa PT dengan saldo stok yang terisolasi aman secara logis (`stock.company_id`).
- **Master Barang Terpusat (*Centralized SKU Catalog*) & Kepemilikan Stok per PT**:
  - Setiap kode barang (`items`) cukup didaftarkan **1 kali** pada katalog grup tanpa batasan kepemilikan "Global / Khusus PT" pada master item.
  - Saldo stok dan transaksi untuk barang yang sama tercatat **terpisah per masing-masing PT** pada tabel `stock` (contoh: item `MSP-OPL-178` bisa dimiliki di `MSP` 100 pcs, `SLN` 1.500 pcs, dan `PDM` 15 pcs secara bersamaan).
  - Halaman detail barang menampilkan **Kepemilikan Stok per PT** sekaligus rincian per lokasi untuk PT aktif.
- **Company Switcher Instan**: Pilihan aktif PT di topbar navigasi; pengguna yang memiliki hak akses multi-PT dapat berpindah entitas bisnis tanpa perlu logout.
- **Transfer Stok Antar-PT (*Inter-Company Transfer*)**: Pemindahan kepemilikan stok barang yang sama dari PT asal ke PT tujuan (contoh: PT `SLN` mentransfer sebagian stok `MSP-OPL-178` ke PT `PDM`) dengan audit mutasi ganda otomatis (`transfer_out` dan `transfer_in`).
- **Mode Laporan Konsolidasi Holding**: Khusus akun Holding / Super Admin, tersedia opsi filter **"Semua PT"** untuk melihat total stok konsolidasi seluruh grup perusahaan, maupun filter spesifik per entitas PT.
- **Penomoran Dokumen Khusus per PT**: Nomor referensi transaksi otomatis membawa kode PT (`SI-MSP-...`, `SO-SLN-...`, `PO-PDM-...`, `ADJ-MSP-...`, `RET-MSP-...`).

### 2. Manajemen Transaksi Operasional
- **Purchase Order (PO) & Workflow Approval**:
  - Alur status: `draft` ➔ `pending` (menunggu persetujuan) ➔ `approved` ➔ `partial` (diterima sebagian) / `completed` (diterima penuh).
  - Keamanan konkurensi penerimaan barang dengan *Database Row Locking* (`FOR UPDATE`) untuk mencegah duplikasi stok masuk.
- **Barang Masuk (*Stock In*)**: Input multi-baris barang, pencatatan otomatis mutasi stok, dan integrasi nomor PO.
- **Barang Keluar (*Stock Out* / Surat Jalan)**: Pengurangan stok dari rak gudang dengan validasi ketersediaan stok fisik per PT.
- **Retur ke Supplier (*Supplier Returns*)**: Pengembalian unit cacat/klaim garansi pabrik dengan opsi pembatalan aman (*safe cancellation*).
- **Stock Opname Fisik & Penyesuaian Stok (*Adjustment*)**:
  - Sesi opname berkala (*draft* ➔ *counting* ➔ *variance check* ➔ *finalization*).
  - Pembuatan dokumen Stock Adjustment otomatis yang membutuhkan persetujuan (*approval workflow*) berjenjang.

### 3. Pemindaian Barcode & Cetak Label
- **Barcode / QR Scanner (Kamera)**: Pemindaian barcode barang via kamera HP/laptop secara *real-time* menggunakan pustaka `html5-qrcode` yang tersimpan lokal (tanpa ketergantungan CDN eksternal).
- **Cetak Label Barcode SVG**: Cetak label barcode standar Code 39 langsung dari browser tanpa perlu library tambahan atau driver khusus printer.
- **Format Cetak Dokumen A4**: Template cetak siap cetak / simpan PDF untuk Faktur Barang Masuk, Surat Jalan Barang Keluar, Bukti Transfer Antar-PT, dan Purchase Order.

### 4. UI/UX Modern & PWA
- **Progressive Web App (PWA)**: Dapat diinstal langsung di layar smartphone Android/iOS (*Add to Home Screen*) dan mendukung *offline fallback*.
- **Portal Mobile**: Antarmuka ringkas khusus Staff Gudang di lapangan untuk cek stok dan pemindaian cepat.
- **Tema Gelap (*Dark Mode*)**: Dukungan mode gelap persisten dengan kontras warna yang nyaman di mata, termasuk dropdown TomSelect.

---

## 🛠️ Tech Stack

- **Backend**: PHP 8.2+ (Native, OOP, Zero-Composer dependency)
- **Database**: MySQL 5.7+ / MariaDB 10.4+ (*MySQLi with Prepared Statements & Transactions*)
- **Frontend**:
  - Bootstrap 5.3.8 + Bootstrap Icons 1.13.2
  - Tom Select 2.3.1 (Autocomplete dropdown dengan proteksi *dropdownParent: body*)
  - Chart.js 4.5.1 (Visualisasi statistik transaksi dan nilai inventori)
  - HTML5-QRCode 2.3.8 (Lokal di `assets/vendor/`)
- **Web Server**: Apache 2.4+ (XAMPP / cPanel Linux)

---

## 🚀 Panduan Instalasi & Menjalankan di XAMPP Lokal

### 1. Kloning / Letakkan Proyek
Pastikan folder proyek berada di direktori `htdocs` XAMPP Anda:
```
C:\xampp\htdocs\masuk-sukses-pertama-warehouse\
```

### 2. Import Database Tunggal
Database telah dikemas menjadi **satu file installer mandiri** yang berisi struktur tabel, indeks, serta dummy data riil operasional ban PT Masuk Sukses Pertama:
- **Lokasi File**: [`database/schema.sql`](file:///c:/xampp/htdocs/masuk-sukses-pertama-warehouse/database/schema.sql)
- **Cara Import via Command Prompt / PowerShell**:
  ```bash
  mysql -u root db_msp_warehouse < C:\xampp\htdocs\masuk-sukses-pertama-warehouse\database\schema.sql
  ```
- **Cara Import via phpMyAdmin**:
  1. Buka browser: `http://localhost/phpmyadmin` (atau `http://localhost:8080/phpmyadmin`).
  2. Buat database baru bernama `db_msp_warehouse` (opsional, script akan membuat otomatis jika belum ada).
  3. Klik tab **Import** -> Pilih file `database/schema.sql` -> Klik tombol **Import / Kirim**.

### 3. Konfigurasi Aplikasi & Port Web Server
Periksa file konfigurasi:
1. **[`config/database.php`](file:///c:/xampp/htdocs/masuk-sukses-pertama-warehouse/config/database.php)**:
   ```php
   defined('DB_HOST') || define('DB_HOST', 'localhost');
   defined('DB_USER') || define('DB_USER', 'root');
   defined('DB_PASS') || define('DB_PASS', '');
   defined('DB_NAME') || define('DB_NAME', 'db_msp_warehouse');
   defined('DB_PORT') || define('DB_PORT', 3306);
   ```
2. **[`config/config.php`](file:///c:/xampp/htdocs/masuk-sukses-pertama-warehouse/config/config.php)**:
   Sesuaikan `APP_URL` dengan port Apache yang aktif di komputer Anda:
   - Jika Apache berjalan di port default: `http://localhost/masuk-sukses-pertama-warehouse`
   - Jika Apache berjalan di port 8080 (seperti di setup XAMPP saat ini): `http://localhost:8080/masuk-sukses-pertama-warehouse`

### 4. Akun Pengguna Default untuk Pengujian

Semua akun dummy menggunakan password standar: **`P@ssw0rd`**

| Username | Role / Peran | Hak Akses Perusahaan (Multi-PT) | Deskripsi |
| :--- | :--- | :--- | :--- |
| **`superadmin`** | Super Admin | **Semua PT** (MSP, SLN, PDM) | Akses kontrol penuh, kelola PT, user, role, approval, dan laporan konsolidasi |
| **`admin`** | Admin Operasional | **Semua PT** (MSP, SLN, PDM) | Kelola data master, transaksi barang, PO, dan laporan |
| **`staffgudang`** | Staff Gudang | PT Masuk Sukses Pertama & SLN | Input transaksi masuk, keluar, transfer, dan opname fisik di gudang |
| **`viewer`** | Viewer Auditor | PT Masuk Sukses Pertama | Akses baca saja (*Read-only*) dan cetak laporan |

---

## 📦 Data Dummy Riil mspgrup.co.id

Database telah terisi data riil hasil scraping profil [mspgrup.co.id](https://mspgrup.co.id):

### Katalog Produk Ban & Komponen (17 Item Terpusat)
Semua PT menggunakan katalog SKU terpusat di bawah ini, sementara saldo stok masing-masing PT (seperti contoh `MSP-OPL-178` yang terisi di `MSP` 100 pcs, `SLN` 1.500 pcs, dan `PDM` 15 pcs) dicatat terpisah pada tabel `stock`:
- **Ban Dalam, Flap & Aksesoris**:
  - `SHR-TUB-01`: WEN FENG Butyl Inner Tube 12.00R20 TR179A
  - `SHR-TUB-02`: WANBO Butyl Inner Tube 23.5-25 TR179A
  - `SHR-FLP-01`: Flap Selendang Ban Truk 12.00-20 Heavy Duty
  - `SHR-VAL-01`: Valve Pentil Ban OTR TRJ1175C Heavy Duty Brass
  - `SHR-ORG-01`: O-Ring Seal Ban Tubeless OTR 25 Inch
- **Seri OPALS TBR & OTR**:
  - `MSP-OPL-232`: OPALS OP232 315/80R22.5 (TBR Tubeless Highway Long Haul)
  - `MSP-OPL-225`: OPALS OP225 11.00R20 (TBR All-Position 3-Line)
  - `MSP-OPL-359`: OPALS EV OP359 12.00R20 (TBR Mining & Heavy Logging)
  - `MSP-OPL-195`: OPALS OTR OP195 23.5-25 (Wheel Loader Tambang)
  - `MSP-OPL-178`: OPALS OTR OP178 14.00-24 (Motor Grader — stok contoh multi-PT: MSP 100, SLN 1.500, PDM 15)
- **Seri WANBO Mining OTR & Solid Forklift**:
  - `SLN-WNB-699`: WANBO WB699 18.00-25 (Dump Truck Tambang Batubara)
  - `SLN-WNB-625`: WANBO WB625 23.5-25 (Loader Batubara)
  - `SLN-WNB-711`: WANBO WB711 29.5R25 (Giant Mining Radial Haul Truck)
  - `SLN-WNB-SLD`: WANBO Solid Tire 28x9-15 (Forklift Pelabuhan 3.5 - 5 Ton)
- **Seri Ban Distribusi Kargo & Light Truck**:
  - `PDM-OPL-366`: OPALS OP366 10.00R20 (Truk Kargo Antar-Kota)
  - `PDM-OPL-265`: OPALS OP265 7.50R16 (Truk Canter / Engkel Distribusi)
  - `PDM-WNB-650`: WANBO Solid Tire 6.50-10 (Ban Solid Forklift Pabrik 2 - 2.5 Ton)

### Lokasi Rak Bersama & Gudang Wilayah
- `RAK-A1`: Rak A1 - Ban Truk & Bus Radial (TBR)
- `RAK-A2`: Rak A2 - Ban Truk Tubeless (TBR)
- `RAK-B1`: Rak B1 - Ban Tambang & Alat Berat (OTR)
- `RAK-B2`: Rak B2 - Ban Solid Forklift
- `RAK-C1`: Rak C1 - Ban Dalam & Flap
- `GUDANG-BJM`: Gudang Bizpark Banjarmasin Blok C1-7, Kalsel
- `GUDANG-UTAMA`: Gudang Utama Staging Kontainer Jakarta
- `AREA-TRANSIT`: Area Karantina & Pemeriksaan Klaim Garansi Retur

---

## 📁 Struktur Direktori Proyek

```
masuk-sukses-pertama-warehouse/
├── .htaccess                       # Aturan keamanan Apache & header proteksi
├── index.php                       # Entry point aplikasi (redirect ke dashboard / login)
├── PRD.md                          # Product Requirement Document (PRD) Multi-PT lengkap
├── README.md                       # Dokumentasi resmi proyek
├── manifest.json                   # Web App Manifest untuk instalasi PWA
├── sw.js                           # Service worker PWA untuk caching & offline support
├── config/
│   ├── database.php                # Pengaturan koneksi MySQLi
│   └── config.php                  # Pengaturan aplikasi, session cookie, dan timezone
├── includes/
│   ├── auth.php                    # Otentikasi, session RBAC, dan verifikasi email
│   ├── functions.php               # Helper: CSRF, format rupiah, filter data, sanitasi input
│   ├── header.php                  # Topbar navigasi, dark mode switch, company switcher
│   ├── sidebar.php                 # Menu samping dinamis sesuai izin peran (RBAC)
│   ├── footer.php                  # Inisialisasi library client-side (TomSelect, Bootstrap)
│   ├── purchase_orders.php         # Logika bisnis transaksi PO & kalkulasi parsial
│   ├── supplier_returns.php        # Logika bisnis retur supplier & pembatalan
│   ├── stock_opname.php            # Logika bisnis stock opname & stock adjustment
│   ├── low_stock_alerts.php        # Mesin peringatan stok menipis & rekomendasi PO
│   └── scanner-modal.php           # Komponen modal kamera pemindai barcode/QR
├── auth/
│   ├── login.php                   # Form login pengguna
│   ├── logout.php                  # Pembersihan sesi aman
│   ├── switch-company.php          # Handler perpindahan perusahaan aktif
│   ├── forgot-password.php         # Permintaan reset password
│   ├── reset-password.php          # Form pembaharuan password via token
│   └── verify-email.php            # Verifikasi aktivasi akun email
├── pages/
│   ├── dashboard.php               # Metrik inventori, grafik transaksi 7 hari, top barang
│   ├── portal.php                  # Antarmuka mobile-friendly Staff Gudang
│   ├── companies/                  # Manajemen data PT (Holding & Anak Perusahaan)
│   ├── items/                      # Master barang, cetak label barcode, import CSV/Excel
│   ├── categories/                 # Master kategori barang
│   ├── units/                      # Master satuan ukuran
│   ├── suppliers/                  # Master data prinsipal/supplier
│   ├── locations/                  # Master lokasi rak gudang
│   ├── stock/                      # Monitoring stok inventori & peringatan stok menipis
│   ├── purchase-orders/            # Pembuatan PO, form receive, dan approval
│   ├── stock-in/                   # Transaksi barang masuk kontainer & cetak surat penerimaan
│   ├── stock-out/                  # Transaksi barang keluar, surat jalan, & cetak DO
│   ├── transfers/                  # Pemindahan stok antar-lokasi & transfer antar-PT
│   ├── stock-opname/               # Sesi opname, form hitung fisik, dan finalisasi
│   ├── adjustments/                # Surat penyesuaian stok selisih opname
│   ├── supplier-returns/           # Retur unit cacat ke supplier & riwayat klaim
│   ├── reports/                    # Laporan mutasi, kartu stok, PO, dan performa supplier
│   ├── users/                      # Manajemen akun staf & hak akses multi-PT
│   └── roles/                      # Editor matriks permission per peran
├── assets/
│   ├── css/style.css               # Styling custom, tema gelap, dan responsive layout
│   ├── js/app.js                   # Interaksi baris dinamis, kalkulasi live, dan scanner
│   └── vendor/
│       └── html5-qrcode/           # Library pemindai barcode lokal tanpa CDN
├── database/
│   └── schema.sql                  # Skema database terpadu + data dummy riil
└── tests/                          # Automated PHP test suites
    ├── dropdown_overflow_test.php  # Verifikasi render dropdown TomSelect di grid
    ├── feature_self_check.php      # Pemeriksaan integritas fitur & DDL
    ├── integration_test.php        # Uji transaksi PO, mutasi, barcode SVG, dan race condition
    ├── location_stock_test.php     # Validasi ketersediaan stok fisik per lokasi
    ├── low_stock_alert_test.php    # Uji akurasi kalkulasi stok menipis & email alert
    ├── multi_company_test.php      # Uji lengkap skenario multi-PT & shared warehouse
    ├── partial_receive_test.php    # Uji penerimaan bertahap purchase order
    ├── stock_opname_test.php       # Uji alur draft, hitung fisik, variance, dan finalisasi
    └── supplier_returns_test.php   # Uji pengurangan stok retur & pemulihan saat dibatalkan
```

---

## 🧪 Pengujian Otomatis (*Test Suites*)

Aplikasi ini dilengkapi 9 skrip pengujian otomatis berbasis PHP native (tanpa perlu PHPUnit). Seluruh test suite dapat dijalankan secara langsung dari terminal:

```powershell
Get-ChildItem -Path tests -Filter *.php | ForEach-Object { 
    Write-Host "`n=== $($_.Name) ==="; 
    & "C:\xampp\php\php.exe" $_.FullName 
}
```

Hasil verifikasi:
- ✅ `dropdown_overflow_test.php`: **Passed (6 / 6)**
- ✅ `feature_self_check.php`: **Passed (16 checks)**
- ✅ `integration_test.php`: **Passed (29 / 29)**
- ✅ `location_stock_test.php`: **Passed (5 / 5)**
- ✅ `low_stock_alert_test.php`: **Passed (33 / 33)**
- ✅ `multi_company_test.php`: **Passed (27 / 27)**
- ✅ `partial_receive_test.php`: **Passed (20 / 20)**
- ✅ `stock_opname_test.php`: **Passed (14 / 14)**
- ✅ `supplier_returns_test.php`: **Passed (20 / 20)**
- **Total: 160+ Assertions Lulus 100% tanpa regresi.**

---

## 🔒 Standar Keamanan & Best Practices

1. **SQL Injection Prevention**: 100% query database menggunakan MySQLi prepared statements dengan parameter binding (`bind_param`).
2. **Password Hashing**: Menggunakan algoritma standar industri `password_hash(PASSWORD_BCRYPT, ['cost' => 12])`.
3. **Cross-Site Request Forgery (CSRF)**: Token unik berbasis HMAC di-generate per sesi pengguna dan divalidasi pada setiap request POST (`verify_csrf()`).
4. **Cross-Site Scripting (XSS)**: Semua output data dari database di-escape menggunakan `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
5. **Session Hardening**: Cookie sesi dilindungi dengan flag `HttpOnly`, `SameSite=Lax`, dan masa aktif otomatis 2 jam.
6. **Granular RBAC**: Proteksi berbasis izin fungsional (`require_perm()`) di tingkat controller/halaman, bukan hanya pembatasan nama peran biasa.

---

## 📝 Catatan Deployment ke cPanel / Server Produksi

1. Unggah seluruh file ke folder `public_html/masuk-sukses-pertama-warehouse/` (atau root domain).
2. Pastikan file `.htaccess` ikut terunggah untuk mengaktifkan proteksi direktori sensitif (`config/`, `database/`, `includes/`).
3. Buat database baru di cPanel MySQL Databases dan impor file `database/schema.sql`.
4. Sesuaikan konstanta pada `config/database.php` (DB_USER, DB_PASS, DB_NAME).
5. Pada `config/config.php`, ubah `APP_URL` menjadi URL domain produksi Anda dengan protokol **HTTPS** (wajib untuk akses kamera pemindai barcode).
6. Atur izin direktori (*file permissions*): folder `755`, file `644`.

---

© 2026 PT Masuk Sukses Pertama. All Rights Reserved.
