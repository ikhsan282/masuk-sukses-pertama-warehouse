<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
ob_start();
$db = getDB();

$date_from   = req_str('date_from', date('Y-m-01'));
$date_to     = req_str('date_to',   date('Y-m-d'));
$sup_filter  = req_int('supplier_id');
$page        = max(1, req_int('page', 1));

$where_parts=['si.transaction_date >= ?','si.transaction_date <= ?'];
$params=[]; $types='';
$params[]=&$date_from; $params[]=&$date_to; $types.='ss';
if($sup_filter){$where_parts[]='si.supplier_id=?';$params[]=&$sup_filter;$types.='i';}
$where='WHERE '.implode(' AND ',$where_parts);

$cq=$db->prepare("SELECT COUNT(*) FROM stock_in si $where");
$cq->bind_param($types,...$params);$cq->execute();$cq->bind_result($total);$cq->fetch();$cq->close();

$pag=paginate($total,$page);$offset=$pag['offset'];$limit=PER_PAGE;
$t2=$types.'ii';$p2=$params;$p2[]=&$limit;$p2[]=&$offset;

$st=$db->prepare("SELECT si.reference_no, si.transaction_date, s.name AS supplier_name,
    l.name AS location_name, u.name AS user_name,
    (SELECT COUNT(*) FROM stock_in_details d WHERE d.stock_in_id=si.id) AS item_count,
    (SELECT SUM(d.quantity) FROM stock_in_details d WHERE d.stock_in_id=si.id) AS total_qty,
    (SELECT SUM(d.quantity*d.buy_price) FROM stock_in_details d WHERE d.stock_in_id=si.id) AS total_value
    FROM stock_in si JOIN suppliers s ON s.id=si.supplier_id JOIN locations l ON l.id=si.location_id
    JOIN users u ON u.id=si.user_id $where ORDER BY si.transaction_date DESC, si.id DESC LIMIT ? OFFSET ?");
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
  <div class="btn-group btn-group-sm">
    <button type="button" onclick="window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>Cetak</button>
    <a href="stock-in_export.php?<?=http_build_query(['date_from'=>$date_from,'date_to'=>$date_to,'supplier_id'=>$sup_filter])?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>CSV</a>
    <a href="stock-in_export.php?<?=http_build_query(['date_from'=>$date_from,'date_to'=>$date_to,'supplier_id'=>$sup_filter,'format'=>'print'])?>" class="btn btn-outline-danger"><i class="bi bi-printer me-1"></i>Print</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6"><div class="card p-3 border-start border-4 border-success">
    <div class="small text-muted">Total Qty Masuk (Periode)</div>
    <div class="fs-4 fw-bold"><?=number_format($period_qty)?> unit</div>
  </div></div>
  <div class="col-md-6"><div class="card p-3 border-start border-4 border-primary">
    <div class="small text-muted">Total Nilai Masuk (Periode)</div>
    <div class="fs-4 fw-bold"><?=idr((float)$period_val)?></div>
  </div></div>
</div>

<div class="card table-card">
  <div class="card-header bg-white py-2">
    <form class="d-flex flex-wrap gap-2" method="GET">
      <input type="date" name="date_from" class="form-control form-control-sm" style="max-width:150px" value="<?=htmlspecialchars($date_from)?>">
      <input type="date" name="date_to"   class="form-control form-control-sm" style="max-width:150px" value="<?=htmlspecialchars($date_to)?>">
      <select name="supplier_id" class="form-select form-select-sm ts-select" style="max-width:200px">
        <option value="">Semua Supplier</option>
        <?php foreach($suppliers as $s): ?>
        <option value="<?=$s['id']?>" <?=$sup_filter==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-secondary">Filter</button>
      <a href="?" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></a>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>#</th><th>Referensi</th><th>Tanggal</th><th>Supplier</th><th>Lokasi</th>
        <th class="text-center">Item</th><th class="text-center">Total Qty</th>
        <th class="text-end">Nilai</th><th>Oleh</th></tr></thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach($rows as $i=>$r): ?>
        <tr>
          <td class="small text-muted"><?=$pag['offset']+$i+1?></td>
          <td><a href="<?=APP_URL?>/pages/stock-in/view.php?id=<?=$r['id']??''?>">
            <code class="small"><?=htmlspecialchars($r['reference_no'])?></code></a></td>
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
