<?php
// Landing page Masuk Sukses Pertama Warehouse
// Redirect to dashboard/portal if already logged in
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user'])) {
    $role = $_SESSION['user']['role_name'] ?? '';
    if (in_array($role, ['Staff Gudang', 'Viewer'], true)) {
        redirect(APP_URL . '/pages/portal.php');
    }
    redirect(APP_URL . '/pages/dashboard.php');
}

$db = getDB();

// Public aggregate operational catalog counts (non-sensitive)
$total_companies = 0;
$total_items     = 0;
$total_locations = 0;
$total_suppliers = 0;

if ($q = $db->query('SELECT COUNT(*) FROM companies WHERE is_active=1')) {
    $total_companies = (int)$q->fetch_row()[0];
}
if ($q = $db->query('SELECT COUNT(*) FROM items WHERE is_active=1')) {
    $total_items = (int)$q->fetch_row()[0];
}
if ($q = $db->query('SELECT COUNT(*) FROM locations WHERE is_active=1')) {
    $total_locations = (int)$q->fetch_row()[0];
}
if ($q = $db->query('SELECT COUNT(*) FROM suppliers WHERE is_active=1')) {
    $total_suppliers = (int)$q->fetch_row()[0];
}

$css_ver = @filemtime(__DIR__ . '/assets/css/style.css') ?: '1';

$features = [
    [
        'icon' => 'bi-buildings',
        'tone' => 'success',
        'title' => 'Multi-PT & Holding Terpadu',
        'desc' => 'Isolasi data stok per entitas perusahaan, pergantian PT instan dari topbar, serta ringkasan konsolidasi lintas grup.',
    ],
    [
        'icon' => 'bi-clipboard-check',
        'tone' => 'primary',
        'title' => 'Purchase Order & Partial Receive',
        'desc' => 'Alur pengadaan lengkap dari draft PO, approval bertingkat, penerimaan bertahap (partial receive), hingga retur supplier.',
    ],
    [
        'icon' => 'bi-arrow-left-right',
        'tone' => 'warning',
        'title' => 'Mutasi, Transfer & Stock Opname',
        'desc' => 'Catat barang masuk/keluar, transfer antar lokasi maupun lintas PT, stock adjustment ber-approval, dan sesi opname fisik.',
    ],
    [
        'icon' => 'bi-file-earmark-spreadsheet',
        'tone' => 'info',
        'title' => 'Import XLSX/CSV & Label Barcode',
        'desc' => 'Import massal master barang dari file .xlsx/.csv secara langsung, cetak label barang, surat jalan, dan bukti penerimaan.',
    ],
    [
        'icon' => 'bi-file-earmark-bar-graph',
        'tone' => 'success',
        'title' => 'Laporan & Ekspor PDF / Excel',
        'desc' => 'Analisis stok, kartu mutasi, rekap masuk/keluar, dan performa supplier dengan ekspor sekali klik ke PDF, Excel (.xlsx), dan CSV.',
    ],
    [
        'icon' => 'bi-shield-lock',
        'tone' => 'danger',
        'title' => 'Keamanan RBAC & Audit Log',
        'desc' => 'Hak akses berbasis peran granular, proteksi brute-force login, Portal Gudang mobile (PWA), serta rekam jejak Audit Log.',
    ],
];

$workflows = [
    ['step' => '01', 'title' => 'Master Data & Saldo Awal', 'desc' => 'Kelola PT, kategori, satuan, lokasi gudang, supplier, dan import item .xlsx/.csv.'],
    ['step' => '02', 'title' => 'Pengadaan & Penerimaan', 'desc' => 'Terbitkan PO, lakukan persetujuan admin, lalu terima stok penuh atau bertahap.'],
    ['step' => '03', 'title' => 'Distribusi & Opname Stok', 'desc' => 'Proses barang keluar, transfer antar gudang/PT, penyesuaian stok, dan opname rutin.'],
    ['step' => '04', 'title' => 'Monitoring & Pelaporan', 'desc' => 'Pantau KPI dashboard, alert stok menipis, ekspor PDF/Excel, serta Audit Trail.'],
];

