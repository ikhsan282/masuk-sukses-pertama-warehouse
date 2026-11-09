<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('stock_out.view');
$db = getDB();

$id = req_int('id');
$st = $db->prepare('SELECT so.*, l.name AS location_name, u.name AS user_name, c.code AS comp_code, c.name AS comp_name
    FROM stock_out so JOIN locations l ON l.id=so.location_id
    JOIN users u ON u.id=so.user_id LEFT JOIN companies c ON c.id=so.company_id WHERE so.id=?');
$st->bind_param('i',$id); $st->execute();
$header = $st->get_result()->fetch_assoc(); $st->close();
if (!$header) { set_flash('error','Data tidak ditemukan.'); redirect(APP_URL.'/pages/stock-out/index.php'); }

$st2 = $db->prepare('SELECT d.*, i.code AS item_code, i.name AS item_name, u.abbreviation
    FROM stock_out_details d JOIN items i ON i.id=d.item_id JOIN units u ON u.id=i.unit_id
    WHERE d.stock_out_id=?');
$st2->bind_param('i',$id); $st2->execute();
$details = $st2->get_result()->fetch_all(MYSQLI_ASSOC); $st2->close();

$total_qty = array_sum(array_column($details, 'quantity'));
$page_title='Detail Barang Keluar'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?=APP_URL?>/pages/stock-out/index.php">Barang Keluar</a></li>
    <li class="breadcrumb-item active">Detail</li>
  </ol></nav>
  <div class="d-flex align-items-center justify-content-between">
    <h4><i class="bi bi-box-arrow-up me-2 text-primary"></i>Detail Barang Keluar</h4>
    <div class="d-flex gap-2">
      <a href="<?=APP_URL?>/pages/stock-out/print.php?id=<?=$header['id']?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Cetak</a>
      <?php if(can('stock_out.delete')):?>
      <form method="POST" action="<?=APP_URL?>/pages/stock-out/delete.php" class="d-inline">
        <?=csrf_field()?><input type="hidden" name="id" value="<?=$header['id']?>">
        <button class="btn btn-sm btn-outline-danger" data-confirm="Hapus transaksi ini? Stok akan dikembalikan.">
          <i class="bi bi-trash me-1"></i>Hapus</button>
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
          <tr><td class="text-muted small">Tanggal</td><td><?=tgl($header['transaction_date'])?></td></tr>
          <tr><td class="text-muted small">Lokasi Asal</td><td class="fw-semibold"><?=htmlspecialchars($header['location_name'])?></td></tr>
          <tr><td class="text-muted small">Penerima</td><td><?=htmlspecialchars($header['recipient']??'-')?></td></tr>
          <tr><td class="text-muted small">Keperluan</td><td><?=htmlspecialchars($header['purpose']??'-')?></td></tr>
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
      <div class="card-header bg-white fw-semibold">Daftar Barang Keluar</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>#</th><th>Kode</th><th>Nama Barang</th><th class="text-center">Qty</th><th class="text-end">Harga Jual</th><th class="text-end">Subtotal</th></tr></thead>
          <tbody>
          <?php foreach($details as $i=>$d):?>
          <tr>
            <td class="small text-muted"><?=$i+1?></td>
            <td><code class="small"><?=htmlspecialchars($d['item_code'])?></code></td>
            <td><?=htmlspecialchars($d['item_name'])?></td>
            <td class="text-center"><?=$d['quantity']?> <?=htmlspecialchars($d['abbreviation'])?></td>
            <td class="text-end small"><?=idr((float)$d['sell_price'])?></td>
            <td class="text-end fw-semibold"><?=idr($d['quantity']*$d['sell_price'])?></td>
          </tr>
          <?php endforeach;?>
          <tr class="table-light fw-bold">
            <td colspan="3" class="text-end">Total</td>
            <td class="text-center"><?=$total_qty?></td>
            <td colspan="2"></td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__.'/../../includes/footer.php';?>
