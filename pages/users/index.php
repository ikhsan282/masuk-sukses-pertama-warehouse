<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('users.view');
$db = getDB();

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = req_str('action');

    if ($action === 'create' && can('users.create')) {
        $name        = req_str('name');
        $username    = req_str('username');
        $email       = req_str('email');
        $role_id     = req_int('role_id');
        $password    = req_str('password');
        $company_ids = $_POST['company_ids'] ?? [];
        $auto_verify = !empty($_POST['auto_verify']);
        if (!$name||!$username||!$email||!$role_id||!$password) {
            set_flash('error','Semua field wajib diisi.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error','Format email tidak valid.');
        } elseif (strlen($password) < 8) {
            set_flash('error','Kata sandi minimal 8 karakter.');
        } else {
            if ($auto_verify) {
                $token = null;
                $hash  = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
                $st    = $db->prepare('INSERT INTO users (role_id,name,username,email,password,verification_token,email_verified_at) VALUES (?,?,?,?,?,NULL,NOW())');
                $st->bind_param('issss',$role_id,$name,$username,$email,$hash);
            } else {
                $token = bin2hex(random_bytes(32));
                $hash  = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
                $st    = $db->prepare('INSERT INTO users (role_id,name,username,email,password,verification_token) VALUES (?,?,?,?,?,?)');
                $st->bind_param('isssss',$role_id,$name,$username,$email,$hash,$token);
            }
            if ($st->execute()) {
                $new_uid = $db->insert_id;
                // Save company access
                if (empty($company_ids)) $company_ids = [1];
                $uc_st = $db->prepare('INSERT IGNORE INTO user_companies (user_id, company_id, is_default) VALUES (?, ?, ?)');
                foreach ($company_ids as $idx => $cid) {
                    $cid = (int)$cid;
                    if ($cid > 0) {
                        $is_def = ($idx === 0) ? 1 : 0;
                        $uc_st->bind_param('iii', $new_uid, $cid, $is_def);
                        $uc_st->execute();
                    }
                }
                $uc_st->close();

                if ($auto_verify) {
                    audit_log('user.create', 'users', $new_uid, $username, "Menambah pengguna '{$username}' (langsung aktif/terverifikasi)");
                    set_flash('success', "Pengguna <strong>{$username}</strong> ditambahkan dan langsung aktif.");
                } else {
                    $sent = send_verification_email($email, $name, $token);
                    audit_log('user.create', 'users', $new_uid, $username, "Menambah pengguna '{$username}' (menunggu verifikasi email)");
                    set_flash('success', "Pengguna <strong>{$username}</strong> ditambahkan." . ($sent ? ' Email verifikasi dikirim.' : ' Email belum terkirim; gunakan tombol Verifikasi di daftar pengguna jika perlu.'));
                }
            } else {
                set_flash('error','Username atau email sudah digunakan.');
            }
            $st->close();
        }
    }

    if ($action === 'verify_user' && can('users.edit')) {
        $id = req_int('id');
        $st = $db->prepare('UPDATE users SET email_verified_at=COALESCE(email_verified_at, NOW()), verification_token=NULL WHERE id=?');
        $st->bind_param('i', $id);
        if ($st->execute() && $st->affected_rows >= 0) {
            audit_log('user.verify', 'users', $id, '', "Verifikasi manual akun user #{$id}");
            set_flash('success', 'Akun pengguna berhasil diverifikasi dan siap digunakan untuk login.');
        } else {
            set_flash('error', 'Gagal memverifikasi pengguna.');
        }
        $st->close();
    }

    if ($action === 'edit' && can('users.edit')) {
        $id          = req_int('id');
        $name        = req_str('name');
        $role_id     = req_int('role_id');
        $active      = req_int('is_active');
        $company_ids = $_POST['company_ids'] ?? [];

        $st = $db->prepare('UPDATE users SET name=?,role_id=?,is_active=? WHERE id=?');
        $st->bind_param('siii',$name,$role_id,$active,$id);
        if ($st->execute()) {
            // Update companies
            if (!empty($company_ids)) {
                $delUc = $db->prepare('DELETE FROM user_companies WHERE user_id = ?');
                $delUc->bind_param('i', $id);
                $delUc->execute();
                $delUc->close();
                $uc_st = $db->prepare('INSERT IGNORE INTO user_companies (user_id, company_id, is_default) VALUES (?, ?, ?)');
                foreach ($company_ids as $idx => $cid) {
                    $cid = (int)$cid;
                    if ($cid > 0) {
                        $is_def = ($idx === 0) ? 1 : 0;
                        $uc_st->bind_param('iii', $id, $cid, $is_def);
                        $uc_st->execute();
                    }
                }
                $uc_st->close();
            }
            if ($id === (int)current_user()['id']) {
                sync_active_user_session();
            }
            audit_log('user.update', 'users', $id, '', "Memperbarui user #{$id} ({$name}), aktif={$active}");
            set_flash('success','Pengguna diperbarui.');
        } else {
            set_flash('error','Gagal memperbarui pengguna.');
        }
        $st->close();
    }

    if ($action === 'reset_password' && can('users.edit')) {
        $id  = req_int('id');
        $pwd = req_str('new_password');
        if (strlen($pwd) < 8) {
            set_flash('error','Kata sandi minimal 8 karakter.');
        } else {
            $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost'=>12]);
            $st   = $db->prepare('UPDATE users SET password=? WHERE id=?');
            $st->bind_param('si',$hash,$id);
            if ($st->execute()) {
                audit_log('user.reset_password', 'users', $id, '', "Reset kata sandi user #{$id} oleh Admin");
                set_flash('success','Kata sandi berhasil direset.');
            } else {
                set_flash('error','Gagal.');
            }
            $st->close();
        }
    }

    if ($action === 'delete' && can('users.delete')) {
        $id = req_int('id');
        if ($id === (int)current_user()['id']) {
            set_flash('error','Tidak dapat menghapus akun sendiri.');
        } else {
            $st = $db->prepare('DELETE FROM users WHERE id=?');
            $st->bind_param('i',$id);
            if ($st->execute()) {
                audit_log('user.delete', 'users', $id, '', "Menghapus pengguna #{$id}");
                set_flash('success','Pengguna dihapus.');
            } else {
                set_flash('error','Gagal menghapus pengguna.');
            }
            $st->close();
        }
    }

    redirect(APP_URL.'/pages/users/index.php');
}

