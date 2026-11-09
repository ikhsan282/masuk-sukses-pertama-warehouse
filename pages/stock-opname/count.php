<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/stock_opname.php';

require_perm('stock_opname.count');
$db = getDB();
$id = req_int('id');

$session_stmt = $db->prepare('SELECT o.*,u.name user_name FROM stock_opname o JOIN users u ON u.id=o.user_id WHERE o.session_id=?');
$session_stmt->bind_param('i', $id);
$session_stmt->execute();
$opname = $session_stmt->get_result()->fetch_assoc();
$session_stmt->close();
if (!$opname) { set_flash('error', 'Sesi stock opname tidak ditemukan.'); redirect(APP_URL . '/pages/stock-opname/index.php'); }
if ($opname['status'] !== 'draft') redirect(APP_URL . '/pages/stock-opname/report.php?id=' . $id);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $item_id = req_int('item_id');
    $location_id = req_int('location_id');
    $physical_qty = filter_input(INPUT_POST, 'physical_qty', FILTER_VALIDATE_INT);
    if (!$item_id) $errors[] = 'Barang wajib dipilih.';
    if (!$location_id) $errors[] = 'Lokasi wajib dipilih.';
    if ($physical_qty === false || $physical_qty === null || $physical_qty < 0) $errors[] = 'Jumlah fisik harus bilangan bulat nol atau lebih.';
    if (empty($errors)) {
        try {
            $result = save_stock_opname_count($db, $id, $item_id, $location_id, $physical_qty);
            $label = $result['variance'] === 0 ? 'sesuai' : (($result['variance'] > 0 ? '+' : '') . $result['variance']);
            set_flash('success', "Hasil hitung tersimpan. Selisih: {$label}.");
            redirect(APP_URL . '/pages/stock-opname/count.php?id=' . $id);
        } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }
}

$opname_comp_id = (int)($opname['company_id'] ?: current_company_id());
$items = $db->query('SELECT i.id,i.code,i.name,u.abbreviation FROM items i JOIN units u ON u.id=i.unit_id WHERE i.is_active=1 AND (i.company_id IS NULL OR i.company_id=' . $opname_comp_id . ') ORDER BY i.name')->fetch_all(MYSQLI_ASSOC);
$locations = $db->query('SELECT id,code,name FROM locations WHERE is_active=1 ORDER BY code')->fetch_all(MYSQLI_ASSOC);
$list = $db->prepare('SELECT oi.*,i.code,i.name,u.abbreviation,l.code location_code,l.name location_name
    FROM stock_opname_items oi JOIN items i ON i.id=oi.item_id JOIN units u ON u.id=i.unit_id
    JOIN locations l ON l.id=oi.location_id WHERE oi.session_id=? ORDER BY oi.id DESC');
$list->bind_param('i', $id);
$list->execute();
$counts = $list->get_result()->fetch_all(MYSQLI_ASSOC);
$list->close();

$page_title = 'Hitung Stock Opname';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-start">
  <div>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1"><li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/stock-opname/index.php">Stock Opname</a></li><li class="breadcrumb-item active">Sesi #<?= $id ?></li></ol></nav>
    <h4><i class="bi bi-upc-scan me-2 text-primary"></i>Input Hasil Hitung</h4>
    <div class="small text-muted"><?= tgl($opname['opname_date']) ?> &middot; <?= e($opname['user_name']) ?></div>
  </div>
  <a href="<?= APP_URL ?>/pages/stock-opname/report.php?id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-table me-1"></i>Lihat Variance</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form method="POST" class="row g-3 align-items-end">
      <?= csrf_field() ?>
      <div class="col-lg-5">
        <label class="form-label small fw-semibold required">Barang (scan / cari)</label>
        <div class="input-group">
          <select name="item_id" class="form-select ts-select item-select" required>
            <option value="">— Pilih barang —</option>
            <?php foreach ($items as $it): ?><option value="<?= $it['id'] ?>" data-code="<?= e($it['code']) ?>">[<?= e($it['code']) ?>] <?= e($it['name']) ?> (<?= e($it['abbreviation']) ?>)</option><?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-outline-secondary scan-btn" title="Scan Barcode/QR"><i class="bi bi-upc-scan"></i></button>
        </div>
      </div>
      <div class="col-lg-3">
        <label class="form-label small fw-semibold required">Lokasi</label>
        <select name="location_id" class="form-select ts-select" required>
          <option value="">— Pilih lokasi —</option>
          <?php foreach ($locations as $loc): ?><option value="<?= $loc['id'] ?>"><?= e($loc['code']) ?> — <?= e($loc['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-2">
        <label class="form-label small fw-semibold required">Qty Fisik</label>
        <input type="number" name="physical_qty" class="form-control" min="0" step="1" required autofocus>
      </div>
      <div class="col-lg-2"><button class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Simpan</button></div>
    </form>
  </div>
</div>

<div class="card table-card">
  <div class="card-header bg-white fw-semibold">Hitungan Tersimpan (<?= count($counts) ?>)</div>
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Barang</th><th>Lokasi</th><th class="text-end">Sistem</th><th class="text-end">Fisik</th><th class="text-end">Selisih</th><th>Waktu</th></tr></thead>
    <tbody>
    <?php if (!$counts): ?><tr><td colspan="6" class="text-center text-muted py-4">Belum ada barang dihitung</td></tr><?php endif; ?>
    <?php foreach ($counts as $row): $v=(int)$row['variance']; ?>
      <tr><td><code><?= e($row['code']) ?></code> <?= e($row['name']) ?></td><td><?= e($row['location_code']) ?> — <?= e($row['location_name']) ?></td><td class="text-end"><?= $row['system_qty'] ?></td><td class="text-end fw-semibold"><?= $row['physical_qty'] ?></td><td class="text-end <?= $v < 0 ? 'text-danger' : ($v > 0 ? 'text-success' : 'text-muted') ?> fw-semibold"><?= $v > 0 ? '+' : '' ?><?= $v ?></td><td class="small text-muted"><?= date('d/m H:i', strtotime($row['counted_at'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php include __DIR__ . '/../../includes/scanner-modal.php'; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
