<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_perm('purchase_orders.view');
$db = getDB();
$id = req_int('id');
$st = $db->prepare('SELECT po.*,s.name supplier_name,s.phone,s.email,l.name location_name,l.code location_code,u.name user_name,r.name received_name,a.name approved_name,
 c.name company_name, c.code company_code
 FROM purchase_orders po 
 JOIN suppliers s ON s.id=po.supplier_id 
 JOIN locations l ON l.id=po.location_id 
 JOIN users u ON u.id=po.user_id
 LEFT JOIN companies c ON c.id=po.company_id
 LEFT JOIN users r ON r.id=po.received_by 
 LEFT JOIN users a ON a.id=po.approved_by 
 WHERE po.id=?');
$st->bind_param('i', $id);
$st->execute();
$po = $st->get_result()->fetch_assoc();
$st->close();
if (!$po) {
    set_flash('error', 'Purchase order tidak ditemukan.');
    redirect(APP_URL . '/pages/purchase-orders/index.php');
}
$det = $db->prepare('SELECT d.*,i.code,i.name FROM purchase_order_details d JOIN items i ON i.id=d.item_id WHERE d.po_id=? ORDER BY i.name');
$det->bind_param('i', $id);
$det->execute();
$items = $det->get_result()->fetch_all(MYSQLI_ASSOC);
$det->close();
$labels = ['draft' => ['Draft', 'secondary'], 'pending' => ['Pending', 'warning'], 'partial' => ['Sebagian', 'info'], 'completed' => ['Selesai', 'success'], 'cancelled' => ['Dibatalkan', 'danger']];
$approval_labels = ['draft' => ['Draft', 'secondary'], 'pending' => ['Pending', 'warning'], 'approved' => ['Approved', 'success'], 'rejected' => ['Rejected', 'danger']];
[$status_label, $status_color] = $labels[$po['status']] ?? ['Unknown', 'secondary'];
[$approval_label, $approval_color] = $approval_labels[$po['approval_status']] ?? ['Unknown', 'secondary'];
$page_title = 'Detail Purchase Order';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-1">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/purchase-orders/index.php">Purchase Order</a></li>
      <li class="breadcrumb-item active">Detail</li>
    </ol>
  </nav>
  <div class="d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-cart-check me-2 text-primary"></i><?= e($po['reference_no']) ?></h4>
    <div class="d-flex gap-2">
      <?php if (in_array($po['status'], ['pending', 'partial'], true) && $po['approval_status'] === 'approved' && can('purchase_orders.receive')): ?>
        <a href="receive-form.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-success">
          <i class="bi bi-box-arrow-in-down me-1"></i>Terima Barang
        </a>
      <?php endif; ?>
      <?php if ($po['status'] === 'draft' && can('purchase_orders.edit')): ?>
        <a href="form.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-pencil me-1"></i>Edit
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold">Informasi PO</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr>
            <td class="text-muted small" style="width:40%">Perusahaan</td>
            <td><strong>[<?= htmlspecialchars($po['company_code'] ?? 'MSP') ?>]</strong> <?= htmlspecialchars($po['company_name'] ?? 'PT Masuk Sukses Pertama') ?></td>
          </tr>
          <tr>
            <td class="text-muted small">Status</td>
            <td><span class="badge bg-<?= $status_color ?>"><?= $status_label ?></span></td>
          </tr>
          <tr>
            <td class="text-muted small">Approval</td>
            <td><span class="badge bg-<?= $approval_color ?>"><?= $approval_label ?></span></td>
          </tr>
          <tr>
            <td class="text-muted small">Tanggal PO</td>
            <td><?= tgl($po['order_date']) ?></td>
          </tr>
          <?php if ($po['expected_date']): ?>
            <tr>
              <td class="text-muted small">Estimasi Tiba</td>
              <td><?= tgl($po['expected_date']) ?></td>
            </tr>
          <?php endif; ?>
          <tr>
            <td class="text-muted small">Supplier</td>
            <td class="fw-semibold"><?= e($po['supplier_name']) ?></td>
          </tr>
          <?php if ($po['phone']): ?>
            <tr>
              <td class="text-muted small">Telepon</td>
              <td><?= e($po['phone']) ?></td>
            </tr>
          <?php endif; ?>
          <?php if ($po['email']): ?>
            <tr>
              <td class="text-muted small">Email</td>
              <td><a href="mailto:<?= e($po['email']) ?>"><?= e($po['email']) ?></a></td>
            </tr>
          <?php endif; ?>
          <tr>
            <td class="text-muted small">Lokasi Tujuan</td>
            <td><?= e($po['location_name']) ?> <code class="small"><?= e($po['location_code']) ?></code></td>
          </tr>
          <tr>
            <td class="text-muted small">Dibuat oleh</td>
            <td><?= e($po['user_name']) ?></td>
          </tr>
          <tr>
            <td class="text-muted small">Dibuat</td>
            <td><?= date('d/m/Y H:i', strtotime($po['created_at'])) ?></td>
          </tr>
          <?php if ($po['approved_by']): ?>
            <tr>
              <td class="text-muted small">Diapprove oleh</td>
              <td><?= e($po['approved_name']) ?></td>
            </tr>
            <tr>
              <td class="text-muted small">Diapprove</td>
              <td><?= date('d/m/Y H:i', strtotime($po['approved_at'])) ?></td>
            </tr>
            <?php if ($po['approval_notes']): ?>
              <tr>
                <td class="text-muted small">Catatan Approval</td>
                <td class="small"><?= nl2br(e($po['approval_notes'])) ?></td>
              </tr>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (in_array($po['status'], ['partial', 'completed'], true) && $po['received_by']): ?>
            <tr>
              <td class="text-muted small">Diterima oleh</td>
              <td><?= e($po['received_name']) ?></td>
            </tr>
            <tr>
              <td class="text-muted small">Terakhir diterima</td>
              <td><?= date('d/m/Y H:i', strtotime($po['received_at'])) ?></td>
            </tr>
          <?php endif; ?>
          <?php if ($po['notes']): ?>
            <tr>
              <td class="text-muted small">Catatan</td>
              <td class="small"><?= nl2br(e($po['notes'])) ?></td>
            </tr>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold">Daftar Barang</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Kode</th>
              <th>Nama Barang</th>
              <th class="text-end">Qty</th>
              <th class="text-end">Diterima</th>
              <th class="text-end">Harga</th>
              <th class="text-end">Subtotal</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $total = 0;
            foreach ($items as $i => $item):
                $subtotal = $item['quantity'] * $item['buy_price'];
                $total += $subtotal;
                $ordered = (int)$item['quantity'];
                $received = (int)$item['received_quantity'];
                $progress_pct = $ordered > 0 ? round(($received / $ordered) * 100) : 0;
            ?>
              <tr>
                <td class="text-muted small"><?= $i + 1 ?></td>
                <td><code class="small"><?= e($item['code']) ?></code></td>
                <td><?= e($item['name']) ?></td>
                <td class="text-end"><?= $ordered ?></td>
                <td class="text-end">
                  <span class="<?= $received < $ordered ? 'text-warning fw-semibold' : 'text-success' ?>"><?= $received ?></span>
                  <?php if ($ordered > 0): ?>
                    <small class="text-muted">(<?= $progress_pct ?>%)</small>
                  <?php endif; ?>
                </td>
                <td class="text-end small"><?= idr((float)$item['buy_price']) ?></td>
                <td class="text-end"><?= idr($subtotal) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="table-light">
              <td colspan="6" class="text-end fw-bold">Total</td>
              <td class="text-end fw-bold"><?= idr($total) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
