<?php
require __DIR__ . '/lib.php';
require_post();
$in = input();

$f = [];
foreach (['name' => 120, 'phone' => 30, 'email' => 160, 'address' => 255, 'postcode' => 10, 'city' => 80, 'state' => 40, 'notes' => 500] as $k => $max) {
    $f[$k] = trim(mb_substr((string)($in[$k] ?? ''), 0, $max));
}
if ($f['name'] === '' || $f['address'] === '' || $f['city'] === '') fail('Sila lengkapkan nama dan alamat penghantaran.');
if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) fail('Alamat emel tidak sah.');
$phone = preg_replace('/[^0-9+]/', '', $f['phone']);
if (strlen(preg_replace('/\D/', '', $phone)) < 9) fail('Nombor telefon tidak sah.');
if (!preg_match('/^\d{5}$/', $f['postcode'])) fail('Poskod mesti 5 digit.');
if (!in_array($f['state'], STATES, true)) fail('Sila pilih negeri.');

$q = quote((array)($in['items'] ?? []), (string)($in['coupon'] ?? ''), $f['state']);
if (trim((string)($in['coupon'] ?? '')) !== '' && !$q['coupon']) fail($q['coupon_message'] ?: 'Kod kupon tidak sah.');

$ref = new_ref();
$token = bin2hex(random_bytes(16));
$pdo = db();
$pdo->prepare('INSERT INTO orders (ref, token, status, name, phone, email, address, postcode, city, state, region, items, subtotal, discount, coupon_code, shipping, total, notes, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
    $ref, $token, 'pending', $f['name'], $phone, $f['email'], $f['address'], $f['postcode'], $f['city'], $f['state'],
    $q['region'], json_encode($q['items'], JSON_UNESCAPED_UNICODE), $q['subtotal'], $q['discount'], $q['coupon'],
    $q['shipping'], $q['total'], $f['notes'] ?: null, now(),
]);
$orderId = (int)$pdo->lastInsertId();

$site = rtrim((string)cfg('site_url'), '/');
$back = $site . '/checkout/selesai/?ref=' . rawurlencode($ref) . '&t=' . $token;

$products = [];
foreach ($q['items'] as $it) {
    $products[] = ['name' => $it['name'], 'price' => $it['price'], 'quantity' => $it['qty']];
}
$products[] = ['name' => 'Penghantaran (' . $q['region_label'] . ')', 'price' => $q['shipping'], 'quantity' => 1];
$notes = $q['coupon'] ? 'Kupon ' . $q['coupon'] . ': -' . rm($q['discount']) : null;

try {
    $res = chip('POST', 'purchases/', [
        'brand_id' => cfg('chip_brand_id'),
        'reference' => $ref,
        'client' => [
            'email' => $f['email'],
            'full_name' => $f['name'],
            'phone' => $phone,
            'street_address' => $f['address'],
            'zip_code' => $f['postcode'],
            'city' => $f['city'],
            'state' => $f['state'],
            'country' => 'MY',
        ],
        'purchase' => array_filter([
            'currency' => 'MYR',
            'products' => $products,
            'total_override' => $q['total'],
            'notes' => $notes,
            'language' => 'ms',
        ], fn($v) => $v !== null),
        'send_receipt' => true,
        'success_redirect' => $back,
        'failure_redirect' => $back,
        'cancel_redirect' => $back,
        'success_callback' => $site . '/api/callback.php',
    ]);
} catch (Throwable $e) {
    $pdo->prepare("UPDATE orders SET status = 'failed' WHERE id = ?")->execute([$orderId]);
    fail('Maaf, sistem pembayaran tidak dapat dihubungi. Sila cuba lagi sebentar.', 502);
}

$chipTotal = (int)($res['purchase']['total'] ?? -1);
if (empty($res['id']) || empty($res['checkout_url']) || $chipTotal !== $q['total']) {
    error_log('CHIP jumlah tidak sepadan untuk ' . $ref . ': dijangka ' . $q['total'] . ', CHIP ' . $chipTotal);
    $pdo->prepare("UPDATE orders SET status = 'failed' WHERE id = ?")->execute([$orderId]);
    fail('Maaf, ralat semasa menyediakan pembayaran. Sila hubungi kami melalui WhatsApp.', 502);
}

$pdo->prepare('UPDATE orders SET chip_purchase_id = ? WHERE id = ?')->execute([$res['id'], $orderId]);
json_out(['ok' => true, 'ref' => $ref, 'checkout_url' => $res['checkout_url']]);
