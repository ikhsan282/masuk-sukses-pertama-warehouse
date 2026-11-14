<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('stock_out.view');
$db = getDB();

$search     = grid_search_term('q');
$date_from  = req_date('date_from');
$date_to    = req_date('date_to');
$page       = max(1, req_int('page', 1));
$per_page   = grid_per_page();
grid_apply_timeout($db);
$active_cid = current_company_id();

$where_parts = ['so.company_id = ?']; $params=[&$active_cid]; $types='i';
if ($search)    { $where_parts[]='so.reference_no LIKE ?'; $like='%'.escape_like($search).'%'; $params[]=&$like; $types.='s'; }
if ($date_from) { $where_parts[]='so.transaction_date >= ?'; $params[]=&$date_from; $types.='s'; }
if ($date_to)   { $where_parts[]='so.transaction_date <= ?'; $params[]=&$date_to; $types.='s'; }
$where='WHERE '.implode(' AND ',$where_parts);

$cq=$db->prepare("SELECT COUNT(*) FROM stock_out so $where");
if($types)$cq->bind_param($types,...$params);
$cq->execute();$cq->bind_result($total);$cq->fetch();$cq->close();

$pag=paginate($total,$page,$per_page); $offset=$pag['offset']; $limit=$pag['per_page'];
$t2=$types.'ii'; $p2=$params; $p2[]=&$limit; $p2[]=&$offset;
$st=$db->prepare("SELECT so.*, l.name AS location_name, u.name AS user_name,
    (SELECT COUNT(*) FROM stock_out_details d WHERE d.stock_out_id=so.id) AS item_count,
    (SELECT SUM(d.quantity) FROM stock_out_details d WHERE d.stock_out_id=so.id) AS total_qty
    FROM (SELECT so.id FROM stock_out so $where ORDER BY so.id DESC LIMIT ? OFFSET ?) pg
    JOIN stock_out so ON so.id=pg.id
    JOIN locations l ON l.id=so.location_id
    JOIN users u ON u.id=so.user_id
    ORDER BY so.id DESC");
$st->bind_param($t2,...$p2); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$page_title='Barang Keluar'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-box-arrow-up me-2 text-primary"></i>Barang Keluar</h4>
  <?php if(can('stock_out.create')):?>
  <a href="<?=APP_URL?>/pages/stock-out/create.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Input Barang Keluar
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
      <thead><tr><th>No. Referensi</th><th>Tanggal</th><th>Lokasi</th><th>Penerima</th><th>Keperluan</th><th class="text-center">Item</th><th>Oleh</th><th class="text-center">Aksi</th></tr></thead>
      <tbody>
      <?php if(empty($rows)):?>
        <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach($rows as $r):?>
        <tr>
          <td><code class="small"><?=htmlspecialchars($r['reference_no'])?></code></td>
          <td class="small"><?=tgl($r['transaction_date'])?></td>
          <td class="small"><?=htmlspecialchars($r['location_name'])?></td>
          <td class="small"><?=htmlspecialchars($r['recipient']??'-')?></td>
          <td class="small text-muted"><?=htmlspecialchars($r['purpose']??'-')?></td>
          <td class="text-center"><span class="badge bg-danger-subtle text-danger"><?=$r['item_count']?> item</span></td>
          <td class="small text-muted"><?=htmlspecialchars($r['user_name'])?></td>
          <td class="text-center">
            <a href="<?=APP_URL?>/pages/stock-out/view.php?id=<?=$r['id']?>" class="btn btn-action btn-outline-info"><i class="bi bi-eye"></i></a>
            <?php if(can('stock_out.delete')):?>
            <form method="POST" action="<?=APP_URL?>/pages/stock-out/delete.php" class="d-inline">
              <?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-action btn-outline-danger" data-confirm="Hapus transaksi ini? Stok akan dikembalikan."><i class="bi bi-trash"></i></button>
            </form>
            <?php endif;?>
          </td>
        </tr>
      <?php endforeach;endif;?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'transaksi', ['q' => $search, 'date_from' => $date_from, 'date_to' => $date_to]) ?>
</div>
<?php include __DIR__.'/../../includes/footer.php';?>
