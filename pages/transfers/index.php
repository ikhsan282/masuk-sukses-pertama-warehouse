<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_perm('transfers.view');
$db = getDB();

$page       = max(1, req_int('page', 1));
$per_page   = grid_per_page();
grid_apply_timeout($db);
$active_cid = current_company_id();

$cq=$db->prepare('SELECT (SELECT COUNT(*) FROM transfers WHERE from_company_id=?) + (SELECT COUNT(*) FROM transfers WHERE to_company_id=? AND from_company_id<>?)');
$cq->bind_param('iii', $active_cid, $active_cid, $active_cid);
$cq->execute();
$total=(int)$cq->get_result()->fetch_row()[0];
$cq->close();

$pag=paginate($total,$page,$per_page); $offset=$pag['offset']; $limit=$pag['per_page'];
$inner_limit = $limit + $offset;
$st=$db->prepare('SELECT t.*, fl.name AS from_loc, tl.name AS to_loc, u.name AS user_name,
    fc.code AS from_comp_code, tc.code AS to_comp_code,
    (SELECT COUNT(*) FROM transfer_details d WHERE d.transfer_id=t.id) AS item_count
    FROM (
        SELECT id FROM (
            (SELECT id FROM transfers WHERE from_company_id=? ORDER BY id DESC LIMIT ?)
            UNION
            (SELECT id FROM transfers WHERE to_company_id=? ORDER BY id DESC LIMIT ?)
        ) combined ORDER BY id DESC LIMIT ? OFFSET ?
    ) pg
    JOIN transfers t ON t.id=pg.id
    JOIN locations fl ON fl.id=t.from_location_id
    JOIN locations tl ON tl.id=t.to_location_id
    JOIN users u ON u.id=t.user_id
    LEFT JOIN companies fc ON fc.id=t.from_company_id
    LEFT JOIN companies tc ON tc.id=t.to_company_id
    ORDER BY t.id DESC');
$st->bind_param('iiiiii', $active_cid, $inner_limit, $active_cid, $inner_limit, $limit, $offset); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$page_title='Transfer Lokasi'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-arrow-left-right me-2 text-primary"></i>Transfer Antar Lokasi</h4>
  <?php if(can('transfers.create')):?>
  <a href="<?=APP_URL?>/pages/transfers/create.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Buat Transfer
  </a>
  <?php endif;?>
</div>
<div class="card table-card">
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>No. Referensi</th><th>Tanggal</th><th>Dari</th><th>Ke</th><th class="text-center">Item</th><th>Oleh</th><th class="text-center">Aksi</th></tr></thead>
      <tbody>
      <?php if(empty($rows)):?>
        <tr><td colspan="7" class="text-center text-muted py-4">Belum ada transfer</td></tr>
      <?php else: foreach($rows as $r):?>
        <tr>
          <td><code class="small"><?=htmlspecialchars($r['reference_no'])?></code></td>
          <td class="small"><?=tgl($r['transaction_date'])?></td>
          <td class="small">
            <?=htmlspecialchars($r['from_loc'])?>
            <?php if ($r['from_company_id'] != $r['to_company_id']): ?>
              <span class="badge bg-secondary-subtle text-secondary border ms-1"><?=htmlspecialchars($r['from_comp_code'] ?? '')?></span>
            <?php endif; ?>
          </td>
          <td class="small">
            <?=htmlspecialchars($r['to_loc'])?>
            <?php if ($r['from_company_id'] != $r['to_company_id']): ?>
              <span class="badge bg-primary-subtle text-primary border ms-1"><?=htmlspecialchars($r['to_comp_code'] ?? '')?></span>
            <?php endif; ?>
          </td>
          <td class="text-center"><span class="badge bg-info-subtle text-info"><?=$r['item_count']?> item</span></td>
          <td class="small text-muted"><?=htmlspecialchars($r['user_name'])?></td>
          <td class="text-center">
            <a href="<?=APP_URL?>/pages/transfers/view.php?id=<?=$r['id']?>" class="btn btn-action btn-outline-info"><i class="bi bi-eye"></i></a>
            <?php if(can('transfers.delete')):?>
            <form method="POST" action="<?=APP_URL?>/pages/transfers/delete.php" class="d-inline">
              <?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-action btn-outline-danger" data-confirm="Hapus transfer ini? Stok akan dikembalikan."><i class="bi bi-trash"></i></button>
            </form>
            <?php endif;?>
          </td>
        </tr>
      <?php endforeach;endif;?>
      </tbody>
    </table>
  </div>
  <?= grid_footer_html($pag, 'transfer') ?>
</div>
<?php include __DIR__.'/../../includes/footer.php';?>
