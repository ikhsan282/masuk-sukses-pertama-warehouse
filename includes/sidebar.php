<?php
$current_url = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
function nav_active(string $path): string {
    global $current_url;
    // Cocokkan segmen penuh: '/stock' aktif untuk '/stock/index.php', bukan '/stock-in/...'
    return ($current_url === $path || str_starts_with($current_url, $path . '/')) ? 'active' : '';
}
?>
<nav id="sidebar" class="sidebar text-white d-flex flex-column">
  <div class="sidebar-header d-flex align-items-center justify-content-between px-3 py-3 border-bottom border-secondary">
    <a href="<?= APP_URL ?>/pages/dashboard.php" class="text-white text-decoration-none d-flex align-items-center gap-2">
      <i class="bi bi-boxes fs-5 text-success"></i>
      <span class="fw-bold sidebar-label"><?= APP_NAME ?></span>
    </a>
  </div>
  <div class="sidebar-menu flex-grow-1 overflow-y-auto py-2">

    <?php if (can('dashboard.view')): ?>
    <a href="<?= APP_URL ?>/pages/dashboard.php" class="sidebar-link <?= nav_active('/dashboard') ?>">
      <i class="bi bi-speedometer2"></i><span class="sidebar-label">Dashboard</span>
    </a>
    <?php endif; ?>

    <?php if (can('items.view') || can('categories.view') || can('units.view') || can('suppliers.view') || can('locations.view')): ?>
    <div class="sidebar-section-title sidebar-label">DATA MASTER</div>
    <?php endif; ?>

    <?php if (can('items.view')): ?>
    <a href="<?= APP_URL ?>/pages/items/index.php" class="sidebar-link <?= nav_active('/items') ?>">
      <i class="bi bi-box-seam"></i><span class="sidebar-label">Barang</span>
    </a>
    <?php endif; ?>

    <?php if (can('categories.view')): ?>
    <a href="<?= APP_URL ?>/pages/categories/index.php" class="sidebar-link <?= nav_active('/categories') ?>">
      <i class="bi bi-tags"></i><span class="sidebar-label">Kategori</span>
    </a>
    <?php endif; ?>

    <?php if (can('units.view')): ?>
    <a href="<?= APP_URL ?>/pages/units/index.php" class="sidebar-link <?= nav_active('/units') ?>">
      <i class="bi bi-rulers"></i><span class="sidebar-label">Satuan</span>
    </a>
    <?php endif; ?>

    <?php if (can('suppliers.view')): ?>
    <a href="<?= APP_URL ?>/pages/suppliers/index.php" class="sidebar-link <?= nav_active('/suppliers') ?>">
      <i class="bi bi-truck"></i><span class="sidebar-label">Supplier</span>
    </a>
    <?php endif; ?>

    <?php if (can('locations.view')): ?>
    <a href="<?= APP_URL ?>/pages/locations/index.php" class="sidebar-link <?= nav_active('/locations') ?>">
      <i class="bi bi-geo-alt"></i><span class="sidebar-label">Lokasi Rak</span>
    </a>
    <?php endif; ?>

    <?php if (can('stock_in.view') || can('stock_out.view') || can('transfers.view') || can('adjustments.view')): ?>
    <div class="sidebar-section-title sidebar-label">TRANSAKSI</div>
    <?php endif; ?>

    <?php if (can('stock_in.view')): ?>
    <a href="<?= APP_URL ?>/pages/stock-in/index.php" class="sidebar-link <?= nav_active('/stock-in') ?>">
      <i class="bi bi-box-arrow-in-down"></i><span class="sidebar-label">Barang Masuk</span>
    </a>
    <?php endif; ?>

    <?php if (can('stock_out.view')): ?>
    <a href="<?= APP_URL ?>/pages/stock-out/index.php" class="sidebar-link <?= nav_active('/stock-out') ?>">
      <i class="bi bi-box-arrow-up"></i><span class="sidebar-label">Barang Keluar</span>
    </a>
    <?php endif; ?>

    <?php if (can('transfers.view')): ?>
    <a href="<?= APP_URL ?>/pages/transfers/index.php" class="sidebar-link <?= nav_active('/transfers') ?>">
      <i class="bi bi-arrow-left-right"></i><span class="sidebar-label">Transfer Lokasi</span>
    </a>
    <?php endif; ?>

    <?php if (can('adjustments.view')): ?>
    <a href="<?= APP_URL ?>/pages/adjustments/index.php" class="sidebar-link <?= nav_active('/adjustments') ?>">
      <i class="bi bi-clipboard-check"></i><span class="sidebar-label">Penyesuaian Stok</span>
    </a>
    <?php endif; ?>

    <?php if (can('stock_opname.view')): ?>
    <a href="<?= APP_URL ?>/pages/stock-opname/index.php" class="sidebar-link <?= nav_active('/stock-opname') ?>">
      <i class="bi bi-upc-scan"></i><span class="sidebar-label">Stock Opname</span>
    </a>
    <?php endif; ?>

    <?php if (can('purchase_orders.view')): ?>
    <a href="<?= APP_URL ?>/pages/purchase-orders/index.php" class="sidebar-link <?= nav_active('/purchase-orders') ?>">
      <i class="bi bi-cart-check"></i><span class="sidebar-label">Purchase Order</span>
    </a>
    <?php endif; ?>

    <?php if (can('supplier_returns.view')): ?>
    <a href="<?= APP_URL ?>/pages/supplier-returns/index.php" class="sidebar-link <?= nav_active('/supplier-returns') ?>">
      <i class="bi bi-arrow-return-left"></i><span class="sidebar-label">Retur Supplier</span>
    </a>
    <?php endif; ?>

    <?php if (can('stock.view')): ?>
    <div class="sidebar-section-title sidebar-label">STOK</div>
    <a href="<?= APP_URL ?>/pages/stock/index.php" class="sidebar-link <?= nav_active('/stock') ?>">
      <i class="bi bi-clipboard-data"></i><span class="sidebar-label">Cek Stok</span>
    </a>
    <?php endif; ?>

    <?php if (can('stock.alerts')): ?>
    <a href="<?= APP_URL ?>/pages/stock/alerts.php" class="sidebar-link <?= nav_active('/stock/alerts') ?>">
      <i class="bi bi-bell"></i><span class="sidebar-label">Peringatan Stok</span>
    </a>
    <?php endif; ?>

    <?php if (can('reports.view')): ?>
    <div class="sidebar-section-title sidebar-label">LAPORAN</div>
    <a href="<?= APP_URL ?>/pages/reports/mutation.php" class="sidebar-link <?= nav_active('/reports/mutation') ?>">
      <i class="bi bi-arrow-down-up"></i><span class="sidebar-label">Mutasi Barang</span>
    </a>
    <a href="<?= APP_URL ?>/pages/reports/stock.php" class="sidebar-link <?= nav_active('/reports/stock') ?>">
      <i class="bi bi-bar-chart-line"></i><span class="sidebar-label">Laporan Stok</span>
    </a>
    <a href="<?= APP_URL ?>/pages/reports/stock-in.php" class="sidebar-link <?= nav_active('/reports/stock-in') ?>">
      <i class="bi bi-file-earmark-arrow-down"></i><span class="sidebar-label">Laporan Masuk</span>
    </a>
    <a href="<?= APP_URL ?>/pages/reports/stock-out.php" class="sidebar-link <?= nav_active('/reports/stock-out') ?>">
      <i class="bi bi-file-earmark-arrow-up"></i><span class="sidebar-label">Laporan Keluar</span>
    </a>
    <?php endif; ?>

    <?php if (can('users.view') || can('roles.view')): ?>
    <div class="sidebar-section-title sidebar-label">PENGGUNA</div>
    <?php endif; ?>

    <?php if (can('users.view')): ?>
    <a href="<?= APP_URL ?>/pages/users/index.php" class="sidebar-link <?= nav_active('/users') ?>">
      <i class="bi bi-people"></i><span class="sidebar-label">Pengguna</span>
    </a>
    <?php endif; ?>

    <?php if (can('roles.view')): ?>
    <a href="<?= APP_URL ?>/pages/roles/index.php" class="sidebar-link <?= nav_active('/roles') ?>">
      <i class="bi bi-shield-lock"></i><span class="sidebar-label">Peran & Izin</span>
    </a>
    <?php endif; ?>

  </div>
  <div class="sidebar-footer px-3 py-2 border-top border-secondary">
    <a href="<?= APP_URL ?>/auth/logout.php" class="sidebar-link text-danger">
      <i class="bi bi-box-arrow-right"></i><span class="sidebar-label">Keluar</span>
    </a>
  </div>
</nav>
