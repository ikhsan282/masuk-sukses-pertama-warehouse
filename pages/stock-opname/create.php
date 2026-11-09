<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/stock_opname.php';

require_perm('stock_opname.create');
$db = getDB();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $date  = req_str('opname_date');
    $notes = req_str('notes');

    if (!$date || !strtotime($date)) $errors[] = 'Tanggal opname wajib diisi dengan format yang benar.';
    if (strtotime($date) > strtotime(date('Y-m-d'))) $errors[] = 'Tanggal opname tidak boleh di masa depan.';

    if (empty($errors)) {
        $cid = current_company_id();
        $new_id = create_stock_opname($db, $date, current_user()['id'], $notes, $cid);
        set_flash('success', 'Sesi stock opname dibuat. Lanjutkan penginputan hasil hitung.');
        redirect(APP_URL . '/pages/stock-opname/count.php?id=' . $new_id);
    }
}

$cid = current_company_id();
$recent = $db->query("SELECT session_id,opname_date,status FROM stock_opname WHERE status='draft' AND company_id=$cid ORDER BY session_id DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

$page_title = 'Buat Sesi Stock Opname';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/stock-opname/index.php">Stock Opname</a></li>
    <li class="breadcrumb-item active">Buat Sesi</li>
  </ol></nav>
  <h4><i class="bi bi-clipboard-plus me-2 text-primary"></i>Buat Sesi Stock Opname</h4>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <form method="POST">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold required">Tanggal Opname</label>
            <input type="date" name="opname_date" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= e(req_str('opname_date', date('Y-m-d'))) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Catatan</label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Contoh: opname rutin akhir bulan, area gudang utama"><?= e(req_str('notes')) ?></textarea>
          </div>
          <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i>
            Sesi dibuat dengan status <strong>draft</strong>. Anda dapat menghentikan penghitungan dan melanjutkannya nanti.
            Stok baru berubah setelah sesi <strong>difinalisasi</strong>.
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Buat & Mulai Hitung</button>
            <a href="<?= APP_URL ?>/pages/stock-opname/index.php" class="btn btn-outline-secondary">Batal</a>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-hourglass-split me-2"></i>Sesi Draft Belum Final</div>
      <?php if (empty($recent)): ?>
        <div class="card-body text-center text-muted small py-4">Tidak ada sesi draft</div>
      <?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($recent as $r): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center small">
          <span><strong>#<?= (int)$r['session_id'] ?></strong> &middot; <?= tgl($r['opname_date']) ?></span>
          <a href="<?= APP_URL ?>/pages/stock-opname/count.php?id=<?= (int)$r['session_id'] ?>" class="btn btn-sm btn-outline-primary">Lanjutkan</a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
