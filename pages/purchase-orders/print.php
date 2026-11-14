<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('purchase_orders.view');
$db = getDB();
$id = req_int('id');

$st = $db->prepare('SELECT po.*, s.name AS supplier_name, s.phone AS supplier_phone, s.email AS supplier_email,
    s.address AS supplier_address, s.contact_person AS supplier_cp,
    l.code AS location_code, l.name AS location_name,
    u.name AS user_name, r.name AS received_name, a.name AS approved_name,
    c.code AS company_code, c.name AS company_name, c.address AS company_address,
    c.phone AS company_phone, c.npwp AS company_npwp
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    JOIN locations l ON l.id = po.location_id
    JOIN users u ON u.id = po.user_id
    LEFT JOIN companies c ON c.id = po.company_id
    LEFT JOIN users r ON r.id = po.received_by
    LEFT JOIN users a ON a.id = po.approved_by
    WHERE po.id = ?');
$st->bind_param('i', $id);
$st->execute();
$po = $st->get_result()->fetch_assoc();
$st->close();
if (!$po) {
    set_flash('error', 'Purchase order tidak ditemukan.');
    redirect(APP_URL . '/pages/purchase-orders/index.php');
}
require_company_access((int)$po['company_id'], false);

$comp_name = $po['company_name'] ?: COMPANY_NAME;
$comp_addr = $po['company_address'] ?: COMPANY_ADDRESS;
$comp_info = [];
if (!empty($po['company_phone'])) $comp_info[] = 'Telp: ' . $po['company_phone'];
if (!empty($po['company_npwp']))  $comp_info[] = 'NPWP: ' . $po['company_npwp'];
$comp_extra = $comp_info ? ' | ' . implode(' | ', $comp_info) : '';

$det = $db->prepare('SELECT d.*, i.code AS item_code, i.name AS item_name, un.abbreviation
    FROM purchase_order_details d
    JOIN items i ON i.id = d.item_id
    JOIN units un ON un.id = i.unit_id
    WHERE d.po_id = ? ORDER BY i.name');
$det->bind_param('i', $id);
$det->execute();
$details = $det->get_result()->fetch_all(MYSQLI_ASSOC);
$det->close();

$total = array_sum(array_map(fn($d) => (int)$d['quantity'] * (float)$d['buy_price'], $details));
$labels = ['draft' => 'Draft', 'pending' => 'Menunggu', 'partial' => 'Diterima Sebagian', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'];
$approval_labels = ['draft' => 'Draft', 'pending' => 'Menunggu Approval', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];
$status_text = $labels[$po['status']] ?? ucfirst((string)$po['status']);
$approval_text = $approval_labels[$po['approval_status']] ?? ucfirst((string)$po['approval_status']);

audit_log('export.print', 'purchase_order', $id, (string)$po['reference_no'], 'Mencetak dokumen PO ' . $po['reference_no'], (int)$po['company_id']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cetak Purchase Order - <?= e($po['reference_no']) ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font:12px/1.5 Arial,sans-serif;color:#000;background:#fff}
.container{max-width:820px;margin:20px auto;padding:0 15px}
.header{text-align:center;border-bottom:2px solid #000;padding-bottom:15px;margin-bottom:20px}
.header h2{font-size:20px;margin-bottom:4px}
.header p{font-size:11px;color:#555}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:18px}
.info-box{border:1px solid #ddd;padding:10px}
.info-box h4{font-size:13px;margin-bottom:8px;border-bottom:1px solid #eee;padding-bottom:4px}
.info-row{display:flex;margin-bottom:4px}
.info-label{width:125px;font-weight:600;font-size:11px}
.info-value{flex:1;font-size:11px}
table{width:100%;border-collapse:collapse;margin-bottom:15px}
thead{background:#f5f5f5}
th,td{border:1px solid #ddd;padding:7px 6px;font-size:11px}
th{font-weight:600;text-align:left}
.text-center{text-align:center}
.text-end{text-align:right}
.fw-bold{font-weight:700}
.note-box{border:1px solid #ddd;padding:10px;margin-bottom:15px;background:#f9f9f9;font-size:11px}
.signature{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:36px}
.signature div{text-align:center}
.signature p{margin-bottom:58px;font-size:11px}
.signature span{display:block;border-top:1px solid #000;padding-top:4px;font-size:11px}
@media print{
  body{margin:0;padding:0}
  .container{margin:0;padding:15px}
  .no-print{display:none}
}
@media screen{
  .print-btn{position:fixed;bottom:20px;right:20px;padding:12px 24px;background:#0d6efd;color:#fff;border:none;border-radius:6px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.2);font-size:14px}
  .print-btn:hover{background:#0b5ed7}
}
</style>
</head>
<body>
<button class="print-btn no-print" onclick="window.print()">Cetak PO</button>
<div class="container">
  <div class="header">
    <h2><?= e($comp_name) ?></h2>
    <p><?= e($comp_addr) ?><?= e($comp_extra) ?></p>
    <h3 style="margin-top:10px;font-size:15px;text-decoration:underline">PURCHASE ORDER (PO)</h3>
  </div>

  <div class="info-grid">
    <div class="info-box">
      <h4>Informasi Pesanan</h4>
      <div class="info-row"><span class="info-label">No. PO</span><span class="info-value fw-bold"><?= e($po['reference_no']) ?></span></div>
      <div class="info-row"><span class="info-label">Tanggal Order</span><span class="info-value"><?= tgl($po['order_date']) ?></span></div>
      <div class="info-row"><span class="info-label">Estimasi Tiba</span><span class="info-value"><?= $po['expected_date'] ? tgl($po['expected_date']) : '-' ?></span></div>
      <div class="info-row"><span class="info-label">Lokasi Tujuan</span><span class="info-value">[<?= e($po['location_code']) ?>] <?= e($po['location_name']) ?></span></div>
      <div class="info-row"><span class="info-label">Status / Approval</span><span class="info-value"><?= e($status_text) ?> &middot; <?= e($approval_text) ?></span></div>
      <div class="info-row"><span class="info-label">Dibuat Oleh</span><span class="info-value"><?= e($po['user_name']) ?></span></div>
    </div>
    <div class="info-box">
      <h4>Kepada Supplier</h4>
      <div class="info-row"><span class="info-label">Nama Supplier</span><span class="info-value fw-bold"><?= e($po['supplier_name']) ?></span></div>
      <div class="info-row"><span class="info-label">PIC / Kontak</span><span class="info-value"><?= e($po['supplier_cp'] ?: '-') ?></span></div>
      <div class="info-row"><span class="info-label">Telepon</span><span class="info-value"><?= e($po['supplier_phone'] ?: '-') ?></span></div>
      <?php if (!empty($po['supplier_email'])): ?>
      <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?= e($po['supplier_email']) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($po['supplier_address'])): ?>
      <div class="info-row"><span class="info-label">Alamat</span><span class="info-value"><?= e($po['supplier_address']) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:30px">No</th>
        <th style="width:85px">Kode</th>
        <th>Nama Barang</th>
        <th class="text-center" style="width:75px">Qty Pesan</th>
        <th class="text-center" style="width:65px">Diterima</th>
        <th class="text-end" style="width:100px">Harga Satuan</th>
        <th class="text-end" style="width:110px">Subtotal</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($details as $i => $d): $sub = (int)$d['quantity'] * (float)$d['buy_price']; ?>
      <tr>
        <td class="text-center"><?= $i + 1 ?></td>
        <td><?= e($d['item_code']) ?></td>
        <td><?= e($d['item_name']) ?></td>
        <td class="text-center"><?= (int)$d['quantity'] ?> <?= e($d['abbreviation']) ?></td>
        <td class="text-center"><?= (int)$d['received_quantity'] ?></td>
        <td class="text-end"><?= idr((float)$d['buy_price']) ?></td>
        <td class="text-end"><?= idr($sub) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr style="background:#f5f5f5">
        <td colspan="6" class="text-end fw-bold">Total Nilai Purchase Order</td>
        <td class="text-end fw-bold"><?= idr($total) ?></td>
      </tr>
    </tbody>
  </table>

  <?php if (!empty($po['notes']) || !empty($po['approval_notes'])): ?>
  <div class="note-box">
    <?php if (!empty($po['notes'])): ?>
      <div><strong>Catatan PO:</strong><br><?= nl2br(e($po['notes'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($po['approval_notes'])): ?>
      <div style="<?= !empty($po['notes']) ? 'margin-top:8px;' : '' ?>"><strong>Catatan Approval:</strong><br><?= nl2br(e($po['approval_notes'])) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="signature">
    <div><p>Dibuat Oleh (Purchasing)</p><span><?= e($po['user_name']) ?></span></div>
    <div><p>Disetujui Oleh</p><span><?= e($po['approved_name'] ?: 'Pejabat Berwenang') ?></span></div>
    <div><p>Konfirmasi Supplier</p><span><?= e($po['supplier_name']) ?></span></div>
  </div>
</div>
</body>
</html>
