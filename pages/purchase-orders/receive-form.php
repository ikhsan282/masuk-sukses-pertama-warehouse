<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/purchase_orders.php';
require_perm('purchase_orders.receive');
$db = getDB();
$id = req_int('id');

$st = $db->prepare('SELECT po.*, s.name supplier_name FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id WHERE po.id=?');
$st->bind_param('i', $id);
$st->execute();
$po = $st->get_result()->fetch_assoc();
$st->close();
if (!$po) { set_flash('error', 'Purchase order tidak ditemukan.'); redirect(APP_URL . '/pages/purchase-orders/index.php'); }
require_company_access((int)$po['company_id']);
if ($po['approval_status'] !== 'approved') { set_flash('error', 'Hanya PO yang sudah diapprove yang dapat diterima.'); redirect(APP_URL . '/pages/purchase-orders/view.php?id=' . $id); }
if (!in_array($po['status'], ['pending', 'partial'], true)) { set_flash('error', 'PO sudah selesai atau tidak dapat diterima.'); redirect(APP_URL . '/pages/purchase-orders/view.php?id=' . $id); }

$det = $db->prepare('SELECT d.*, i.code, i.name, u.abbreviation FROM purchase_order_details d JOIN items i ON i.id=d.item_id JOIN units u ON u.id=i.unit_id WHERE d.po_id=? ORDER BY i.name');
$det->bind_param('i', $id);
$det->execute();
$items = $det->get_result()->fetch_all(MYSQLI_ASSOC);
$det->close();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $receive_qty = [];
    foreach ($items as $item) {
        $qty = filter_input(INPUT_POST, 'qty_' . $item['item_id'], FILTER_VALIDATE_INT);
        if ($qty !== false && $qty !== null && $qty > 0) $receive_qty[(int)$item['item_id']] = $qty;
    }
    if (empty($receive_qty)) $errors[] = 'Tidak ada barang yang diterima. Masukkan quantity > 0 minimal untuk satu item.';
    if (empty($errors)) {
        try {
            $ref = receive_purchase_order_partial($db, $id, $receive_qty, current_user()['id']);
            set_flash('success', 'Penerimaan berhasil. Stock-in: ' . $ref);
            redirect(APP_URL . '/pages/purchase-orders/view.php?id=' . $id);
        } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }
}

$page_title = 'Terima Purchase Order';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/purchase-orders/index.php">Purchase Order</a></li>
    <li class="breadcrumb-item"><a href="view.php?id=<?= $id ?>">Detail</a></li>
    <li class="breadcrumb-item active">Terima Barang</li>
  </ol></nav>
  <h4><i class="bi bi-box-arrow-in-down me-2 text-success"></i>Terima Barang PO <?= e($po['reference_no']) ?></h4>
  <div class="small text-muted">Supplier: <?= e($po['supplier_name']) ?></div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="POST">
  <?= csrf_field() ?>
  <div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Masukkan Jumlah Diterima</div>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead>
          <tr>
            <th>Kode</th>
            <th>Nama Barang</th>
            <th class="text-end">Dipesan</th>
            <th class="text-end">Sudah Diterima</th>
            <th class="text-end">Sisa</th>
            <th style="width:140px">Terima Sekarang</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item):
            $ordered = (int)$item['quantity'];
            $received = (int)$item['received_quantity'];
            $remaining = $ordered - $received;
          ?>
            <tr>
              <td><code class="small"><?= e($item['code']) ?></code></td>
              <td><?= e($item['name']) ?> <small class="text-muted"><?= e($item['abbreviation']) ?></small></td>
              <td class="text-end"><?= $ordered ?></td>
              <td class="text-end"><?= $received ?></td>
              <td class="text-end fw-semibold <?= $remaining > 0 ? 'text-warning' : 'text-success' ?>"><?= $remaining ?></td>
              <td>
                <input type="number" name="qty_<?= $item['item_id'] ?>" class="form-control form-control-sm text-end" min="0" max="<?= $remaining ?>" step="1" value="<?= $remaining > 0 ? $remaining : 0 ?>" <?= $remaining === 0 ? 'disabled' : '' ?>>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Penerimaan Partial:</strong> Anda dapat menerima sebagian barang dan menyimpannya. Sisa barang dapat diterima di lain waktu. PO akan otomatis berstatus <strong>Selesai</strong> ketika seluruh barang sudah diterima.
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Terima & Tambah Stok</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Batal</a>
  </div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
