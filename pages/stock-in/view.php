<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('stock_in.view');
$db = getDB();

$id = req_int('id');
$st = $db->prepare('SELECT si.*, s.name AS supplier_name, s.phone AS supplier_phone,
    l.name AS location_name, u.name AS user_name, c.code AS comp_code, c.name AS comp_name
    FROM stock_in si JOIN suppliers s ON s.id=si.supplier_id
    JOIN locations l ON l.id=si.location_id JOIN users u ON u.id=si.user_id
    LEFT JOIN companies c ON c.id=si.company_id WHERE si.id=?');
$st->bind_param('i',$id); $st->execute();
$header = $st->get_result()->fetch_assoc(); $st->close();
if (!$header) { set_flash('error','Data tidak ditemukan.'); redirect(APP_URL.'/pages/stock-in/index.php'); }

$st2 = $db->prepare('SELECT d.*, i.code AS item_code, i.name AS item_name, u.abbreviation
    FROM stock_in_details d JOIN items i ON i.id=d.item_id JOIN units u ON u.id=i.unit_id
    WHERE d.stock_in_id=?');
$st2->bind_param('i',$id); $st2->execute();
$details = $st2->get_result()->fetch_all(MYSQLI_ASSOC); $st2->close();

$total_value = array_sum(array_map(fn($d) => $d['quantity']*$d['buy_price'], $details));
$page_title='Detail Barang Masuk'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?=APP_URL?>/pages/stock-in/index.php">Barang Masuk</a></li>
    <li class="breadcrumb-item active">Detail</li>
  </ol></nav>
  <div class="d-flex align-items-center justify-content-between">
    <h4><i class="bi bi-box-arrow-in-down me-2 text-primary"></i>Detail Barang Masuk</h4>
    <div class="d-flex gap-2">
      <a href="<?=APP_URL?>/pages/stock-in/print.php?id=<?=$header['id']?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Cetak</a>
      <?php if(can('stock_in.delete')):?>
      <form method="POST" action="<?=APP_URL?>/pages/stock-in/delete.php" class="d-inline">
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
          <tr><td class="text-muted small" style="width:45%">No. Referensi</td>
              <td><code class="fw-bold"><?=htmlspecialchars($header['reference_no'])?></code></td></tr>
          <tr><td class="text-muted small">Perusahaan (PT)</td>
              <td><span class="badge bg-primary-subtle text-primary border"><?=htmlspecialchars(($header['comp_code'] ?? 'MSP') . ' — ' . ($header['comp_name'] ?? ''))?></span></td></tr>
          <tr><td class="text-muted small">Tanggal</td><td><?=tgl($header['transaction_date'])?></td></tr>
          <tr><td class="text-muted small">Supplier</td><td class="fw-semibold"><?=htmlspecialchars($header['supplier_name'])?></td></tr>
          <tr><td class="text-muted small">Telepon</td><td><?=htmlspecialchars($header['supplier_phone']??'-')?></td></tr>
          <tr><td class="text-muted small">Lokasi</td><td><?=htmlspecialchars($header['location_name'])?></td></tr>
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
      <div class="card-header bg-white fw-semibold">Daftar Barang</div>
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
            <td colspan="5" class="text-end">Total Nilai</td>
            <td class="text-end text-primary"><?=idr($total_value)?></td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__.'/../../includes/footer.php';?>
