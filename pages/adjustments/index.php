<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('adjustments.view');
$db = getDB();

$page = max(1, req_int('page', 1));
$comp_id = current_company_id();
$cq = $db->prepare('SELECT COUNT(*) FROM stock_adjustments WHERE company_id=?');
$cq->bind_param('i', $comp_id);
$cq->execute();
$total = $cq->get_result()->fetch_row()[0];
$cq->close();
$pag = paginate($total, $page);
$offset = $pag['offset'];
$limit = PER_PAGE;

$st = $db->prepare('SELECT a.*, l.name AS loc_name, u.name AS user_name,
    au.name AS approver_name,
    (SELECT COUNT(*) FROM stock_adjustment_details d WHERE d.adjustment_id=a.id) AS item_count
    FROM stock_adjustments a
    JOIN locations l ON l.id=a.location_id
    JOIN users u ON u.id=a.user_id
    LEFT JOIN users au ON au.id=a.approved_by
    WHERE a.company_id=?
    ORDER BY a.id DESC LIMIT ? OFFSET ?');
$st->bind_param('iii', $comp_id, $limit, $offset);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$page_title = 'Stok Opname';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-clipboard-check me-2 text-primary"></i>Stok Opname (Penyesuaian)</h4>
  <?php if (can('adjustments.create')): ?>
  <a href="<?= APP_URL ?>/pages/adjustments/create.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Buat Opname
  </a>
  <?php endif; ?>
</div>
<div class="card table-card">
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr>
        <th>No. Referensi</th>
        <th>Tanggal</th>
        <th>Lokasi</th>
        <th class="text-center">Item</th>
        <th class="text-center">Status</th>
        <th>Dibuat</th>
        <th>Disetujui</th>
        <th class="text-center">Aksi</th>
      </tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Belum ada penyesuaian stok</td></tr>
      <?php else:
        foreach ($rows as $r):
          $status_map = [
            'draft' => ['warning', 'Draft'],
            'approved' => ['success', 'Approved']
          ];
          [$badge, $label] = $status_map[$r['status']] ?? ['secondary', $r['status']];
      ?>
        <tr>
          <td><code class="small"><?= htmlspecialchars($r['reference_no']) ?></code></td>
          <td class="small"><?= tgl($r['transaction_date']) ?></td>
          <td class="small"><?= htmlspecialchars($r['loc_name']) ?></td>
          <td class="text-center"><span class="badge bg-info-subtle text-info"><?= $r['item_count'] ?> item</span></td>
          <td class="text-center"><span class="badge bg-<?= $badge ?>-subtle text-<?= $badge ?> border"><?= $label ?></span></td>
          <td class="small text-muted"><?= htmlspecialchars($r['user_name']) ?></td>
          <td class="small text-muted"><?= $r['approver_name'] ? htmlspecialchars($r['approver_name']) : '-' ?></td>
          <td class="text-center">
            <a href="<?= APP_URL ?>/pages/adjustments/view.php?id=<?= $r['id'] ?>" class="btn btn-action btn-outline-info"><i class="bi bi-eye"></i></a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex align-items-center justify-content-between py-2">
    <small class="text-muted">Menampilkan <?= count($rows) ?> dari <?= $total ?> penyesuaian</small>
    <?= pagination_html($pag, '?page=%d') ?>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
