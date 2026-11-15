<?php
/* NEKTOVIA — fungsi bersama untuk API dan admin. */
declare(strict_types=1);

const SHIPPING = [
    'semenanjung'   => ['label' => 'Semenanjung Malaysia', 'fee' => 800],
    'sabah_sarawak' => ['label' => 'Sabah, Sarawak & Labuan', 'fee' => 1800],
];
const EAST_STATES = ['Sabah', 'Sarawak', 'WP Labuan'];
const STATES = [
    'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis',
    'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu',
    'WP Kuala Lumpur', 'WP Labuan', 'WP Putrajaya',
];
const MAX_QTY = 20;

function cfg(string $key = null)
{
    static $c;
    if ($c === null) {
        $file = getenv('NEKTOVIA_CONFIG') ?: __DIR__ . '/config.php';
        if (!is_file($file)) {
            fail('Sistem belum dikonfigurasi (config.php tiada).', 500);
        }
        $c = require $file;
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

function products(): array
{
    static $p;
    return $p ??= require __DIR__ . '/products.php';
}

function db(): PDO
{
    static $pdo;
    if ($pdo) return $pdo;
    $pdo = new PDO(cfg('db_dsn'), cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS coupons (
        id $id,
        code VARCHAR(40) NOT NULL UNIQUE,
        discount INT NOT NULL,
        min_subtotal INT NOT NULL DEFAULT 0,
        max_uses INT NULL,
        used_count INT NOT NULL DEFAULT 0,
        starts_at DATE NULL,
        ends_at DATE NULL,
        active TINYINT NOT NULL DEFAULT 1,
        created_at VARCHAR(19) NOT NULL
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id $id,
        ref VARCHAR(24) NOT NULL UNIQUE,
        token VARCHAR(64) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        name VARCHAR(120) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        email VARCHAR(160) NOT NULL,
        address VARCHAR(255) NOT NULL,
        postcode VARCHAR(10) NOT NULL,
        city VARCHAR(80) NOT NULL,
        state VARCHAR(40) NOT NULL,
        region VARCHAR(20) NOT NULL,
        items TEXT NOT NULL,
        subtotal INT NOT NULL,
        discount INT NOT NULL DEFAULT 0,
        coupon_code VARCHAR(40) NULL,
        shipping INT NOT NULL,
        total INT NOT NULL,
        chip_purchase_id VARCHAR(64) NULL,
        tracking_no VARCHAR(80) NULL,
        notes TEXT NULL,
        coupon_counted TINYINT NOT NULL DEFAULT 0,
        created_at VARCHAR(19) NOT NULL,
        paid_at VARCHAR(19) NULL,
        shipped_at VARCHAR(19) NULL
    )$tail");
    // Kupon permulaan (boleh diubah/dipadam di halaman admin)
    $n = (int)$pdo->query('SELECT COUNT(*) FROM coupons')->fetchColumn();
    if ($n === 0) {
        $ins = $pdo->prepare('INSERT INTO coupons (code, discount, min_subtotal, active, created_at) VALUES (?,?,?,1,?)');
        $ins->execute(['JIMAT20', 2000, 9000, now()]);
        $ins->execute(['JIMAT50', 5000, 15000, now()]);
    }
}

function now(): string
{
    return (new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d H:i:s');
}

function rm(int $sen): string
{
    return 'RM' . number_format($sen / 100, 2);
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void
{
    json_out(['ok' => false, 'error' => $msg], $code);
}

function input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Kaedah tidak dibenarkan.', 405);
}

function region_for(string $state): string
{
    return in_array($state, EAST_STATES, true) ? 'sabah_sarawak' : 'semenanjung';
}

/** Sahkan troli & kira jumlah. Semua harga dari pelayan, bukan dari pelayar. */
function quote(array $rawItems, string $couponCode, string $state): array
{
    $catalog = products();
    $items = [];
    $subtotal = 0;
    foreach ($rawItems as $id => $qty) {
        $id = (string)$id;
        $qty = (int)$qty;
        if ($qty <= 0) continue;
        if (!isset($catalog[$id])) fail('Produk tidak dikenali.');
        $p = $catalog[$id];
        if (!$p['active']) fail($p['short'] . ' belum dibuka untuk jualan.');
        if ($qty > MAX_QTY) fail('Maksimum ' . MAX_QTY . ' unit bagi setiap produk.');
        $line = $p['price'] * $qty;
        $items[] = ['id' => $id, 'name' => $p['name'], 'price' => $p['price'], 'qty' => $qty, 'line' => $line];
        $subtotal += $line;
    }
    if (!$items) fail('Troli anda kosong.');

    $region = $state !== '' ? region_for($state) : 'semenanjung';
    $shipping = SHIPPING[$region]['fee'];

    $discount = 0;
    $coupon = null;
    $couponMsg = null;
    $couponCode = strtoupper(trim($couponCode));
    if ($couponCode !== '') {
        [$coupon, $couponMsg] = check_coupon($couponCode, $subtotal);
        if ($coupon) $discount = min((int)$coupon['discount'], $subtotal);
    }

    return [
        'items' => $items,
        'subtotal' => $subtotal,
        'discount' => $discount,
        'coupon' => $coupon ? $coupon['code'] : null,
        'coupon_message' => $couponMsg,
        'region' => $region,
        'region_label' => SHIPPING[$region]['label'],
        'shipping' => $shipping,
        'total' => $subtotal - $discount + $shipping,
    ];
}

/** @return array{0:?array,1:string} */
function check_coupon(string $code, int $subtotal): array
{
    $st = db()->prepare('SELECT * FROM coupons WHERE UPPER(code) = ?');
    $st->execute([$code]);
    $c = $st->fetch();
    $today = substr(now(), 0, 10);
    if (!$c || !(int)$c['active']) return [null, 'Kod kupon tidak sah.'];
    if ($c['starts_at'] && $today < $c['starts_at']) return [null, 'Kod kupon belum bermula.'];
    if ($c['ends_at'] && $today > $c['ends_at']) return [null, 'Kod kupon telah tamat tempoh.'];
    if ($c['max_uses'] !== null && (int)$c['used_count'] >= (int)$c['max_uses']) return [null, 'Kod kupon telah habis digunakan.'];
    if ($subtotal < (int)$c['min_subtotal']) {
        return [null, 'Kod ' . $c['code'] . ' memerlukan belian minimum ' . rm((int)$c['min_subtotal']) . ' (tidak termasuk penghantaran). Tambah ' . rm((int)$c['min_subtotal'] - $subtotal) . ' lagi.'];
    }
    return [$c, 'Kod ' . $c['code'] . ' digunakan: jimat ' . rm((int)$c['discount']) . '.'];
}

/* ---------- CHIP ---------- */

function chip(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init(rtrim((string)cfg('chip_endpoint'), '/') . '/' . ltrim($path, '/'));
    $headers = ['Authorization: Bearer ' . cfg('chip_secret_key'), 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $data = is_string($res) ? json_decode($res, true) : null;
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        error_log('CHIP ' . $method . ' ' . $path . ' gagal: HTTP ' . $code . ' ' . $err . ' ' . substr((string)$res, 0, 500));
        throw new RuntimeException('Gagal berhubung dengan CHIP.');
    }
    return $data;
}

/** Semak status sebenar di CHIP dan kemas kini pesanan. Pulangkan pesanan terkini. */
function sync_order(array $order): array
{
    if ($order['status'] !== 'pending' || !$order['chip_purchase_id']) return $order;
    try {
        $p = chip('GET', 'purchases/' . rawurlencode($order['chip_purchase_id']) . '/');
    } catch (Throwable $e) {
        return $order;
    }
    $status = $p['status'] ?? '';
    $paidTotal = (int)($p['purchase']['total'] ?? -1);
    if ($status === 'paid' && $paidTotal === (int)$order['total']) {
        mark_paid((int)$order['id']);
    } elseif (in_array($status, ['error', 'cancelled', 'expired', 'blocked'], true)) {
        db()->prepare("UPDATE orders SET status = 'failed' WHERE id = ? AND status = 'pending'")->execute([$order['id']]);
    }
    return find_order('id', (string)$order['id']);
}

function mark_paid(int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = ? WHERE id = ? AND status = 'pending'");
    $st->execute([now(), $id]);
    if ($st->rowCount() === 1) {
        $o = find_order('id', (string)$id);
        if ($o['coupon_code'] && !(int)$o['coupon_counted']) {
            $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE code = ?')->execute([$o['coupon_code']]);
            $pdo->prepare('UPDATE orders SET coupon_counted = 1 WHERE id = ?')->execute([$id]);
        }
    }
    $pdo->commit();
}

function find_order(string $field, string $value): ?array
{
    $allowed = ['id', 'ref', 'chip_purchase_id'];
    if (!in_array($field, $allowed, true)) return null;
    $st = db()->prepare("SELECT * FROM orders WHERE $field = ?");
    $st->execute([$value]);
    return $st->fetch() ?: null;
}

function new_ref(): string
{
    return 'NKT' . (new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur')))->format('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}
