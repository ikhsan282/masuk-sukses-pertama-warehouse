<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
ob_start();
$db = getDB();

// Filter parameters
$supplier_filter = req_int('supplier_id');
$start_date = req_str('start_date', date('Y-m-01')); // Default: first day of current month
$end_date = req_str('end_date', date('Y-m-d'));      // Default: today
$page = max(1, req_int('page', 1));

// Build query conditions
$where_parts = ['po.order_date BETWEEN ? AND ?'];
$params = [$start_date, $end_date];
$types = 'ss';

if ($supplier_filter) {
    $where_parts[] = 's.id = ?';
    $params[] = $supplier_filter;
    $types .= 'i';
}

$where = 'WHERE ' . implode(' AND ', $where_parts);

// Count total suppliers for pagination
$count_query = "SELECT COUNT(DISTINCT s.id) 
    FROM suppliers s 
    LEFT JOIN purchase_orders po ON s.id = po.supplier_id 
    $where";
$cq = $db->prepare($count_query);
$cq->bind_param($types, ...$params);
$cq->execute();
$cq->bind_result($total);
$cq->fetch();
$cq->close();

$pag = paginate($total, $page);
$offset = $pag['offset'];
$limit = PER_PAGE;

// Main query with all metrics
$query = "SELECT 
    s.id,
    s.code,
    s.name,
    s.contact_person,
    s.phone,
    COUNT(DISTINCT po.id) as total_po,
    SUM(CASE WHEN po.status='completed' THEN 1 ELSE 0 END) as completed_po,
    AVG(CASE 
        WHEN po.received_at IS NOT NULL AND po.order_date IS NOT NULL 
        THEN DATEDIFF(po.received_at, po.order_date) 
        ELSE NULL 
    END) as avg_lead_time,
    ROUND(
        (SUM(CASE WHEN po.received_at IS NOT NULL AND po.expected_date IS NOT NULL 
            AND po.received_at <= po.expected_date THEN 1 ELSE 0 END) / 
         NULLIF(SUM(CASE WHEN po.received_at IS NOT NULL AND po.expected_date IS NOT NULL THEN 1 ELSE 0 END), 0)) * 100, 
        2
    ) as on_time_rate,
    COALESCE(SUM(pod.quantity * pod.buy_price), 0) as total_value
FROM suppliers s
LEFT JOIN purchase_orders po ON s.id = po.supplier_id AND po.order_date BETWEEN ? AND ?
LEFT JOIN purchase_order_details pod ON po.id = pod.po_id
" . ($supplier_filter ? "WHERE s.id = ?" : "") . "
GROUP BY s.id
ORDER BY total_value DESC
LIMIT ? OFFSET ?";

$st = $db->prepare($query);
$query_params = [$start_date, $end_date];
$query_types = 'ss';
if ($supplier_filter) {
    $query_params[] = $supplier_filter;
    $query_types .= 'i';
}
$query_params[] = $limit;
$query_params[] = $offset;
$query_types .= 'ii';

