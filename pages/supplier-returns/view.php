<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/supplier_returns.php';

require_perm('supplier_returns.view');
$db = getDB();

$id = req_int('id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel'])) {
    require_perm('supplier_returns.delete');
    verify_csrf();
    try {
        $user_id = current_user()['id'];
        cancel_supplier_return($db, $id, $user_id);
        set_flash('success', 'Retur supplier berhasil dibatalkan dan stok dikembalikan.');
        redirect(APP_URL.'/pages/supplier-returns/view.php?id='.$id);
    } catch (Exception $e) {
        set_flash('error', 'Gagal membatalkan: '.$e->getMessage());
    }
}

$st = $db->prepare('SELECT sr.*, s.name AS supplier_name, l.name AS location_name, u.name AS user_name, c.code AS comp_code, c.name AS comp_name
    FROM supplier_returns sr JOIN suppliers s ON s.id=sr.supplier_id
    JOIN locations l ON l.id=sr.location_id JOIN users u ON u.id=sr.user_id
    LEFT JOIN companies c ON c.id=sr.company_id WHERE sr.id=?');
$st->bind_param('i',$id); $st->execute();
$header = $st->get_result()->fetch_assoc(); $st->close();
if (!$header) { set_flash('error','Data tidak ditemukan.'); redirect(APP_URL.'/pages/supplier-returns/index.php'); }

$st2 = $db->prepare('SELECT d.*, i.code AS item_code, i.name AS item_name, u.abbreviation
    FROM supplier_return_details d JOIN items i ON i.id=d.item_id JOIN units u ON u.id=i.unit_id
    WHERE d.return_id=?');
$st2->bind_param('i',$id); $st2->execute();
$details = $st2->get_result()->fetch_all(MYSQLI_ASSOC); $st2->close();

$total_qty = array_sum(array_column($details, 'quantity'));
$total_value = 0;
foreach ($details as $d) $total_value += $d['quantity'] * $d['buy_price'];

$page_title='Detail Retur Supplier'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?=APP_URL?>/pages/supplier-returns/index.php">Retur Supplier</a></li>
    <li class="breadcrumb-item active">Detail</li>
  </ol></nav>
  <div class="d-flex align-items-center justify-content-between">
    <h4><i class="bi bi-arrow-return-left me-2 text-primary"></i>Detail Retur Supplier</h4>
    <div class="d-flex gap-2">
      <?php if($header['status']==='completed' && can('supplier_returns.delete')):?>
      <form method="POST" class="d-inline">
        <?=csrf_field()?><input type="hidden" name="cancel" value="1">
        <button class="btn btn-sm btn-outline-danger" data-confirm="Batalkan retur ini? Stok akan dikembalikan.">
          <i class="bi bi-x-circle me-1"></i>Batalkan
        </button>
      </form>
      <?php endif;?>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-5">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold">Informasi Transaksi</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="text-muted small" style="width:40%">No. Referensi</td>
              <td><code class="fw-bold"><?=htmlspecialchars($header['reference_no'])?></code></td></tr>
          <tr><td class="text-muted small">Perusahaan (PT)</td>
              <td><span class="badge bg-primary-subtle text-primary border"><?=htmlspecialchars(($header['comp_code'] ?? 'MSP') . ' — ' . ($header['comp_name'] ?? ''))?></span></td></tr>
          <tr><td class="text-muted small">Status</td>
              <td><span class="badge bg-<?=$header['status']==='completed'?'success':'secondary'?>"><?=ucfirst($header['status'])?></span></td></tr>
          <tr><td class="text-muted small">Tanggal</td><td><?=tgl($header['transaction_date'])?></td></tr>
          <tr><td class="text-muted small">Supplier</td><td class="fw-semibold"><?=htmlspecialchars($header['supplier_name'])?></td></tr>
          <tr><td class="text-muted small">Lokasi Asal</td><td><?=htmlspecialchars($header['location_name'])?></td></tr>
          <tr><td class="text-muted small">Alasan Retur</td><td class="text-danger"><?=htmlspecialchars($header['reason'])?></td></tr>
          <tr><td class="text-muted small">Dicatat Oleh</td><td><?=htmlspecialchars($header['user_name'])?></td></tr>
          <?php if($header['notes']):?>
          <tr><td class="text-muted small">Catatan</td><td class="small"><?=htmlspecialchars($header['notes'])?></td></tr>
          <?php endif;?>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-7">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold">Daftar Barang Retur</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>#</th><th>Kode</th><th>Nama Barang</th><th class="text-center">Qty</th><th class="text-end">Harga Beli</th><th class="text-end">Subtotal</th></tr></thead>
          <tbody>
          <?php foreach($details as $i=>$d):?>
          <tr>
            <td class="small text-muted"><?=$i+1?></td>
            <td><code class="small"><?=htmlspecialchars($d['item_code'])?></code></td>
            <td><?=htmlspecialchars($d['item_name'])?></td>
            <td class="text-center"><?=$d['quantity']?> <?=htmlspecialchars($d['abbreviation'])?></td>
            <td class="text-end small"><?=idr((float)$d['buy_price'])?></td>
            <td class="text-end fw-semibold"><?=idr($d['quantity']*$d['buy_price'])?></td>
          </tr>
          <?php endforeach;?>
          <tr class="table-light fw-bold">
            <td colspan="3" class="text-end">Total</td>
            <td class="text-center"><?=$total_qty?></td>
            <td class="text-end"><?=idr($total_value)?></td>
            <td></td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__.'/../../includes/footer.php';?>
