# Masuk Sukses Pertama Warehouse

Sistem Manajemen Inventori & Gudang — PHP Native + MySQLi + Bootstrap 5.

## Stack
- PHP 8.5+ (Native, tanpa framework)
- MySQLi dengan prepared statements
- MySQL / MariaDB
- Bootstrap 5.3.8 + Bootstrap Icons 1.13.2 (CDN)
- Chart.js 4.5.1 (CDN)
- Tom Select 2.3.1 (CDN)
- Vanilla JavaScript
- PWA (Web App Manifest + Service Worker)
- `html5-qrcode` (lokal) untuk pemindaian barcode/QR
- Generator PDF dan parser XLSX native tanpa Composer

## Instalasi

### 1. Import Database
```sql
mysql -u root -p < database/schema.sql
```

### 2. Konfigurasi Database
Edit `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'db_msp_warehouse');
```

### 3. Konfigurasi Aplikasi
Edit `config/config.php`:
```php
define('APP_URL', 'http://yourdomain.com/masuk-sukses-pertama-warehouse');
define('MAIL_FROM', 'no-reply@yourdomain.com');
define('COMPANY_NAME', 'Nama Perusahaan');
define('COMPANY_ADDRESS', 'Alamat Perusahaan');
```

### 4. Upload ke cPanel
- Upload semua file ke `public_html/masuk-sukses-pertama-warehouse/`
- Pastikan `.htaccess` ikut terupload
- Set permission folder: `755`, file: `644`

### 5. Login Default
| Username | Password | Peran |
|---|---|---|
| `superadmin` | `P@ssw0rd` | Super Admin |
| `admin` | `P@ssw0rd` | Admin |
| `staffgudang` | `P@ssw0rd` | Staff Gudang |
| `viewer` | `P@ssw0rd` | Viewer |

> **Ganti password segera setelah login pertama!**

> **Catatan:** Akun default sudah terverifikasi email. Pengguna baru yang didaftarkan melalui UI akan menerima email verifikasi via `mail()`.

---

## Struktur File
```
masuk-sukses-pertama-warehouse/
├── .htaccess                  # Apache config, security headers
├── index.php                  # Entry point → redirect
├── config/
│   ├── database.php           # DB constants (ubah untuk cPanel)
│   └── config.php             # App settings, session, timezone
├── includes/
│   ├── auth.php               # Login, logout, RBAC, email verify
│   ├── functions.php          # Helpers: CSRF, flash, paginate, idr()
│   ├── header.php             # HTML head + navbar
│   ├── sidebar.php            # Sidebar navigasi (permission-aware)
│   └── footer.php             # Scripts, closing tags
├── assets/
│   ├── css/style.css          # Layout, sidebar, auth, badges
│   └── js/app.js              # Sidebar toggle, dynamic rows, confirm
├── auth/
│   ├── login.php
│   ├── logout.php
│   ├── forgot-password.php
│   ├── reset-password.php
│   └── verify-email.php
├── pages/
│   ├── dashboard.php          # Stats, stok menipis, mutasi terbaru
│   ├── stock-opname/          # Stock opname sessions, count, variance, finalize
│   ├── items/                 # index, create, edit, view, delete
│   ├── categories/            # index (CRUD modal)
│   ├── units/                 # index (CRUD modal)
│   ├── suppliers/             # index (CRUD modal)
│   ├── locations/             # index (CRUD modal)
│   ├── purchase-orders/        # PO CRUD, detail, status workflow, receive
│   ├── supplier-returns/       # index, create, view (cancel), retur ke supplier
│   ├── items/labels.php        # Printable browser barcode labels
│   ├── stock/alerts.php        # Low-stock badge/list and PO suggestions
│   ├── stock-in/              # index, create, view, delete
│   ├── stock-out/             # index, create, view, delete
│   ├── transfers/             # index, create, view, delete
│   ├── stock/                 # index (cek stok + alert)
│   ├── reports/               # mutation, stock, stock-in, stock-out
│   ├── users/                 # index (CRUD + reset password)
│   └── roles/                 # index (permission editor per role)
└── database/
    └── schema.sql             # DDL + seed data
```

## Peran Default (RBAC)
| Peran | Akses |
|---|---|
| Super Admin | Semua fitur |
| Admin | Semua kecuali hapus user & edit role |
| Staff Gudang | Transaksi + lihat master data |
| Viewer | Read-only semua |

## Fitur

### PWA, Portal Mobile & Dark Mode
- Aplikasi dapat dipasang di ponsel melalui `manifest.json` dan service worker, dengan halaman fallback saat offline
- Portal mobile khusus Staff Gudang dan Viewer untuk cek stok serta aktivitas transaksi
- Dark mode persisten mengikuti preferensi pengguna

### Purchase Order
- CRUD draft PO with supplier, destination location, order/expected dates, line items, and status workflow: draft → ordered → received/cancelled.
- Receive action is CSRF/RBAC protected and uses one transaction with row locking; it creates the stock-in document, details, stock updates, and mutation audit entries together.
- A PO can only be received once; the received stock-in reference is linked back to the PO.
- Low-stock page shows an accurate in-app badge/list and quantity/value restock suggestions; suggestions can prefill a new PO.

### Retur Supplier
- Retur barang ke supplier: pilih supplier, lokasi asal, alasan wajib, dan daftar barang (qty, harga beli)
- Stok lokasi dikurangi dalam satu transaksi dengan row lock; retur ditolak jika stok tidak cukup
- Nomor referensi `RS-...`; setiap baris dicatat di mutasi sebagai `out` (sumber `supplier_return`)
- Retur tidak dihapus: pembatalan mengembalikan stok, mencatat mutasi `in`, dan status menjadi `cancelled`

