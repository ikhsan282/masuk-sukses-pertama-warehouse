<?php
// Landing page Maura Warehouse
// Redirect to dashboard if already logged in
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

// Public stats
$total_items = 0;
$total_suppliers = 0;
$total_locations = 0;
$inventory_value = 0;

$q_items = $db->query('SELECT COUNT(*) FROM items WHERE is_active=1');
if ($q_items) $total_items = (int)$q_items->fetch_row()[0];

$q_suppliers = $db->query('SELECT COUNT(*) FROM suppliers WHERE is_active=1');
if ($q_suppliers) $total_suppliers = (int)$q_suppliers->fetch_row()[0];

$q_locations = $db->query('SELECT COUNT(*) FROM locations WHERE is_active=1');
if ($q_locations) $total_locations = (int)$q_locations->fetch_row()[0];

$q_value = $db->query('SELECT COALESCE(SUM(s.quantity * i.buy_price),0) FROM stock s JOIN items i ON i.id=s.item_id');
if ($q_value) $inventory_value = (float)$q_value->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(APP_NAME) ?> — Sistem Manajemen Inventori & Gudang</title>
  <meta name="description" content="Sistem manajemen inventori, stok gudang, dan mutasi barang berbasis web untuk efisiensi operasional gudang dan supply chain.">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
  <style>
    :root {
      --brand-primary: #198754;
      --brand-dark: #146c43;
      --brand-accent: #2d6a4f;
      --bs-primary: #198754;
      --bs-primary-rgb: 25, 135, 84;
      --bs-link-color: #198754;
      --bs-link-hover-color: #146c43;
      --text-dark: #212529;
      --text-muted: #6c757d;
      --border-subtle: #dee2e6;
      --card-shadow: 0 8px 24px rgba(25, 135, 84, 0.12);
    }
    body {
      font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
      color: var(--text-dark);
      line-height: 1.6;
    }
    .navbar-glass {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--border-subtle);
    }
    .hero-section {
      padding: 5rem 0 4rem;
      background: linear-gradient(135deg, rgba(25, 135, 84, 0.05) 0%, rgba(255, 255, 255, 1) 100%);
      border-bottom: 1px solid var(--border-subtle);
    }
    .stat-box {
      background: #fff;
      border: 1px solid var(--border-subtle);
      border-radius: 12px;
      padding: 1.5rem;
      box-shadow: var(--card-shadow);
      transition: transform .2s;
    }
    .stat-box:hover { transform: translateY(-4px); }
    .stat-icon {
      width: 48px; height: 48px;
      border-radius: 10px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.4rem;
      margin-bottom: 1rem;
    }
    .feature-card {
      background: #fff;
      border: 1px solid var(--border-subtle);
      border-radius: 14px;
      padding: 2rem;
      height: 100%;
      box-shadow: var(--card-shadow);
      transition: all .2s;
    }
    .feature-card:hover {
      border-color: var(--brand-primary);
      transform: translateY(-4px);
    }
    .btn-brand {
      background: var(--brand-primary);
      color: #fff;
      border: none;
      font-weight: 600;
      padding: 0.75rem 1.5rem;
      border-radius: 8px;
      transition: all .2s;
    }
    .btn-brand:hover {
      background: var(--brand-dark);
      color: #fff;
      transform: translateY(-2px);
    }
    .section-title {
      font-weight: 800;
      letter-spacing: -0.01em;
    }
    .footer-section {
      background: #1a1d23;
      color: rgba(255, 255, 255, 0.7);
      padding: 2rem 0;
    }
    @media (max-width: 768px) {
      .hero-section { padding: 3rem 0 2rem; }
      .section-title { font-size: 1.75rem; }
      .stat-box { padding: 1rem; }
    }
  </style>
