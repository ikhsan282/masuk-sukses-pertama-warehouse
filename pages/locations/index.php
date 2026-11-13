<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('locations.view');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = req_str('action');

    if ($action === 'create' && can('locations.create')) {
        $code=strtoupper(req_str('code')); $name=req_str('name'); $desc=req_str('description');
        if (!$code||!$name){set_flash('error','Kode dan nama wajib diisi.');}
        else {
            $st=$db->prepare('INSERT INTO locations (code,name,description) VALUES (?,?,?)');
            $st->bind_param('sss',$code,$name,$desc);
            if($st->execute()) set_flash('success','Lokasi berhasil ditambahkan.');
            else set_flash('error','Kode lokasi sudah digunakan.');
            $st->close();
        }
    }
    if ($action === 'edit' && can('locations.edit')) {
        $id=req_int('id'); $code=strtoupper(req_str('code')); $name=req_str('name');
        $desc=req_str('description'); $active=req_int('is_active');
        $st=$db->prepare('UPDATE locations SET code=?,name=?,description=?,is_active=? WHERE id=?');
        $st->bind_param('sssii',$code,$name,$desc,$active,$id);
        if($st->execute()) set_flash('success','Lokasi diperbarui.'); else set_flash('error','Gagal.');
        $st->close();
    }
    if ($action === 'delete' && can('locations.delete')) {
        $id=req_int('id');
        $st=$db->prepare('DELETE FROM locations WHERE id=?');
        $st->bind_param('i',$id);
        if($st->execute()) set_flash('success','Lokasi dihapus.'); else set_flash('error','Gagal. Lokasi masih digunakan.');
        $st->close();
    }
    redirect(APP_URL.'/pages/locations/index.php');
}

$edit_row = null;
if (req_int('edit')) {
    $st=$db->prepare('SELECT * FROM locations WHERE id=?'); $st->bind_param('i',$_GET['edit']);
    $st->execute(); $edit_row=$st->get_result()->fetch_assoc(); $st->close();
}

$search   = grid_search_term('q');
$page     = max(1, req_int('page', 1));
$per_page = grid_per_page();
grid_apply_timeout($db);
$where    = $search ? 'WHERE l.name LIKE ? OR l.code LIKE ?' : '';
$cq = $db->prepare("SELECT COUNT(*) FROM locations l $where");
if ($search) { $like = '%' . escape_like($search) . '%'; $cq->bind_param('ss', $like, $like); }
$cq->execute(); $cq->bind_result($total); $cq->fetch(); $cq->close();

$pag    = paginate($total, $page, $per_page);
$offset = $pag['offset'];
$limit  = $pag['per_page'];
$st = $db->prepare("SELECT l.*,
    (SELECT COALESCE(SUM(s.quantity),0) FROM stock s WHERE s.location_id = l.id) AS total_stock
    FROM locations l
    JOIN (SELECT l.id FROM locations l $where ORDER BY l.code LIMIT ? OFFSET ?) pg ON pg.id = l.id
    ORDER BY l.code");
if ($search) { $st->bind_param('ssii', $like, $like, $limit, $offset); }
else { $st->bind_param('ii', $limit, $offset); }
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$page_title='Lokasi Rak/Bin'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-geo-alt me-2 text-primary"></i>Lokasi Rak / Bin</h4>
  <?php if(can('locations.create')):?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#formModal">
    <i class="bi bi-plus-lg me-1"></i>Tambah Lokasi
  </button>
  <?php endif;?>
</div>

<div class="card table-card">
  <div class="card-header bg-white py-2">
    <form class="d-flex gap-2" method="GET">
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:260px"
        placeholder="Cari kode / nama lokasi..." value="<?= htmlspecialchars($search) ?>">
      <input type="hidden" name="per_page" value="<?= (int)$pag['per_page'] ?>">
      <button class="btn btn-sm btn-outline-secondary">Cari</button>
      <?php if ($search): ?><a href="?per_page=<?= (int)$pag['per_page'] ?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></a><?php endif; ?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>#</th><th>Kode</th><th>Nama Lokasi</th><th>Deskripsi</th><th class="text-center">Total Stok</th><th class="text-center">Status</th><th class="text-center">Aksi</th></tr></thead>
      <tbody>
      <?php if(empty($rows)):?>
        <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach($rows as $i=>$r):?>
        <tr>
          <td class="small text-muted"><?= $pag['offset'] + $i + 1 ?></td>
          <td><span class="badge bg-primary-subtle text-primary border"><?=htmlspecialchars($r['code'])?></span></td>
          <td class="fw-semibold"><?=htmlspecialchars($r['name'])?></td>
          <td class="small text-muted"><?=htmlspecialchars($r['description']??'')?></td>
          <td class="text-center fw-semibold"><?=$r['total_stock']?></td>
          <td class="text-center"><?=$r['is_active']?'<span class="badge badge-ok">Aktif</span>':'<span class="badge badge-zero">Nonaktif</span>'?></td>
          <td class="text-center">
            <?php if(can('locations.edit')):?>
            <a href="?edit=<?=$r['id']?>" class="btn btn-action btn-outline-primary"><i class="bi bi-pencil"></i></a>
            <?php endif;?>
            <?php if(can('locations.delete')):?>
            <form method="POST" class="d-inline">
              <?=csrf_field()?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-action btn-outline-danger" data-confirm="Hapus lokasi '<?=htmlspecialchars($r['name'])?>'?">
                <i class="bi bi-trash"></i></button>
            </form>
            <?php endif;?>
          </td>
        </tr>
      <?php endforeach; endif;?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'lokasi', ['q' => $search]) ?>
</div>

<?php $is_edit=!is_null($edit_row);?>
<div class="modal fade <?=$is_edit?'show':''?>" id="formModal" tabindex="-1"
  <?=$is_edit?'style="display:block" aria-modal="true"':''?>>
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="<?=$is_edit?'edit':'create'?>">
        <?php if($is_edit):?><input type="hidden" name="id" value="<?=$edit_row['id']?>"><?php endif;?>
        <div class="modal-header">
          <h5 class="modal-title"><?=$is_edit?'Edit':'Tambah'?> Lokasi</h5>
          <a href="?" class="btn-close"></a>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold required">Kode Lokasi</label>
            <input type="text" name="code" class="form-control" placeholder="RAK-A1"
              value="<?=htmlspecialchars($edit_row['code']??'')?>" required maxlength="20">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold required">Nama Lokasi</label>
            <input type="text" name="name" class="form-control"
              value="<?=htmlspecialchars($edit_row['name']??'')?>" required maxlength="100">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Deskripsi</label>
            <input type="text" name="description" class="form-control"
              value="<?=htmlspecialchars($edit_row['description']??'')?>">
          </div>
          <?php if($is_edit):?>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Status</label>
            <select name="is_active" class="form-select">
              <option value="1" <?=$edit_row['is_active']?'selected':''?>>Aktif</option>
              <option value="0" <?=!$edit_row['is_active']?'selected':''?>>Nonaktif</option>
            </select>
          </div>
          <?php endif;?>
        </div>
        <div class="modal-footer">
          <a href="?" class="btn btn-secondary btn-sm">Batal</a>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php if($is_edit):?><div class="modal-backdrop fade show"></div><?php endif;?>
<?php include __DIR__.'/../../includes/footer.php';?>
