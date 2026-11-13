<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_perm('stock_opname.view');
$db = getDB();

$status     = req_str('status');
$page       = max(1, req_int('page', 1));
$per_page   = grid_per_page();
grid_apply_timeout($db);
$active_cid = current_company_id();
$where_parts = ['o.company_id=?'];
$params = [&$active_cid];
$types = 'i';
if (in_array($status, ['draft', 'finalized'], true)) {
    $where_parts[] = 'o.status=?';
    $params[] = &$status;
    $types .= 's';
}
$where = implode(' AND ', $where_parts);

$cq = $db->prepare("SELECT COUNT(*) FROM stock_opname o WHERE $where");
$cq->bind_param($types, ...$params);
$cq->execute(); $cq->bind_result($total); $cq->fetch(); $cq->close();

$pag = paginate($total, $page, $per_page);
$offset = $pag['offset']; $limit = $pag['per_page'];
$t2 = $types . 'ii';
$p2 = $params; $p2[] = &$limit; $p2[] = &$offset;

$st = $db->prepare("SELECT o.*, u.name user_name, fu.name finalized_name,
    (SELECT COUNT(*) FROM stock_opname_items WHERE session_id=o.session_id) item_count,
    (SELECT COUNT(*) FROM stock_opname_items WHERE session_id=o.session_id AND variance!=0) changed_count
    FROM (SELECT o.session_id FROM stock_opname o WHERE $where ORDER BY o.session_id DESC LIMIT ? OFFSET ?) pg
    JOIN stock_opname o ON o.session_id=pg.session_id
    JOIN users u ON u.id=o.user_id LEFT JOIN users fu ON fu.id=o.finalized_by
    ORDER BY o.session_id DESC");
$st->bind_param($t2, ...$p2);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$page_title = 'Stock Opname';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
  <h4><i class="bi bi-upc-scan me-2 text-primary"></i>Stock Opname</h4>
  <?php if (can('stock_opname.create')): ?>
    <a href="create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Buat Sesi Baru</a>
  <?php endif; ?>
</div>

<div class="card table-card">
  <div class="card-header bg-white">
    <form method="get" class="d-flex gap-2">
      <select name="status" class="form-select form-select-sm" style="max-width:180px">
        <option value="">Semua status</option>
        <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
        <option value="finalized" <?= $status === 'finalized' ? 'selected' : '' ?>>Finalized</option>
      </select>
      <input type="hidden" name="per_page" value="<?= (int)$pag['per_page'] ?>">
      <button class="btn btn-sm btn-outline-secondary">Filter</button>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr>
          <th>Sesi</th>
          <th>Tanggal</th>
          <th>Dibuat oleh</th>
          <th>Item</th>
          <th>Berselisih</th>
          <th>Status</th>
          <th class="text-center">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">Belum ada sesi stock opname</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><strong>#<?= (int)$r['session_id'] ?></strong></td>
            <td><?= tgl($r['opname_date']) ?></td>
            <td><?= e($r['user_name']) ?></td>
            <td><?= (int)$r['item_count'] ?></td>
            <td><span class="<?= (int)$r['changed_count'] > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>"><?= (int)$r['changed_count'] ?></span></td>
            <td><span class="badge <?= $r['status'] === 'finalized' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $r['status'] === 'finalized' ? 'Finalized' : 'Draft' ?></span></td>
            <td class="text-center text-nowrap">
              <a href="report.php?id=<?= $r['session_id'] ?>" class="btn btn-action btn-outline-info" title="Lihat variance"><i class="bi bi-clipboard-data"></i></a>
              <?php if ($r['status'] === 'draft' && can('stock_opname.count')): ?>
                <a href="count.php?id=<?= $r['session_id'] ?>" class="btn btn-action btn-outline-primary" title="Lanjut hitung"><i class="bi bi-upc-scan"></i></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'sesi opname', ['status' => $status]) ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
