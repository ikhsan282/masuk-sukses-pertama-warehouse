<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('transfers.view');
$db = getDB();
$id = req_int('id');
$st = $db->prepare('SELECT t.*, fl.name AS from_loc, tl.name AS to_loc, u.name AS user_name,
    fc.code AS from_comp_code, fc.name AS from_comp_name, tc.code AS to_comp_code, tc.name AS to_comp_name
    FROM transfers t JOIN locations fl ON fl.id=t.from_location_id
    JOIN locations tl ON tl.id=t.to_location_id JOIN users u ON u.id=t.user_id
    LEFT JOIN companies fc ON fc.id=t.from_company_id
    LEFT JOIN companies tc ON tc.id=t.to_company_id WHERE t.id=?');
$st->bind_param('i',$id); $st->execute();
$header=$st->get_result()->fetch_assoc(); $st->close();
if (!$header) { set_flash('error','Data tidak ditemukan.'); redirect(APP_URL.'/pages/transfers/index.php'); }
require_any_company_access([(int)$header['from_company_id'], (int)$header['to_company_id']]);

$st2=$db->prepare('SELECT d.*, i.code AS item_code, i.name AS item_name, u.abbreviation
    FROM transfer_details d JOIN items i ON i.id=d.item_id JOIN units u ON u.id=i.unit_id WHERE d.transfer_id=?');
$st2->bind_param('i',$id); $st2->execute();
$details=$st2->get_result()->fetch_all(MYSQLI_ASSOC); $st2->close();

$page_title='Detail Transfer'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?=APP_URL?>/pages/transfers/index.php">Transfer</a></li>
    <li class="breadcrumb-item active">Detail</li>
  </ol></nav>
  <div class="d-flex align-items-center justify-content-between">
    <h4><i class="bi bi-arrow-left-right me-2 text-primary"></i>Detail Transfer</h4>
    <div class="d-flex gap-2">
      <a href="<?=APP_URL?>/pages/transfers/print.php?id=<?=$header['id']?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Cetak</a>
      <?php if(can('transfers.delete')):?>
      <form method="POST" action="<?=APP_URL?>/pages/transfers/delete.php" class="d-inline">
        <?=csrf_field()?><input type="hidden" name="id" value="<?=$header['id']?>">
        <button class="btn btn-sm btn-outline-danger" data-confirm="Hapus transfer ini?"><i class="bi bi-trash me-1"></i>Hapus</button>
      </form>
      <?php endif;?>
    </div>
  </div>
</div>
<div class="row g-3">
  <div class="col-md-5">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold">Informasi Transfer</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="text-muted small" style="width:40%">No. Referensi</td><td><code class="fw-bold"><?=htmlspecialchars($header['reference_no'])?></code></td></tr>
          <tr><td class="text-muted small">Dari PT</td><td><span class="badge bg-primary-subtle text-primary border"><?=htmlspecialchars(($header['from_comp_code'] ?? 'MSP') . ' — ' . ($header['from_comp_name'] ?? ''))?></span></td></tr>
          <?php if (($header['from_company_id'] ?? 1) != ($header['to_company_id'] ?? 1)): ?>
          <tr><td class="text-muted small">Ke PT</td><td><span class="badge bg-success-subtle text-success border"><?=htmlspecialchars(($header['to_comp_code'] ?? 'MSP') . ' — ' . ($header['to_comp_name'] ?? ''))?></span></td></tr>
          <?php endif; ?>
          <tr><td class="text-muted small">Tanggal</td><td><?=tgl($header['transaction_date'])?></td></tr>
          <tr><td class="text-muted small">Dari Lokasi</td><td class="fw-semibold text-danger"><?=htmlspecialchars($header['from_loc'])?></td></tr>
          <tr><td class="text-muted small">Ke Lokasi</td><td class="fw-semibold text-success"><?=htmlspecialchars($header['to_loc'])?></td></tr>
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
          <thead><tr><th>#</th><th>Kode</th><th>Nama Barang</th><th class="text-center">Qty</th></tr></thead>
          <tbody>
          <?php foreach($details as $i=>$d):?>
          <tr>
            <td class="small text-muted"><?=$i+1?></td>
            <td><code class="small"><?=htmlspecialchars($d['item_code'])?></code></td>
            <td><?=htmlspecialchars($d['item_name'])?></td>
            <td class="text-center fw-semibold"><?=$d['quantity']?> <?=htmlspecialchars($d['abbreviation'])?></td>
          </tr>
          <?php endforeach;?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__.'/../../includes/footer.php';?>
