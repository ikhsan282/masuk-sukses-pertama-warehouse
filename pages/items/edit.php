<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('items.edit');
$db = getDB();

$id = req_int('id');
$st = $db->prepare('SELECT * FROM items WHERE id=?');
$st->bind_param('i', $id); $st->execute();
$item = $st->get_result()->fetch_assoc(); $st->close();
if (!$item) { set_flash('error','Barang tidak ditemukan.'); redirect(APP_URL.'/pages/items/index.php'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $code      = strtoupper(req_str('code'));
    $name      = req_str('name');
    $cat_id    = req_int('category_id');
    $unit_id   = req_int('unit_id');
    $min_stock = req_int('min_stock');
    $buy_price = (float)req_str('buy_price');
    $sell_price= (float)req_str('sell_price');
    $desc      = req_str('description');
    $active    = req_int('is_active');
    $remove_img= req_int('remove_image');
    $image     = $item['image'] ?? null;

    if (!$code) $errors[] = 'Kode barang wajib diisi.';
    if (!$name) $errors[] = 'Nama barang wajib diisi.';

    if (empty($errors)) {
        try {
            if ($remove_img && empty($_FILES['image']['tmp_name'])) {
                delete_item_image($image);
                $image = null;
            } else {
                $image = upload_item_image($_FILES['image'] ?? [], $image);
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        $st = $db->prepare('UPDATE items SET code=?,name=?,category_id=?,unit_id=?,min_stock=?,
            buy_price=?,sell_price=?,description=?,image=?,is_active=? WHERE id=?');
        $st->bind_param('ssiiiddssii', $code,$name,$cat_id,$unit_id,$min_stock,$buy_price,$sell_price,$desc,$image,$active,$id);
        if ($st->execute()) {
            set_flash('success', 'Barang berhasil diperbarui.');
            redirect(APP_URL . '/pages/items/index.php');
        } else {
            $errors[] = 'Kode barang sudah digunakan oleh barang lain.';
        }
        $st->close();
    }
    // repopulate
    $item = array_merge($item, ['code'=>$code,'name'=>$name,'category_id'=>$cat_id,'unit_id'=>$unit_id,
        'min_stock'=>$min_stock,'buy_price'=>$buy_price,'sell_price'=>$sell_price,'description'=>$desc,'image'=>$image,'is_active'=>$active]);
}

$categories = $db->query('SELECT id,name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$units      = $db->query('SELECT id,name,abbreviation FROM units ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$page_title = 'Edit Barang';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-1">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/items/index.php">Barang</a></li>
      <li class="breadcrumb-item active">Edit</li>
    </ol>
  </nav>
  <h4><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Barang</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0">
  <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label small fw-semibold required">Kode Barang</label>
          <input type="text" name="code" class="form-control" value="<?= htmlspecialchars($item['code']) ?>" required>
        </div>
        <div class="col-md-8">
          <label class="form-label small fw-semibold required">Nama Barang</label>
          <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($item['name']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold required">Kategori</label>
          <select name="category_id" class="form-select" required>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $item['category_id']==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold required">Satuan</label>
          <select name="unit_id" class="form-select" required>
            <?php foreach ($units as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $item['unit_id']==$u['id']?'selected':'' ?>>
              <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['abbreviation']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold">Stok Minimum</label>
          <input type="number" name="min_stock" class="form-control" min="0" value="<?= $item['min_stock'] ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Harga Beli (Rp)</label>
          <input type="number" name="buy_price" class="form-control" min="0" step="any" value="<?= $item['buy_price'] ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Harga Jual (Rp)</label>
          <input type="number" name="sell_price" class="form-control" min="0" step="any" value="<?= $item['sell_price'] ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Status</label>
          <select name="is_active" class="form-select">
            <option value="1" <?= $item['is_active']?'selected':'' ?>>Aktif</option>
            <option value="0" <?= !$item['is_active']?'selected':'' ?>>Nonaktif</option>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Foto Barang</label>
          <?php if (!empty($item['image'])): ?>
            <div class="mb-2">
              <img src="<?= APP_URL ?>/<?= htmlspecialchars($item['image']) ?>" alt="Foto barang"
                   class="img-thumbnail" style="max-height:120px">
            </div>
          <?php endif; ?>
          <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp">
          <?php if (!empty($item['image'])): ?>
            <div class="form-check mt-1">
              <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="removeImg">
              <label class="form-check-label small text-danger" for="removeImg">Hapus foto saat simpan</label>
            </div>
          <?php endif; ?>
          <div class="form-text">JPG, PNG, atau WebP. Maksimal 2MB. Biarkan kosong jika tidak diubah.</div>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Deskripsi</label>
          <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($item['description']??'') ?></textarea>
        </div>
      </div>
      <hr class="my-4">
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Perbarui</button>
        <a href="<?= APP_URL ?>/pages/items/index.php" class="btn btn-outline-secondary">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
