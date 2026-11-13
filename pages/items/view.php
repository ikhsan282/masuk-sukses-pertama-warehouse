<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('items.view');
$db = getDB();

$id = req_int('id');
$comp_id = current_company_id();
$comp_code = current_company()['code'] ?? 'MSP';
$st = $db->prepare('SELECT i.*, c.name AS cat_name, u.name AS unit_name, u.abbreviation
    FROM items i
    JOIN categories c ON c.id=i.category_id
    JOIN units u ON u.id=i.unit_id
    WHERE i.id=?');
$st->bind_param('i', $id); $st->execute();
$item = $st->get_result()->fetch_assoc(); $st->close();
if (!$item) { set_flash('error','Barang tidak ditemukan.'); redirect(APP_URL.'/pages/items/index.php'); }

// Stock per location (for active company)
$stock_rows = $db->prepare('SELECT l.code, l.name, s.quantity FROM stock s
    JOIN locations l ON l.id=s.location_id WHERE s.item_id=? AND s.company_id=? AND s.quantity>0 ORDER BY l.code');
$stock_rows->bind_param('ii', $id, $comp_id); $stock_rows->execute();
$stocks = $stock_rows->get_result()->fetch_all(MYSQLI_ASSOC); $stock_rows->close();

// Stock breakdown per PT (within user's assigned companies)
$allowed_ids = array_values(array_filter(array_map(fn($c) => (int)$c['id'], user_companies())));
$pt_stocks = [];
if (count($allowed_ids) > 1) {
    $in_sql = implode(',', $allowed_ids);
    $pt_st = $db->prepare("SELECT c.id, c.code, c.name, COALESCE(SUM(s.quantity),0) AS qty
        FROM companies c
        LEFT JOIN stock s ON s.company_id=c.id AND s.item_id=?
        WHERE c.id IN ($in_sql) AND c.is_active=1
        GROUP BY c.id, c.code, c.name
        ORDER BY c.is_holding DESC, c.id ASC");
    $pt_st->bind_param('i', $id);
    $pt_st->execute();
    $pt_stocks = $pt_st->get_result()->fetch_all(MYSQLI_ASSOC);
    $pt_st->close();
}

// Recent mutations (for active company)
$mut = $db->prepare('SELECT m.*, l.name AS loc_name FROM mutations m
    JOIN locations l ON l.id=m.location_id WHERE m.item_id=? AND m.company_id=? ORDER BY m.id DESC LIMIT 15');
$mut->bind_param('ii', $id, $comp_id); $mut->execute();
$mutations = $mut->get_result()->fetch_all(MYSQLI_ASSOC); $mut->close();

$total_stock = array_sum(array_column($stocks, 'quantity'));
$page_title = 'Detail Barang';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/items/index.php">Barang</a></li>
    <li class="breadcrumb-item active">Detail</li>
  </ol></nav>
  <div class="d-flex align-items-center justify-content-between">
    <h4><i class="bi bi-box-seam me-2 text-primary"></i><?= htmlspecialchars($item['name']) ?></h4>
    <?php if (can('items.edit')): ?>
    <a href="<?= APP_URL ?>/pages/items/edit.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-pencil me-1"></i>Edit
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-2"></i>Informasi Barang</div>
      <div class="card-body">
        <?php if (!empty($item['image'])): ?>
          <div class="text-center mb-3">
            <img src="<?= APP_URL ?>/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>"
                 class="img-fluid rounded border" style="max-height:260px;object-fit:contain">
          </div>
        <?php endif; ?>
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="text-muted small" style="width:40%">Kode</td><td><code><?= htmlspecialchars($item['code']) ?></code></td></tr>
          <tr><td class="text-muted small">Nama</td><td class="fw-semibold"><?= htmlspecialchars($item['name']) ?></td></tr>
          <tr><td class="text-muted small">Kategori</td><td><?= htmlspecialchars($item['cat_name']) ?></td></tr>
          <tr><td class="text-muted small">Satuan</td><td><?= htmlspecialchars($item['unit_name']) ?> (<?= htmlspecialchars($item['abbreviation']) ?>)</td></tr>
          <tr><td class="text-muted small">Stok Minimum</td><td><?= $item['min_stock'] ?></td></tr>
          <tr><td class="text-muted small">Harga Beli</td><td><?= idr((float)$item['buy_price']) ?></td></tr>
          <tr><td class="text-muted small">Harga Jual</td><td><?= idr((float)$item['sell_price']) ?></td></tr>
          <tr><td class="text-muted small">Status</td><td><?= $item['is_active'] ? '<span class="badge badge-ok">Aktif</span>' : '<span class="badge badge-zero">Nonaktif</span>' ?></td></tr>
          <?php if ($item['description']): ?>
          <tr><td class="text-muted small">Deskripsi</td><td class="small"><?= htmlspecialchars($item['description']) ?></td></tr>
          <?php endif; ?>
        </table>
      </div>
    </div>

    <div class="card shadow-sm mt-3">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-geo-alt me-2"></i>Stok <?= htmlspecialchars($comp_code) ?> per Lokasi</div>
      <?php if (empty($stocks)): ?>
        <div class="card-body text-center text-muted py-3 small">Belum ada stok di PT <?= htmlspecialchars($comp_code) ?></div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Lokasi</th><th class="text-end">Qty</th></tr></thead>
          <tbody>
          <?php foreach ($stocks as $s): ?>
            <tr><td><?= htmlspecialchars($s['name']) ?> <code class="small"><?= htmlspecialchars($s['code']) ?></code></td>
            <td class="text-end fw-bold"><?= $s['quantity'] ?></td></tr>
          <?php endforeach; ?>
          <tr class="table-light"><td class="fw-bold">Total (<?= htmlspecialchars($comp_code) ?>)</td><td class="text-end fw-bold">
            <span class="badge <?= $total_stock==0?'badge-zero':($total_stock<=$item['min_stock']?'badge-low':'badge-ok') ?>">
              <?= $total_stock ?> <?= htmlspecialchars($item['abbreviation']) ?>
            </span></td></tr>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($pt_stocks)): $total_group_stock = array_sum(array_column($pt_stocks, 'qty')); ?>
    <div class="card shadow-sm mt-3">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-buildings me-2"></i>Kepemilikan Stok per PT</span>
        <?php if (can('transfers.create')): ?>
        <a href="<?= APP_URL ?>/pages/transfers/create.php" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size:.75rem">Transfer Antar-PT</a>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Perusahaan (PT)</th><th class="text-end">Stok</th></tr></thead>
          <tbody>
          <?php foreach ($pt_stocks as $ps): ?>
            <tr class="<?= (int)$ps['id'] === $comp_id ? 'table-success bg-opacity-10' : '' ?>">
              <td><code class="small"><?= htmlspecialchars($ps['code']) ?></code> <?= htmlspecialchars($ps['name']) ?></td>
              <td class="text-end fw-semibold"><?= number_format((int)$ps['qty']) ?> <?= htmlspecialchars($item['abbreviation']) ?></td>
            </tr>
          <?php endforeach; ?>
          <tr class="table-light">
            <td class="fw-bold">Total Konsolidasi Grup</td>
            <td class="text-end fw-bold"><?= number_format($total_group_stock) ?> <?= htmlspecialchars($item['abbreviation']) ?></td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-2"></i>Riwayat Mutasi (15 terbaru)</div>
      <?php if (empty($mutations)): ?>
        <div class="card-body text-center text-muted py-3 small">Belum ada mutasi</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Waktu</th><th>Tipe</th><th>Lokasi</th><th>Referensi</th><th class="text-end">Qty</th></tr></thead>
          <tbody>
          <?php foreach ($mutations as $m):
            $map=['in'=>['Masuk','success'],'out'=>['Keluar','danger'],'transfer_in'=>['T.Masuk','info'],'transfer_out'=>['T.Keluar','warning']];
            [$lbl,$col]=$map[$m['type']]??['?','secondary'];
          ?>
            <tr>
              <td class="small text-muted"><?= date('d/m/y H:i', strtotime($m['created_at'])) ?></td>
              <td><span class="badge bg-<?= $col ?>-subtle text-<?= $col ?> border"><?= $lbl ?></span></td>
              <td class="small"><?= htmlspecialchars($m['loc_name']) ?></td>
              <td><code class="small"><?= htmlspecialchars($m['reference_no']) ?></code></td>
              <td class="text-end fw-semibold"><?= $m['quantity'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
