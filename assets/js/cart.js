/* NEKTOVIA — troli dikongsi antara semua halaman. Harga sebenar dikira di pelayan. */
(function () {
  'use strict';
  var KEY = 'nkt_cart';
  // Tier promosi untuk paparan sahaja (kupon sebenar disemak di pelayan)
  var TIERS = [{ min: 9000, save: 2000 }, { min: 15000, save: 5000 }];

  function read() {
    try { var c = JSON.parse(localStorage.getItem(KEY) || '{}'); return c && typeof c === 'object' ? c : {}; }
    catch (e) { return {}; }
  }
  function write(c) {
    try { localStorage.setItem(KEY, JSON.stringify(c)); } catch (e) {}
    updateCount();
    document.dispatchEvent(new CustomEvent('cart:change'));
  }
  function count() {
    var c = read(), n = 0;
    for (var k in c) n += (+c[k] || 0);
    return n;
  }
  function add(id, qty) {
    var c = read();
    c[id] = Math.min(20, (+c[id] || 0) + qty);
    write(c);
  }
  function set(id, qty) {
    var c = read();
    if (qty <= 0) delete c[id]; else c[id] = Math.min(20, qty);
    write(c);
  }
  function clear() { write({}); }
  function rm(sen) { return 'RM' + (sen / 100).toFixed(2); }

  function updateCount() {
    var n = count();
    document.querySelectorAll('[data-cart-count]').forEach(function (el) {
      el.textContent = n;
      el.hidden = n === 0;
    });
  }

  function toast(html) {
    var t = document.querySelector('.toast');
    if (!t) {
      t = document.createElement('div');
      t.className = 'toast';
      t.setAttribute('role', 'status');
      document.body.appendChild(t);
    }
    t.innerHTML = html;
    t.classList.add('show');
    clearTimeout(t._h);
    t._h = setTimeout(function () { t.classList.remove('show'); }, 3500);
  }

  function tierHint(sub) {
    if (sub >= TIERS[1].min) return '🎉 Layak jimat <b>' + rm(TIERS[1].save) + '</b> dengan kod kupon!';
    if (sub >= TIERS[0].min) return '✓ Layak jimat <b>' + rm(TIERS[0].save) + '</b> dengan kod kupon. Tambah ' + rm(TIERS[1].min - sub) + ' lagi untuk jimat ' + rm(TIERS[1].save) + '.';
    return 'Tambah ' + rm(TIERS[0].min - sub) + ' lagi untuk layak kupon jimat ' + rm(TIERS[0].save) + '.';
  }

  // Blok "tambah ke troli" pada landing page
  document.querySelectorAll('.add[data-product]').forEach(function (box) {
    var id = box.dataset.product;
    var price = +box.dataset.price;
    var input = box.querySelector('input[type=number]');
    var line = box.querySelector('[data-line-total]');
    var hint = box.querySelector('[data-tier-hint]');
    function clamp(v) { v = parseInt(v, 10); if (isNaN(v) || v < 1) v = 1; if (v > 20) v = 20; return v; }
    function refresh() {
      var q = clamp(input.value);
      if (line) line.textContent = rm(q * price);
      if (hint) {
        var inCart = (+read()[id] || 0);
        hint.innerHTML = tierHint((q + inCart) * price) + (inCart ? ' <span class="muted">(termasuk ' + inCart + ' dalam troli)</span>' : '');
      }
    }
    box.querySelectorAll('[data-step]').forEach(function (b) {
      b.addEventListener('click', function () { input.value = clamp(clamp(input.value) + +b.dataset.step); refresh(); });
    });
    input.addEventListener('input', refresh);
    input.addEventListener('blur', function () { input.value = clamp(input.value); refresh(); });
    document.addEventListener('cart:change', refresh);
    var buy = box.querySelector('[data-buy-now]');
    var addBtn = box.querySelector('[data-add-cart]');
    if (buy) buy.addEventListener('click', function () { add(id, clamp(input.value)); location.href = '/checkout/'; });
    if (addBtn) addBtn.addEventListener('click', function () {
      add(id, clamp(input.value));
      input.value = 1; refresh();
      toast('✓ Ditambah ke troli (' + count() + ' item). <a href="/checkout/">Checkout →</a>');
    });
    refresh();
    fetch('/api/catalog.php').then(function (r) { return r.json(); }).then(function (d) {
      var prod = d && d.products && d.products[id];
      if (prod && !prod.active) {
        box.classList.add('soon');
        [buy, addBtn].forEach(function (b) { if (b) { b.disabled = true; } });
        if (buy) buy.textContent = 'Akan dibuka tidak lama lagi';
        if (hint) hint.textContent = 'Produk ini belum dibuka untuk jualan. Hubungi kami melalui WhatsApp untuk daftar minat.';
      }
    }).catch(function () {});
  });

  window.NKTCart = { read: read, set: set, add: add, clear: clear, count: count, rm: rm };
  updateCount();
  window.addEventListener('storage', function (e) { if (e.key === KEY) updateCount(); });
})();