### Barcode Labels
- Select active items from the item list and print browser-printable Code 39 SVG labels using the existing item code (no new dependency or build tool).

### Dashboard
- Statistik real-time: total barang aktif, jumlah item stok menipis, nilai total inventori (qty × harga beli), jumlah transaksi masuk+keluar hari ini
- **Grafik transaksi 7 hari terakhir** (Chart.js) — tren barang masuk vs keluar
- **Top 5 nilai inventori** — doughnut chart barang dengan nilai stok terbesar
- Tabel 10 item dengan stok ≤ stok minimum (badge merah jika nol, kuning jika menipis)
- Tabel 8 mutasi terbaru: waktu, barang, tipe (masuk/keluar/transfer), lokasi, qty
- Ringkasan hari ini: transaksi masuk & transaksi keluar (shortcut ke masing-masing halaman)

### Master Data
- **Barang** — CRUD lengkap; kode, nama, kategori, satuan, harga beli, stok minimum, status aktif; **foto barang** JPG/PNG/WebP maks 2MB (tampil di list & detail); halaman view menampilkan stok per lokasi
- **Import Barang Excel/CSV** — upload `.csv`/`.xlsx` maks 5MB/1.000 baris, preview dan validasi per baris, kode duplikat dilewati, kompatibel Excel/cPanel tanpa Composer
- **Kategori** — CRUD via modal; nama & deskripsi
- **Satuan** — CRUD via modal; nama & singkatan
- **Supplier** — CRUD via modal; kode, nama, kontak
- **Lokasi** — CRUD via modal; kode & nama lokasi gudang

### Barang Masuk (`stock-in`)
- Input multi-baris barang dalam satu transaksi (dynamic rows JavaScript)
- **Barcode/QR Scanner** — tombol 📷 scan untuk input barang via kamera (HTML5)
- Field: tanggal, supplier, lokasi tujuan, catatan, + daftar barang (item, qty, harga beli)
- Nomor referensi di-generate otomatis (`SI-...`)
- Stok lokasi diperbarui dan mutasi dicatat otomatis setiap baris
- Transaksi dibungkus dalam DB transaction — rollback jika ada error

### Barang Keluar (`stock-out`)
- Sama seperti barang masuk; stok dikurangi dari lokasi asal
- **Barcode/QR Scanner** untuk input barang cepat
- Nomor referensi `SO-...`

### Transfer Antar Lokasi
- Pindah stok dari satu lokasi ke lokasi lain
- **Barcode/QR Scanner** untuk input barang
- Mencatat dua mutasi sekaligus: `transfer_out` di sumber, `transfer_in` di tujuan
- Nomor referensi `TR-...`

### Stok Opname (Penyesuaian)
- Hitung fisik vs stok sistem per lokasi
- Draft dokumen dengan selisih otomatis dan alasan wajib
- **Approval workflow** — pembuat tidak dapat menyetujui dokumennya sendiri
- Stok sistem baru berubah setelah approval
- **Barcode/QR Scanner** untuk input barang
- Mutation log untuk audit trail

### Cek Stok
- Tabel stok per item per lokasi
- Alert visual untuk item di bawah stok minimum

### Cetak Dokumen / Simpan PDF
- Halaman cetak mandiri untuk Barang Masuk, Barang Keluar/Surat Jalan, dan Transfer Lokasi
- Format A4 dengan identitas perusahaan, tabel barang, catatan, dan area tanda tangan
- Gunakan dialog browser **Cetak → Simpan sebagai PDF** — tanpa library/Composer dan kompatibel XAMPP/cPanel

### Laporan
- **Mutasi Barang** — filter: rentang tanggal, barang, tipe (masuk/keluar/transfer masuk/transfer keluar); tabel: waktu, kode, nama, tipe, lokasi, qty, referensi, oleh; export CSV (UTF-8 BOM untuk Excel)
- **Stok** — snapshot stok saat ini per item & lokasi; export CSV
- **Barang Masuk** — riwayat transaksi masuk dengan filter tanggal & supplier
- **Barang Keluar** — riwayat transaksi keluar dengan filter tanggal & lokasi
- **Performa Supplier** — jumlah dan tingkat penyelesaian PO, rata-rata lead time, ketepatan pengiriman, total nilai pembelian, filter periode/supplier, dan ekspor CSV

### Manajemen User & Role
- CRUD user; nama, username, email, peran
- Permission editor per role: centang/uncentang permission individual
- Toggle aktif/nonaktif; reset password oleh Super Admin

## Keamanan
- Semua query pakai MySQLi prepared statements
- Password di-hash dengan `password_hash()` bcrypt cost=12
- CSRF token di setiap form POST
- Output di-escape dengan `htmlspecialchars()`
- Session `httponly` + `samesite=Strict`
- `.htaccess` blokir akses langsung ke `config/`, `includes/`, `database/`
- Validasi permission di setiap halaman (`require_perm()`)
- Email verifikasi akun via `mail()`
- Forgot & reset password dengan token berumur 1 jam

## Catatan Deployment

- **HTTPS Wajib** untuk fitur barcode scanner — browser modern blokir akses kamera di HTTP plain
- Development localhost (XAMPP/WAMP) aman tanpa HTTPS
- Production di cPanel: aktifkan SSL/Let's Encrypt gratis melalui cPanel SSL/TLS menu
- Library `html5-qrcode` (MIT license) di-serve lokal dari `assets/vendor/` — tidak ada dependency CDN pihak ketiga untuk scanner, jalan penuh di shared hosting & XAMPP
