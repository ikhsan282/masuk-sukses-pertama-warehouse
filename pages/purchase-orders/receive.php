<?php
require_once __DIR__ . '/../../config/config.php';require_once __DIR__ . '/../../includes/auth.php';require_once __DIR__ . '/../../includes/functions.php';require_once __DIR__ . '/../../includes/purchase_orders.php';require_perm('purchase_orders.receive');
if($_SERVER['REQUEST_METHOD']!=='POST'){redirect(APP_URL.'/pages/purchase-orders/index.php');}verify_csrf();$id=req_int('id');
try{$ref=receive_purchase_order(getDB(),$id,current_user()['id']);audit_log('po.receive','purchase_order',$id,(string)$ref,'Menerima PO dan membuat stok masuk '.$ref);set_flash('success','PO diterima dan stok masuk dibuat: '.$ref);}catch(Throwable $e){set_flash('error','Penerimaan gagal: '.$e->getMessage());}redirect(APP_URL.'/pages/purchase-orders/index.php');
