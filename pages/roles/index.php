<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('roles.view');
$db = getDB();

// Save permissions for a role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can('roles.edit')) {
    verify_csrf();
    $role_id = req_int('role_id');
    $perms   = $_POST['permissions'] ?? [];

    // Delete existing then re-insert
    $del = $db->prepare('DELETE FROM role_permissions WHERE role_id=?');
    $del->bind_param('i',$role_id); $del->execute(); $del->close();

    if (!empty($perms)) {
        $ins = $db->prepare('INSERT INTO role_permissions (role_id,permission_id) VALUES (?,?)');
        foreach ($perms as $pid) {
            $pid = (int)$pid;
            $ins->bind_param('ii',$role_id,$pid); $ins->execute();
        }
        $ins->close();
    }
    set_flash('success','Izin peran berhasil disimpan.');
    audit_log('role.permissions', 'roles', $role_id, '', 'Memperbarui izin peran #' . $role_id . ' (' . count($perms) . ' izin)');
    redirect(APP_URL.'/pages/roles/index.php?role_id='.$role_id);
}

$roles = $db->query('SELECT * FROM roles ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$all_perms = $db->query('SELECT * FROM permissions ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$selected_role_id = req_int('role_id', $roles[0]['id'] ?? 1);

// Load current perms for selected role
$rp = $db->prepare('SELECT permission_id FROM role_permissions WHERE role_id=?');
$rp->bind_param('i',$selected_role_id); $rp->execute();
$res = $rp->get_result();
$role_perms = [];
while ($r = $res->fetch_assoc()) $role_perms[] = $r['permission_id'];
$rp->close();

// Group permissions by prefix
$grouped = [];
foreach ($all_perms as $p) {
    $prefix = explode('.', $p['name'])[0];
    $grouped[$prefix][] = $p;
}

$page_title='Peran & Izin'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header">
  <h4><i class="bi bi-shield-lock me-2 text-primary"></i>Peran &amp; Izin</h4>
</div>

<div class="row g-3">
  <!-- Role list -->
  <div class="col-md-3">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold py-2">Daftar Peran</div>
      <div class="list-group list-group-flush">
        <?php foreach($roles as $role): ?>
        <a href="?role_id=<?=$role['id']?>"
           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center
                  <?=$role['id']==$selected_role_id?'active':''?>">
          <?=htmlspecialchars($role['name'])?>
          <span class="badge bg-<?=$role['id']==$selected_role_id?'white text-primary':'primary'?>-subtle
                rounded-pill" style="font-size:.7rem">
            <?=count(array_filter($all_perms, fn($p) => in_array($p['id'], $role_perms)))?>/<?=count($all_perms)?>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Permission editor -->
  <div class="col-md-9">
    <?php $cur_role = array_values(array_filter($roles, fn($r)=>$r['id']==$selected_role_id))[0]??null; ?>
    <?php if($cur_role): ?>
    <div class="card shadow-sm">
      <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold">Izin untuk: <span class="text-primary"><?=htmlspecialchars($cur_role['name'])?></span></span>
        <?php if($cur_role['description']):?>
        <small class="text-muted"><?=htmlspecialchars($cur_role['description'])?></small>
        <?php endif;?>
      </div>
      <div class="card-body">
        <?php if(can('roles.edit')):?>
        <form method="POST">
          <?=csrf_field()?>
          <input type="hidden" name="role_id" value="<?=$selected_role_id?>">
          <div class="mb-3 d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(true)">Pilih Semua</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(false)">Hapus Semua</button>
          </div>
          <div class="row g-3">
            <?php foreach($grouped as $group => $perms): ?>
            <div class="col-md-4">
              <div class="card border bg-body-tertiary">
                <div class="card-header py-1 bg-white">
                  <strong class="small text-uppercase"><?=htmlspecialchars($group)?></strong>
                </div>
                <div class="card-body py-2">
                  <?php foreach($perms as $p): ?>
                  <div class="form-check form-check-sm">
                    <input class="form-check-input perm-check" type="checkbox"
                      name="permissions[]" value="<?=$p['id']?>"
                      id="perm_<?=$p['id']?>"
                      <?=in_array($p['id'],$role_perms)?'checked':''?>>
                    <label class="form-check-label small" for="perm_<?=$p['id']?>">
                      <?=htmlspecialchars(explode('.',$p['name'])[1]??$p['name'])?>
                    </label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Izin</button>
          </div>
        </form>
        <?php else: ?>
        <div class="alert alert-warning small">Anda tidak memiliki izin untuk mengubah peran.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleAll(state) {
  document.querySelectorAll('.perm-check').forEach(el => el.checked = state);
}
</script>
<?php include __DIR__.'/../../includes/footer.php';?>