// Fetch for edit modal
$edit_row = null;
$edit_user_companies = [];
if (req_int('edit')) {
    $st = $db->prepare('SELECT id,name,username,email,role_id,is_active FROM users WHERE id=?');
    $st->bind_param('i',$_GET['edit']); $st->execute();
    $edit_row = $st->get_result()->fetch_assoc(); $st->close();
    if ($edit_row) {
        $c_res = $db->query("SELECT company_id FROM user_companies WHERE user_id = " . (int)$edit_row['id']);
        while ($c_row = $c_res->fetch_row()) $edit_user_companies[] = (int)$c_row[0];
    }
}

$search   = grid_search_term('q');
$page     = max(1, req_int('page', 1));
$per_page = grid_per_page();
grid_apply_timeout($db);
$where    = $search ? "WHERE u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?" : '';
$cq=$db->prepare("SELECT COUNT(*) FROM users u $where");
if($search){$like='%'.escape_like($search).'%';$cq->bind_param('sss',$like,$like,$like);}
$cq->execute();$cq->bind_result($total);$cq->fetch();$cq->close();

$pag=paginate($total,$page,$per_page);$offset=$pag['offset'];$limit=$pag['per_page'];
$st=$db->prepare("SELECT u.id,u.name,u.username,u.email,u.is_active,u.email_verified_at,u.created_at,
    r.name AS role_name,
    GROUP_CONCAT(c.code ORDER BY c.is_holding DESC, c.code ASC SEPARATOR ', ') AS company_codes
    FROM (SELECT u.id FROM users u $where ORDER BY u.id DESC LIMIT ? OFFSET ?) pg
    JOIN users u ON u.id = pg.id
    JOIN roles r ON r.id=u.role_id
    LEFT JOIN user_companies uc ON uc.user_id=u.id
    LEFT JOIN companies c ON c.id=uc.company_id
    GROUP BY u.id
    ORDER BY u.id DESC");
if($search){$st->bind_param('sssii',$like,$like,$like,$limit,$offset);}
else{$st->bind_param('ii',$limit,$offset);}
$st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$roles = $db->query('SELECT id,name FROM roles ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$all_companies = $db->query('SELECT id,code,name,is_holding FROM companies WHERE is_active=1 ORDER BY is_holding DESC, name ASC')->fetch_all(MYSQLI_ASSOC);
$page_title='Manajemen Pengguna'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-people me-2 text-primary"></i>Manajemen Pengguna</h4>
  <?php if(can('users.create')):?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="bi bi-plus-lg me-1"></i>Tambah Pengguna
  </button>
  <?php endif;?>
</div>

<div class="card table-card">
  <div class="card-header bg-white grid-toolbar">
    <form class="grid-filter-form" method="GET">
      <?= grid_filter_search_html($search, 'Cari nama, username, email...') ?>
      <?= grid_filter_actions_html($pag['per_page'], (bool)$search, 'Cari', 'bi-search') ?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0 align-middle">
      <thead><tr><th>#</th><th>Nama</th><th>Username</th><th>Email</th><th>Peran</th><th>Akses PT</th><th class="text-center">Status</th><th class="text-center">Verifikasi</th><th class="text-center">Aksi</th></tr></thead>
      <tbody>
      <?php if(empty($rows)):?>
        <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach($rows as $i=>$r):?>
        <tr>
          <td class="small text-muted"><?=$pag['offset']+$i+1?></td>
          <td class="fw-semibold"><?=htmlspecialchars($r['name'])?></td>
          <td><code class="small"><?=htmlspecialchars($r['username'])?></code></td>
          <td class="small"><?=htmlspecialchars($r['email'])?></td>
          <td><span class="badge bg-secondary-subtle text-secondary border"><?=htmlspecialchars($r['role_name'])?></span></td>
          <td>
            <?php if (!empty($r['company_codes'])): ?>
              <span class="badge bg-success-subtle text-success border"><?= htmlspecialchars($r['company_codes']) ?></span>
            <?php else: ?>
              <span class="text-muted small">Semua / Default</span>
            <?php endif; ?>
          </td>
          <td class="text-center"><?=$r['is_active']?'<span class="badge badge-ok">Aktif</span>':'<span class="badge badge-zero">Nonaktif</span>'?></td>
          <td class="text-center"><?=$r['email_verified_at']?'<span class="badge badge-ok"><i class="bi bi-check"></i></span>':'<span class="badge badge-zero">Belum</span>'?></td>
          <td class="text-center">
            <?php if(can('users.edit') && empty($r['email_verified_at'])):?>
            <form method="POST" class="d-inline">
              <?=csrf_field()?><input type="hidden" name="action" value="verify_user">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-action btn-outline-success" title="Verifikasi / Aktifkan Akun Sekarang" data-confirm="Verifikasi akun '<?=htmlspecialchars($r['username'])?>' agar langsung bisa login?">
                <i class="bi bi-patch-check"></i></button>
            </form>
            <?php endif;?>
            <?php if(can('users.edit')):?>
            <a href="?edit=<?=$r['id']?>" class="btn btn-action btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
            <?php endif;?>
            <?php if(can('users.delete') && $r['id']!=current_user()['id']):?>
            <form method="POST" class="d-inline">
              <?=csrf_field()?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-action btn-outline-danger" data-confirm="Hapus pengguna '<?=htmlspecialchars($r['username'])?>'?">
                <i class="bi bi-trash"></i></button>
            </form>
            <?php endif;?>
          </td>
        </tr>
      <?php endforeach;endif;?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'pengguna', ['q' => $search]) ?>
</div>

<!-- Create Modal -->
<?php if(can('users.create')):?>
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?=csrf_field()?><input type="hidden" name="action" value="create">
        <div class="modal-header"><h5 class="modal-title">Tambah Pengguna</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label small fw-semibold required">Nama Lengkap</label>
              <input type="text" name="name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label small fw-semibold required">Username</label>
              <input type="text" name="username" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label small fw-semibold required">Email</label>
              <input type="email" name="email" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label small fw-semibold required">Peran</label>
              <select name="role_id" class="form-select" required>
                <option value="">— Pilih Peran —</option>
                <?php foreach($roles as $r):?>
                <option value="<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></option>
                <?php endforeach;?>
              </select>
            </div>
            <div class="col-12"><label class="form-label small fw-semibold required">Kata Sandi (min. 8 karakter)</label>
              <input type="password" name="password" class="form-control" required minlength="8"></div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Akses Perusahaan (PT)</label>
              <div class="border rounded p-2 bg-body-tertiary">
                <?php foreach ($all_companies as $c): ?>
                <div class="form-check form-check-inline me-3">
                  <input class="form-check-input" type="checkbox" name="company_ids[]" value="<?=$c['id']?>" id="comp_<?=$c['id']?>" <?=$c['is_holding']?'checked':''?>>
                  <label class="form-check-label small" for="comp_<?=$c['id']?>">
                    [<?=htmlspecialchars($c['code'])?>] <?=htmlspecialchars($c['name'])?> <?=$c['is_holding']?'(Holding)':''?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
              <small class="text-muted">Pilih satu atau beberapa PT yang dapat diakses oleh pengguna ini.</small>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="auto_verify" value="1" id="autoVerifyCheck" checked>
                <label class="form-check-label small" for="autoVerifyCheck">
                  Langsung verifikasi &amp; aktifkan akun (disarankan bila server belum dikonfigurasi email SMTP)
                </label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif;?>

<!-- Edit Modal -->
<?php $is_edit=!is_null($edit_row);?>
<?php if($is_edit && can('users.edit')):?>
<div class="modal fade show" id="editModal" tabindex="-1" style="display:block" aria-modal="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?=csrf_field()?><input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?=$edit_row['id']?>">
        <div class="modal-header"><h5 class="modal-title">Edit Pengguna</h5><a href="?" class="btn-close"></a></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label small fw-semibold">Username</label>
              <input type="text" class="form-control" value="<?=htmlspecialchars($edit_row['username'])?>" disabled></div>
            <div class="col-md-6"><label class="form-label small fw-semibold required">Nama Lengkap</label>
              <input type="text" name="name" class="form-control" value="<?=htmlspecialchars($edit_row['name'])?>" required></div>
            <div class="col-md-6"><label class="form-label small fw-semibold required">Peran</label>
              <select name="role_id" class="form-select" required>
                <?php foreach($roles as $r):?>
                <option value="<?=$r['id']?>" <?=$edit_row['role_id']==$r['id']?'selected':''?>><?=htmlspecialchars($r['name'])?></option>
                <?php endforeach;?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Status</label>
              <select name="is_active" class="form-select">
                <option value="1" <?=$edit_row['is_active']?'selected':''?>>Aktif</option>
                <option value="0" <?=!$edit_row['is_active']?'selected':''?>>Nonaktif</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Akses Perusahaan (PT)</label>
              <div class="border rounded p-2 bg-body-tertiary">
                <?php foreach ($all_companies as $c): ?>
                <div class="form-check form-check-inline me-3">
                  <input class="form-check-input" type="checkbox" name="company_ids[]" value="<?=$c['id']?>" id="edit_comp_<?=$c['id']?>" <?=in_array((int)$c['id'], $edit_user_companies, true)?'checked':''?>>
                  <label class="form-check-label small" for="edit_comp_<?=$c['id']?>">
                    [<?=htmlspecialchars($c['code'])?>] <?=htmlspecialchars($c['name'])?> <?=$c['is_holding']?'(Holding)':''?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="?" class="btn btn-secondary btn-sm">Batal</a>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Perbarui</button>
        </div>
      </form>
      <div class="modal-body border-top pt-3">
        <p class="small fw-semibold mb-2">Reset Kata Sandi (opsional)</p>
        <form method="POST" class="d-flex gap-2">
          <?=csrf_field()?><input type="hidden" name="action" value="reset_password">
          <input type="hidden" name="id" value="<?=$edit_row['id']?>">
          <input type="password" name="new_password" class="form-control" placeholder="Kata sandi baru (min. 8 karakter)" minlength="8" required>
          <button type="submit" class="btn btn-warning btn-sm text-nowrap"><i class="bi bi-key me-1"></i>Reset</button>
        </form>
      </div>
    </div>
  </div>
</div>
<div class="modal-backdrop fade show"></div>
<?php endif;?>
<?php include __DIR__.'/../../includes/footer.php';?>
