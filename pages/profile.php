<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

auth_check();
$db  = getDB();
$uid = (int)current_user()['id'];

$profile_errors = [];
$pwd_errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = req_str('action');

    if ($action === 'update_profile') {
        $name = trim(req_str('name'));
        if ($name === '' || mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 100) {
            $profile_errors[] = 'Nama lengkap harus antara 2 sampai 100 karakter.';
        } else {
            $st = $db->prepare('UPDATE users SET name = ? WHERE id = ?');
            $st->bind_param('si', $name, $uid);
            if ($st->execute()) {
                $st->close();
                sync_active_user_session();
                audit_log('profile.update', 'user', $uid, (string)(current_user()['username'] ?? ''), 'Memperbarui nama profil menjadi: ' . $name);
                set_flash('success', 'Profil berhasil diperbarui.');
                redirect(APP_URL . '/pages/profile.php');
            }
            $st->close();
            $profile_errors[] = 'Gagal menyimpan perubahan profil.';
        }
    } elseif ($action === 'change_password') {
        $current_pwd = (string)($_POST['current_password'] ?? '');
        $new_pwd     = (string)($_POST['new_password'] ?? '');
        $confirm_pwd = (string)($_POST['confirm_password'] ?? '');

        if ($current_pwd === '' || $new_pwd === '' || $confirm_pwd === '') {
            $pwd_errors[] = 'Semua kolom kata sandi wajib diisi.';
        } elseif (strlen($new_pwd) < 8) {
            $pwd_errors[] = 'Kata sandi baru minimal 8 karakter.';
        } elseif ($new_pwd !== $confirm_pwd) {
            $pwd_errors[] = 'Konfirmasi kata sandi baru tidak cocok.';
        } else {
            $st = $db->prepare('SELECT username, password FROM users WHERE id = ? LIMIT 1');
            $st->bind_param('i', $uid);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$row || !password_verify($current_pwd, (string)$row['password'])) {
                $pwd_errors[] = 'Kata sandi saat ini salah.';
            } elseif (password_verify($new_pwd, (string)$row['password'])) {
                $pwd_errors[] = 'Kata sandi baru tidak boleh sama dengan kata sandi saat ini.';
            } else {
                $hash = password_hash($new_pwd, PASSWORD_BCRYPT, ['cost' => 12]);
                $up = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
                $up->bind_param('si', $hash, $uid);
                if ($up->execute()) {
                    $up->close();
                    auth_revoke_all_remember_tokens($uid);
                    sync_active_user_session();
                    session_regenerate_id(true);
                    audit_log('profile.password_change', 'user', $uid, (string)$row['username'], 'Mengubah kata sandi akun secara mandiri');
                    set_flash('success', 'Kata sandi berhasil diperbarui.');
                    redirect(APP_URL . '/pages/profile.php');
                }
                $up->close();
                $pwd_errors[] = 'Gagal memperbarui kata sandi.';
            }
        }
    }
}

$stUser = $db->prepare('SELECT u.id, u.name, u.username, u.email, u.email_verified_at, u.created_at, r.name AS role_name
    FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1');
$stUser->bind_param('i', $uid);
$stUser->execute();
$user_info = $stUser->get_result()->fetch_assoc();
$stUser->close();

$assigned_companies = user_companies();
$active_cid         = current_company_id();
$back_url           = in_array($user_info['role_name'] ?? '', ['Staff Gudang', 'Viewer'], true)
    ? APP_URL . '/pages/portal.php'
    : APP_URL . '/pages/dashboard.php';

$page_title = 'Profil & Kata Sandi';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
  <div>
    <h4><i class="bi bi-person-gear me-2 text-primary"></i>Profil &amp; Kata Sandi</h4>
    <p class="text-muted small mb-0">Kelola informasi akun dan keamanan kata sandi Anda secara mandiri.</p>
  </div>
  <a href="<?= htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Kembali
  </a>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white fw-semibold">
        <i class="bi bi-person-badge me-2 text-primary"></i>Informasi Akun
      </div>
      <div class="card-body">
        <?php if ($profile_errors): ?>
          <div class="alert alert-danger py-2 small">
            <ul class="mb-0 ps-3"><?php foreach ($profile_errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_profile">
          <div class="mb-3">
            <label class="form-label small fw-semibold required">Nama Lengkap</label>
            <input type="text" name="name" class="form-control" maxlength="100" required
              value="<?= e((($_POST['action'] ?? '') === 'update_profile') ? req_str('name') : ($user_info['name'] ?? '')) ?>">
          </div>
          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label small fw-semibold">Username</label>
              <input type="text" class="form-control" value="<?= e($user_info['username'] ?? '') ?>" disabled>
            </div>
            <div class="col-sm-6">
              <label class="form-label small fw-semibold">Peran</label>
              <input type="text" class="form-control" value="<?= e($user_info['role_name'] ?? '') ?>" disabled>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Email</label>
            <div class="input-group">
              <input type="email" class="form-control" value="<?= e($user_info['email'] ?? '') ?>" disabled>
              <span class="input-group-text">
                <?php if (!empty($user_info['email_verified_at'])): ?>
                  <span class="text-success small"><i class="bi bi-patch-check-fill me-1"></i>Terverifikasi</span>
                <?php else: ?>
                  <span class="text-warning small"><i class="bi bi-exclamation-circle me-1"></i>Belum Verifikasi</span>
                <?php endif; ?>
              </span>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Akses Perusahaan (PT)</label>
            <div class="d-flex flex-wrap gap-1">
              <?php foreach ($assigned_companies as $c): ?>
                <span class="badge <?= (int)$c['id'] === $active_cid ? 'bg-primary' : 'bg-secondary-subtle text-body border' ?>">
                  [<?= e($c['code']) ?>] <?= e($c['name']) ?><?= (int)$c['id'] === $active_cid ? ' (Aktif)' : '' ?>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-check-lg me-1"></i>Simpan Profil
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white fw-semibold">
        <i class="bi bi-shield-lock me-2 text-primary"></i>Ubah Kata Sandi
      </div>
      <div class="card-body">
        <?php if ($pwd_errors): ?>
          <div class="alert alert-danger py-2 small">
            <ul class="mb-0 ps-3"><?php foreach ($pwd_errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="mb-3">
            <label class="form-label small fw-semibold required">Kata Sandi Saat Ini</label>
            <div class="input-group">
              <input type="password" name="current_password" id="curPwd" class="form-control" required>
              <button type="button" class="btn btn-outline-secondary" onclick="toggleProfilePwd('curPwd', this)">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold required">Kata Sandi Baru</label>
            <div class="input-group">
              <input type="password" name="new_password" id="newPwd" class="form-control" minlength="8" required>
              <button type="button" class="btn btn-outline-secondary" onclick="toggleProfilePwd('newPwd', this)">
                <i class="bi bi-eye"></i>
              </button>
            </div>
            <div class="form-text">Minimal 8 karakter; seluruh token "Ingat saya" di perangkat lain akan direset.</div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold required">Konfirmasi Kata Sandi Baru</label>
            <div class="input-group">
              <input type="password" name="confirm_password" id="cfmPwd" class="form-control" minlength="8" required>
              <button type="button" class="btn btn-outline-secondary" onclick="toggleProfilePwd('cfmPwd', this)">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="btn btn-warning btn-sm fw-semibold">
            <i class="bi bi-key me-1"></i>Perbarui Kata Sandi
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function toggleProfilePwd(id, btn) {
  var inp = document.getElementById(id);
  var ico = btn.querySelector('i');
  if (!inp) return;
  inp.type = inp.type === 'password' ? 'text' : 'password';
  if (ico) ico.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
