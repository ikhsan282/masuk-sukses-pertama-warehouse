<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/purchase_orders.php';
require_perm('purchase_orders.view');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = req_int('id'); $action = req_str('action');
    if ($action === 'order' && can('purchase_orders.edit')) {
        $st=$db->prepare("UPDATE purchase_orders SET status='pending', approval_status='pending' WHERE id=? AND status='draft'");
        $st->bind_param('i',$id); $st->execute(); $ok=$st->affected_rows; $st->close();
        set_flash($ok?'success':'error',$ok?'Purchase order dikirim untuk approval.':'Status purchase order tidak dapat diubah.');
    } elseif ($action === 'cancel' && can('purchase_orders.cancel')) {
        $st=$db->prepare("UPDATE purchase_orders SET status='cancelled' WHERE id=? AND status IN ('draft','pending','partial')");
        $st->bind_param('i',$id); $st->execute(); $ok=$st->affected_rows; $st->close();
        set_flash($ok?'success':'error',$ok?'Purchase order dibatalkan.':'Purchase order tidak dapat dibatalkan.');
    } elseif ($action === 'delete' && can('purchase_orders.edit')) {
        $st=$db->prepare("DELETE FROM purchase_orders WHERE id=? AND status='draft'");
        $st->bind_param('i',$id); $st->execute(); $ok=$st->affected_rows; $st->close();
        set_flash($ok?'success':'error',$ok?'Draft purchase order dihapus.':'Hanya draft yang dapat dihapus.');
    } else set_flash('error','Aksi tidak diizinkan.');
    redirect(APP_URL.'/pages/purchase-orders/index.php');
}

$active_cid = current_company_id();
$status     = req_str('status');
$search     = grid_search_term('q');
$page       = max(1, req_int('page', 1));
$per_page   = grid_per_page();
grid_apply_timeout($db);

$where_parts=['po.company_id=?']; $params=[&$active_cid]; $types='i';
if (in_array($status,['draft','pending','partial','completed','cancelled'],true)) { $where_parts[]='po.status=?'; $params[]=&$status; $types.='s'; }
if ($search) { $where_parts[]='po.reference_no LIKE ?'; $like='%'.escape_like($search).'%'; $params[]=&$like; $types.='s'; }
$where='WHERE '.implode(' AND ', $where_parts);

$cq=$db->prepare("SELECT COUNT(*) FROM purchase_orders po $where");
$cq->bind_param($types,...$params); $cq->execute(); $cq->bind_result($total); $cq->fetch(); $cq->close();

$pag=paginate($total,$page,$per_page); $offset=$pag['offset']; $limit=$pag['per_page'];
$t2=$types.'ii'; $p2=$params; $p2[]=&$limit; $p2[]=&$offset;

