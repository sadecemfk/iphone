(function () {
  'use strict';
  var $ = function (s, k) { return (k || document).querySelector(s); };
  var $$ = function (s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); };

  function toast(m) {
    var t = document.createElement('div');
    t.className = 'toast'; t.textContent = m;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 2200);
  }

  /** "27.000" / "9.000,50" / "9000.5" → sayı */
  function sayi(s) {
    s = String(s || '').replace(/[\s₺]/g, '').replace(/TL/gi, '');
    if (!s) return 0;
    if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.');
    else if (/^-?\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  }
  function tl(n) {
    return n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
  }
  function telWa(t) {
    var d = String(t || '').replace(/\D+/g, '');
    if (d.indexOf('00') === 0) d = d.slice(2);
    if (d.length === 11 && d[0] === '0') d = '90' + d.slice(1);
    else if (d.length === 10 && d[0] === '5') d = '90' + d;
    return d;
  }

  /* Kopyala */
  $$('[data-kopyala]').forEach(function (b) {
    b.addEventListener('click', function () {
      var m = b.getAttribute('data-kopyala');
      if (navigator.clipboard) {
        navigator.clipboard.writeText(m).then(function () { toast('Kopyalandı'); });
      } else {
        var ta = document.createElement('textarea'); ta.value = m; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); ta.remove(); toast('Kopyalandı');
      }
    });
  });

  /* Numara sorup WhatsApp'a gönder */
  $$('[data-wa-sor]').forEach(function (b) {
    b.addEventListener('click', function () {
      var son = '';
      try { son = localStorage.getItem('sonWaNo') || ''; } catch (e) {}
      var no = prompt('WhatsApp numarası (ör. 0544 406 74 94):', son);
      if (!no) return;
      try { localStorage.setItem('sonWaNo', no); } catch (e) {}
      window.open('https://wa.me/' + telWa(no) + '?text=' + encodeURIComponent(b.getAttribute('data-metin')), '_blank');
    });
  });

  /* Belge dosyalarını paylaş (iPhone: WhatsApp'a PDF olarak gönderilir) */
  var pb = $('#dosya-paylas');
  if (pb) {
    pb.addEventListener('click', function () {
      var liste = JSON.parse(pb.getAttribute('data-dosyalar'));
      if (!navigator.share || !navigator.canShare) {
        alert('Bu tarayıcı dosya paylaşımını desteklemiyor. "Belge Linklerini Gönder" butonunu kullanın.');
        return;
      }
      pb.disabled = true; pb.textContent = 'Hazırlanıyor…';
      Promise.all(liste.map(function (d) {
        return fetch(d.url, { credentials: 'same-origin' }).then(function (r) { return r.blob(); }).then(function (b) {
          return new File([b], d.ad, { type: d.mime || b.type });
        });
      })).then(function (files) {
        var veri = { files: files, text: pb.getAttribute('data-metin') };
        if (!navigator.canShare(veri)) { delete veri.text; }
        if (!navigator.canShare(veri)) throw new Error('Paylaşılamıyor');
        return navigator.share(veri);
      }).catch(function (e) {
        if (e && e.name !== 'AbortError') alert('Paylaşım yapılamadı: ' + e.message);
      }).then(function () {
        pb.disabled = false; pb.textContent = '📎 PDF Dosyalarını Paylaş (WhatsApp vb.)';
      });
    });
  }

  /* Depo formu: koordinat */
  var lat = $('#lat'), lng = $('#lng');
  if (lat && lng) {
    var durum = $('#konum-durum'), onizleme = $('#harita-onizleme');
    var harita = function () {
      var a = parseFloat(String(lat.value).replace(',', '.')), b = parseFloat(String(lng.value).replace(',', '.'));
      if (isNaN(a) || isNaN(b)) { onizleme.innerHTML = ''; return; }
      var d = 0.01;
      onizleme.innerHTML = '<iframe loading="lazy" src="https://www.openstreetmap.org/export/embed.html?bbox=' +
        (b - d) + '%2C' + (a - d) + '%2C' + (b + d) + '%2C' + (a + d) + '&layer=mapnik&marker=' + a + '%2C' + b + '"></iframe>' +
        '<a target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=' + a + ',' + b + '">Google Maps\'te kontrol et →</a>';
    };
    var yaz = function (a, b, mesaj) {
      lat.value = (+a).toFixed(7).replace(/0+$/, '').replace(/\.$/, '');
      lng.value = (+b).toFixed(7).replace(/0+$/, '').replace(/\.$/, '');
      durum.textContent = mesaj || '✅ Koordinat alındı.';
      harita();
    };
    var yerelCoz = function (s) {
      try { s = decodeURIComponent(s); } catch (e) {}
      var d = [
        /!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/,
        /@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/,
        /[?&](?:q|query|ll|destination|daddr|center)=(?:loc:)?(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/,
        /^\s*(-?\d{1,2}(?:[.,]\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:[.,]\d+)?)\s*$/,
        /(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/
      ];
      for (var i = 0; i < d.length; i++) {
        var m = s.match(d[i]);
        if (m) {
          var a = parseFloat(m[1].replace(',', '.')), b = parseFloat(m[2].replace(',', '.'));
          if (Math.abs(a) <= 90 && Math.abs(b) <= 180) return [a, b];
        }
      }
      return null;
    };
    var coz = function () {
      var v = $('#konum-yapistir').value.trim();
      if (!v) { durum.textContent = 'Önce link veya koordinat yapıştırın.'; return; }
      var k = yerelCoz(v);
      if (k) { yaz(k[0], k[1]); return; }
      durum.textContent = '⏳ Link çözülüyor…';
      fetch('index.php?p=koordinat&url=' + encodeURIComponent(v), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok) yaz(j.lat, j.lng); else durum.textContent = '⚠️ ' + j.hata; })
        .catch(function () { durum.textContent = '⚠️ Link çözülemedi.'; });
    };
    $('#konum-coz').addEventListener('click', coz);
    $('#konum-yapistir').addEventListener('paste', function () { setTimeout(coz, 50); });
    $('#konum-yapistir').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); coz(); } });
    $('#konum-gps').addEventListener('click', function () {
      if (!navigator.geolocation) { durum.textContent = 'Cihaz konum desteklemiyor.'; return; }
      durum.textContent = '⏳ Konum alınıyor…';
      navigator.geolocation.getCurrentPosition(function (p) {
        yaz(p.coords.latitude, p.coords.longitude, '✅ Bulunduğunuz konum alındı (±' + Math.round(p.coords.accuracy) + ' m).');
      }, function (e) { durum.textContent = '⚠️ Konum alınamadı: ' + e.message; }, { enableHighAccuracy: true, timeout: 20000 });
    });
    lat.addEventListener('change', harita); lng.addEventListener('change', harita);
    harita();
  }

  /* Sefer formu */
  var sf = $('#sefer-form');
  if (sf) {
    var sofor = $('#sofor_id'), urun = $('#urun_id');
    sofor.addEventListener('change', function () {
      var o = sofor.options[sofor.selectedIndex];
      $('#yeni-sofor').hidden = sofor.value !== 'yeni';
      if (o && o.dataset.tel !== undefined) {
        if (o.dataset.tel) $('#sofor_telefon').value = o.dataset.tel;
        if (o.dataset.cekici) $('#cekici_plaka').value = o.dataset.cekici;
        if (o.dataset.dorse) $('#dorse_plaka').value = o.dataset.dorse;
      }
    });
    urun.addEventListener('change', function () {
      var o = urun.options[urun.selectedIndex];
      if (o && o.dataset.fiyat !== undefined) {
        $('#urun_ton_fiyat').value = o.dataset.fiyat;
        $('#urun_kdv').value = o.dataset.kdv;
        hesap();
      }
    });
    var hesap = function () {
      var kg = sayi($('#miktar_kg').value), ton = kg / 1000;
      var mal = ton * sayi($('#urun_ton_fiyat').value), malK = mal * sayi($('#urun_kdv').value) / 100;
      var nak = ton * sayi($('#nakliye_ton_fiyat').value), nakK = nak * sayi($('#nakliye_kdv').value) / 100;
      var el = $('#hesap-ozet');
      if (!kg) { el.innerHTML = ''; return; }
      var s = function (a, b, k) { return '<div class="satir' + (k ? ' kalin' : '') + '"><span>' + a + '</span><span>' + b + '</span></div>'; };
      el.innerHTML =
        s('Miktar', kg.toLocaleString('tr-TR') + ' kg = ' + ton.toLocaleString('tr-TR', { maximumFractionDigits: 3 }) + ' ton', true) +
        s('Mal bedeli (KDV hariç)', tl(mal)) + s('Mal KDV', tl(malK)) + s('Mal bedeli (KDV dahil)', tl(mal + malK), true) +
        s('Nakliye (KDV hariç)', tl(nak)) + s('Nakliye KDV', tl(nakK)) + s('Nakliye (KDV dahil)', tl(nak + nakK), true);
    };
    ['miktar_kg', 'urun_ton_fiyat', 'urun_kdv', 'nakliye_ton_fiyat', 'nakliye_kdv'].forEach(function (i) {
      $('#' + i).addEventListener('input', hesap);
    });
    hesap();
    // Çift tıklamayla iki kez kaydı önle
    sf.addEventListener('submit', function () {
      var b = $('button.ana', sf); setTimeout(function () { b.disabled = true; b.textContent = 'Kaydediliyor…'; }, 0);
    });
  }
})();
