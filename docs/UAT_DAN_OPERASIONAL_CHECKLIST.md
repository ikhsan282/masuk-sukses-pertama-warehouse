# Checklist UAT Multi-Role & Kesiapan Operasional Produksi — MSP Warehouse

Dokumen ini menjadi panduan standar sebelum rilis/go-live maupun audit berkala pada **MSP Warehouse** (Multi-PT Inventory & Warehouse Management).

---

## 1. Pre-Flight Check Database & Keamanan Produksi

Jalankan seluruh pemeriksaan berikut dari root direktori aplikasi sebelum membuka akses ke pengguna:

1. **Verifikasi Konfigurasi Produksi**
   - Salin `.env.example` menjadi `.env` atau `config/config.local.example.php` menjadi `config/config.local.php` sesuai environment target (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `DB_PORT`, `APP_URL`, `MAIL_DRIVER`, `SMTP_*`). Bila `APP_URL` tidak diisi eksplisit, `config/env.php` mendeteksi scheme/host/subfolder HTTP secara otomatis.
   - Pastikan aturan `.htaccess` aktif pada Apache (`AllowOverride All`) untuk memblokir akses langsung ke `.env`, `.user.ini`, `config/`, `includes/`, `database/`, `logs/`, `cron/`, `backups/`, `tests/`, dan `docs/`, serta mengaktifkan security headers.
   - Pastikan file backup dan konfigurasi lokal tidak ikut ter-commit (`.gitignore` mengecualikan `.env`, `config/config.local.php`, `config/*.local.php`, `backups/*.sql`, `backups/*.sql.gz`, `backups/*.zip`, `logs/*.log`).

2. **Verifikasi Tabel, Charset, Master PT/Role, & Composite Index**
   ```bash
   php cron/verify_production_db.php
   ```
   Jika terdapat index atau tabel keamanan/audit yang belum terpasang pada database existing, jalankan mode idempoten:
   ```bash
   php cron/verify_production_db.php --apply-missing
   ```
   Output wajib berstatus **`[PASS]`** dengan:
   - `29` tabel inti berengine **InnoDB** dan charset **utf8mb4** (termasuk `login_attempts` dan `audit_logs`).
   - Entitas PT default aktif (`MSP`, `SLN`, `PDM`) dan `4` role inti (`Super Admin`, `Admin`, `Staff Gudang`, `Viewer`).
   - `45/45` composite & lookup indexes pada `15` tabel terverifikasi (termasuk `idx_transfers_from_comp_date_id`, `idx_transfers_to_comp_date_id`, `idx_login_attempts_lookup`, dan index `audit_logs`).

3. **Verifikasi Proteksi Direktori Backup over HTTP**
   - Uji akses URL `<APP_URL>/backups/` melalui browser/curl. Server wajib mengembalikan HTTP **`403 Forbidden`**.

---

## 2. Backup Database Harian Otomatis (Retensi 14 Hari)

### A. Windows / XAMPP (`cron/backup_db.ps1`)
Skrip membaca kredensial dari `config/database.php`, menjalankan `mysqldump` dengan `--single-transaction --routines --triggers --hex-blob --default-character-set=utf8mb4`, mengarsipkan ke `.zip` di folder `backups/`, menghapus `.sql` sementara, dan memangkas arsip yang lebih lama dari `14` hari.

Uji jalankan manual:
```powershell
powershell -ExecutionPolicy Bypass -File cron/backup_db.ps1
```

Daftarkan pada **Windows Task Scheduler** agar berjalan otomatis setiap hari pukul `02:00`:
```powershell
schtasks /Create /TN "MSP Warehouse Daily DB Backup" /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\masuk-sukses-pertama-warehouse\cron\backup_db.ps1" /SC DAILY /ST 02:00 /F
```

### B. Linux / cPanel (`cron/backup_db.sh`)
Menghasilkan arsip terkompres `.sql.gz` pada direktori `backups/` dengan retensi default `14` hari (`RETENTION_DAYS=14`).

Pastikan executable dan uji jalankan:
```bash
chmod +x cron/backup_db.sh
./cron/backup_db.sh
```

Tambahkan pada `crontab -e` (setiap hari pukul `02:00`):
```cron
0 2 * * * /path/ke/masuk-sukses-pertama-warehouse/cron/backup_db.sh >> /path/ke/masuk-sukses-pertama-warehouse/logs/backup.log 2>&1
```

---

## 3. Verifikasi Otomatis (Automated UAT, Security & Grid Pentest)

Sebelum go-live (pastikan web server aktif sesuai `APP_URL` atau set env `UAT_BASE_URL`), jalankan:

```bash
php tests/uat_roles_workflow_test.php
php tests/grid_pentest_test.php
php tests/phase5_improvements_test.php
```

Cakupan otomatis `tests/uat_roles_workflow_test.php`:
- **RBAC Multi-Role (`4` akun role)** melalui sesi HTTP & CSRF token asli:
  - Guest diredirect ke `/auth/login.php`.
  - **Viewer**: masuk ke `/pages/portal.php`, bisa melihat/export stok & laporan, ditolak HTTP `403` pada modul tulis/master/approve/finalize, serta ditolak berpindah ke PT yang tidak diizinkan.
  - **Staff Gudang**: masuk ke `/pages/portal.php`, bisa membuka form operasional (`transfers/create.php`, `stock-opname/create.php`), bisa berpindah antara PT `MSP` <-> `SLN`, ditolak ke PT `PDM`, serta ditolak HTTP `403` di `companies`, `users`, `roles`, `po.approve`, & `stock_opname.finalize`.
  - **Admin**: masuk ke `/pages/dashboard.php`, memiliki akses kelola operasional, approval PO, finalisasi opname, dan view `companies`/`users`/`roles`.
  - **Super Admin**: akses penuh lintas seluruh PT termasuk `roles.edit` (`pages/roles/index.php`).
