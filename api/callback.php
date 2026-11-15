<?php
/* CHIP memanggil URL ini selepas bayaran berjaya. Kita tidak percaya kandungan
   panggilan — status disemak semula terus dengan CHIP menggunakan secret key. */
require __DIR__ . '/lib.php';
$in = input();
$id = (string)($in['id'] ?? '');
if ($id === '') fail('id tiada', 400);
$order = find_order('chip_purchase_id', $id);
if (!$order) fail('pesanan tidak dijumpai', 404);
$order = sync_order($order);
json_out(['ok' => true, 'status' => $order['status']]);
