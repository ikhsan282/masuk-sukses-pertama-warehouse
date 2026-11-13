<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('suppliers.view');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = req_str('action');

    if ($action === 'create' && can('suppliers.create')) {
        $code = strtoupper(req_str('code'));
        $name = req_str('name');
        $cp   = req_str('contact_person');
        $phone= req_str('phone');
        $email= req_str('email');
        $addr = req_str('address');
        if (!$code || !$name) { set_flash('error','Kode dan nama wajib diisi.'); }
        else {
            $st = $db->prepare('INSERT INTO suppliers (code,name,contact_person,phone,email,address) VALUES (?,?,?,?,?,?)');
            $st->bind_param('ssssss',$code,$name,$cp,$phone,$email,$addr);
            if ($st->execute()) set_flash('success','Supplier berhasil ditambahkan.');
            else set_flash('error','Kode supplier sudah digunakan.');
            $st->close();
        }
    }

    if ($action === 'edit' && can('suppliers.edit')) {
        $id=req_int('id'); $code=strtoupper(req_str('code')); $name=req_str('name');
        $cp=req_str('contact_person'); $phone=req_str('phone'); $email=req_str('email');
        $addr=req_str('address'); $active=req_int('is_active');
        $st=$db->prepare('UPDATE suppliers SET code=?,name=?,contact_person=?,phone=?,email=?,address=?,is_active=? WHERE id=?');
        $st->bind_param('ssssssii',$code,$name,$cp,$phone,$email,$addr,$active,$id);
        if($st->execute()) set_flash('success','Supplier diperbarui.'); else set_flash('error','Gagal.');
        $st->close();
    }

    if ($action === 'delete' && can('suppliers.delete')) {
        $id=req_int('id');
        $st=$db->prepare('DELETE FROM suppliers WHERE id=?');
        $st->bind_param('i',$id);
        if($st->execute()) set_flash('success','Supplier dihapus.'); else set_flash('error','Gagal. Supplier masih digunakan.');
        $st->close();
    }
    redirect(APP_URL.'/pages/suppliers/index.php');
}

$edit_row = null;
if (req_int('edit')) {
    $st=$db->prepare('SELECT * FROM suppliers WHERE id=?'); $st->bind_param('i',$_GET['edit']);
    $st->execute(); $edit_row=$st->get_result()->fetch_assoc(); $st->close();
}

$search   = grid_search_term('q');
$page     = max(1, req_int('page', 1));
$per_page = grid_per_page();
grid_apply_timeout($db);
$where  = $search ? "WHERE name LIKE ? OR code LIKE ? OR contact_person LIKE ?" : '';
$cq=$db->prepare("SELECT COUNT(*) FROM suppliers $where");
if($search){$like='%'.escape_like($search).'%';$cq->bind_param('sss',$like,$like,$like);}
$cq->execute();$cq->bind_result($total);$cq->fetch();$cq->close();
$pag=paginate($total,$page,$per_page);
$offset=$pag['offset'];$limit=$pag['per_page'];
$st=$db->prepare("SELECT * FROM suppliers $where ORDER BY name LIMIT ? OFFSET ?");
if($search){$st->bind_param('sssii',$like,$like,$like,$limit,$offset);}
else{$st->bind_param('ii',$limit,$offset);}
$st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$page_title='Supplier'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-truck me-2 text-primary"></i>Supplier</h4>
  <?php if(can('suppliers.create')):?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#formModal">
    <i class="bi bi-plus-lg me-1"></i>Tambah Supplier
  </button>
  <?php endif;?>
</div>

