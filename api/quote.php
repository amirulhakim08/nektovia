<?php
require __DIR__ . '/lib.php';
require_post();
$in = input();
$q = quote((array)($in['items'] ?? []), (string)($in['coupon'] ?? ''), (string)($in['state'] ?? ''));
json_out(['ok' => true] + $q);
