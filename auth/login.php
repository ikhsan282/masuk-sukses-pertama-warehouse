<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-layout.php';

auth_guest();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = req_str('username');
    $password = req_str('password');
    $remember = !empty($_POST['remember']);
    if (!$username || !$password) {
        $error = 'Username dan kata sandi wajib diisi.';
    } elseif (auth_login($username, $password, $remember)) {
        $role = $_SESSION['user']['role_name'] ?? '';
        redirect(APP_URL . (in_array($role, ['Staff Gudang', 'Viewer'], true) ? '/pages/portal.php' : '/pages/dashboard.php'));
    } else {
        $error = $error ?: 'Username atau kata sandi salah.';
        // pick up flash error set inside auth_login
        $f = get_flash();
        if ($f) $error = $f['message'];
    }
}
auth_layout_start('Masuk');
?>
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
          <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember"<?= !empty($_POST['remember']) ? ' checked' : '' ?>>
          <label class="form-check-label small" for="remember">Ingat saya</label>
        </div>
        <a href="<?= APP_URL ?>/auth/forgot-password.php" class="small text-primary text-decoration-none">Lupa kata sandi?</a>
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-semibold">
        <i class="bi bi-box-arrow-in-right me-1"></i>Masuk
      </button>
    </form>
<?php
auth_layout_end(<<<'JS'
function togglePwd() {
  const p = document.getElementById('password');
  const i = document.getElementById('eyeIcon');
  p.type = p.type === 'password' ? 'text' : 'password';
  i.className = p.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
JS);
