<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-layout.php';

auth_guest();

$token = req_str('token');
$done  = false;
$error = '';
$invalid = false;

// Validate hashed token exists and has not expired
$invalid = ($token === '' || auth_find_user_by_reset_token($token) === null);

if (!$invalid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = req_str('password');
    $confirm  = req_str('confirm_password');
    if (strlen($password) < 8) {
        $error = 'Kata sandi minimal 8 karakter.';
    } elseif ($password !== $confirm) {
        $error = 'Konfirmasi kata sandi tidak cocok.';
    } elseif (reset_password($token, $password)) {
        $done = true;
    } else {
        $error = 'Token tidak valid atau sudah kedaluwarsa.';
    }
}
auth_layout_start('Reset Kata Sandi');
?>
    <div class="text-center mb-4">
      <div class="auth-logo"><i class="bi bi-boxes"></i></div>
      <div class="auth-logo fs-4"><?= APP_NAME ?></div>
    </div>
    <?php if ($invalid): ?>
      <div class="alert alert-danger small"><i class="bi bi-x-circle me-1"></i>Link reset tidak valid atau sudah kedaluwarsa.</div>
      <a href="<?= APP_URL ?>/auth/forgot-password.php" class="btn btn-outline-primary w-100 mt-2">Minta Link Baru</a>
    <?php elseif ($done): ?>
      <div class="alert alert-success small"><i class="bi bi-check-circle me-1"></i>Kata sandi berhasil direset!</div>
      <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary w-100 mt-2">Masuk Sekarang</a>
    <?php else: ?>
      <h5 class="fw-bold mb-1">Reset Kata Sandi</h5>
      <p class="text-muted small mb-3">Buat kata sandi baru untuk akun Anda.</p>
      <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="mb-3">
          <label class="form-label fw-semibold small required">Kata Sandi Baru</label>
          <input type="password" name="password" class="form-control" placeholder="Min. 8 karakter" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small required">Konfirmasi Kata Sandi</label>
          <input type="password" name="confirm_password" class="form-control" placeholder="Ulangi kata sandi" required>
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-semibold">
          <i class="bi bi-shield-check me-1"></i>Simpan Kata Sandi
        </button>
      </form>
    <?php endif; ?>
<?php auth_layout_end();
