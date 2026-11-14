<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$current_user = current_user();
$page_title   = $page_title ?? APP_NAME;
$low_stock_count = 0;
if ($current_user && can('stock.alerts')) {
    $cid = current_company_id();
    $cached = $_SESSION['low_stock_cache'][$cid] ?? null;
    if (is_array($cached) && (time() - (int)($cached['ts'] ?? 0)) < 60) {
        $low_stock_count = (int)$cached['count'];
    } else {
        $low_stock_count = (int)getDB()->query("SELECT COUNT(*) FROM items i
            WHERE i.is_active=1
            AND COALESCE((SELECT SUM(s.quantity) FROM stock s WHERE s.item_id=i.id AND s.company_id=$cid), 0) <= i.min_stock")->fetch_row()[0];
        $_SESSION['low_stock_cache'][$cid] = ['count' => $low_stock_count, 'ts' => time()];
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($page_title) ?> — <?= APP_NAME ?></title>
  <meta name="theme-color" content="#198754">
  <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
  <script>
  (function(){try{var t=localStorage.getItem('mw_theme');if(!t)t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
  </script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1' ?>">
</head>
<body class="bg-light">
<?php if ($current_user): ?>
<div class="wrapper d-flex">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content flex-grow-1">
    <!-- Top navbar -->
    <nav class="navbar navbar-expand-lg navbar-white bg-white border-bottom px-3 py-2 sticky-top shadow-sm">
      <button class="btn btn-sm btn-outline-secondary me-3 d-lg-none" id="sidebarToggleMobile">
        <i class="bi bi-list"></i>
      </button>
      <button class="btn btn-sm btn-outline-secondary me-3 d-none d-lg-inline-flex" id="sidebarToggle">
        <i class="bi bi-layout-sidebar"></i>
      </button>
      <span class="navbar-brand fw-semibold text-primary mb-0 h6"><?= htmlspecialchars($page_title) ?></span>
      <div class="ms-auto d-flex align-items-center gap-2">
        <?php 
        $active_comp = current_company();
        $u_comps = user_companies();
        ?>
        <?php if ($active_comp): ?>
          <?php if (count($u_comps) > 1): ?>
          <div class="dropdown me-1">
            <button class="btn btn-sm btn-outline-success dropdown-toggle d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="dropdown" title="Pilih Perusahaan / PT">
              <i class="bi bi-building"></i>
              <span class="fw-semibold text-truncate company-switch-name"><?= htmlspecialchars($active_comp['name']) ?></span>
              <?php if (!empty($active_comp['is_holding'])): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle ms-1 d-none d-sm-inline" style="font-size:0.65rem;">Holding</span>
              <?php endif; ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
              <li><h6 class="dropdown-header"><i class="bi bi-buildings me-1"></i>Pilih Perusahaan Aktif</h6></li>
              <?php foreach ($u_comps as $uc): ?>
              <li>
                <a class="dropdown-item d-flex align-items-center justify-content-between <?= (int)$uc['id'] === (int)$active_comp['id'] ? 'active' : '' ?>" 
                   href="<?= APP_URL ?>/auth/switch-company.php?id=<?= $uc['id'] ?>">
                  <span>
                    <strong>[<?= htmlspecialchars($uc['code']) ?>]</strong> <?= htmlspecialchars($uc['name']) ?>
                  </span>
                  <?php if (!empty($uc['is_holding'])): ?>
                    <span class="badge text-bg-light ms-2" style="font-size:0.65rem;">Holding</span>
                  <?php endif; ?>
                </a>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php else: ?>
          <span class="badge text-bg-light border text-secondary d-none d-sm-inline-flex align-items-center gap-1 py-2 px-2">
            <i class="bi bi-building text-success"></i> <?= htmlspecialchars($active_comp['name']) ?>
          </span>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (can('stock.alerts')): ?>
        <a class="btn btn-sm btn-outline-warning position-relative" href="<?= APP_URL ?>/pages/stock/alerts.php" title="Peringatan stok">
          <i class="bi bi-bell"></i>
          <?php if ($low_stock_count): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $low_stock_count ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-secondary" id="darkToggle" title="Toggle tema">
          <i class="bi bi-moon-stars"></i>
        </button>
        <div class="dropdown">
          <button class="btn btn-sm btn-light dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown">
            <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:.8rem">
              <?= strtoupper(substr($current_user['name'], 0, 1)) ?>
            </div>
            <span class="d-none d-md-flex flex-column text-start lh-sm">
              <span><?= htmlspecialchars($current_user['name']) ?></span>
              <small class="text-muted" style="font-size:.7rem"><?= htmlspecialchars($current_user['role_name']) ?></small>
            </span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><span class="dropdown-item-text small text-muted"><?= htmlspecialchars($current_user['email']) ?></span></li>
            <li><hr class="dropdown-divider my-1"></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/pages/profile.php"><i class="bi bi-person-gear me-2"></i>Profil & Kata Sandi</a></li>
            <li><hr class="dropdown-divider my-1"></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Keluar</a></li>
          </ul>
        </div>
      </div>
    </nav>
    <div class="content-area p-3 p-lg-4">
      <?= flash_html() ?>
<?php endif; ?>