$st->bind_param($query_types, ...$query_params);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Get all suppliers for dropdown
$suppliers = $db->query('SELECT id, code, name FROM suppliers WHERE is_active=1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);

// Calculate summary totals
$summary_query = "SELECT 
    COUNT(DISTINCT po.id) as total_po,
    SUM(CASE WHEN po.status='completed' THEN 1 ELSE 0 END) as completed_po,
    COALESCE(SUM(pod.quantity * pod.buy_price), 0) as total_value
FROM purchase_orders po
LEFT JOIN purchase_order_details pod ON po.id = pod.po_id
WHERE po.order_date BETWEEN ? AND ?";

if ($supplier_filter) {
    $summary_query .= " AND po.supplier_id = ?";
}

$sq = $db->prepare($summary_query);
$sq->bind_param($types, ...$params);
$sq->execute();
$summary = $sq->get_result()->fetch_assoc();
$sq->close();

$page_title = 'Laporan Performa Supplier';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-graph-up me-2 text-primary"></i>Laporan Performa Supplier</h4>
  <div class="btn-group btn-group-sm">
    <button type="button" onclick="window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>Cetak</button>
    <a href="supplier-performance_export.php?<?=http_build_query(['supplier_id'=>$supplier_filter,'start_date'=>$start_date,'end_date'=>$end_date])?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>CSV</a>
    <a href="supplier-performance_export.php?<?=http_build_query(['supplier_id'=>$supplier_filter,'start_date'=>$start_date,'end_date'=>$end_date,'format'=>'print'])?>" class="btn btn-outline-danger"><i class="bi bi-printer me-1"></i>Print</a>
  </div>
</div>

<div class="alert alert-info py-2 small">
  <i class="bi bi-info-circle me-1"></i>
  Periode: <strong><?=tgl($start_date)?></strong> s/d <strong><?=tgl($end_date)?></strong> &bull; 
  Total PO: <strong><?=$summary['total_po']?></strong> &bull; 
  Total Nilai: <strong><?=idr((float)$summary['total_value'])?></strong>
</div>

<div class="card table-card">
  <div class="card-header bg-white py-2">
    <form class="d-flex flex-wrap gap-2" method="GET">
      <input type="date" name="start_date" value="<?=e($start_date)?>" class="form-control form-control-sm" style="max-width:160px" required>
      <span class="align-self-center">s/d</span>
      <input type="date" name="end_date" value="<?=e($end_date)?>" class="form-control form-control-sm" style="max-width:160px" required>
      <select name="supplier_id" class="form-select form-select-sm" style="max-width:220px">
        <option value="">Semua Supplier</option>
        <?php foreach($suppliers as $sup): ?>
        <option value="<?=$sup['id']?>" <?=$supplier_filter==$sup['id']?'selected':''?>>
          <?=htmlspecialchars($sup['code'])?> - <?=htmlspecialchars($sup['name'])?>
        </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Tampilkan</button>
      <a href="?" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></a>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Kode</th>
          <th>Nama Supplier</th>
          <th>Kontak</th>
          <th class="text-center">Total PO</th>
          <th class="text-center">Selesai</th>
          <th class="text-center">Lead Time<br><small class="text-muted fw-normal">(hari)</small></th>
          <th class="text-center">On-Time<br><small class="text-muted fw-normal">(%)</small></th>
          <th class="text-end">Total Nilai</th>
        </tr>
      </thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada data untuk periode yang dipilih</td></tr>
      <?php else: foreach($rows as $i => $r): 
        $completion_rate = $r['total_po'] > 0 ? round(($r['completed_po'] / $r['total_po']) * 100, 1) : 0;
        $lead_time = $r['avg_lead_time'] !== null ? round($r['avg_lead_time'], 1) : '-';
        $on_time = $r['on_time_rate'] !== null ? $r['on_time_rate'] : '-';
        
        // Color coding for on-time rate
        $on_time_class = '';
        if ($r['on_time_rate'] !== null) {
            if ($r['on_time_rate'] >= 90) $on_time_class = 'text-success fw-bold';
            elseif ($r['on_time_rate'] >= 75) $on_time_class = 'text-warning fw-bold';
            else $on_time_class = 'text-danger fw-bold';
        }
      ?>
        <tr>
          <td class="small text-muted"><?=$pag['offset']+$i+1?></td>
          <td><code class="small"><?=htmlspecialchars($r['code'])?></code></td>
          <td class="fw-semibold"><?=htmlspecialchars($r['name'])?></td>
          <td class="small">
            <?=htmlspecialchars($r['contact_person'] ?: '-')?>
            <?php if($r['phone']): ?>
              <br><span class="text-muted"><i class="bi bi-telephone"></i> <?=htmlspecialchars($r['phone'])?></span>
            <?php endif; ?>
          </td>
          <td class="text-center"><?=$r['total_po']?></td>
          <td class="text-center">
            <?=$r['completed_po']?>
            <small class="text-muted">(<?=$completion_rate?>%)</small>
          </td>
          <td class="text-center"><?=$lead_time?></td>
          <td class="text-center <?=$on_time_class?>"><?=$on_time?><?=$on_time !== '-' ? '%' : ''?></td>
          <td class="text-end fw-semibold"><?=idr((float)$r['total_value'])?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex align-items-center justify-content-between py-2">
    <small class="text-muted">Menampilkan <?=count($rows)?> dari <?=$total?> supplier</small>
    <?=pagination_html($pag, '?' . http_build_query(['supplier_id'=>$supplier_filter,'start_date'=>$start_date,'end_date'=>$end_date]) . '&page=%d')?>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
