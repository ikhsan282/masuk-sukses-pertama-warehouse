<?php
require_once __DIR__ . '/../../config/config.php';require_once __DIR__ . '/../../includes/auth.php';require_once __DIR__ . '/../../includes/functions.php';require_once __DIR__ . '/../../includes/purchase_orders.php';require_perm('purchase_orders.receive');
if($_SERVER['REQUEST_METHOD']!=='POST'){redirect(APP_URL.'/pages/purchase-orders/index.php');}verify_csrf();$id=req_int('id');
$db=getDB();$st=$db->prepare('SELECT company_id FROM purchase_orders WHERE id=?');$st->bind_param('i',$id);$st->execute();$po=$st->get_result()->fetch_assoc();$st->close();
if(!$po){set_flash('error','Purchase order tidak ditemukan.');redirect(APP_URL.'/pages/purchase-orders/index.php');}
require_company_access((int)$po['company_id']);
try{$ref=receive_purchase_order($db,$id,current_user()['id']);audit_log('po.receive','purchase_order',$id,(string)$ref,'Menerima PO dan membuat stok masuk '.$ref);set_flash('success','PO diterima dan stok masuk dibuat: '.$ref);}catch(Throwable $e){set_flash('error','Penerimaan gagal: '.$e->getMessage());}redirect(APP_URL.'/pages/purchase-orders/index.php');