</head>
<body>

  <!-- Navigation -->
  <nav class="navbar navbar-expand-lg navbar-glass sticky-top py-3">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2" href="<?= APP_URL ?>">
        <i class="bi bi-boxes text-primary fs-3"></i>
        <span class="fw-bold"><?= e(APP_NAME) ?></span>
      </a>
      <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
        <i class="bi bi-list fs-4"></i>
      </button>
      <div class="collapse navbar-collapse" id="navMenu">
        <ul class="navbar-nav ms-auto align-items-lg-center gap-3 my-3 my-lg-0">
          <li class="nav-item">
            <a class="nav-link fw-medium" href="#fitur">Fitur Sistem</a>
          </li>
          <li class="nav-item">
            <a class="btn btn-brand" href="<?= APP_URL ?>/auth/login.php">
              <i class="bi bi-box-arrow-in-right me-1"></i> Masuk
            </a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <!-- Hero Section -->
  <section class="hero-section">
    <div class="container">
      <div class="row align-items-center gy-4">
        <div class="col-lg-7">
          <div class="mb-3">
            <span class="badge bg-primary-subtle text-primary px-3 py-2">
              <i class="bi bi-award me-1"></i> Sistem Manajemen Gudang Terpadu
            </span>
          </div>
          <h1 class="display-5 fw-bold mb-3 section-title">
            Kelola Inventori & Gudang dengan <span class="text-primary">Efisien</span>
          </h1>
          <p class="lead text-muted mb-4">
            Sistem berbasis web untuk manajemen stok barang, mutasi masuk/keluar, transfer antar lokasi, dan laporan inventori real-time.
            Akses dari mana saja, data terpusat, operasional lebih cepat.
          </p>
          <div class="d-flex flex-wrap gap-3">
            <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-brand btn-lg">
              <i class="bi bi-door-open me-1"></i> Masuk ke Sistem
            </a>
          </div>
        </div>
        <div class="col-lg-5 text-center">
          <i class="bi bi-boxes display-1 text-primary" style="font-size: 8rem; opacity: 0.15;"></i>
        </div>
      </div>
    </div>
  </section>

  <!-- Stats Section -->
  <section class="py-5 bg-light">
    <div class="container">
      <div class="row g-4 text-center">
        <div class="col-md-3 col-6">
          <div class="stat-box">
            <div class="stat-icon bg-primary-subtle text-primary mx-auto">
              <i class="bi bi-box-seam"></i>
            </div>
            <h3 class="fw-bold mb-0"><?= number_format($total_items) ?></h3>
            <p class="text-muted mb-0 small">Item Terdaftar</p>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="stat-box">
            <div class="stat-icon bg-success-subtle text-success mx-auto">
              <i class="bi bi-building"></i>
            </div>
            <h3 class="fw-bold mb-0"><?= number_format($total_suppliers) ?></h3>
            <p class="text-muted mb-0 small">Supplier Aktif</p>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="stat-box">
            <div class="stat-icon bg-warning-subtle text-warning mx-auto">
              <i class="bi bi-geo-alt"></i>
            </div>
            <h3 class="fw-bold mb-0"><?= number_format($total_locations) ?></h3>
            <p class="text-muted mb-0 small">Lokasi Gudang</p>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="stat-box">
            <div class="stat-icon bg-info-subtle text-info mx-auto">
              <i class="bi bi-cash-stack"></i>
            </div>
            <h3 class="fw-bold mb-0">Rp<?= number_format($inventory_value / 1000000, 1) ?>jt</h3>
            <p class="text-muted mb-0 small">Nilai Inventori</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Features Section -->
  <section id="fitur" class="py-5">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="fw-bold section-title">Fitur Sistem</h2>
        <p class="text-muted">Kelola gudang lebih sistematis dan real-time</p>
      </div>
      <div class="row g-4">
        <div class="col-md-4">
          <div class="feature-card">
            <div class="stat-icon bg-primary-subtle text-primary">
              <i class="bi bi-arrow-down-up"></i>
            </div>
            <h5 class="fw-bold mb-2">Mutasi Stok Real-Time</h5>
            <p class="text-muted mb-0">
              Catat barang masuk, keluar, dan transfer antar lokasi. Histori lengkap dengan bukti transaksi dan cetak dokumen.
            </p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="feature-card">
            <div class="stat-icon bg-success-subtle text-success">
              <i class="bi bi-database"></i>
            </div>
            <h5 class="fw-bold mb-2">Master Data Terpadu</h5>
            <p class="text-muted mb-0">
              Kelola kategori, satuan, supplier, dan lokasi gudang. Import barang dari Excel/CSV untuk setup cepat.
            </p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="feature-card">
            <div class="stat-icon bg-warning-subtle text-warning">
              <i class="bi bi-graph-up-arrow"></i>
            </div>
            <h5 class="fw-bold mb-2">Laporan & Analisis</h5>
            <p class="text-muted mb-0">
              Laporan stok per lokasi, mutasi barang, dan nilai inventori. Export ke Excel untuk analisis lanjutan.
            </p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="feature-card">
            <div class="stat-icon bg-danger-subtle text-danger">
              <i class="bi bi-exclamation-triangle"></i>
            </div>
            <h5 class="fw-bold mb-2">Alert Stok Menipis</h5>
            <p class="text-muted mb-0">
              Notifikasi otomatis saat stok di bawah minimum. Hindari kekosongan barang dan optimalkan purchase order.
            </p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="feature-card">
            <div class="stat-icon bg-info-subtle text-info">
              <i class="bi bi-shield-check"></i>
            </div>
            <h5 class="fw-bold mb-2">Role-Based Access</h5>
            <p class="text-muted mb-0">
              Kontrol akses berbasis role: Super Admin, Admin, Staff Gudang, dan Viewer. Audit trail setiap aktivitas.
            </p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="feature-card">
            <div class="stat-icon bg-secondary-subtle text-secondary">
              <i class="bi bi-printer"></i>
            </div>
            <h5 class="fw-bold mb-2">Cetak Dokumen</h5>
            <p class="text-muted mb-0">
              Cetak bukti penerimaan, surat jalan, dan transfer. Format A4 siap print atau save PDF langsung dari browser.
            </p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="py-5" style="background: linear-gradient(135deg, var(--brand-primary), var(--brand-dark));">
    <div class="container text-center text-white">
      <h3 class="fw-bold mb-3">Siap Kelola Gudang Lebih Efisien?</h3>
      <p class="mb-4 opacity-75">Masuk ke sistem dan mulai manajemen inventori yang lebih baik.</p>
      <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-light btn-lg">
        <i class="bi bi-box-arrow-in-right me-1"></i> Masuk Sekarang
      </a>
    </div>
  </section>

  <!-- Footer -->
  <footer class="footer-section">
    <div class="container">
      <div class="row align-items-center py-3">
        <div class="col-md-6 text-center text-md-start mb-2 mb-md-0">
          <small>© 2026 <?= e(APP_NAME) ?>. Sistem manajemen gudang berbasis web.</small>
        </div>
        <div class="col-md-6 text-center text-md-end">
          <a href="#" class="text-white-50 text-decoration-none mx-2" title="Facebook">
            <i class="bi bi-facebook"></i>
          </a>
          <a href="#" class="text-white-50 text-decoration-none mx-2" title="Instagram">
            <i class="bi bi-instagram"></i>
          </a>
          <a href="#" class="text-white-50 text-decoration-none mx-2" title="WhatsApp">
            <i class="bi bi-whatsapp"></i>
          </a>
        </div>
      </div>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
