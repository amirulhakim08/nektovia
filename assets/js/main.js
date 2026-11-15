/* NEKTOVIA — nektovia.com */
(function () {
  'use strict';

  document.documentElement.classList.remove('no-js');
  var yr = document.getElementById('yr');
  if (yr) yr.textContent = new Date().getFullYear();

  // Nav background on scroll + sticky buy bar
  var nav = document.getElementById('nav');
  if (!nav) return;
  var sticky = document.getElementById('sticky');
  var buy = document.getElementById('beli');
  var buyVisible = false;
  function onScroll() {
    var y = window.scrollY;
    nav.classList.toggle('scrolled', y > 20);
    if (sticky) sticky.classList.toggle('show', y > 600 && !buyVisible);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // Reveal on scroll
  var reveals = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    reveals.forEach(function (el) { io.observe(el); });

    if (buy) {
      new IntersectionObserver(function (entries) {
        buyVisible = entries[0].isIntersecting;
        onScroll();
      }).observe(buy);
    }

    // 5-second countdown when the pump panel appears
    var count = document.getElementById('count');
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (count && !reduce) {
      var ran = false;
      new IntersectionObserver(function (entries, obs) {
        if (!entries[0].isIntersecting || ran) return;
        ran = true; obs.disconnect();
        var n = 5;
        var t = setInterval(function () {
          n -= 1;
          count.textContent = n > 0 ? n : '✓';
          if (n <= 0) {
            clearInterval(t);
            setTimeout(function () { count.textContent = '5'; }, 2200);
          }
        }, 1000);
      }, { threshold: 0.6 }).observe(count);
    }
  } else {
    reveals.forEach(function (el) { el.classList.add('in'); });
  }

  // Voice note: auto-main selepas interaksi pertama (klik/sentuh/kekunci)
  var voice = document.getElementById('voice');
  var vBtn = document.getElementById('voiceBtn');
  if (voice && vBtn) {
    var vLabel = document.getElementById('voiceLabel');
    var vProg = document.getElementById('voiceProg');
    var C = 125.66, started = false;
    var setLabel = function (t) { vLabel.textContent = t; };
    var playVoice = function () {
      var p = voice.play();
      if (p && p.catch) p.catch(function () {});
    };
    var firstInteract = function (e) {
      removeFirst();
      if (started || (e && vBtn.contains(e.target))) return;
      playVoice();
    };
    var evs = ['click', 'touchend', 'keydown'];
    var removeFirst = function () { evs.forEach(function (ev) { document.removeEventListener(ev, firstInteract, true); }); };
    evs.forEach(function (ev) { document.addEventListener(ev, firstInteract, true); });

    vBtn.addEventListener('click', function () {
      removeFirst();
      if (voice.paused) playVoice(); else voice.pause();
    });
    voice.addEventListener('play', function () {
      started = true;
      vBtn.classList.add('playing', 'started');
      vBtn.setAttribute('aria-pressed', 'true');
      vBtn.setAttribute('aria-label', 'Jeda suara NEKTOVIA');
      setLabel('Sedang dimainkan');
    });
    voice.addEventListener('pause', function () {
      vBtn.classList.remove('playing');
      vBtn.setAttribute('aria-pressed', 'false');
      vBtn.setAttribute('aria-label', 'Main suara NEKTOVIA');
      if (!voice.ended) setLabel('Dijeda — sentuh untuk sambung');
    });
    voice.addEventListener('ended', function () {
      setLabel('Main semula');
      vProg.style.strokeDashoffset = C;
    });
    voice.addEventListener('timeupdate', function () {
      if (voice.duration) vProg.style.strokeDashoffset = C * (1 - voice.currentTime / voice.duration);
    });
  }

})();
