<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

auth_guest();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = req_str('username');
    $password = req_str('password');
    if (!$username || !$password) {
        $error = 'Username dan kata sandi wajib diisi.';
    } elseif (auth_login($username, $password)) {
        $role = $_SESSION['user']['role_name'] ?? '';
        redirect(APP_URL . (in_array($role, ['Staff Gudang', 'Viewer'], true) ? '/pages/portal.php' : '/pages/dashboard.php'));
    } else {
        $error = $error ?: 'Username atau kata sandi salah.';
        // pick up flash error set inside auth_login
        $f = get_flash();
        if ($f) $error = $f['message'];
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Masuk — <?= APP_NAME ?></title>
  <script>(function(){try{var t=localStorage.getItem('mw_theme');if(!t)t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1' ?>">
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card">
    <div class="text-center mb-4">
      <div class="auth-logo"><i class="bi bi-boxes"></i></div>
      <div class="auth-logo fs-4"><?= APP_NAME ?></div>
      <p class="text-muted small mt-1">Sistem Manajemen Inventori & Gudang</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?= flash_html() ?>

    <form method="POST" action="" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label fw-semibold small">Username / Email</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person"></i></span>
          <input type="text" name="username" class="form-control" placeholder="Username atau email"
            value="<?= htmlspecialchars(req_str('username')) ?>" required autofocus>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold small">Kata Sandi</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
          <input type="password" name="password" id="password" class="form-control" placeholder="Kata sandi" required>
          <button type="button" class="btn btn-outline-secondary" onclick="togglePwd()">
            <i class="bi bi-eye" id="eyeIcon"></i>
          </button>
        </div>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="remember">
          <label class="form-check-label small" for="remember">Ingat saya</label>
        </div>
        <a href="<?= APP_URL ?>/auth/forgot-password.php" class="small text-primary text-decoration-none">Lupa kata sandi?</a>
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-semibold">
        <i class="bi bi-box-arrow-in-right me-1"></i>Masuk
      </button>
    </form>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePwd() {
  const p = document.getElementById('password');
  const i = document.getElementById('eyeIcon');
  p.type = p.type === 'password' ? 'text' : 'password';
  i.className = p.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>
</body>
</html>
