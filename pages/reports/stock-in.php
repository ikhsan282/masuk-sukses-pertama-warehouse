<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
ob_start();
$db = getDB();

$date_from   = req_date('date_from', date('Y-m-01'));
$date_to     = req_date('date_to',   date('Y-m-d'));
$sup_filter  = req_int('supplier_id');
$page        = max(1, req_int('page', 1));
$per_page    = grid_per_page();
grid_apply_timeout($db);
$cf          = resolve_company_filter('company_id');
$comp_in     = $cf['sql_in'];

$where_parts=["si.company_id IN ($comp_in)", 'si.transaction_date >= ?','si.transaction_date <= ?'];
$params=[&$date_from, &$date_to]; $types='ss';
if($sup_filter){$where_parts[]='si.supplier_id=?';$params[]=&$sup_filter;$types.='i';}
$where='WHERE '.implode(' AND ',$where_parts);

$cq=$db->prepare("SELECT COUNT(*) FROM stock_in si $where");
$cq->bind_param($types,...$params);$cq->execute();$cq->bind_result($total);$cq->fetch();$cq->close();

$pag=paginate($total,$page,$per_page);$offset=$pag['offset'];$limit=$pag['per_page'];
$t2=$types.'ii';$p2=$params;$p2[]=&$limit;$p2[]=&$offset;

$st=$db->prepare("SELECT si.id, si.reference_no, si.transaction_date, s.name AS supplier_name,
    l.name AS location_name, u.name AS user_name, comp.code AS comp_code,
    (SELECT COUNT(*) FROM stock_in_details d WHERE d.stock_in_id=si.id) AS item_count,
    (SELECT SUM(d.quantity) FROM stock_in_details d WHERE d.stock_in_id=si.id) AS total_qty,
    (SELECT SUM(d.quantity*d.buy_price) FROM stock_in_details d WHERE d.stock_in_id=si.id) AS total_value
    FROM (SELECT si.id FROM stock_in si $where ORDER BY si.transaction_date DESC, si.id DESC LIMIT ? OFFSET ?) pg
    JOIN stock_in si ON si.id=pg.id
    JOIN suppliers s ON s.id=si.supplier_id JOIN locations l ON l.id=si.location_id
    JOIN users u ON u.id=si.user_id LEFT JOIN companies comp ON comp.id=si.company_id
    ORDER BY si.transaction_date DESC, si.id DESC");
$st->bind_param($t2,...$p2);$st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();

// Totals for period
$tot=$db->prepare("SELECT COALESCE(SUM(d.quantity),0), COALESCE(SUM(d.quantity*d.buy_price),0)
    FROM stock_in si JOIN stock_in_details d ON d.stock_in_id=si.id $where");
$tot->bind_param($types,...$params);$tot->execute();$tot->bind_result($period_qty,$period_val);$tot->fetch();$tot->close();

$suppliers = $db->query('SELECT id,name FROM suppliers WHERE is_active=1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$page_title='Laporan Barang Masuk'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-file-earmark-arrow-down me-2 text-primary"></i>Laporan Barang Masuk</h4>
  <?= report_export_dropdown_html(['company_id'=>$cf['param_value'],'date_from'=>$date_from,'date_to'=>$date_to,'supplier_id'=>$sup_filter], 'stock-in_export.php') ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6"><div class="card p-3 border-start border-4 border-success">
    <div class="small text-muted">Total Qty Masuk (<?=htmlspecialchars($cf['label'])?>)</div>
    <div class="fs-4 fw-bold"><?=number_format($period_qty)?> unit</div>
  </div></div>
  <div class="col-md-6"><div class="card p-3 border-start border-4 border-primary">
    <div class="small text-muted">Total Nilai Masuk (<?=htmlspecialchars($cf['label'])?>)</div>
    <div class="fs-4 fw-bold"><?=idr((float)$period_val)?></div>
  </div></div>
</div>

<div class="card table-card">
  <div class="card-header bg-white grid-toolbar">
    <form class="grid-filter-form" method="GET">
      <?= company_filter_select_html('company_id', $cf['param_value']) ?>
      <?= grid_filter_date_range_html('date_from', $date_from, 'date_to', $date_to) ?>
      <select name="supplier_id" class="form-select form-select-sm ts-select" aria-label="Filter Supplier">
        <option value="">Semua Supplier</option>
        <?php foreach($suppliers as $s): ?>
        <option value="<?=$s['id']?>" <?=$sup_filter==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option>
        <?php endforeach; ?>
      </select>
      <?= grid_filter_actions_html($pag['per_page'], true) ?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>#</th><th>Referensi</th><th>PT</th><th>Tanggal</th><th>Supplier</th><th>Lokasi</th>
        <th class="text-center">Item</th><th class="text-center">Total Qty</th>
        <th class="text-end">Nilai</th><th>Oleh</th></tr></thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach($rows as $i=>$r): ?>
        <tr>
          <td class="small text-muted"><?=$pag['offset']+$i+1?></td>
          <td><a href="<?=APP_URL?>/pages/stock-in/view.php?id=<?=$r['id']??''?>">
            <code class="small"><?=htmlspecialchars($r['reference_no'])?></code></a></td>
          <td><span class="badge bg-primary-subtle text-primary border"><?=htmlspecialchars($r['comp_code'] ?? 'MSP')?></span></td>
          <td class="small"><?=tgl($r['transaction_date'])?></td>
          <td class="small"><?=htmlspecialchars($r['supplier_name'])?></td>
          <td class="small"><?=htmlspecialchars($r['location_name'])?></td>
          <td class="text-center"><?=$r['item_count']?></td>
          <td class="text-center fw-semibold"><?=number_format($r['total_qty'])?></td>
          <td class="text-end small"><?=idr((float)$r['total_value'])?></td>
          <td class="small text-muted"><?=htmlspecialchars($r['user_name'])?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'transaksi', ['company_id' => $cf['param_value'], 'date_from' => $date_from, 'date_to' => $date_to, 'supplier_id' => $sup_filter ?: '']) ?>
</div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
