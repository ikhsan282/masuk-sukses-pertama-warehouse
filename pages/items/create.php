<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('items.create');
$db = getDB();

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
    $image     = null;

    if (!$code)    $errors[] = 'Kode barang wajib diisi.';
    if (!$name)    $errors[] = 'Nama barang wajib diisi.';
    if (!$cat_id)  $errors[] = 'Kategori wajib dipilih.';
    if (!$unit_id) $errors[] = 'Satuan wajib dipilih.';

    if (empty($errors)) {
        try {
            $image = upload_item_image($_FILES['image'] ?? []);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        $st = $db->prepare('INSERT INTO items (code,name,category_id,unit_id,min_stock,buy_price,sell_price,description,image)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $st->bind_param('ssiiiddss', $code,$name,$cat_id,$unit_id,$min_stock,$buy_price,$sell_price,$desc,$image);
        if ($st->execute()) {
            set_flash('success', "Barang <strong>" . htmlspecialchars($name) . "</strong> berhasil ditambahkan.");
            redirect(APP_URL . '/pages/items/index.php');
        } else {
            $errors[] = 'Kode barang sudah digunakan.';
        }
        $st->close();
    }
}

$categories = $db->query('SELECT id,name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$units      = $db->query('SELECT id,name,abbreviation FROM units ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$page_title = 'Tambah Barang';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-1">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/items/index.php">Barang</a></li>
      <li class="breadcrumb-item active">Tambah</li>
    </ol>
  </nav>
  <h4><i class="bi bi-plus-square me-2 text-primary"></i>Tambah Barang Baru</h4>
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
          <input type="text" name="code" class="form-control" placeholder="BRG-001"
            value="<?= htmlspecialchars(req_str('code')) ?>" required maxlength="30">
        </div>
        <div class="col-md-8">
          <label class="form-label small fw-semibold required">Nama Barang</label>
          <input type="text" name="name" class="form-control"
            value="<?= htmlspecialchars(req_str('name')) ?>" required maxlength="150">
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold required">Kategori</label>
          <select name="category_id" class="form-select" required>
            <option value="">— Pilih Kategori —</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= req_int('category_id')==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold required">Satuan</label>
          <select name="unit_id" class="form-select" required>
            <option value="">— Pilih —</option>
            <?php foreach ($units as $u): ?>
            <option value="<?= $u['id'] ?>" <?= req_int('unit_id')==$u['id']?'selected':'' ?>>
              <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['abbreviation']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold">Stok Minimum</label>
          <input type="number" name="min_stock" class="form-control" min="0"
            value="<?= req_int('min_stock') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Harga Beli (Rp)</label>
          <input type="number" name="buy_price" class="form-control" min="0" step="any"
            value="<?= req_str('buy_price') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Harga Jual (Rp)</label>
          <input type="number" name="sell_price" class="form-control" min="0" step="any"
            value="<?= req_str('sell_price') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold">Foto Barang</label>
          <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp">
          <div class="form-text">JPG, PNG, atau WebP. Maksimal 2MB.</div>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Deskripsi</label>
          <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars(req_str('description')) ?></textarea>
        </div>
      </div>
      <hr class="my-4">
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan</button>
        <a href="<?= APP_URL ?>/pages/items/index.php" class="btn btn-outline-secondary">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