$st=$db->prepare("SELECT po.*,s.name supplier_name,l.name location_name,u.name user_name,
 (SELECT COUNT(*) FROM purchase_order_details d WHERE d.po_id=po.id) item_count,
 (SELECT COALESCE(SUM(quantity*buy_price),0) FROM purchase_order_details d WHERE d.po_id=po.id) total
 FROM (SELECT po.id FROM purchase_orders po $where ORDER BY po.id DESC LIMIT ? OFFSET ?) pg
 JOIN purchase_orders po ON po.id=pg.id
 JOIN suppliers s ON s.id=po.supplier_id JOIN locations l ON l.id=po.location_id JOIN users u ON u.id=po.user_id ORDER BY po.id DESC");
$st->bind_param($t2,...$p2); $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
$labels=['draft'=>['Draft','secondary'],'pending'=>['Pending','warning'],'partial'=>['Sebagian','info'],'completed'=>['Selesai','success'],'cancelled'=>['Dibatalkan','danger']];
$approval_labels=['draft'=>['Draft','secondary'],'pending'=>['Pending','warning'],'approved'=>['Approved','success'],'rejected'=>['Rejected','danger']];
$page_title='Purchase Order'; include __DIR__.'/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center"><h4><i class="bi bi-cart-check me-2 text-primary"></i>Purchase Order</h4>
<?php if(can('purchase_orders.create')):?><a class="btn btn-primary btn-sm" href="form.php"><i class="bi bi-plus-lg me-1"></i>Buat PO</a><?php endif;?></div>
<div class="card table-card"><div class="card-header bg-white"><form method="get" class="d-flex flex-wrap gap-2"><input type="text" name="q" class="form-control form-control-sm" style="max-width:200px" placeholder="No. PO..." value="<?=htmlspecialchars($search)?>"><select name="status" class="form-select form-select-sm" style="max-width:180px"><option value="">Semua status</option><?php foreach($labels as $key=>$label):?><option value="<?=$key?>" <?=$status===$key?'selected':''?>><?=$label[0]?></option><?php endforeach;?></select><input type="hidden" name="per_page" value="<?=(int)$pag['per_page']?>"><button class="btn btn-sm btn-outline-secondary">Filter</button><?php if($search||$status):?><a href="?per_page=<?=(int)$pag['per_page']?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></a><?php endif;?></form></div>
<div class="table-responsive"><table class="table mb-0"><thead><tr><th>Nomor</th><th>Tanggal</th><th>Supplier</th><th>Tujuan</th><th>Item</th><th class="text-end">Nilai</th><th>Status</th><th>Approval</th><th class="text-center">Aksi</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="9" class="text-center text-muted py-4">Belum ada purchase order</td></tr><?php endif;?>
<?php foreach($rows as $r): [$label,$color]=$labels[$r['status']]; [$alabel,$acolor]=$approval_labels[$r['approval_status']];?><tr><td><a href="view.php?id=<?=$r['id']?>"><code><?=e($r['reference_no'])?></code></a></td><td><?=tgl($r['order_date'])?></td><td><?=e($r['supplier_name'])?></td><td><?=e($r['location_name'])?></td><td><?=$r['item_count']?></td><td class="text-end"><?=idr((float)$r['total'])?></td><td><span class="badge bg-<?=$color?>"><?=$label?></span></td><td><span class="badge bg-<?=$acolor?>"><?=$alabel?></span></td><td class="text-center text-nowrap">
<a class="btn btn-action btn-outline-info" href="view.php?id=<?=$r['id']?>"><i class="bi bi-eye"></i></a>
<?php if($r['status']==='draft'&&can('purchase_orders.edit')):?><a class="btn btn-action btn-outline-primary" href="form.php?id=<?=$r['id']?>"><i class="bi bi-pencil"></i></a><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><button name="action" value="order" class="btn btn-action btn-outline-success" title="Kirim untuk approval"><i class="bi bi-send"></i></button><button name="action" value="delete" class="btn btn-action btn-outline-danger" data-confirm="Hapus draft PO?"><i class="bi bi-trash"></i></button></form><?php endif;?>
<?php if($r['approval_status']==='pending'&&can('po.approve')):?><button class="btn btn-action btn-outline-success" data-bs-toggle="modal" data-bs-target="#approveModal<?=$r['id']?>" title="Approve"><i class="bi bi-check-lg"></i></button><button class="btn btn-action btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?=$r['id']?>" title="Reject"><i class="bi bi-x-lg"></i></button><?php endif;?>
<?php if(in_array($r['status'],['pending','partial'],true)&&$r['approval_status']==='approved'&&can('purchase_orders.receive')):?><a href="view.php?id=<?=$r['id']?>" class="btn btn-action btn-success" title="Terima barang"><i class="bi bi-box-arrow-in-down"></i></a><?php endif;?>
<?php if(in_array($r['status'],['draft','pending','partial'],true)&&can('purchase_orders.cancel')):?><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><button name="action" value="cancel" class="btn btn-action btn-outline-danger" data-confirm="Batalkan PO?"><i class="bi bi-x-lg"></i></button></form><?php endif;?>
</td></tr>
<!-- Approve Modal -->
<div class="modal fade" id="approveModal<?=$r['id']?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" action="approve.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="approve"><div class="modal-header"><h5 class="modal-title">Approve PO <?=e($r['reference_no'])?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">Catatan (opsional)</label><textarea name="approval_notes" class="form-control" rows="3"></textarea></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-success">Approve</button></div></form></div></div></div>
<!-- Reject Modal -->
<div class="modal fade" id="rejectModal<?=$r['id']?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" action="approve.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="reject"><div class="modal-header"><h5 class="modal-title">Reject PO <?=e($r['reference_no'])?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">Alasan reject <span class="text-danger">*</span></label><textarea name="approval_notes" class="form-control" rows="3" required></textarea></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-danger">Reject</button></div></form></div></div></div>
<?php endforeach;?></tbody></table></div><?= grid_footer_html($pag, 'purchase order', ['q' => $search, 'status' => $status]) ?></div>
<?php include __DIR__.'/../../includes/footer.php';?>
