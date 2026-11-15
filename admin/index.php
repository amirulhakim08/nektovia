<?php
/* NEKTOVIA — halaman admin: pesanan & kupon. */
declare(strict_types=1);
require __DIR__ . '/../api/lib.php';

session_name('nktadmin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function check_csrf(): void { if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Sesi tamat. Muat semula halaman.'); } }
function go(string $q = ''): void { header('Location: ./' . ($q ? '?' . $q : '')); exit; }
function flash(?string $m = null): ?string { if ($m !== null) { $_SESSION['flash'] = $m; return null; } $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function money_in(string $v): int { return (int)round((float)str_replace(',', '', $v) * 100); }

const STATUS = ['pending' => 'Menunggu bayaran', 'paid' => 'Dibayar', 'shipped' => 'Dihantar', 'failed' => 'Gagal/Batal', 'cancelled' => 'Dibatalkan'];

$p = $_GET['p'] ?? 'orders';

/* ---------- Log masuk ---------- */
if ($p === 'logout') { session_destroy(); go(); }
if (empty($_SESSION['admin'])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pw = (string)cfg('admin_password');
        if ($pw !== '' && $pw !== 'TUKAR_KATA_LALUAN_INI' && hash_equals($pw, (string)($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            go();
        }
        sleep(2);
        $err = 'Kata laluan salah.';
    }
    layout('Log masuk', '<form method="post" class="card login"><h1>Admin NEKTOVIA</h1>' . ($err ? '<p class="err">' . h($err) . '</p>' : '') .
        '<label>Kata laluan<input type="password" name="password" autofocus required></label><button class="btn">Log masuk</button></form>', false);
    exit;
}

$pdo = db();

/* ---------- Tindakan (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['a'] ?? '';
    if ($a === 'order_update') {
        $id = (int)$_POST['id'];
        $status = (string)$_POST['status'];
        if (!isset(STATUS[$status])) go();
        $o = find_order('id', (string)$id);
        if ($status === 'paid' && $o && $o['status'] === 'pending') mark_paid($id);
        $pdo->prepare('UPDATE orders SET status = ?, tracking_no = ?, notes = ?, shipped_at = CASE WHEN ? = \'shipped\' AND shipped_at IS NULL THEN ? ELSE shipped_at END WHERE id = ?')
            ->execute([$status, trim((string)$_POST['tracking_no']) ?: null, trim((string)$_POST['notes']) ?: null, $status, now(), $id]);
        flash('Pesanan dikemas kini.');
        go('p=order&id=' . $id);
    }
    if ($a === 'order_sync') {
        $o = find_order('id', (string)(int)$_POST['id']);
        if ($o) { $o = sync_order($o); flash('Status di CHIP disemak: ' . STATUS[$o['status']] . '.'); }
        go('p=order&id=' . (int)$_POST['id']);
    }
    if ($a === 'coupon_save') {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_POST['code']));
        $disc = money_in((string)$_POST['discount']);
        $min = money_in((string)$_POST['min_subtotal']);
        $max = trim((string)$_POST['max_uses']) === '' ? null : max(0, (int)$_POST['max_uses']);
        $start = (string)$_POST['starts_at'] ?: null;
        $end = (string)$_POST['ends_at'] ?: null;
        $active = isset($_POST['active']) ? 1 : 0;
        $id = (int)($_POST['id'] ?? 0);
        if ($code === '' || $disc <= 0) { flash('Kod dan nilai diskaun diperlukan.'); go('p=coupons'); }
        try {
            if ($id) {
                $pdo->prepare('UPDATE coupons SET code=?, discount=?, min_subtotal=?, max_uses=?, starts_at=?, ends_at=?, active=? WHERE id=?')
                    ->execute([$code, $disc, $min, $max, $start, $end, $active, $id]);
            } else {
                $pdo->prepare('INSERT INTO coupons (code, discount, min_subtotal, max_uses, starts_at, ends_at, active, created_at) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$code, $disc, $min, $max, $start, $end, $active, now()]);
            }
            flash('Kupon ' . $code . ' disimpan.');
        } catch (PDOException $e) {
            flash('Kod ' . $code . ' sudah wujud.');
        }
        go('p=coupons');
    }
    if ($a === 'coupon_delete') {
        $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('Kupon dipadam.');
        go('p=coupons');
    }
    go();
}

/* ---------- Eksport CSV ---------- */
if ($p === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pesanan-nektovia-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Rujukan', 'Tarikh', 'Status', 'Nama', 'Telefon', 'Emel', 'Alamat', 'Poskod', 'Bandar', 'Negeri', 'Produk', 'Subjumlah', 'Kupon', 'Diskaun', 'Penghantaran', 'Jumlah', 'No. Tracking', 'Dibayar pada']);
    foreach ($pdo->query("SELECT * FROM orders WHERE status IN ('paid','shipped') ORDER BY id DESC") as $o) {
        $items = implode('; ', array_map(fn($i) => $i['name'] . ' x' . $i['qty'], json_decode($o['items'], true)));
        fputcsv($out, [$o['ref'], $o['created_at'], STATUS[$o['status']], $o['name'], $o['phone'], $o['email'], $o['address'], $o['postcode'], $o['city'], $o['state'],
            $items, $o['subtotal'] / 100, $o['coupon_code'], $o['discount'] / 100, $o['shipping'] / 100, $o['total'] / 100, $o['tracking_no'], $o['paid_at']]);
    }
    exit;
}

/* ---------- Paparan ---------- */
$msg = flash();
$html = $msg ? '<p class="flash">' . h($msg) . '</p>' : '';

if ($p === 'order') {
    $o = find_order('id', (string)(int)($_GET['id'] ?? 0));
    if (!$o) go();
    $items = json_decode($o['items'], true);
    $rows = '';
    foreach ($items as $i) $rows .= '<tr><td>' . h($i['name']) . '</td><td>' . (int)$i['qty'] . '</td><td class="r">' . rm((int)$i['line']) . '</td></tr>';
    $opts = '';
    foreach (STATUS as $k => $v) $opts .= '<option value="' . $k . '"' . ($o['status'] === $k ? ' selected' : '') . '>' . $v . '</option>';
    $wa = 'https://wa.me/' . preg_replace('/\D/', '', preg_replace('/^0/', '60', $o['phone']));
    $html .= '<p><a href="./">← Semua pesanan</a></p><div class="grid2"><div class="card"><h2>' . h($o['ref']) . ' <span class="st st-' . $o['status'] . '">' . STATUS[$o['status']] . '</span></h2>
      <table><tr><th>Produk</th><th>Kuantiti</th><th class="r">Jumlah</th></tr>' . $rows . '
      <tr><td colspan="2">Subjumlah</td><td class="r">' . rm((int)$o['subtotal']) . '</td></tr>' .
      ($o['discount'] ? '<tr><td colspan="2">Diskaun (' . h($o['coupon_code']) . ')</td><td class="r">−' . rm((int)$o['discount']) . '</td></tr>' : '') . '
      <tr><td colspan="2">Penghantaran (' . h(SHIPPING[$o['region']]['label']) . ')</td><td class="r">' . rm((int)$o['shipping']) . '</td></tr>
      <tr class="tot"><td colspan="2">Jumlah</td><td class="r">' . rm((int)$o['total']) . '</td></tr></table>
      <p class="muted">Dibuat: ' . h($o['created_at']) . ($o['paid_at'] ? ' · Dibayar: ' . h($o['paid_at']) : '') . ($o['shipped_at'] ? ' · Dihantar: ' . h($o['shipped_at']) : '') . '<br>ID CHIP: ' . h($o['chip_purchase_id'] ?: '—') . '</p>' .
      ($o['status'] === 'pending' ? '<form method="post">' . csrf_field() . '<input type="hidden" name="a" value="order_sync"><input type="hidden" name="id" value="' . $o['id'] . '"><button class="btn ghost">Semak status di CHIP</button></form>' : '') . '</div>
      <div class="card"><h2>Penghantaran</h2><p><strong>' . h($o['name']) . '</strong><br>' . h($o['address']) . '<br>' . h($o['postcode'] . ' ' . $o['city']) . '<br>' . h($o['state']) . '</p>
      <p>📞 ' . h($o['phone']) . ' · <a href="' . h($wa) . '" target="_blank" rel="noopener">WhatsApp</a><br>✉️ ' . h($o['email']) . '</p>
      <form method="post" class="stack">' . csrf_field() . '<input type="hidden" name="a" value="order_update"><input type="hidden" name="id" value="' . $o['id'] . '">
      <label>Status<select name="status">' . $opts . '</select></label>
      <label>No. tracking<input name="tracking_no" value="' . h($o['tracking_no']) . '"></label>
      <label>Nota<textarea name="notes" rows="3">' . h($o['notes']) . '</textarea></label><button class="btn">Simpan</button></form></div></div>';
    layout('Pesanan ' . $o['ref'], $html);
    exit;
}

if ($p === 'coupons') {
    $edit = null;
    if (isset($_GET['edit'])) { $st = $pdo->prepare('SELECT * FROM coupons WHERE id = ?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch() ?: null; }
    $rows = '';
    foreach ($pdo->query('SELECT * FROM coupons ORDER BY min_subtotal, code') as $c) {
        $rows .= '<tr><td><strong>' . h($c['code']) . '</strong></td><td>' . rm((int)$c['discount']) . '</td><td>' . rm((int)$c['min_subtotal']) . '</td><td>' . (int)$c['used_count'] . ($c['max_uses'] !== null ? ' / ' . (int)$c['max_uses'] : '') . '</td><td>' . h(($c['starts_at'] ?: '—') . ' → ' . ($c['ends_at'] ?: '—')) . '</td><td>' . ((int)$c['active'] ? '<span class="st st-paid">Aktif</span>' : '<span class="st st-failed">Tidak aktif</span>') . '</td>
          <td class="r"><a href="?p=coupons&edit=' . $c['id'] . '">Ubah</a> <form method="post" class="inline" onsubmit="return confirm(\'Padam kupon ' . h($c['code']) . '?\')">' . csrf_field() . '<input type="hidden" name="a" value="coupon_delete"><input type="hidden" name="id" value="' . $c['id'] . '"><button class="link">Padam</button></form></td></tr>';
    }
    $v = fn($k, $d = '') => h($edit[$k] ?? $d);
    $html .= '<div class="card"><h2>Kupon</h2><table><tr><th>Kod</th><th>Diskaun</th><th>Min. belian</th><th>Digunakan</th><th>Tempoh</th><th>Status</th><th></th></tr>' . ($rows ?: '<tr><td colspan="7" class="muted">Tiada kupon.</td></tr>') . '</table>
      <p class="muted">Belian minimum dikira daripada harga produk sahaja (tidak termasuk penghantaran). Kiraan "Digunakan" bertambah bila pesanan dibayar.</p></div>
      <div class="card"><h2>' . ($edit ? 'Ubah kupon ' . h($edit['code']) : 'Kupon baharu') . '</h2><form method="post" class="form-grid">' . csrf_field() . '<input type="hidden" name="a" value="coupon_save"><input type="hidden" name="id" value="' . $v('id', '0') . '">
      <label>Kod<input name="code" required value="' . $v('code') . '" placeholder="JIMAT20"></label>
      <label>Diskaun (RM)<input name="discount" type="number" step="0.01" min="0.01" required value="' . ($edit ? number_format($edit['discount'] / 100, 2, '.', '') : '') . '"></label>
      <label>Min. belian (RM)<input name="min_subtotal" type="number" step="0.01" min="0" value="' . ($edit ? number_format($edit['min_subtotal'] / 100, 2, '.', '') : '0') . '"></label>
      <label>Had penggunaan<input name="max_uses" type="number" min="0" value="' . $v('max_uses') . '" placeholder="Tanpa had"></label>
      <label>Mula<input name="starts_at" type="date" value="' . $v('starts_at') . '"></label>
      <label>Tamat<input name="ends_at" type="date" value="' . $v('ends_at') . '"></label>
      <label class="chk"><input type="checkbox" name="active"' . (!$edit || (int)$edit['active'] ? ' checked' : '') . '> Aktif</label>
      <div><button class="btn">Simpan kupon</button>' . ($edit ? ' <a href="?p=coupons">Batal</a>' : '') . '</div></form></div>';
    layout('Kupon', $html);
    exit;
}

// Senarai pesanan
$filter = $_GET['s'] ?? 'active';
$where = match ($filter) {
    'all' => '1=1',
    'pending' => "status = 'pending'",
    'shipped' => "status = 'shipped'",
    'failed' => "status IN ('failed','cancelled')",
    default => "status = 'paid'",
};
$search = trim((string)($_GET['q'] ?? ''));
$params = [];
if ($search !== '') { $where .= ' AND (ref LIKE ? OR name LIKE ? OR phone LIKE ? OR email LIKE ?)'; $params = array_fill(0, 4, '%' . $search . '%'); }
$st = $pdo->prepare("SELECT * FROM orders WHERE $where ORDER BY id DESC LIMIT 300");
$st->execute($params);
$rows = '';
foreach ($st as $o) {
    $qty = array_sum(array_map(fn($i) => (int)$i['qty'], json_decode($o['items'], true)));
    $rows .= '<tr onclick="location=\'?p=order&id=' . $o['id'] . '\'"><td><a href="?p=order&id=' . $o['id'] . '">' . h($o['ref']) . '</a></td><td>' . h(substr($o['created_at'], 0, 16)) . '</td><td>' . h($o['name']) . '<br><span class="muted">' . h($o['state']) . '</span></td><td>' . $qty . ' unit' . ($o['coupon_code'] ? '<br><span class="muted">' . h($o['coupon_code']) . '</span>' : '') . '</td><td class="r">' . rm((int)$o['total']) . '</td><td><span class="st st-' . $o['status'] . '">' . STATUS[$o['status']] . '</span></td></tr>';
}
$stats = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM orders WHERE status IN ('paid','shipped')")->fetch();
$toShip = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'paid'")->fetchColumn();
$tabs = '';
foreach (['active' => 'Perlu dihantar', 'shipped' => 'Dihantar', 'pending' => 'Menunggu bayaran', 'failed' => 'Gagal', 'all' => 'Semua'] as $k => $v) {
    $tabs .= '<a class="tab' . ($filter === $k ? ' on' : '') . '" href="?s=' . $k . '">' . $v . '</a>';
}
$html .= '<div class="stats"><div class="card"><small>Perlu dihantar</small><b>' . $toShip . '</b></div><div class="card"><small>Pesanan dibayar</small><b>' . (int)$stats['n'] . '</b></div><div class="card"><small>Jumlah jualan</small><b>' . rm((int)$stats['t']) . '</b></div></div>
  <div class="card"><div class="bar"><nav class="tabs">' . $tabs . '</nav><form class="search"><input type="hidden" name="s" value="' . h($filter) . '"><input name="q" value="' . h($search) . '" placeholder="Cari nama, telefon, rujukan"></form></div>
  <div class="scroll"><table class="list"><tr><th>Rujukan</th><th>Tarikh</th><th>Pelanggan</th><th>Item</th><th class="r">Jumlah</th><th>Status</th></tr>' . ($rows ?: '<tr><td colspan="6" class="muted">Tiada pesanan.</td></tr>') . '</table></div>
  <p><a href="?p=export">⬇ Muat turun CSV (pesanan dibayar)</a></p></div>';
layout('Pesanan', $html);

function layout(string $title, string $body, bool $nav = true): void
{
    echo '<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . h($title) . ' — Admin NEKTOVIA</title><link rel="icon" href="/assets/favicon.svg">
    <style>:root{--ink:#2a2620;--muted:#776e62;--line:#e6dccb;--gold:#9a7339;--bg:#f7f2e8;--forest:#1f2d24}*{box-sizing:border-box}body{margin:0;font:15px/1.5 Inter,-apple-system,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--ink)}a{color:var(--gold)}
    header{background:var(--forest);color:#fff;padding:14px 20px;display:flex;gap:18px;align-items:center;flex-wrap:wrap}header b{font-family:Georgia,serif;letter-spacing:.08em;margin-right:auto}header a{color:#f1dcae;text-decoration:none}
    main{max-width:1100px;margin:0 auto;padding:20px 16px}.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px}h1,h2{font-family:Georgia,serif;margin:0 0 12px}h2{font-size:20px}
    table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}.r{text-align:right}.tot td{font-weight:700;border-bottom:0}
    .list tr:not(:first-child){cursor:pointer}.list tr:not(:first-child):hover{background:#fbf7ef}.scroll{overflow-x:auto}.muted{color:var(--muted);font-size:13px}
    .st{display:inline-block;padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600;white-space:nowrap}.st-paid{background:#e3f4e8;color:#1c6b3a}.st-shipped{background:#e4ecf8;color:#24508f}.st-pending{background:#fbf0d9;color:#8a6212}.st-failed,.st-cancelled{background:#f8e3df;color:#9b3826}
    .btn{background:var(--gold);color:#fff;border:0;border-radius:99px;padding:10px 20px;font:inherit;font-weight:600;cursor:pointer}.btn.ghost{background:#fff;color:var(--gold);border:1px solid var(--line)}.link{background:none;border:0;color:#9b3826;cursor:pointer;font:inherit;padding:0}.inline{display:inline}
    input,select,textarea{width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:9px;font:inherit;background:#fff;margin-top:4px}label{display:block;font-size:13px;font-weight:600}.chk{display:flex;gap:8px;align-items:center}.chk input{width:auto;margin:0}
    .stack{display:grid;gap:12px}.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end}.grid2{display:grid;grid-template-columns:1.3fr 1fr;gap:16px}
    .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.stats small{display:block;color:var(--muted)}.stats b{font-size:24px;font-family:Georgia,serif}
    .bar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}.tabs{display:flex;gap:6px;flex-wrap:wrap}.tab{padding:6px 12px;border-radius:99px;text-decoration:none;color:var(--muted);border:1px solid var(--line);font-size:13px}.tab.on{background:var(--forest);color:#fff;border-color:var(--forest)}.search input{margin:0;min-width:240px}
    .flash{background:#e3f4e8;color:#1c6b3a;padding:10px 14px;border-radius:10px}.err{color:#9b3826}.login{max-width:360px;margin:12vh auto;display:grid;gap:14px}
    @media(max-width:760px){.grid2,.stats{grid-template-columns:1fr}.search input{min-width:0}}</style></head><body>' .
    ($nav ? '<header><b>NEKTOVIA · Admin</b><a href="./">Pesanan</a><a href="?p=coupons">Kupon</a><a href="/" target="_blank">Laman web</a><a href="?p=logout">Log keluar</a></header>' : '') .
    '<main>' . $body . '</main></body></html>';
}
