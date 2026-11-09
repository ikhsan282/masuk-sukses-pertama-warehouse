<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('stock.view');
$db = getDB();

$search     = req_str('q');
$loc_filter = req_int('location_id');
$page       = max(1, req_int('page', 1));
$cf         = resolve_company_filter('company_id');
$comp_in    = $cf['sql_in'];

$where_parts = ['i.is_active=1', "(i.company_id IS NULL OR i.company_id IN ($comp_in))"];
$params=[]; $types='';
if ($search)     { $where_parts[]='(i.name LIKE ? OR i.code LIKE ?)'; $like="%$search%"; $params[]=&$like; $params[]=&$like; $types.='ss'; }
if ($loc_filter) { $where_parts[]='s.location_id=?'; $params[]=&$loc_filter; $types.='i'; }
$where = 'WHERE '.implode(' AND ',$where_parts);

$cq=$db->prepare("SELECT COUNT(DISTINCT i.id) FROM items i
    LEFT JOIN stock s ON s.item_id=i.id AND s.company_id IN ($comp_in) $where");
if($types)$cq->bind_param($types,...$params);
$cq->execute();$cq->bind_result($total);$cq->fetch();$cq->close();

$pag=paginate($total,$page); $offset=$pag['offset']; $limit=PER_PAGE;
$t2=$types.'ii'; $p2=$params; $p2[]=&$limit; $p2[]=&$offset;

$st=$db->prepare("SELECT i.id, i.code, i.name, i.min_stock,
    c.name AS cat_name, u.abbreviation,
    COALESCE(SUM(s.quantity),0) AS total_stock,
    COALESCE(SUM(s.quantity * i.buy_price),0) AS stock_value
    FROM items i
    JOIN categories c ON c.id=i.category_id
    JOIN units u ON u.id=i.unit_id
    LEFT JOIN stock s ON s.item_id=i.id AND s.company_id IN ($comp_in)
    $where GROUP BY i.id ORDER BY i.code LIMIT ? OFFSET ?");
$st->bind_param($t2,...$p2); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$locations = $db->query('SELECT id,code,name FROM locations WHERE is_active=1 ORDER BY code')->fetch_all(MYSQLI_ASSOC);

// Summary stats for selected company scope
$stat_zero = $db->query("SELECT COUNT(DISTINCT i.id) FROM items i LEFT JOIN stock s ON s.item_id=i.id AND s.company_id IN ($comp_in) WHERE i.is_active=1 AND (i.company_id IS NULL OR i.company_id IN ($comp_in)) GROUP BY i.id HAVING COALESCE(SUM(s.quantity),0)=0")->num_rows;
$stat_low  = $db->query("SELECT COUNT(*) FROM items i LEFT JOIN (SELECT item_id,SUM(quantity) total_stock FROM stock WHERE company_id IN ($comp_in) GROUP BY item_id) s ON s.item_id=i.id WHERE i.is_active=1 AND (i.company_id IS NULL OR i.company_id IN ($comp_in)) AND COALESCE(s.total_stock,0) > 0 AND COALESCE(s.total_stock,0) <= i.min_stock")->fetch_row()[0];
$stat_val  = $db->query("SELECT COALESCE(SUM(s.quantity * i.buy_price),0) FROM stock s JOIN items i ON i.id=s.item_id WHERE s.company_id IN ($comp_in)")->fetch_row()[0];

$page_title='Cek Stok'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-clipboard-data me-2 text-primary"></i>Cek Stok</h4>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card stat-card p-3 border-start border-4 border-danger">
      <div class="small text-muted">Stok Habis</div>
      <div class="fs-4 fw-bold text-danger"><?= $stat_zero ?> barang</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card p-3 border-start border-4 border-warning">
      <div class="small text-muted">Stok Menipis (≤ min)</div>
      <div class="fs-4 fw-bold text-warning"><?= $stat_low ?> barang</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card p-3 border-start border-4 border-success">
      <div class="small text-muted">Nilai Inventori</div>
      <div class="fs-5 fw-bold text-success"><?= idr((float)$stat_val) ?></div>
    </div>
  </div>
</div>

<div class="card table-card">
  <div class="card-header bg-white py-2">
    <form class="d-flex flex-wrap gap-2" method="GET">
      <?= company_filter_select_html('company_id', $cf['param_value']) ?>
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:220px"
        placeholder="Cari kode / nama..." value="<?= htmlspecialchars($search) ?>">
      <select name="location_id" class="form-select form-select-sm ts-select" style="max-width:180px">
        <option value="">Semua Lokasi</option>
        <?php foreach($locations as $l): ?>
        <option value="<?=$l['id']?>" <?=$loc_filter==$l['id']?'selected':''?>><?=htmlspecialchars($l['code'])?> — <?=htmlspecialchars($l['name'])?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-secondary">Filter</button>
      <?php if($search||$loc_filter||isset($_GET['company_id'])):?><a href="?" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></a><?php endif;?>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr><th>#</th><th>Kode</th><th>Nama Barang</th><th>Kategori</th>
        <th class="text-center">Stok Saat Ini</th><th class="text-center">Min. Stok</th>
        <th class="text-center">Status</th><th class="text-end">Nilai Stok</th></tr>
      </thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data</td></tr>
      <?php else: foreach($rows as $i=>$r):
        if ($r['total_stock']==0)                       $st_cls='badge-zero';
        elseif ($r['total_stock']<=$r['min_stock'])     $st_cls='badge-low';
        else                                             $st_cls='badge-ok';
        $st_label = $r['total_stock']==0 ? 'Habis' : ($r['total_stock']<=$r['min_stock'] ? 'Menipis' : 'Aman');
      ?>
        <tr>
          <td class="small text-muted"><?=$pag['offset']+$i+1?></td>
          <td><code class="small"><?=htmlspecialchars($r['code'])?></code></td>
          <td class="fw-semibold">
            <a href="<?=APP_URL?>/pages/items/view.php?id=<?=$r['id']?>" class="text-decoration-none">
              <?=htmlspecialchars($r['name'])?>
            </a>
          </td>
          <td class="small text-muted"><?=htmlspecialchars($r['cat_name'])?></td>
          <td class="text-center fw-bold">
            <?=$r['total_stock']?> <small class="text-muted"><?=htmlspecialchars($r['abbreviation'])?></small>
          </td>
          <td class="text-center text-muted"><?=$r['min_stock']?></td>
          <td class="text-center"><span class="badge <?=$st_cls?>"><?=$st_label?></span></td>
          <td class="text-end small"><?=idr((float)$r['stock_value'])?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex align-items-center justify-content-between py-2">
    <small class="text-muted">Menampilkan <?=count($rows)?> dari <?=$total?> barang</small>
    <?=pagination_html($pag,'?'.http_build_query(['company_id'=>$cf['param_value'],'q'=>$search,'location_id'=>$loc_filter]).'&page=%d')?>
  </div>
</div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