- **Alur Operasional End-to-End (HTTP POST + Verifikasi Matematika Stok MySQL + Auto-Rollback Clean)**:
  1. Switch PT aktif ke **MSP** via `/auth/switch-company.php`.
  2. Buat Draft Purchase Order (`+10 pcs`) -> Submit (`pending`) -> Approve (`approved`).
  3. Partial Receive tahap 1 (`+4 pcs`, status PO `partial`) dan tahap 2 (`+6 pcs`, status PO `completed`).
  4. Retur Supplier (`-3 pcs`) memotong stok PT MSP secara akurat.
  5. Transfer Antar PT (`MSP` -> `SLN`, `2 pcs`): memotong stok MSP (`-2`), menambah stok SLN (`+2`), dan terfilter lewat `.grid-toolbar` (`?q=...&date_from=...&date_to=...`) baik pada tampilan PT asal (`>SLN<`) maupun PT tujuan (`>MSP<`).
  6. Stock Opname pada PT MSP: buat sesi -> hitung fisik (`+1` selisih/variance) -> finalisasi -> posting adjustment & mutasi.
  7. Verifikasi endpoint export laporan mutasi (`CSV` dan `?format=print` HTML) bebas PHP warning/error.
  8. Teardown otomatis mengembalikan seluruh transaksi simulasi & saldo `stock` persis ke kondisi awal.

---

## 4. Checklist UAT Manual di Browser (Sign-Off Lapangan)

| No | Area Uji | Langkah Uji | Ekspektasi Hasil | Status |
|---|---|---|---|---|
| 1 | Login & Redirect Role | Login dengan akun **Viewer** / **Staff Gudang** vs **Admin** / **Super Admin** | Role lapangan masuk ke `pages/portal.php`; Admin/Super Admin masuk ke `pages/dashboard.php` | [ ] |
| 2 | Switch Entitas PT | Ubah PT aktif dari dropdown topbar | Seluruh KPI dashboard, stok, transaksi, & filter grid langsung mengikuti PT terpilih | [ ] |
| 3 | Master PT & Dark Mode | Buka `pages/companies/index.php` dan halaman laporan dalam mode **Dark** & **Light** | Teks nama PT terbaca kontras di dark mode; header/total `.table-light` tidak silau/putih polos | [ ] |
| 4 | Toolbar & Filter Grid | Cek filter cari + tanggal pada `pages/transfers/index.php`, `pages/items/index.php`, & laporan | Kontrol toolbar sejajar rapi di desktop/tablet, `per_page` terjaga, `.grid-footer` konsisten | [ ] |
| 5 | Alur PO -> Retur | Buat PO -> Submit -> Approve -> Partial Receive -> Retur Supplier | Status PO bergerak `draft -> pending -> partial -> completed`; stok dan mutasi sinkron | [ ] |
| 6 | Transfer Antar PT | Transfer barang dari **PT A** ke **PT B** | Stok PT asal berkurang, stok PT tujuan bertambah, muncul di daftar transfer kedua PT | [ ] |
| 7 | Stock Opname | Buat sesi -> Input Qty Fisik -> Lihat Variance -> Finalisasi | Adjustment & mutasi tercatat otomatis saat finalisasi; sesi terkunci (`Finalized`) | [ ] |
| 8 | Export & Cetak | Unduh `PDF`, `Excel (.xlsx)`, `CSV`, dan buka `Tampilan Cetak` di halaman Master, Laporan, & Audit Log | File `.pdf` dan `.xlsx` valid terbuka tanpa error, formula Excel ternetralkan (`CWE-1236`), serta terekam di `audit_logs` | [ ] |
| 9 | PWA & Path Agnostik | Install/register Service Worker (`sw.js`) & simulasi koneksi **Offline** | Aset fallback `offline.html` muncul dan tombol Portal mengarah ke `<base>/pages/portal.php` | [ ] |
| 10 | Import Barang Excel (.xlsx) & CSV | Unggah `.xlsx` (tanpa memerlukan `ext-zip`) atau `.csv` di `pages/items/import.php`, lalu klik **Konfirmasi Import** | Pratinjau terbaca akurat dan konfirmasi berhasil menyimpan/memperbarui barang tanpa error file sementara | [ ] |
| 11 | Onboarding User & Brute-Force Guard | Tambah user baru dengan/tanpa opsi verifikasi langsung, lalu uji salah sandi 5x pada user uji terisolasi | Admin dapat mengaktifkan akun instan atau klik verifikasi 1 klik; percobaan ke-6 dikunci 300 detik (HTTP `429`) | [ ] |
| 12 | Dashboard Operasional & Multi-PT | Buka `pages/dashboard.php` di Light & Dark Mode, klik toggle `7 Hari / 30 Hari`, cek panel Tugas Tertunda & tabel Multi-PT | Tinggi 4 kartu KPI seragam (`compact_idr`), Chart adaptif Dark Mode, tugas tertunda akurat, dan ringkasan lintas PT tampil | [ ] |
| 13 | Audit Log & Jejak Aktivitas | Buka `pages/audit-logs/index.php` lewat menu sidebar, filter kategori/tanggal, dan export PDF/Excel | Login, approval PO/Adjustment/Opname, CRUD/Import barang, dan export tercatat lengkap beserta IP & PT | [ ] |

---

## 5. Sign-Off Rilis

- **Tanggal Verifikasi:** `____-__-__`
- **Verifikator Teknis (IT / DevOps):** `__________________`
- **User Acceptance Lead (Kepala Gudang / Operasional):** `__________________`
- **Backup Terakhir Terverifikasi (`backups/*.zip` / `.sql.gz`):** `__________________`
