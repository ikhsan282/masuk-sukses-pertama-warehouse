<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('transfers.view');
$db = getDB();

$search     = grid_search_term('q');
$date_from  = req_date('date_from');
$date_to    = req_date('date_to');
$page       = max(1, req_int('page', 1));
$per_page   = grid_per_page();
grid_apply_timeout($db);
$active_cid = current_company_id();

$extra_parts = [];
$extra_types = '';
$extra_vals  = [];
if ($search !== '') {
    $extra_parts[] = 't.reference_no LIKE ?';
    $extra_types  .= 's';
    $extra_vals[]  = '%' . escape_like($search) . '%';
}
if ($date_from !== '') {
    $extra_parts[] = 't.transaction_date >= ?';
    $extra_types  .= 's';
    $extra_vals[]  = $date_from;
}
if ($date_to !== '') {
    $extra_parts[] = 't.transaction_date <= ?';
    $extra_types  .= 's';
    $extra_vals[]  = $date_to;
}
$extra_sql = $extra_parts ? (' AND ' . implode(' AND ', $extra_parts)) : '';

$cq = $db->prepare("SELECT (SELECT COUNT(*) FROM transfers t WHERE t.from_company_id=?{$extra_sql}) + (SELECT COUNT(*) FROM transfers t WHERE t.to_company_id=? AND t.from_company_id<>?{$extra_sql})");
$cq_types = 'i' . $extra_types . 'ii' . $extra_types;
$cq_vals  = array_merge([$active_cid], $extra_vals, [$active_cid, $active_cid], $extra_vals);
$cq->bind_param($cq_types, ...$cq_vals);
$cq->execute();
$total = (int)$cq->get_result()->fetch_row()[0];
$cq->close();

$pag = paginate($total, $page, $per_page); $offset = $pag['offset']; $limit = $pag['per_page'];
$inner_limit = $limit + $offset;
$st = $db->prepare("SELECT t.*, fl.name AS from_loc, tl.name AS to_loc, u.name AS user_name,
    fc.code AS from_comp_code, tc.code AS to_comp_code,
    (SELECT COUNT(*) FROM transfer_details d WHERE d.transfer_id=t.id) AS item_count
    FROM (
        SELECT id FROM (
            (SELECT t.id FROM transfers t WHERE t.from_company_id=?{$extra_sql} ORDER BY t.id DESC LIMIT ?)
            UNION
            (SELECT t.id FROM transfers t WHERE t.to_company_id=?{$extra_sql} ORDER BY t.id DESC LIMIT ?)
        ) combined ORDER BY id DESC LIMIT ? OFFSET ?
    ) pg
    JOIN transfers t ON t.id=pg.id
    JOIN locations fl ON fl.id=t.from_location_id
    JOIN locations tl ON tl.id=t.to_location_id
    JOIN users u ON u.id=t.user_id
    LEFT JOIN companies fc ON fc.id=t.from_company_id
    LEFT JOIN companies tc ON tc.id=t.to_company_id
    ORDER BY t.id DESC");
$dq_types = 'i' . $extra_types . 'ii' . $extra_types . 'iii';
$dq_vals  = array_merge([$active_cid], $extra_vals, [$inner_limit, $active_cid], $extra_vals, [$inner_limit, $limit, $offset]);
$st->bind_param($dq_types, ...$dq_vals); $st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$page_title='Transfer Lokasi'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-arrow-left-right me-2 text-primary"></i>Transfer Antar Lokasi</h4>
  <?php if(can('transfers.create')):?>
  <a href="<?=APP_URL?>/pages/transfers/create.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Buat Transfer
  </a>
  <?php endif;?>
</div>
<div class="card table-card">
  <div class="card-header bg-white grid-toolbar">
    <form class="grid-filter-form" method="GET">
      <?= grid_filter_search_html($search, 'Cari no. referensi...') ?>
      <?= grid_filter_date_range_html('date_from', $date_from, 'date_to', $date_to) ?>
      <?= grid_filter_actions_html($pag['per_page'], (bool)($search || $date_from || $date_to)) ?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>No. Referensi</th><th>Tanggal</th><th>Dari</th><th>Ke</th><th class="text-center">Item</th><th>Oleh</th><th class="text-center">Aksi</th></tr></thead>
      <tbody>
      <?php if(empty($rows)):?>
        <tr><td colspan="7" class="text-center text-muted py-4">Belum ada transfer</td></tr>
      <?php else: foreach($rows as $r):?>
        <tr>
          <td><code class="small"><?=htmlspecialchars($r['reference_no'])?></code></td>
          <td class="small"><?=tgl($r['transaction_date'])?></td>
          <td class="small">
            <?=htmlspecialchars($r['from_loc'])?>
            <?php if ($r['from_company_id'] != $r['to_company_id']): ?>
              <span class="badge bg-secondary-subtle text-secondary border ms-1"><?=htmlspecialchars($r['from_comp_code'] ?? '')?></span>
            <?php endif; ?>
          </td>
          <td class="small">
            <?=htmlspecialchars($r['to_loc'])?>
            <?php if ($r['from_company_id'] != $r['to_company_id']): ?>
              <span class="badge bg-primary-subtle text-primary border ms-1"><?=htmlspecialchars($r['to_comp_code'] ?? '')?></span>
            <?php endif; ?>
          </td>
          <td class="text-center"><span class="badge bg-info-subtle text-info"><?=$r['item_count']?> item</span></td>
          <td class="small text-muted"><?=htmlspecialchars($r['user_name'])?></td>
          <td class="text-center text-nowrap">
            <a href="<?=APP_URL?>/pages/transfers/view.php?id=<?=$r['id']?>" class="btn btn-action btn-outline-info"><i class="bi bi-eye"></i></a>
            <?php if(can('transfers.delete')):?>
            <form method="POST" action="<?=APP_URL?>/pages/transfers/delete.php" class="d-inline">
              <?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-action btn-outline-danger" data-confirm="Hapus transfer ini? Stok akan dikembalikan."><i class="bi bi-trash"></i></button>
            </form>
            <?php endif;?>
          </td>
        </tr>
      <?php endforeach;endif;?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'transfer', ['q' => $search, 'date_from' => $date_from, 'date_to' => $date_to]) ?>
</div>
<?php include __DIR__.'/../../includes/footer.php';?>
