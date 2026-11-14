<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-layout.php';

auth_guest();

$token   = req_str('token');
$success = false;
$error   = '';

if ($token) {
    if (verify_email_token($token)) {
        $success = true;
    } else {
        $error = 'Token verifikasi tidak valid atau sudah digunakan.';
    }
} else {
    $error = 'Token verifikasi tidak ditemukan.';
}
auth_layout_start('Verifikasi Email', 'text-center');
?>
    <div class="auth-logo mb-3"><i class="bi bi-boxes"></i></div>
    <?php if ($success): ?>
      <div class="mb-3"><i class="bi bi-patch-check-fill text-success" style="font-size:3rem"></i></div>
      <h5 class="fw-bold">Email Terverifikasi!</h5>
      <p class="text-muted small">Akun Anda telah aktif. Silakan masuk untuk melanjutkan.</p>
      <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary w-100 mt-2 fw-semibold">
        <i class="bi bi-box-arrow-in-right me-1"></i>Masuk Sekarang
      </a>
    <?php else: ?>
      <div class="mb-3"><i class="bi bi-x-circle-fill text-danger" style="font-size:3rem"></i></div>
      <h5 class="fw-bold">Verifikasi Gagal</h5>
      <p class="text-muted small"><?= htmlspecialchars($error) ?></p>
      <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-secondary w-100 mt-2">Kembali ke Halaman Masuk</a>
    <?php endif; ?>
<?php auth_layout_end();