$roles_overview = [
    ['name' => 'Super Admin', 'badge' => 'Kendali Holding', 'icon' => 'bi-shield-shaded', 'desc' => 'Pengelolaan multi-PT, manajemen pengguna & hak peran (RBAC), approval, dan Audit Log lengkap.'],
    ['name' => 'Admin', 'badge' => 'Operasional PT', 'icon' => 'bi-person-gear', 'desc' => 'Manajemen master barang, pengadaan & approval PO, mutasi, opname, serta pelaporan eksekutif.'],
    ['name' => 'Staff Gudang', 'badge' => 'Eksekusi Lapangan', 'icon' => 'bi-phone', 'desc' => 'Akses cepat Portal Gudang mobile-first untuk cek stok, pencatatan masuk/keluar, dan transfer harian.'],
    ['name' => 'Viewer', 'badge' => 'Monitoring Read-Only', 'icon' => 'bi-eye', 'desc' => 'Pemantauan posisi stok real-time, peringatan minimum stok, laporan inventori, dan ekspor data.'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#198754">
  <title><?= e(APP_NAME) ?> — Sistem Manajemen Inventori &amp; Gudang</title>
  <meta name="description" content="Sistem manajemen inventori dan gudang multi-PT: pengadaan PO, mutasi real-time, transfer antar lokasi/PT, stock opname, portal gudang PWA, laporan PDF/Excel, dan audit log.">
  <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
  <?= theme_prepaint_script() ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= $css_ver ?>">
  <style>
    .landing-nav {
      background: rgba(255, 255, 255, .92);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border-color);
    }
    .landing-nav .navbar-brand {
      max-width: none;
      white-space: normal;
      overflow: visible;
      line-height: 1.2;
    }
    html[data-bs-theme=dark] .landing-nav {
      background: rgba(18, 26, 23, .92);
    }
    .landing-hero {
      padding: 4.5rem 0 3.75rem;
      background:
        radial-gradient(circle at 85% 15%, rgba(25, 135, 84, .12), transparent 45%),
        linear-gradient(180deg, rgba(25, 135, 84, .05) 0%, transparent 100%);
      border-bottom: 1px solid var(--border-color);
    }
    html[data-bs-theme=dark] .landing-hero {
      background:
        radial-gradient(circle at 85% 15%, rgba(61, 214, 140, .12), transparent 48%),
        linear-gradient(180deg, rgba(29, 41, 36, .65) 0%, var(--page-bg) 100%);
    }
    .landing-title {
      font-weight: 800;
      letter-spacing: -0.02em;
      line-height: 1.18;
    }
    .landing-badge {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      padding: .45rem .85rem;
      border-radius: 999px;
      font-size: .8rem;
      font-weight: 600;
      background: var(--accent-soft);
      color: var(--accent);
      border: 1px solid rgba(25, 135, 84, .22);
    }
    html[data-bs-theme=dark] .landing-badge,
    html[data-bs-theme=dark] .landing-accent-text {
      color: #4ade80 !important;
    }
    .landing-accent-text {
      color: var(--accent);
    }
    .landing-chip {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .3rem .7rem;
      border-radius: .5rem;
      font-size: .78rem;
      font-weight: 500;
      background: var(--card-bg);
      color: var(--text-muted);
      border: 1px solid var(--border-color);
    }
    .landing-preview {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 1rem;
      box-shadow: 0 16px 40px rgba(0, 0, 0, .09);
      overflow: hidden;
    }
    html[data-bs-theme=dark] .landing-preview {
      box-shadow: 0 18px 48px rgba(0, 0, 0, .45);
    }
    .landing-preview-head {
      background: var(--surface-muted);
      border-bottom: 1px solid var(--border-color);
      padding: .85rem 1.15rem;
    }
    .landing-preview-row {
      display: flex;
      align-items: flex-start;
      gap: .75rem;
      padding: .75rem .9rem;
      border-radius: .7rem;
      background: var(--surface-muted);
      border: 1px solid var(--border-color);
    }
    .landing-stat-box,
    .landing-card {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: .95rem;
      height: 100%;
      transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
    }
    .landing-stat-box {
      padding: 1.35rem 1rem;
      box-shadow: 0 2px 10px rgba(0, 0, 0, .04);
    }
    .landing-card {
      padding: 1.6rem;
      box-shadow: 0 3px 14px rgba(0, 0, 0, .04);
    }
    .landing-card:hover,
    .landing-stat-box:hover {
      transform: translateY(-3px);
      border-color: var(--accent);
      box-shadow: 0 10px 28px rgba(25, 135, 84, .12);
    }
    .landing-icon-box {
      width: 46px;
      height: 46px;
      border-radius: .75rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      flex-shrink: 0;
    }
    .landing-step-num {
      width: 34px;
      height: 34px;
      border-radius: .55rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: .82rem;
      background: var(--accent-soft);
      color: var(--accent);
      border: 1px solid rgba(25, 135, 84, .22);
    }
    html[data-bs-theme=dark] .landing-step-num {
      color: #4ade80;
    }
    .landing-band-alt {
      background: var(--surface-muted);
      border-top: 1px solid var(--border-color);
      border-bottom: 1px solid var(--border-color);
    }
    .landing-cta {
      background: linear-gradient(135deg, #1a3c2e 0%, #198754 100%);
      border-radius: 1.25rem;
    }
    html[data-bs-theme=dark] .landing-cta {
      background: linear-gradient(135deg, #132c21 0%, #16653f 100%);
      border: 1px solid var(--border-color);
    }
    .landing-cta .btn-cta-light {
      background: #ffffff;
      color: #146c43;
      border: 1px solid #ffffff;
      font-weight: 600;
    }
    .landing-cta .btn-cta-light:hover {
      background: #f0fdf4;
      color: #0f5132;
    }
    .landing-footer {
      background: #13221c;
      color: rgba(229, 235, 232, .75);
      border-top: 1px solid rgba(255, 255, 255, .08);
    }
    .landing-footer a {
      color: rgba(229, 235, 232, .78);
      text-decoration: none;
    }
    .landing-footer a:hover {
      color: #52d68a;
    }
    @media (max-width: 767.98px) {
      .landing-hero { padding: 2.75rem 0 2.5rem; }
      .landing-title { font-size: 1.85rem; }
      .landing-nav .navbar-brand { font-size: .92rem; max-width: calc(100vw - 120px); }
    }
  </style>
</head>
<body>

  <!-- Navigation -->
  <nav class="navbar navbar-expand-lg landing-nav sticky-top py-2 py-lg-3">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2 text-body" href="<?= APP_URL ?>/">
        <i class="bi bi-boxes landing-accent-text fs-3"></i>
        <span class="fw-bold"><?= e(APP_NAME) ?></span>
      </a>
      <div class="d-flex align-items-center gap-2 order-lg-3">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="darkToggle" title="Ganti tema (Terang / Gelap)" aria-label="Ganti tema terang atau gelap">
          <i class="bi bi-moon-stars"></i>
        </button>
        <a class="btn btn-sm btn-primary fw-semibold px-3 d-none d-sm-inline-flex align-items-center gap-1" href="<?= APP_URL ?>/auth/login.php">
          <i class="bi bi-box-arrow-in-right"></i> Masuk
        </a>
        <button class="navbar-toggler border-0 p-1" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu" aria-label="Buka navigasi">
          <i class="bi bi-list fs-4"></i>
        </button>
      </div>
      <div class="collapse navbar-collapse order-lg-2" id="navMenu">
        <ul class="navbar-nav ms-auto me-lg-3 align-items-lg-center gap-lg-3 my-2 my-lg-0">
          <li class="nav-item"><a class="nav-link fw-medium" href="#fitur">Fitur</a></li>
          <li class="nav-item"><a class="nav-link fw-medium" href="#alur">Alur Kerja</a></li>
          <li class="nav-item"><a class="nav-link fw-medium" href="#peran">Peran &amp; Akses</a></li>
          <li class="nav-item d-sm-none mt-2">
            <a class="btn btn-primary w-100 fw-semibold" href="<?= APP_URL ?>/auth/login.php">
              <i class="bi bi-box-arrow-in-right me-1"></i> Masuk ke Sistem
            </a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <!-- Hero Section -->
  <section class="landing-hero">
    <div class="container">
      <div class="row align-items-center gy-4 gx-lg-5">
        <div class="col-lg-7">
          <div class="mb-3">
            <span class="landing-badge">
              <i class="bi bi-buildings"></i> Multi-PT Warehouse Management System
            </span>
          </div>
          <h1 class="display-5 mb-3 landing-title">
            Kelola Inventori Lintas Entitas dengan <span class="landing-accent-text">Akurat &amp; Real-Time</span>
          </h1>
          <p class="lead text-muted mb-4">
            Platform terpadu untuk pengadaan Purchase Order, approval bertingkat, mutasi barang masuk/keluar, transfer antar lokasi dan antar PT, stock opname, hingga laporan PDF/Excel dan Audit Trail.
          </p>
          <div class="d-flex flex-wrap align-items-center gap-2 gap-sm-3 mb-4">
            <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary btn-lg fw-semibold px-4">
              <i class="bi bi-box-arrow-in-right me-1"></i> Masuk ke Sistem
            </a>
            <a href="#fitur" class="btn btn-outline-secondary btn-lg px-4">
              Lihat Fitur
            </a>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <span class="landing-chip"><i class="bi bi-check2-circle text-success"></i> Multi-PT &amp; Konsolidasi</span>
            <span class="landing-chip"><i class="bi bi-file-earmark-excel text-success"></i> Import &amp; Export XLSX / PDF</span>
            <span class="landing-chip"><i class="bi bi-phone text-success"></i> Portal Gudang PWA</span>
            <span class="landing-chip"><i class="bi bi-shield-check text-success"></i> RBAC &amp; Audit Log</span>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="landing-preview">
            <div class="landing-preview-head d-flex align-items-center justify-content-between">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-grid-1x2-fill landing-accent-text"></i>
                <span class="fw-semibold small">Arsitektur Modul Operasional</span>
              </div>
              <span class="badge bg-success-subtle text-success border border-success-subtle">Terintegrasi</span>
            </div>
            <div class="p-3 d-grid gap-2">
              <div class="landing-preview-row">
                <span class="landing-icon-box bg-primary-subtle text-primary"><i class="bi bi-cart-check"></i></span>
                <div class="min-w-0">
                  <div class="fw-semibold small">Pengadaan &amp; Penerimaan PO</div>
                  <div class="text-muted small">Draft PO, approval, partial receive &amp; retur supplier</div>
                </div>
              </div>
              <div class="landing-preview-row">
                <span class="landing-icon-box bg-warning-subtle text-warning"><i class="bi bi-arrow-left-right"></i></span>
                <div class="min-w-0">
                  <div class="fw-semibold small">Mutasi, Transfer &amp; Opname</div>
                  <div class="text-muted small">Masuk/keluar, transfer lintas PT, adjustment &amp; opname fisik</div>
                </div>
              </div>
              <div class="landing-preview-row">
                <span class="landing-icon-box bg-info-subtle text-info"><i class="bi bi-bar-chart-line"></i></span>
                <div class="min-w-0">
                  <div class="fw-semibold small">Laporan &amp; Ekspor Dokumen</div>
                  <div class="text-muted small">Stok, mutasi, performa supplier — PDF, Excel (.xlsx) &amp; print A4</div>
                </div>
              </div>
              <div class="landing-preview-row">
                <span class="landing-icon-box bg-danger-subtle text-danger"><i class="bi bi-shield-lock"></i></span>
                <div class="min-w-0">
                  <div class="fw-semibold small">Tata Kelola &amp; Keamanan</div>
                  <div class="text-muted small">RBAC 4 peran, proteksi brute-force &amp; jejak Audit Log</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Live Operational Catalog Stats -->
  <section class="py-5 landing-band-alt">
    <div class="container">
      <div class="row g-3 g-md-4 text-center">
        <div class="col-6 col-lg-3">
          <div class="landing-stat-box">
            <div class="landing-icon-box bg-success-subtle text-success mx-auto mb-2">
              <i class="bi bi-buildings"></i>
            </div>
            <h3 class="fw-bold mb-0"><?= number_format($total_companies) ?></h3>
            <p class="text-muted mb-0 small">Entitas / PT Aktif</p>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="landing-stat-box">
            <div class="landing-icon-box bg-primary-subtle text-primary mx-auto mb-2">
              <i class="bi bi-box-seam"></i>
            </div>
            <h3 class="fw-bold mb-0"><?= number_format($total_items) ?></h3>
            <p class="text-muted mb-0 small">Item Terdaftar</p>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="landing-stat-box">
            <div class="landing-icon-box bg-warning-subtle text-warning mx-auto mb-2">
              <i class="bi bi-geo-alt"></i>
            </div>
            <h3 class="fw-bold mb-0"><?= number_format($total_locations) ?></h3>
            <p class="text-muted mb-0 small">Lokasi Gudang</p>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="landing-stat-box">
            <div class="landing-icon-box bg-info-subtle text-info mx-auto mb-2">
              <i class="bi bi-truck"></i>
            </div>
            <h3 class="fw-bold mb-0"><?= number_format($total_suppliers) ?></h3>
            <p class="text-muted mb-0 small">Supplier Aktif</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Features Section -->
  <section id="fitur" class="py-5">
    <div class="container">
      <div class="text-center mb-4 mb-md-5">
        <h2 class="fw-bold landing-title mb-2">Kapabilitas Utama Sistem</h2>
        <p class="text-muted mb-0">Dirancang sesuai operasional gudang multi-entitas dari hulu hingga pelaporan</p>
      </div>
      <div class="row g-4">
        <?php foreach ($features as $f): ?>
        <div class="col-md-6 col-lg-4">
          <div class="landing-card">
            <div class="landing-icon-box bg-<?= $f['tone'] ?>-subtle text-<?= $f['tone'] ?> mb-3">
              <i class="bi <?= $f['icon'] ?>"></i>
            </div>
            <h5 class="fw-bold mb-2"><?= e($f['title']) ?></h5>
            <p class="text-muted mb-0 small lh-base"><?= e($f['desc']) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- Workflow Section -->
  <section id="alur" class="py-5 landing-band-alt">
    <div class="container">
      <div class="text-center mb-4 mb-md-5">
        <h2 class="fw-bold landing-title mb-2">Alur Operasional End-to-End</h2>
        <p class="text-muted mb-0">Setiap pergerakan stok tercatat otomatis dengan referensi dokumen &amp; audit log</p>
      </div>
      <div class="row g-3 g-md-4">
        <?php foreach ($workflows as $wf): ?>
        <div class="col-sm-6 col-lg-3">
          <div class="landing-card p-3 p-md-4">
            <div class="landing-step-num mb-3"><?= $wf['step'] ?></div>
            <h6 class="fw-bold mb-2"><?= e($wf['title']) ?></h6>
            <p class="text-muted small mb-0"><?= e($wf['desc']) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- Roles & Access Overview -->
  <section id="peran" class="py-5">
    <div class="container">
      <div class="text-center mb-4 mb-md-5">
        <h2 class="fw-bold landing-title mb-2">Kontrol Akses Berbasis Peran</h2>
        <p class="text-muted mb-0">Pembagian tanggung jawab yang jelas antara pengelola holding, admin PT, staff lapangan, dan pemantau</p>
      </div>
      <div class="row g-3 g-md-4">
        <?php foreach ($roles_overview as $ro): ?>
        <div class="col-sm-6 col-lg-3">
          <div class="landing-card p-3 p-md-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <span class="landing-icon-box bg-success-subtle text-success"><i class="bi <?= $ro['icon'] ?>"></i></span>
              <span class="badge bg-success-subtle text-success border border-success-subtle"><?= e($ro['badge']) ?></span>
            </div>
            <h6 class="fw-bold mb-2"><?= e($ro['name']) ?></h6>
            <p class="text-muted small mb-0"><?= e($ro['desc']) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="pb-5 pt-2">
    <div class="container">
      <div class="landing-cta text-white text-center p-4 p-md-5 shadow-sm">
        <h3 class="fw-bold mb-2">Siap Mengelola Operasional Gudang?</h3>
        <p class="mb-4 opacity-75">Masuk menggunakan akun perusahaan Anda untuk mengakses Dashboard atau Portal Gudang.</p>
        <div class="d-flex flex-wrap justify-content-center gap-2 gap-sm-3">
          <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-cta-light btn-lg px-4">
            <i class="bi bi-box-arrow-in-right me-1"></i> Masuk Sekarang
          </a>
          <a href="<?= APP_URL ?>/auth/forgot-password.php" class="btn btn-outline-light btn-lg px-4">
            <i class="bi bi-key me-1"></i> Lupa Kata Sandi
          </a>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="landing-footer py-4">
    <div class="container">
      <div class="row align-items-center gy-2">
        <div class="col-md-6 text-center text-md-start">
          <small>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Sistem manajemen inventori &amp; gudang multi-PT.</small>
        </div>
        <div class="col-md-6 text-center text-md-end small d-flex flex-wrap justify-content-center justify-content-md-end gap-3">
          <a href="#fitur">Fitur</a>
          <a href="#alur">Alur Kerja</a>
          <a href="#peran">Peran</a>
          <a href="<?= APP_URL ?>/auth/login.php">Masuk</a>
        </div>
      </div>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
  <?= theme_toggle_script() ?>
</body>
</html>
