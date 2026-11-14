<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
ob_start();
$db = getDB();

$date_from  = req_date('date_from', date('Y-m-01'));
$date_to    = req_date('date_to',   date('Y-m-d'));
$loc_filter = req_int('location_id');
$page       = max(1, req_int('page', 1));
$per_page   = grid_per_page();
grid_apply_timeout($db);
$cf         = resolve_company_filter('company_id');
$comp_in    = $cf['sql_in'];

$where_parts=["so.company_id IN ($comp_in)", 'so.transaction_date >= ?','so.transaction_date <= ?'];
$params=[&$date_from, &$date_to]; $types='ss';
if($loc_filter){$where_parts[]='so.location_id=?';$params[]=&$loc_filter;$types.='i';}
$where='WHERE '.implode(' AND ',$where_parts);

$cq=$db->prepare("SELECT COUNT(*) FROM stock_out so $where");
$cq->bind_param($types,...$params);$cq->execute();$cq->bind_result($total);$cq->fetch();$cq->close();

$pag=paginate($total,$page,$per_page);$offset=$pag['offset'];$limit=$pag['per_page'];
$t2=$types.'ii';$p2=$params;$p2[]=&$limit;$p2[]=&$offset;

$st=$db->prepare("SELECT so.id, so.reference_no, so.transaction_date, so.recipient, so.purpose,
    l.name AS location_name, u.name AS user_name, comp.code AS comp_code,
    (SELECT COUNT(*) FROM stock_out_details d WHERE d.stock_out_id=so.id) AS item_count,
    (SELECT SUM(d.quantity) FROM stock_out_details d WHERE d.stock_out_id=so.id) AS total_qty
    FROM (SELECT so.id FROM stock_out so $where ORDER BY so.transaction_date DESC, so.id DESC LIMIT ? OFFSET ?) pg
    JOIN stock_out so ON so.id=pg.id
    JOIN locations l ON l.id=so.location_id
    JOIN users u ON u.id=so.user_id LEFT JOIN companies comp ON comp.id=so.company_id
    ORDER BY so.transaction_date DESC, so.id DESC");
$st->bind_param($t2,...$p2);$st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();

$tot=$db->prepare("SELECT COALESCE(SUM(d.quantity),0)
    FROM stock_out so JOIN stock_out_details d ON d.stock_out_id=so.id $where");
$tot->bind_param($types,...$params);$tot->execute();$tot->bind_result($period_qty);$tot->fetch();$tot->close();

$locations = $db->query('SELECT id,code,name FROM locations WHERE is_active=1 ORDER BY code')->fetch_all(MYSQLI_ASSOC);
$page_title='Laporan Barang Keluar'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-file-earmark-arrow-up me-2 text-primary"></i>Laporan Barang Keluar</h4>
  <div class="btn-group btn-group-sm">
    <button type="button" onclick="window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>Cetak</button>
    <a href="stock-out_export.php?<?=http_build_query(['company_id'=>$cf['param_value'],'date_from'=>$date_from,'date_to'=>$date_to,'location_id'=>$loc_filter])?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>CSV</a>
    <a href="stock-out_export.php?<?=http_build_query(['company_id'=>$cf['param_value'],'date_from'=>$date_from,'date_to'=>$date_to,'location_id'=>$loc_filter,'format'=>'print'])?>" class="btn btn-outline-danger"><i class="bi bi-printer me-1"></i>Print</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6"><div class="card p-3 border-start border-4 border-danger">
    <div class="small text-muted">Total Qty Keluar (<?=htmlspecialchars($cf['label'])?>)</div>
    <div class="fs-4 fw-bold"><?=number_format($period_qty)?> unit</div>
  </div></div>
  <div class="col-md-6"><div class="card p-3 border-start border-4 border-warning">
    <div class="small text-muted">Total Transaksi (<?=htmlspecialchars($cf['label'])?>)</div>
    <div class="fs-4 fw-bold"><?=$total?> transaksi</div>
  </div></div>
</div>

<div class="card table-card">
  <div class="card-header bg-white grid-toolbar">
    <form class="grid-filter-form" method="GET">
      <?= company_filter_select_html('company_id', $cf['param_value']) ?>
      <?= grid_filter_date_range_html('date_from', $date_from, 'date_to', $date_to) ?>
      <select name="location_id" class="form-select form-select-sm ts-select" aria-label="Filter Lokasi">
        <option value="">Semua Lokasi</option>
        <?php foreach($locations as $l): ?>
        <option value="<?=$l['id']?>" <?=$loc_filter==$l['id']?'selected':''?>><?=htmlspecialchars($l['code'])?></option>
        <?php endforeach; ?>
      </select>
      <?= grid_filter_actions_html($pag['per_page'], true) ?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>#</th><th>Referensi</th><th>PT</th><th>Tanggal</th><th>Lokasi</th><th>Penerima</th><th>Keperluan</th>
        <th class="text-center">Item</th><th class="text-center">Total Qty</th><th>Oleh</th></tr></thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach($rows as $i=>$r): ?>
        <tr>
          <td class="small text-muted"><?=$pag['offset']+$i+1?></td>
          <td><a href="<?=APP_URL?>/pages/stock-out/view.php?id=<?=$r['id']?>">
            <code class="small"><?=htmlspecialchars($r['reference_no'])?></code></a></td>
          <td><span class="badge bg-primary-subtle text-primary border"><?=htmlspecialchars($r['comp_code'] ?? 'MSP')?></span></td>
          <td class="small"><?=tgl($r['transaction_date'])?></td>
          <td class="small"><?=htmlspecialchars($r['location_name'])?></td>
          <td class="small"><?=htmlspecialchars($r['recipient']??'-')?></td>
          <td class="small text-muted"><?=htmlspecialchars($r['purpose']??'-')?></td>
          <td class="text-center"><?=$r['item_count']?></td>
          <td class="text-center fw-semibold"><?=number_format($r['total_qty'])?></td>
          <td class="small text-muted"><?=htmlspecialchars($r['user_name'])?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'transaksi', ['company_id' => $cf['param_value'], 'date_from' => $date_from, 'date_to' => $date_to, 'location_id' => $loc_filter ?: '']) ?>
</div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
