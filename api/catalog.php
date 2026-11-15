<?php
require __DIR__ . '/lib.php';
$out = [];
foreach (products() as $id => $p) {
    $out[$id] = ['name' => $p['short'], 'full' => $p['name'], 'price' => $p['price'], 'image' => $p['image'], 'url' => $p['url'], 'active' => $p['active']];
}
header('Cache-Control: public, max-age=300');
json_out(['ok' => true, 'products' => $out, 'shipping' => SHIPPING, 'states' => STATES, 'east_states' => EAST_STATES]);
