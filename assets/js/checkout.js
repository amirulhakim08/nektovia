/* NEKTOVIA — halaman checkout */
(function () {
  'use strict';
  var C = window.NKTCart, rm = C.rm;
  var form = document.getElementById('co');
  var empty = document.getElementById('empty');
  var itemsEl = document.getElementById('items');
  var errEl = document.getElementById('err');
  var couponEl = document.getElementById('coupon');
  var couponMsg = document.getElementById('couponMsg');
  var stateEl = document.getElementById('state');
  var payBtn = document.getElementById('pay');
  var catalog = null, appliedCoupon = '', lastQuote = null, seq = 0;
  var FIELDS = ['name', 'phone', 'email', 'address', 'postcode', 'city', 'state'];
  var SAVE_KEY = 'nkt_checkout_info';

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }

  function post(url, body) {
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Ralat pelayan.' }; }); });
  }

  function restoreInfo() {
    try {
      var d = JSON.parse(localStorage.getItem(SAVE_KEY) || '{}');
      FIELDS.forEach(function (k) { if (d[k] && form[k]) form[k].value = d[k]; });
    } catch (e) {}
  }
  function saveInfo() {
    try {
      var d = {};
      FIELDS.forEach(function (k) { d[k] = form[k].value; });
      localStorage.setItem(SAVE_KEY, JSON.stringify(d));
    } catch (e) {}
  }

  function renderItems() {
    var cart = C.read(), html = '';
    Object.keys(cart).forEach(function (id) {
      var p = catalog[id];
      if (!p) return;
      html += '<li><img src="' + esc(p.image) + '" alt="" width="56" height="56">' +
        '<div class="ci-main"><b>' + esc(p.name) + '</b><span>' + rm(p.price) + (p.active ? '' : ' · <em>belum dibuka</em>') + '</span>' +
        '<div class="qty qty-sm"><button type="button" data-id="' + id + '" data-d="-1" aria-label="Kurang">−</button><span>' + cart[id] + '</span><button type="button" data-id="' + id + '" data-d="1" aria-label="Tambah">+</button></div></div>' +
        '<div class="ci-end"><b>' + rm(p.price * cart[id]) + '</b><button type="button" class="ci-rm" data-id="' + id + '" data-rm>Buang</button></div></li>';
    });
    itemsEl.innerHTML = html;
    var has = C.count() > 0;
    form.hidden = !has;
    empty.hidden = has;
  }

  itemsEl.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) return;
    var id = b.dataset.id, cart = C.read();
    if (b.hasAttribute('data-rm')) C.set(id, 0);
    else C.set(id, (+cart[id] || 0) + +b.dataset.d);
    renderItems();
    requote();
  });

  function requote() {
    if (!C.count()) return;
    var my = ++seq;
    post('/api/quote.php', { items: C.read(), coupon: appliedCoupon, state: stateEl.value }).then(function (q) {
      if (my !== seq) return;
      if (!q.ok) { errEl.textContent = q.error || 'Ralat.'; payBtn.disabled = true; return; }
      errEl.textContent = '';
      payBtn.disabled = false;
      lastQuote = q;
      $('subtotal').textContent = rm(q.subtotal);
      $('discRow').hidden = !q.discount;
      $('discount').textContent = '−' + rm(q.discount);
      $('discCode').textContent = q.coupon ? '(' + q.coupon + ')' : '';
      $('shipping').textContent = rm(q.shipping);
      $('region').textContent = stateEl.value ? '(' + q.region_label + ')' : '(pilih negeri)';
      $('total').textContent = rm(q.total);
      if (appliedCoupon) {
        couponMsg.textContent = q.coupon_message || '';
        couponMsg.className = 'coupon-msg ' + (q.coupon ? 'ok' : 'bad');
      }
      payBtn.textContent = 'Bayar ' + rm(q.total) + ' dengan CHIP';
    }).catch(function () {
      if (my === seq) { errEl.textContent = 'Tidak dapat menghubungi pelayan. Semak sambungan internet anda.'; }
    });
  }

  function failPay(msg) {
    errEl.textContent = msg;
    payBtn.disabled = false;
    payBtn.textContent = lastQuote ? 'Bayar ' + rm(lastQuote.total) + ' dengan CHIP' : 'Bayar dengan CHIP';
  }

  $('applyCoupon').addEventListener('click', function () {
    appliedCoupon = couponEl.value.trim().toUpperCase();
    couponEl.value = appliedCoupon;
    if (!appliedCoupon) { couponMsg.textContent = ''; }
    requote();
  });
  couponEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); $('applyCoupon').click(); }
  });
  stateEl.addEventListener('change', requote);
  form.addEventListener('input', saveInfo);

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    errEl.textContent = '';
    var bad = null;
    FIELDS.forEach(function (k) {
      var el = form[k], v = el.value.trim();
      var invalid = !v || (k === 'email' && !/^\S+@\S+\.\S+$/.test(v)) || (k === 'postcode' && !/^\d{5}$/.test(v));
      el.classList.toggle('bad', invalid);
      if (invalid && !bad) bad = el;
    });
    if (bad) { errEl.textContent = 'Sila lengkapkan maklumat yang ditanda.'; bad.focus(); return; }
    if (couponEl.value.trim().toUpperCase() !== appliedCoupon) {
      appliedCoupon = couponEl.value.trim().toUpperCase();
    }
    var body = { items: C.read(), coupon: appliedCoupon, notes: form.notes.value };
    FIELDS.forEach(function (k) { body[k] = form[k].value.trim(); });
    payBtn.disabled = true;
    payBtn.textContent = 'Menyediakan pembayaran…';
    post('/api/checkout.php', body).then(function (r) {
      if (r.ok && r.checkout_url) { location.href = r.checkout_url; return; }
      failPay(r.error || 'Ralat. Sila cuba lagi.');
    }).catch(function () {
      failPay('Tidak dapat menghubungi pelayan. Sila cuba lagi.');
    });
  });

  fetch('/api/catalog.php').then(function (r) { return r.json(); }).then(function (d) {
    if (!d.ok) throw new Error();
    catalog = d.products;
    d.states.forEach(function (s) {
      var o = document.createElement('option');
      o.value = o.textContent = s;
      stateEl.appendChild(o);
    });
    restoreInfo();
    renderItems();
    requote();
  }).catch(function () {
    empty.hidden = false;
    empty.innerHTML = '<p>Sistem checkout tidak dapat dimuatkan. Sila cuba sebentar lagi atau hubungi kami melalui WhatsApp.</p>';
  });
})();