<div class="card table-card">
  <div class="card-header bg-white py-2">
    <form class="d-flex gap-2" method="GET">
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:280px"
        placeholder="Cari nama, kode, kontak..." value="<?=htmlspecialchars($search)?>">
      <button class="btn btn-sm btn-outline-secondary">Cari</button>
      <?php if($search):?><a href="?" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></a><?php endif;?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>#</th><th>Kode</th><th>Nama Supplier</th><th>Kontak</th><th>Telepon</th><th>Status</th><th class="text-center">Aksi</th></tr></thead>
      <tbody>
      <?php if(empty($rows)):?>
        <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach($rows as $i=>$r):?>
        <tr>
          <td class="small text-muted"><?=$pag['offset']+$i+1?></td>
          <td><span class="badge bg-secondary-subtle text-secondary border"><?=htmlspecialchars($r['code'])?></span></td>
          <td class="fw-semibold"><?=htmlspecialchars($r['name'])?></td>
          <td class="small"><?=htmlspecialchars($r['contact_person']??'')?></td>
          <td class="small"><?=htmlspecialchars($r['phone']??'')?></td>
          <td><?=$r['is_active']?'<span class="badge badge-ok">Aktif</span>':'<span class="badge badge-zero">Nonaktif</span>'?></td>
          <td class="text-center">
            <?php if(can('suppliers.edit')):?>
            <a href="?edit=<?=$r['id']?>" class="btn btn-action btn-outline-primary"><i class="bi bi-pencil"></i></a>
            <?php endif;?>
            <?php if(can('suppliers.delete')):?>
            <form method="POST" class="d-inline">
              <?=csrf_field()?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-action btn-outline-danger" data-confirm="Hapus supplier '<?=htmlspecialchars($r['name'])?>'?">
                <i class="bi bi-trash"></i></button>
            </form>
            <?php endif;?>
          </td>
        </tr>
      <?php endforeach; endif;?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'supplier', ['q' => $search]) ?>
</div>

<?php $is_edit=!is_null($edit_row);?>
<div class="modal fade <?=$is_edit?'show':''?>" id="formModal" tabindex="-1"
  <?=$is_edit?'style="display:block" aria-modal="true"':''?>>
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="<?=$is_edit?'edit':'create'?>">
        <?php if($is_edit):?><input type="hidden" name="id" value="<?=$edit_row['id']?>"><?php endif;?>
        <div class="modal-header">
          <h5 class="modal-title"><?=$is_edit?'Edit':'Tambah'?> Supplier</h5>
          <a href="?" class="btn-close"></a>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label small fw-semibold required">Kode Supplier</label>
              <input type="text" name="code" class="form-control" placeholder="SUP-001"
                value="<?=htmlspecialchars($edit_row['code']??'')?>" required maxlength="20">
            </div>
            <div class="col-md-8">
              <label class="form-label small fw-semibold required">Nama Supplier</label>
              <input type="text" name="name" class="form-control"
                value="<?=htmlspecialchars($edit_row['name']??'')?>" required maxlength="150">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Nama Kontak</label>
              <input type="text" name="contact_person" class="form-control"
                value="<?=htmlspecialchars($edit_row['contact_person']??'')?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Telepon</label>
              <input type="text" name="phone" class="form-control"
                value="<?=htmlspecialchars($edit_row['phone']??'')?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Email</label>
              <input type="email" name="email" class="form-control"
                value="<?=htmlspecialchars($edit_row['email']??'')?>">
            </div>
            <?php if($is_edit):?>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Status</label>
              <select name="is_active" class="form-select">
                <option value="1" <?=$edit_row['is_active']?'selected':''?>>Aktif</option>
                <option value="0" <?=!$edit_row['is_active']?'selected':''?>>Nonaktif</option>
              </select>
            </div>
            <?php endif;?>
            <div class="col-12">
              <label class="form-label small fw-semibold">Alamat</label>
              <textarea name="address" class="form-control" rows="2"><?=htmlspecialchars($edit_row['address']??'')?></textarea>
            </div>
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
<?php if($is_edit):?><div class="modal-backdrop fade show"></div><?php endif;?>
<?php include __DIR__.'/../../includes/footer.php';?>
