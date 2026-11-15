<?php
require __DIR__ . '/lib.php';
$ref = (string)($_GET['ref'] ?? '');
$token = (string)($_GET['t'] ?? '');
$order = $ref !== '' ? find_order('ref', $ref) : null;
if (!$order || !hash_equals($order['token'], $token)) fail('Pesanan tidak dijumpai.', 404);
$order = sync_order($order);
json_out([
    'ok' => true,
    'ref' => $order['ref'],
    'status' => $order['status'],
    'name' => $order['name'],
    'items' => json_decode($order['items'], true),
    'subtotal' => (int)$order['subtotal'],
    'discount' => (int)$order['discount'],
    'coupon' => $order['coupon_code'],
    'shipping' => (int)$order['shipping'],
    'total' => (int)$order['total'],
    'whatsapp' => cfg('whatsapp'),
]);
