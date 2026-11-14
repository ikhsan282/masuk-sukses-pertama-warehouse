<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('stock.alerts');
$db = getDB();
grid_apply_timeout($db);

$comp_id  = (int)current_company_id();
$search   = grid_search_term('q');
$page     = max(1, req_int('page', 1));
$per_page = grid_per_page();

$where_parts = [
    'i.is_active=1',
    "COALESCE((SELECT SUM(s.quantity) FROM stock s WHERE s.item_id=i.id AND s.company_id={$comp_id}), 0) <= i.min_stock"
];
$params = [];
$types  = '';
if ($search) {
    $where_parts[] = '(i.code LIKE ? OR i.name LIKE ?)';
    $like = '%' . escape_like($search) . '%';
    $params[] = &$like;
    $params[] = &$like;
    $types .= 'ss';
}
$where = 'WHERE ' . implode(' AND ', $where_parts);

$cq = $db->prepare("SELECT COUNT(*) FROM items i $where");
if ($types) $cq->bind_param($types, ...$params);
$cq->execute(); $cq->bind_result($total); $cq->fetch(); $cq->close();

$pag    = paginate($total, $page, $per_page);
$offset = $pag['offset'];
$limit  = $pag['per_page'];
$t2 = $types . 'ii';
$p2 = $params;
$p2[] = &$limit;
$p2[] = &$offset;

$st = $db->prepare("SELECT i.id, i.code, i.name, i.min_stock, i.buy_price, u.abbreviation,
    COALESCE((SELECT SUM(s.quantity) FROM stock s WHERE s.item_id=i.id AND s.company_id={$comp_id}), 0) AS total_stock,
    GREATEST(i.min_stock*2 - COALESCE((SELECT SUM(s.quantity) FROM stock s WHERE s.item_id=i.id AND s.company_id={$comp_id}), 0), 1) AS suggested_qty
    FROM items i
    JOIN units u ON u.id=i.unit_id
    $where
    ORDER BY total_stock ASC, i.name ASC
    LIMIT ? OFFSET ?");
$st->bind_param($t2, ...$p2);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$page_title = 'Peringatan Stok';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
  <div>
    <h4><i class="bi bi-bell me-2 text-warning"></i>Peringatan Stok</h4>
    <p class="text-muted small mb-0">Saran pengadaan menaikkan stok ke dua kali stok minimum.</p>
  </div>
  <?php if ($total > 0 && can('purchase_orders.create')): ?>
    <a href="<?= APP_URL ?>/pages/purchase-orders/form.php?suggested=1" class="btn btn-primary btn-sm">
      <i class="bi bi-cart-plus me-1"></i>Buat PO dari Saran
    </a>
  <?php endif; ?>
</div>
<div class="card table-card">
  <div class="card-header bg-white grid-toolbar">
    <form class="grid-filter-form" method="GET">
      <?= grid_filter_search_html($search, 'Cari kode / nama barang...') ?>
      <?= grid_filter_actions_html($pag['per_page'], (bool)$search, 'Cari', 'bi-search') ?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Kode</th><th>Barang</th><th class="text-end">Stok</th><th class="text-end">Minimum</th><th class="text-end">Saran Pesan</th><th class="text-end">Estimasi</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-check-circle text-success fs-2"></i><br>Semua stok aman</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><code><?= e($r['code']) ?></code></td>
          <td><a href="<?= APP_URL ?>/pages/items/view.php?id=<?= $r['id'] ?>"><?= e($r['name']) ?></a></td>
          <td class="text-end"><span class="badge <?= $r['total_stock'] == 0 ? 'badge-zero' : 'badge-low' ?>"><?= $r['total_stock'] ?> <?= e($r['abbreviation']) ?></span></td>
          <td class="text-end"><?= $r['min_stock'] ?></td>
          <td class="text-end fw-bold"><?= $r['suggested_qty'] ?> <?= e($r['abbreviation']) ?></td>
          <td class="text-end"><?= idr($r['suggested_qty'] * $r['buy_price']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'peringatan stok', ['q' => $search]) ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
