<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('reports.view');
ob_start();
$db = getDB();

$loc_filter = req_int('location_id');
$cat_filter = req_int('category_id');
$show       = req_str('show'); // all | low | zero
$page       = max(1, req_int('page', 1));

$where_parts = ['i.is_active=1'];
$params=[]; $types='';
if ($cat_filter) { $where_parts[]='i.category_id=?'; $params[]=&$cat_filter; $types.='i'; }
if ($loc_filter) { $where_parts[]='s.location_id=?';  $params[]=&$loc_filter; $types.='i'; }
$where='WHERE '.implode(' AND ',$where_parts);

$having = '';
if ($show==='low')  $having = 'HAVING total_stock > 0 AND total_stock <= MAX(i.min_stock)';
if ($show==='zero') $having = 'HAVING total_stock = 0';

$cq=$db->prepare("SELECT COUNT(*) FROM (
    SELECT i.id, i.min_stock, COALESCE(SUM(s.quantity),0) AS total_stock
    FROM items i LEFT JOIN stock s ON s.item_id=i.id $where GROUP BY i.id, i.min_stock $having) sub");
if($types)$cq->bind_param($types,...$params);
$cq->execute();$cq->bind_result($total);$cq->fetch();$cq->close();

$pag=paginate($total,$page); $offset=$pag['offset']; $limit=PER_PAGE;
$t2=$types.'ii'; $p2=$params; $p2[]=&$limit; $p2[]=&$offset;

$st=$db->prepare("SELECT i.id, i.code, i.name, i.min_stock, i.buy_price, i.sell_price,
    c.name AS cat_name, u.abbreviation,
    COALESCE(SUM(s.quantity),0) AS total_stock,
    COALESCE(SUM(s.quantity)*i.buy_price,0) AS stock_value
    FROM items i JOIN categories c ON c.id=i.category_id JOIN units u ON u.id=i.unit_id
    LEFT JOIN stock s ON s.item_id=i.id $where
    GROUP BY i.id, i.code, i.name, i.min_stock, i.buy_price, i.sell_price, c.name, u.abbreviation $having ORDER BY i.code LIMIT ? OFFSET ?");
$st->bind_param($t2,...$p2); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$categories = $db->query('SELECT id,name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$locations  = $db->query('SELECT id,code,name FROM locations WHERE is_active=1 ORDER BY code')->fetch_all(MYSQLI_ASSOC);
$total_value= $db->query('SELECT COALESCE(SUM(s.quantity*i.buy_price),0) FROM stock s JOIN items i ON i.id=s.item_id')->fetch_row()[0];

$page_title='Laporan Stok'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-bar-chart-line me-2 text-primary"></i>Laporan Stok Barang</h4>
  <div class="btn-group btn-group-sm">
    <button type="button" onclick="window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>Cetak</button>
    <a href="stock_export.php?<?=http_build_query(['location_id'=>$loc_filter,'category_id'=>$cat_filter,'show'=>$show])?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>CSV</a>
    <a href="stock_export.php?<?=http_build_query(['location_id'=>$loc_filter,'category_id'=>$cat_filter,'show'=>$show,'format'=>'print'])?>" class="btn btn-outline-danger"><i class="bi bi-printer me-1"></i>Print</a>
  </div>
</div>

<div class="alert alert-info py-2 small">
  <i class="bi bi-info-circle me-1"></i>Total nilai inventori saat ini: <strong><?=idr((float)$total_value)?></strong>
</div>

<div class="card table-card">
  <div class="card-header bg-white py-2">
    <form class="d-flex flex-wrap gap-2" method="GET">
      <select name="category_id" class="form-select form-select-sm" style="max-width:180px">
        <option value="">Semua Kategori</option>
        <?php foreach($categories as $c): ?>
        <option value="<?=$c['id']?>" <?=$cat_filter==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option>
        <?php endforeach; ?>
      </select>
      <select name="location_id" class="form-select form-select-sm" style="max-width:180px">
        <option value="">Semua Lokasi</option>
        <?php foreach($locations as $l): ?>
        <option value="<?=$l['id']?>" <?=$loc_filter==$l['id']?'selected':''?>><?=htmlspecialchars($l['code'])?></option>
        <?php endforeach; ?>
      </select>
      <select name="show" class="form-select form-select-sm" style="max-width:150px">
        <option value="">Semua Stok</option>
        <option value="low"  <?=$show==='low'?'selected':''?>>Stok Menipis</option>
        <option value="zero" <?=$show==='zero'?'selected':''?>>Stok Habis</option>
      </select>
      <button class="btn btn-sm btn-outline-secondary">Filter</button>
      <a href="?" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></a>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>#</th><th>Kode</th><th>Nama Barang</th><th>Kategori</th>
        <th class="text-center">Stok</th><th class="text-center">Min</th>
        <th class="text-center">Status</th><th class="text-end">H. Beli</th><th class="text-end">Nilai Stok</th></tr></thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach($rows as $i=>$r):
        $sc = $r['total_stock']==0 ? 'badge-zero' : ($r['total_stock']<=$r['min_stock'] ? 'badge-low' : 'badge-ok');
        $sl = $r['total_stock']==0 ? 'Habis' : ($r['total_stock']<=$r['min_stock'] ? 'Menipis' : 'Aman');
      ?>
        <tr>
          <td class="small text-muted"><?=$pag['offset']+$i+1?></td>
          <td><code class="small"><?=htmlspecialchars($r['code'])?></code></td>
          <td class="fw-semibold"><?=htmlspecialchars($r['name'])?></td>
          <td class="small"><?=htmlspecialchars($r['cat_name'])?></td>
          <td class="text-center fw-bold"><?=$r['total_stock']?> <small class="text-muted"><?=htmlspecialchars($r['abbreviation'])?></small></td>
          <td class="text-center text-muted"><?=$r['min_stock']?></td>
          <td class="text-center"><span class="badge <?=$sc?>"><?=$sl?></span></td>
          <td class="text-end small"><?=idr((float)$r['buy_price'])?></td>
          <td class="text-end small fw-semibold"><?=idr((float)$r['stock_value'])?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex align-items-center justify-content-between py-2">
    <small class="text-muted">Menampilkan <?=count($rows)?> dari <?=$total?> barang</small>
    <?=pagination_html($pag,'?'.http_build_query(['location_id'=>$loc_filter,'category_id'=>$cat_filter,'show'=>$show]).'&page=%d')?>
  </div>
</div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
