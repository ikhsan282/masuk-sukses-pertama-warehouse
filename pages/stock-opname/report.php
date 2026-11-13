<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/stock_opname.php';

require_perm('stock_opname.view');
$db = getDB();
$id = req_int('id');

$head = $db->prepare('SELECT o.*,u.name user_name,fu.name finalized_name FROM stock_opname o JOIN users u ON u.id=o.user_id LEFT JOIN users fu ON fu.id=o.finalized_by WHERE o.session_id=?');
$head->bind_param('i', $id);
$head->execute();
$opname = $head->get_result()->fetch_assoc();
$head->close();
if (!$opname) { set_flash('error', 'Sesi stock opname tidak ditemukan.'); redirect(APP_URL . '/pages/stock-opname/index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_perm('stock_opname.finalize');
    verify_csrf();
    try {
        $adjustments = finalize_stock_opname($db, $id, current_user()['id']);
        set_flash('success', 'Stock opname difinalisasi. ' . count($adjustments) . ' penyesuaian stok dan mutasi telah diposting.');
    } catch (Throwable $e) { set_flash('error', 'Finalisasi gagal: ' . $e->getMessage()); }
    redirect(APP_URL . '/pages/stock-opname/report.php?id=' . $id);
}

$page     = max(1, req_int('page', 1));
$per_page = grid_per_page();
grid_apply_timeout($db);

$agg = $db->prepare('SELECT COUNT(*), COALESCE(SUM(variance != 0),0), COALESCE(SUM(variance),0) FROM stock_opname_items WHERE session_id=?');
$agg->bind_param('i', $id);
$agg->execute();
$agg->bind_result($total, $changed, $net);
$agg->fetch();
$agg->close();
$total   = (int)$total;
$changed = (int)$changed;
$net     = (int)$net;

$pag    = paginate($total, $page, $per_page);
$offset = $pag['offset'];
$limit  = $pag['per_page'];

$stmt = $db->prepare('SELECT oi.*,i.code,i.name,u.abbreviation,l.code location_code,l.name location_name
    FROM stock_opname_items oi JOIN items i ON i.id=oi.item_id JOIN units u ON u.id=i.unit_id
    JOIN locations l ON l.id=oi.location_id WHERE oi.session_id=? ORDER BY l.code,i.name LIMIT ? OFFSET ?');
$stmt->bind_param('iii', $id, $limit, $offset);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$adjustments = [];
if ($opname['status'] === 'finalized') {
    $adj = $db->prepare('SELECT id,reference_no FROM stock_adjustments WHERE opname_session_id=? ORDER BY id');
    $adj->bind_param('i', $id); $adj->execute();
    $adjustments = $adj->get_result()->fetch_all(MYSQLI_ASSOC); $adj->close();
}

$page_title = 'Variance Stock Opname';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-start">
  <div>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1"><li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/stock-opname/index.php">Stock Opname</a></li><li class="breadcrumb-item active">Variance #<?= $id ?></li></ol></nav>
    <h4><i class="bi bi-clipboard-data me-2 text-primary"></i>Laporan Variance #<?= $id ?></h4>
    <div class="small text-muted"><?= tgl($opname['opname_date']) ?> &middot; <?= e($opname['user_name']) ?></div>
  </div>
  <span class="badge <?= $opname['status'] === 'finalized' ? 'bg-success' : 'bg-warning text-dark' ?> fs-6"><?= $opname['status'] === 'finalized' ? 'Finalized' : 'Draft' ?></span>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Item Dihitung</div><div class="fs-3 fw-bold"><?= number_format($total, 0, ',', '.') ?></div></div></div></div>
  <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Item Berselisih</div><div class="fs-3 fw-bold text-warning"><?= number_format($changed, 0, ',', '.') ?></div></div></div></div>
  <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="small text-muted">Selisih Bersih</div><div class="fs-3 fw-bold <?= $net < 0 ? 'text-danger' : ($net > 0 ? 'text-success' : '') ?>"><?= $net > 0 ? '+' : '' ?><?= number_format($net, 0, ',', '.') ?></div></div></div></div>
</div>

<div class="card table-card mb-3">
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Barang</th><th>Lokasi</th><th class="text-end">Sistem</th><th class="text-end">Fisik</th><th class="text-end">Variance</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">Belum ada hasil hitung</td></tr><?php endif; ?>
    <?php foreach ($rows as $row): $v=(int)$row['variance']; ?>
      <tr><td><code><?= e($row['code']) ?></code> <?= e($row['name']) ?> <small class="text-muted"><?= e($row['abbreviation']) ?></small></td><td><?= e($row['location_code']) ?> — <?= e($row['location_name']) ?></td><td class="text-end"><?= $row['system_qty'] ?></td><td class="text-end fw-semibold"><?= $row['physical_qty'] ?></td><td class="text-end fw-bold <?= $v < 0 ? 'text-danger' : ($v > 0 ? 'text-success' : 'text-muted') ?>"><?= $v > 0 ? '+' : '' ?><?= $v ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= grid_footer_html($pag, 'item opname', ['id' => $id]) ?>
</div>

<div class="d-flex gap-2 align-items-center">
  <?php if ($opname['status'] === 'draft'): ?>
    <?php if (can('stock_opname.count')): ?><a href="<?= APP_URL ?>/pages/stock-opname/count.php?id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Lanjut Hitung</a><?php endif; ?>
    <?php if (can('stock_opname.finalize') && $total > 0): ?>
    <form method="POST" class="d-inline"><input type="hidden" name="action" value="finalize"><?= csrf_field() ?><button class="btn btn-success" data-confirm="Finalisasi sesi? Stok sistem akan disesuaikan dan tindakan ini tidak dapat dibatalkan."><i class="bi bi-check-circle me-1"></i>Finalisasi & Posting</button></form>
    <?php endif; ?>
  <?php else: ?>
    <span class="text-muted small">Difinalisasi <?= date('d/m/Y H:i', strtotime($opname['finalized_at'])) ?> oleh <?= e($opname['finalized_name']) ?>.</span>
    <?php foreach ($adjustments as $a): ?><a href="<?= APP_URL ?>/pages/adjustments/view.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= e($a['reference_no']) ?></a><?php endforeach; ?>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
