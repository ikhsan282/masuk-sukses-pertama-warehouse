<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
ob_start();
$db = getDB();

$date_from  = req_str('date_from', date('Y-m-01'));
$date_to    = req_str('date_to',   date('Y-m-d'));
$item_filter= req_int('item_id');
$type_filter= req_str('type');
$page       = max(1, req_int('page', 1));

$where_parts = ['m.created_at >= ?', 'm.created_at <= ?'];
$params = []; $types = '';
$df = $date_from.' 00:00:00'; $dt = $date_to.' 23:59:59';
$params[]=&$df; $params[]=&$dt; $types.='ss';
if ($item_filter) { $where_parts[]='m.item_id=?'; $params[]=&$item_filter; $types.='i'; }
if ($type_filter) { $where_parts[]='m.type=?';    $params[]=&$type_filter; $types.='s'; }
$where='WHERE '.implode(' AND ',$where_parts);

$cq=$db->prepare("SELECT COUNT(*) FROM mutations m $where");
$cq->bind_param($types,...$params); $cq->execute();
$cq->bind_result($total); $cq->fetch(); $cq->close();

$pag=paginate($total,$page); $offset=$pag['offset']; $limit=PER_PAGE;
$t2=$types.'ii'; $p2=$params; $p2[]=&$limit; $p2[]=&$offset;

$st=$db->prepare("SELECT m.*, i.code AS item_code, i.name AS item_name,
    u.abbreviation, l.name AS loc_name, us.name AS user_name
    FROM mutations m JOIN items i ON i.id=m.item_id JOIN units u ON u.id=i.unit_id
    JOIN locations l ON l.id=m.location_id JOIN users us ON us.id=m.user_id
    $where ORDER BY m.id DESC LIMIT ? OFFSET ?");
$st->bind_param($t2,...$p2); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$items_list = $db->query('SELECT id,code,name FROM items WHERE is_active=1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$page_title='Laporan Mutasi Barang'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-arrow-down-up me-2 text-primary"></i>Laporan Mutasi Barang</h4>
  <div class="btn-group btn-group-sm">
    <button type="button" onclick="window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>Cetak</button>
    <a href="mutation_export.php?<?=http_build_query(['date_from'=>$date_from,'date_to'=>$date_to,'item_id'=>$item_filter,'type'=>$type_filter])?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>CSV</a>
    <a href="mutation_export.php?<?=http_build_query(['date_from'=>$date_from,'date_to'=>$date_to,'item_id'=>$item_filter,'type'=>$type_filter,'format'=>'print'])?>" class="btn btn-outline-danger"><i class="bi bi-printer me-1"></i>Print</a>
  </div>
</div>

<div class="card table-card">
  <div class="card-header bg-white py-2">
    <form class="d-flex flex-wrap gap-2" method="GET">
      <input type="date" name="date_from" class="form-control form-control-sm" style="max-width:150px" value="<?=htmlspecialchars($date_from)?>">
      <input type="date" name="date_to"   class="form-control form-control-sm" style="max-width:150px" value="<?=htmlspecialchars($date_to)?>">
      <select name="item_id" class="form-select form-select-sm ts-select" style="max-width:200px">
        <option value="">Semua Barang</option>
        <?php foreach($items_list as $it): ?>
        <option value="<?=$it['id']?>" <?=$item_filter==$it['id']?'selected':''?>><?=htmlspecialchars($it['name'])?></option>
        <?php endforeach; ?>
      </select>
      <select name="type" class="form-select form-select-sm" style="max-width:150px">
        <option value="">Semua Tipe</option>
        <option value="in" <?=$type_filter=='in'?'selected':''?>>Masuk</option>
        <option value="out" <?=$type_filter=='out'?'selected':''?>>Keluar</option>
        <option value="transfer_in" <?=$type_filter=='transfer_in'?'selected':''?>>Transfer Masuk</option>
        <option value="transfer_out" <?=$type_filter=='transfer_out'?'selected':''?>>Transfer Keluar</option>
        <option value="adjustment" <?=$type_filter=='adjustment'?'selected':''?>>Penyesuaian</option>
      </select>
      <button class="btn btn-sm btn-outline-secondary">Filter</button>
      <a href="?" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></a>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Waktu</th><th>Kode</th><th>Nama Barang</th><th>Tipe</th><th>Lokasi</th><th class="text-end">Qty</th><th>Referensi</th><th>Oleh</th></tr></thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else:
        $type_map=['in'=>['Masuk','success'],'out'=>['Keluar','danger'],'transfer_in'=>['T.Masuk','info'],'transfer_out'=>['T.Keluar','warning'],'adjustment'=>['Opname','primary']];
        foreach($rows as $r): [$lbl,$col]=$type_map[$r['type']]??['?','secondary']; ?>
        <tr>
          <td class="small text-muted"><?=date('d/m/y H:i',strtotime($r['created_at']))?></td>
          <td><code class="small"><?=htmlspecialchars($r['item_code'])?></code></td>
          <td class="small"><?=htmlspecialchars($r['item_name'])?></td>
          <td><span class="badge bg-<?=$col?>-subtle text-<?=$col?> border"><?=$lbl?></span></td>
          <td class="small"><?=htmlspecialchars($r['loc_name'])?></td>
          <td class="text-end fw-semibold"><?=$r['quantity']?> <?=htmlspecialchars($r['abbreviation'])?></td>
          <td><code class="small"><?=htmlspecialchars($r['reference_no']??'')?></code></td>
          <td class="small text-muted"><?=htmlspecialchars($r['user_name'])?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex align-items-center justify-content-between py-2">
    <small class="text-muted">Menampilkan <?=count($rows)?> dari <?=$total?> mutasi</small>
    <?=pagination_html($pag,'?'.http_build_query(['date_from'=>$date_from,'date_to'=>$date_to,'item_id'=>$item_filter,'type'=>$type_filter]).'&page=%d')?>
  </div>
</div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
