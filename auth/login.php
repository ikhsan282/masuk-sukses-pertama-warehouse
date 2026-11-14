<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/captcha.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-layout.php';

auth_guest();

$error = '';
$submittedUsername = req_str('username');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = $submittedUsername;
    $password = req_str('password');
    $remember = !empty($_POST['remember']);
    $key      = login_normalize_key($username);
    $ip       = login_client_ip();

    if (!$username || !$password) {
        $error = 'Username dan kata sandi wajib diisi.';
    } elseif (login_handle_lockout($key, $ip)) {
        $f = get_flash();
        if ($f) $error = $f['message'];
    } elseif (login_requires_captcha($username) && !captcha_verify(req_str('captcha'))) {
        $_SESSION['login_fail_count'] = max(LOGIN_CAPTCHA_THRESHOLD, (int)($_SESSION['login_fail_count'] ?? 0));
        $error = 'Kode verifikasi angka (CAPTCHA) tidak cocok atau telah kedaluwarsa.';
        audit_log('login_captcha_failed', 'user', null, '', "Verifikasi CAPTCHA gagal untuk '{$key}' dari IP {$ip}", null, null, $key);
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

$showCaptcha = login_requires_captcha($submittedUsername);
$captchaVer  = time();
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
            value="<?= htmlspecialchars($submittedUsername) ?>" required autofocus>
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
      <?php if ($showCaptcha): ?>
      <div class="auth-captcha-box mb-3" id="captchaBox">
        <label class="form-label fw-semibold small mb-2" for="captchaInput">
          <i class="bi bi-shield-check me-1 text-primary"></i>Verifikasi Keamanan
        </label>
        <div class="d-flex align-items-center gap-2 mb-2">
          <img id="captchaImg"
               src="<?= APP_URL ?>/auth/captcha.php?v=<?= $captchaVer ?>"
               data-base-url="<?= APP_URL ?>/auth/captcha.php"
               alt="Kode verifikasi angka"
               class="auth-captcha-img"
               width="180"
               height="54">
          <button type="button" id="refreshCaptcha" class="btn btn-outline-secondary btn-sm px-2 py-2" title="Ganti kode angka" aria-label="Ganti kode verifikasi angka">
            <i class="bi bi-arrow-clockwise"></i>
          </button>
        </div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-123"></i></span>
          <input type="text" name="captcha" id="captchaInput" class="form-control"
                 placeholder="Masukkan <?= LOGIN_CAPTCHA_DIGITS ?> digit angka"
                 inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="off" required>
        </div>
      </div>
      <?php endif; ?>
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
function refreshCaptchaImage(clearInput) {
  const img = document.getElementById('captchaImg');
  if (!img) return;
  const base = img.getAttribute('data-base-url') || '';
  const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
  img.src = base + '?v=' + Date.now() + (isDark ? '&theme=dark' : '');
  if (clearInput) {
    const inp = document.getElementById('captchaInput');
    if (inp) { inp.value = ''; inp.focus(); }
  }
}
const refreshBtn = document.getElementById('refreshCaptcha');
if (refreshBtn) {
  refreshBtn.addEventListener('click', function () { refreshCaptchaImage(true); });
}
const themeBtn = document.getElementById('darkToggle');
if (themeBtn) {
  themeBtn.addEventListener('click', function () {
    setTimeout(function () { refreshCaptchaImage(false); }, 30);
  });
}
JS);
