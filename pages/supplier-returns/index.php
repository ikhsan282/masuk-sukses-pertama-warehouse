<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('supplier_returns.view');
$db = getDB();

$search     = grid_search_term('q');
$date_from  = req_date('date_from');
$date_to    = req_date('date_to');
$page       = max(1, req_int('page', 1));
$per_page   = grid_per_page();
grid_apply_timeout($db);
$active_cid = current_company_id();

$where_parts = ['sr.company_id = ?']; $params=[&$active_cid]; $types='i';
if ($search)    { $where_parts[]='sr.reference_no LIKE ?'; $like='%'.escape_like($search).'%'; $params[]=&$like; $types.='s'; }
if ($date_from) { $where_parts[]='sr.transaction_date >= ?'; $params[]=&$date_from; $types.='s'; }
if ($date_to)   { $where_parts[]='sr.transaction_date <= ?'; $params[]=&$date_to; $types.='s'; }
$where='WHERE '.implode(' AND ',$where_parts);

$cq=$db->prepare("SELECT COUNT(*) FROM supplier_returns sr $where");
if($types)$cq->bind_param($types,...$params);
$cq->execute();$cq->bind_result($total);$cq->fetch();$cq->close();

$pag=paginate($total,$page,$per_page); $offset=$pag['offset']; $limit=$pag['per_page'];
$t2=$types.'ii'; $p2=$params; $p2[]=&$limit; $p2[]=&$offset;
$st=$db->prepare("SELECT sr.*, s.name AS supplier_name, l.name AS location_name, u.name AS user_name,
    (SELECT COUNT(*) FROM supplier_return_details d WHERE d.return_id=sr.id) AS item_count,
    (SELECT SUM(d.quantity) FROM supplier_return_details d WHERE d.return_id=sr.id) AS total_qty
    FROM (SELECT sr.id FROM supplier_returns sr $where ORDER BY sr.id DESC LIMIT ? OFFSET ?) pg
    JOIN supplier_returns sr ON sr.id=pg.id
    JOIN suppliers s ON s.id=sr.supplier_id
    JOIN locations l ON l.id=sr.location_id JOIN users u ON u.id=sr.user_id
    ORDER BY sr.id DESC");
$st->bind_param($t2,...$p2); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$page_title='Retur Supplier'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-arrow-return-left me-2 text-primary"></i>Retur Supplier</h4>
  <?php if(can('supplier_returns.create')):?>
  <a href="<?=APP_URL?>/pages/supplier-returns/create.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Buat Retur
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
      <thead><tr><th>No. Referensi</th><th>Tanggal</th><th>Supplier</th><th>Lokasi</th><th>Alasan</th><th class="text-center">Item</th><th>Status</th><th>Oleh</th><th class="text-center">Aksi</th></tr></thead>
      <tbody>
      <?php if(empty($rows)):?>
        <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach($rows as $r):?>
        <tr>
          <td><code class="small"><?=htmlspecialchars($r['reference_no'])?></code></td>
          <td class="small"><?=tgl($r['transaction_date'])?></td>
          <td class="small"><?=htmlspecialchars($r['supplier_name'])?></td>
          <td class="small"><?=htmlspecialchars($r['location_name'])?></td>
          <td class="small text-muted"><?=htmlspecialchars($r['reason'])?></td>
          <td class="text-center"><span class="badge bg-warning-subtle text-warning"><?=$r['item_count']?> item</span></td>
          <td><span class="badge bg-<?=$r['status']==='completed'?'success':'secondary'?>"><?=ucfirst($r['status'])?></span></td>
          <td class="small text-muted"><?=htmlspecialchars($r['user_name'])?></td>
          <td class="text-center">
            <a href="<?=APP_URL?>/pages/supplier-returns/view.php?id=<?=$r['id']?>" class="btn btn-action btn-outline-info"><i class="bi bi-eye"></i></a>
          </td>
        </tr>
      <?php endforeach;endif;?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'retur', ['q' => $search, 'date_from' => $date_from, 'date_to' => $date_to]) ?>
</div>
<?php include __DIR__.'/../../includes/footer.php';?>
