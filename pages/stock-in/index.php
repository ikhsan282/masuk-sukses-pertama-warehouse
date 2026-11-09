<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('stock_in.view');
$db = getDB();

$search = req_str('q');
$date_from = req_str('date_from');
$date_to   = req_str('date_to');
$page = max(1, req_int('page', 1));
$active_cid = current_company_id();

$where_parts = ['si.company_id = ?'];
$params=[&$active_cid]; $types='i';
if ($search)    { $where_parts[]='(si.reference_no LIKE ? OR s.name LIKE ?)'; $like="%$search%"; $params[]=&$like;$params[]=&$like;$types.='ss'; }
if ($date_from) { $where_parts[]='si.transaction_date >= ?'; $params[]=&$date_from;$types.='s'; }
if ($date_to)   { $where_parts[]='si.transaction_date <= ?'; $params[]=&$date_to;$types.='s'; }
$where = 'WHERE '.implode(' AND ',$where_parts);

$cq=$db->prepare("SELECT COUNT(*) FROM stock_in si JOIN suppliers s ON s.id=si.supplier_id $where");
if($types)$cq->bind_param($types,...$params);
$cq->execute();$cq->bind_result($total);$cq->fetch();$cq->close();

$pag=paginate($total,$page); $offset=$pag['offset']; $limit=PER_PAGE;
$t2=$types.'ii'; $p2=$params; $p2[]=&$limit;$p2[]=&$offset;
$st=$db->prepare("SELECT si.*, s.name AS supplier_name, l.name AS location_name, u.name AS user_name,
    (SELECT COUNT(*) FROM stock_in_details d WHERE d.stock_in_id=si.id) AS item_count,
    (SELECT SUM(d.quantity*d.buy_price) FROM stock_in_details d WHERE d.stock_in_id=si.id) AS total_value
    FROM stock_in si JOIN suppliers s ON s.id=si.supplier_id JOIN locations l ON l.id=si.location_id
    JOIN users u ON u.id=si.user_id $where ORDER BY si.id DESC LIMIT ? OFFSET ?");
$st->bind_param($t2,...$p2); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$page_title='Barang Masuk'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-box-arrow-in-down me-2 text-primary"></i>Barang Masuk</h4>
  <?php if(can('stock_in.create')):?>
  <a href="<?= APP_URL ?>/pages/stock-in/create.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Input Barang Masuk
  </a>
  <?php endif;?>
</div>

<div class="card table-card">
  <div class="card-header bg-white py-2">
    <form class="d-flex flex-wrap gap-2" method="GET">
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:200px" placeholder="No. referensi / supplier..." value="<?=htmlspecialchars($search)?>">
      <input type="date" name="date_from" class="form-control form-control-sm" style="max-width:150px" value="<?=htmlspecialchars($date_from)?>">
      <input type="date" name="date_to" class="form-control form-control-sm" style="max-width:150px" value="<?=htmlspecialchars($date_to)?>">
      <button class="btn btn-sm btn-outline-secondary">Filter</button>
      <?php if($search||$date_from||$date_to):?><a href="?" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></a><?php endif;?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>No. Referensi</th><th>Tanggal</th><th>Supplier</th><th>Lokasi</th><th class="text-center">Item</th><th class="text-end">Nilai</th><th>Oleh</th><th class="text-center">Aksi</th></tr></thead>
      <tbody>
      <?php if(empty($rows)):?>
        <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach($rows as $r):?>
        <tr>
          <td><code class="small"><?=htmlspecialchars($r['reference_no'])?></code></td>
          <td class="small"><?=tgl($r['transaction_date'])?></td>
          <td class="small"><?=htmlspecialchars($r['supplier_name'])?></td>
          <td class="small"><?=htmlspecialchars($r['location_name'])?></td>
          <td class="text-center"><span class="badge bg-primary-subtle text-primary"><?=$r['item_count']?> item</span></td>
          <td class="text-end small fw-semibold"><?=idr((float)$r['total_value'])?></td>
          <td class="small text-muted"><?=htmlspecialchars($r['user_name'])?></td>
          <td class="text-center">
            <a href="<?=APP_URL?>/pages/stock-in/view.php?id=<?=$r['id']?>" class="btn btn-action btn-outline-info" title="Detail"><i class="bi bi-eye"></i></a>
            <?php if(can('stock_in.delete')):?>
            <form method="POST" action="<?=APP_URL?>/pages/stock-in/delete.php" class="d-inline">
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
  <div class="card-footer bg-white d-flex align-items-center justify-content-between py-2">
    <small class="text-muted">Menampilkan <?=count($rows)?> dari <?=$total?> transaksi</small>
    <?=pagination_html($pag,'?'.http_build_query(['q'=>$search,'date_from'=>$date_from,'date_to'=>$date_to]).'&page=%d')?>
  </div>
</div>
<?php include __DIR__.'/../../includes/footer.php';?>
