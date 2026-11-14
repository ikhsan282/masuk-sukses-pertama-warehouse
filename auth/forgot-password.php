<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-layout.php';

auth_guest();

$done  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = req_str('email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Alamat email tidak valid.';
    } else {
        send_reset_email($email); // always true-ish to avoid user enum
        $done = true;
    }
}
auth_layout_start('Lupa Kata Sandi');
?>
    <div class="text-center mb-4">
      <div class="auth-logo"><i class="bi bi-boxes"></i></div>
      <div class="auth-logo fs-4"><?= APP_NAME ?></div>
    </div>
    <?php if ($done): ?>
      <div class="alert alert-success small">
        <i class="bi bi-envelope-check me-1"></i>
        Jika email terdaftar, link reset kata sandi telah dikirim. Cek inbox Anda.
      </div>
      <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-primary w-100 mt-2">
        <i class="bi bi-arrow-left me-1"></i>Kembali ke halaman masuk
      </a>
    <?php else: ?>
      <h5 class="fw-bold mb-1">Lupa Kata Sandi</h5>
      <p class="text-muted small mb-3">Masukkan email terdaftar Anda untuk menerima link reset.</p>
      <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="POST">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label fw-semibold small">Alamat Email</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" name="email" class="form-control" placeholder="email@domain.com"
              value="<?= htmlspecialchars(req_str('email')) ?>" required autofocus>
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-semibold">
          <i class="bi bi-send me-1"></i>Kirim Link Reset
        </button>
      </form>
      <div class="text-center mt-3">
        <a href="<?= APP_URL ?>/auth/login.php" class="small text-muted text-decoration-none">
          <i class="bi bi-arrow-left me-1"></i>Kembali masuk
        </a>
      </div>
    <?php endif; ?>
<?php auth_layout_end();
