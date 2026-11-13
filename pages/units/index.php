<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('units.view');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = req_str('action');

    if ($action === 'create' && can('units.create')) {
        $name = req_str('name');
        $abbr = req_str('abbreviation');
        if (!$name || !$abbr) { set_flash('error', 'Nama dan singkatan wajib diisi.'); }
        else {
            $st = $db->prepare('INSERT INTO units (name,abbreviation) VALUES (?,?)');
            $st->bind_param('ss', $name, $abbr);
            if ($st->execute()) set_flash('success', 'Satuan berhasil ditambahkan.');
            else set_flash('error', 'Nama satuan sudah ada.');
            $st->close();
        }
    }
    if ($action === 'edit' && can('units.edit')) {
        $id=$id=req_int('id'); $name=req_str('name'); $abbr=req_str('abbreviation');
        $st=$db->prepare('UPDATE units SET name=?,abbreviation=? WHERE id=?');
        $st->bind_param('ssi',$name,$abbr,$id);
        if($st->execute()) set_flash('success','Satuan diperbarui.'); else set_flash('error','Gagal.');
        $st->close();
    }
    if ($action === 'delete' && can('units.delete')) {
        $id=req_int('id');
        $st=$db->prepare('DELETE FROM units WHERE id=?');
        $st->bind_param('i',$id);
        if($st->execute()) set_flash('success','Satuan dihapus.'); else set_flash('error','Gagal. Satuan masih digunakan.');
        $st->close();
    }
    redirect(APP_URL . '/pages/units/index.php');
}

$edit_row = null;
if (req_int('edit')) {
    $st=$db->prepare('SELECT * FROM units WHERE id=?'); $st->bind_param('i',$_GET['edit']);
    $st->execute(); $edit_row=$st->get_result()->fetch_assoc(); $st->close();
}

$page     = max(1, req_int('page', 1));
$per_page = grid_per_page();
grid_apply_timeout($db);
$total    = (int)$db->query('SELECT COUNT(*) FROM units')->fetch_row()[0];
$pag      = paginate($total, $page, $per_page);
$offset   = $pag['offset'];
$limit    = $pag['per_page'];
$st = $db->prepare('SELECT * FROM units ORDER BY name LIMIT ? OFFSET ?');
$st->bind_param('ii', $limit, $offset);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
$page_title = 'Satuan Barang';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-rulers me-2 text-primary"></i>Satuan Barang</h4>
  <?php if (can('units.create')): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#formModal">
    <i class="bi bi-plus-lg me-1"></i>Tambah Satuan
  </button>
  <?php endif; ?>
</div>

<div class="card table-card">
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>#</th><th>Nama Satuan</th><th>Singkatan</th><th class="text-center">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach ($rows as $i => $r): ?>
        <tr>
          <td class="small text-muted"><?= $pag['offset'] + $i + 1 ?></td>
          <td class="fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
          <td><span class="badge bg-info-subtle text-info border"><?= htmlspecialchars($r['abbreviation']) ?></span></td>
          <td class="text-center">
            <?php if (can('units.edit')): ?>
            <a href="?edit=<?= $r['id'] ?>" class="btn btn-action btn-outline-primary"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
            <?php if (can('units.delete')): ?>
            <form method="POST" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-action btn-outline-danger" data-confirm="Hapus satuan '<?= htmlspecialchars($r['name']) ?>'?">
                <i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'satuan') ?>
</div>

<?php $is_edit = !is_null($edit_row); ?>
<div class="modal fade <?= $is_edit?'show':'' ?>" id="formModal" tabindex="-1"
  <?= $is_edit?'style="display:block" aria-modal="true"':'' ?>>
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $is_edit?'edit':'create' ?>">
        <?php if ($is_edit): ?><input type="hidden" name="id" value="<?= $edit_row['id'] ?>"><?php endif; ?>
        <div class="modal-header">
          <h5 class="modal-title"><?= $is_edit?'Edit':'Tambah' ?> Satuan</h5>
          <a href="?" class="btn-close"></a>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold required">Nama Satuan</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($edit_row['name']??'') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold required">Singkatan</label>
            <input type="text" name="abbreviation" class="form-control" placeholder="pcs, kg, ltr..."
              value="<?= htmlspecialchars($edit_row['abbreviation']??'') ?>" required maxlength="10">
          </div>
        </div>
        <div class="modal-footer">
          <a href="?" class="btn btn-secondary btn-sm">Batal</a>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php if ($is_edit): ?><div class="modal-backdrop fade show"></div><?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
