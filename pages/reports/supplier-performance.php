<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
ob_start();
$db = getDB();

// Filter parameters
$supplier_filter = req_int('supplier_id');
$start_date = req_date('start_date', date('Y-m-01')); // Default: first day of current month
$end_date   = req_date('end_date', date('Y-m-d'));      // Default: today
$page       = max(1, req_int('page', 1));
$per_page   = grid_per_page();
grid_apply_timeout($db);
$cf = resolve_company_filter('company_id');
$comp_in = $cf['sql_in'];

// Build query conditions
$where_parts = ["po.company_id IN ($comp_in)", 'po.order_date BETWEEN ? AND ?'];
$params = [$start_date, $end_date];
$types = 'ss';

if ($supplier_filter) {
    $where_parts[] = 's.id = ?';
    $params[] = $supplier_filter;
    $types .= 'i';
}

$where = 'WHERE ' . implode(' AND ', $where_parts);

// Count active suppliers consistently with main LEFT JOIN grid
if ($supplier_filter) {
    $cq = $db->prepare("SELECT COUNT(*) FROM suppliers s WHERE s.is_active=1 AND s.id=?");
    $cq->bind_param('i', $supplier_filter);
    $cq->execute();
    $cq->bind_result($total);
    $cq->fetch();
    $cq->close();
} else {
    $total = (int)$db->query("SELECT COUNT(*) FROM suppliers WHERE is_active=1")->fetch_row()[0];
}

$pag = paginate($total, $page, $per_page);
$offset = $pag['offset'];
$limit = $pag['per_page'];

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
LEFT JOIN purchase_orders po ON s.id = po.supplier_id AND po.company_id IN ($comp_in) AND po.order_date BETWEEN ? AND ?
LEFT JOIN purchase_order_details pod ON po.id = pod.po_id
WHERE s.is_active=1" . ($supplier_filter ? " AND s.id = ?" : "") . "
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

// Capped supplier dropdown for large datasets
$suppliers = $db->query('SELECT id, code, name FROM suppliers WHERE is_active=1 ORDER BY name LIMIT 500')->fetch_all(MYSQLI_ASSOC);
if ($supplier_filter && !in_array($supplier_filter, array_map('intval', array_column($suppliers, 'id')), true)) {
    $sel_st = $db->prepare('SELECT id, code, name FROM suppliers WHERE id=? LIMIT 1');
    $sel_st->bind_param('i', $supplier_filter);
    $sel_st->execute();
    if ($sel_row = $sel_st->get_result()->fetch_assoc()) array_unshift($suppliers, $sel_row);
    $sel_st->close();
}

// Calculate summary totals
$summary_query = "SELECT 
    COUNT(DISTINCT po.id) as total_po,
    SUM(CASE WHEN po.status='completed' THEN 1 ELSE 0 END) as completed_po,
    COALESCE(SUM(pod.quantity * pod.buy_price), 0) as total_value
FROM purchase_orders po
LEFT JOIN purchase_order_details pod ON po.id = pod.po_id
WHERE po.company_id IN ($comp_in) AND po.order_date BETWEEN ? AND ?";

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
  <?= report_export_dropdown_html(['company_id'=>$cf['param_value'],'supplier_id'=>$supplier_filter,'start_date'=>$start_date,'end_date'=>$end_date], 'supplier-performance_export.php') ?>
</div>

<div class="alert alert-info py-2 small">
  <i class="bi bi-info-circle me-1"></i>
  Cakupan: <strong><?=htmlspecialchars($cf['label'])?></strong> &bull;
  Periode: <strong><?=tgl($start_date)?></strong> s/d <strong><?=tgl($end_date)?></strong> &bull; 
  Total PO: <strong><?=$summary['total_po']?></strong> &bull; 
  Total Nilai: <strong><?=idr((float)$summary['total_value'])?></strong>
</div>

<div class="card table-card">
  <div class="card-header bg-white grid-toolbar">
    <form class="grid-filter-form" method="GET">
      <?= company_filter_select_html('company_id', $cf['param_value']) ?>
      <?= grid_filter_date_range_html('start_date', $start_date, 'end_date', $end_date, true) ?>
      <select name="supplier_id" class="form-select form-select-sm ts-select" aria-label="Filter Supplier">
        <option value="">Semua Supplier</option>
        <?php foreach($suppliers as $sup): ?>
        <option value="<?=$sup['id']?>" <?=$supplier_filter==$sup['id']?'selected':''?>>
          <?=htmlspecialchars($sup['code'])?> - <?=htmlspecialchars($sup['name'])?>
        </option>
        <?php endforeach; ?>
      </select>
      <?= grid_filter_actions_html($pag['per_page'], true) ?>
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
  <?= grid_footer_html($pag, 'supplier', ['company_id' => $cf['param_value'], 'supplier_id' => $supplier_filter ?: '', 'start_date' => $start_date, 'end_date' => $end_date]) ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
