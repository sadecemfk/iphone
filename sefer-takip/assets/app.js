(function () {
  'use strict';
  var $ = function (s, k) { return (k || document).querySelector(s); };
  var $$ = function (s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); };

  function toast(m) {
    var t = document.createElement('div');
    t.className = 'toast'; t.textContent = m;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 2600);
  }
  function depo() { try { return window.localStorage; } catch (e) { return null; } }

  /* ------------------------------------------------------------ */
  /* Sayı / telefon / koordinat yardımcıları (sunucuyla aynı kurallar) */
  /* ------------------------------------------------------------ */

  /** "27.000" → 27000, "9.000,50" → 9000.5, "%20" → 20; boş → 0; okunamazsa null */
  function sayiKati(s) {
    s = String(s == null ? '' : s).replace(/[\s ₺%]/g, '').replace(/(tl|try)$/i, '');
    if (s === '') return 0;
    if (!/^-?[0-9][0-9.,]*$/.test(s) || /[.,]$/.test(s)) return null;
    var v = s.lastIndexOf(','), n = s.lastIndexOf('.');
    if (v >= 0 && n >= 0) {
      s = n > v ? s.replace(/,/g, '') : s.replace(/\./g, '').replace(',', '.');
    } else if (v >= 0) {
      if (s.split(',').length > 2) return null;
      s = s.replace(',', '.');
    } else if (n >= 0) {
      if (/^-?\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
      else if (s.split('.').length > 2) return null;
    }
    var x = Number(s);
    return isNaN(x) ? null : x;
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
  function trIcinde(lat, lng) { return lat >= 35.5 && lat <= 42.5 && lng >= 25.5 && lng <= 45; }

  /** Derece / DMS metnini parçalara ayırır: [{deger, yon}] */
  function dmsParcala(s) {
    s = String(s).replace(/[º˚]/g, '°').replace(/[′’‘´`]/g, "'").replace(/[″”“]/g, '"').replace(/''/g, '"');
    s = s.toUpperCase()
      .replace(/KUZEY/g, 'K').replace(/G[ÜU]NEY/g, 'G').replace(/DO[ĞG]U/g, 'D').replace(/BATI/g, 'B')
      .replace(/NORTH/g, 'N').replace(/SOUTH/g, 'S').replace(/EAST/g, 'E').replace(/WEST/g, 'W');
    var tok = s.match(/[A-ZÇĞİÖŞÜ]+|\d+(?:[.,]\d+)?|[°'"]/g);
    if (!tok) return [];
    var yonMap = { N: 'N', K: 'N', S: 'S', G: 'S', E: 'E', D: 'E', W: 'W', B: 'W' };
    var sonuc = [], cur = null, bekleyen = null;
    for (var i = 0; i < tok.length; i++) {
      var t = tok[i];
      if (/^[A-ZÇĞİÖŞÜ]+$/.test(t)) {
        if (!yonMap[t]) continue;
        if (cur && !cur.yon) { cur.yon = yonMap[t]; sonuc.push(cur); cur = null; }
        else { if (cur) { sonuc.push(cur); cur = null; } bekleyen = yonMap[t]; }
        continue;
      }
      if (t === '°' || t === "'" || t === '"') continue;
      var deger = parseFloat(t.replace(',', '.')), sonraki = tok[i + 1] || '';
      if (cur && sonraki === "'") { cur.dk = deger; i++; }
      else if (cur && sonraki === '"') { cur.sn = deger; i++; }
      else {
        if (cur) sonuc.push(cur);
        cur = { der: deger, dk: 0, sn: 0, yon: bekleyen }; bekleyen = null;
        if (sonraki === '°') i++;
      }
    }
    if (cur) sonuc.push(cur);
    var cikti = [];
    for (var j = 0; j < sonuc.length; j++) {
      var c = sonuc[j];
      if (c.dk >= 60 || c.sn >= 60) return [];
      var v = c.der + c.dk / 60 + c.sn / 3600;
      if (c.yon === 'S' || c.yon === 'W') v = -v;
      cikti.push({ deger: v, yon: c.yon });
    }
    return cikti;
  }
  function dmsCoz(s) {
    var p = dmsParcala(s);
    if (p.length !== 2) return null;
    var a = p[0], b = p[1];
    if ((a.yon === 'E' || a.yon === 'W') && (!b.yon || b.yon === 'N' || b.yon === 'S')) { var x = a; a = b; b = x; }
    if (Math.abs(a.deger) > 90 || Math.abs(b.deger) > 180) return null;
    return [a.deger, b.deger];
  }
  /** Tek kutudaki enlem/boylam: "40.8167", "40,8167", "40°49'00\"N" → sayı; boş → null; okunamazsa false */
  function tekKoordinat(s) {
    s = String(s || '').trim();
    if (!s) return null;
    if (/^-?\d{1,3}([.,]\d+)?$/.test(s)) return parseFloat(s.replace(',', '.'));
    var p = dmsParcala(s);
    return p.length === 1 ? p[0].deger : false;
  }
  /** Link / metinden [lat, lng] */
  function koordinatCoz(s) {
    try { s = decodeURIComponent(s); } catch (e) {}
    s = String(s || '').trim();
    if (!s) return null;
    var g = function (a, b) { return (Math.abs(a) <= 90 && Math.abs(b) <= 180) ? [a, b] : null; };
    var m;
    if (/yandex\./i.test(s) && (m = s.match(/[?&](?:pt|ll|whatshere\[point\])=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/))) {
      return g(parseFloat(m[2]), parseFloat(m[1]));   // Yandex: boylam,enlem
    }
    var d = [
      /!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/,
      /@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/,
      /[?&](?:q|query|ll|sll|coordinate|destination|daddr|center)=(?:loc:)?(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/,
      /^\s*(-?\d{1,2}(?:[.,]\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:[.,]\d+)?)\s*$/
    ];
    for (var i = 0; i < d.length; i++) {
      m = s.match(d[i]);
      if (m) {
        var k = g(parseFloat(m[1].replace(',', '.')), parseFloat(m[2].replace(',', '.')));
        if (k) return k;
      }
    }
    if (s.indexOf('°') >= 0 || /(^|[\s,;0-9])[NSEWKGDB]([\s,;0-9]|$)/i.test(s)) {
      var dk = dmsCoz(s);
      if (dk) return dk;
    }
    if ((m = s.match(/(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/))) return g(parseFloat(m[1]), parseFloat(m[2]));
    var hep = s.match(/-?\d{1,3}[.,]\d{3,}/g);
    if (hep && hep.length === 2) return g(parseFloat(hep[0].replace(',', '.')), parseFloat(hep[1].replace(',', '.')));
    return null;
  }

  /* ------------------------------------------------------------ */
  /* Genel                                                         */
  /* ------------------------------------------------------------ */

  // Başarılı kayıttan sonra form taslaklarını sil
  var temizle = document.body.getAttribute('data-taslak-temizle');
  if (temizle && depo()) {
    temizle.split(',').forEach(function (k) { try { depo().removeItem('taslak:' + k); } catch (e) {} });
  }

  // Kopyala
  $$('[data-kopyala]').forEach(function (b) {
    b.addEventListener('click', function () {
      var m = b.getAttribute('data-kopyala');
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(m).then(function () { toast('Kopyalandı'); });
      } else {
        var ta = document.createElement('textarea'); ta.value = m; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); toast('Kopyalandı'); } catch (e) {}
        ta.remove();
      }
    });
  });

  // Numara sorup WhatsApp'a gönder
  $$('[data-wa-sor]').forEach(function (b) {
    b.addEventListener('click', function () {
      var son = '';
      try { son = depo().getItem('sonWaNo') || ''; } catch (e) {}
      var no = prompt('WhatsApp numarası (ör. 0544 406 74 94):', son);
      if (!no) return;
      if (!/^905\d{9}$/.test(telWa(no)) && !confirm('Bu numara cep telefonu biçiminde görünmüyor. Yine de açılsın mı?')) return;
      try { depo().setItem('sonWaNo', no); } catch (e) {}
      window.open('https://wa.me/' + telWa(no) + '?text=' + encodeURIComponent(b.getAttribute('data-metin')), '_blank');
    });
  });

  // Belge dosyalarını paylaş (iPhone: WhatsApp'a PDF olarak gider)
  var pb = $('#dosya-paylas');
  if (pb) {
    pb.addEventListener('click', function () {
      var liste = JSON.parse(pb.getAttribute('data-dosyalar'));
      if (!navigator.share || !navigator.canShare) {
        alert('Bu tarayıcı dosya paylaşımını desteklemiyor. "Belge Linklerini Gönder" butonunu kullanın.');
        return;
      }
      var eski = pb.textContent;
      pb.disabled = true; pb.textContent = 'Hazırlanıyor…';
      Promise.all(liste.map(function (d) {
        return fetch(d.url, { credentials: 'same-origin' }).then(function (r) {
          if (!r.ok) throw new Error('Belge indirilemedi');
          return r.blob();
        }).then(function (b) { return new File([b], d.ad, { type: d.mime || b.type }); });
      })).then(function (files) {
        var veri = { files: files, text: pb.getAttribute('data-metin') };
        if (!navigator.canShare(veri)) { delete veri.text; }
        if (!navigator.canShare(veri)) throw new Error('Bu dosyalar paylaşılamıyor');
        return navigator.share(veri);
      }).catch(function (e) {
        if (e && e.name !== 'AbortError') alert('Paylaşım yapılamadı: ' + e.message);
      }).then(function () {
        pb.disabled = false; pb.textContent = eski;
      });
    });
  }

  // Boşaltım kutusu: yazarken fireyi göster
  $$('input[data-fire-yukleme]').forEach(function (inp) {
    var kutu = inp.form && inp.form.querySelector('.fire-onizleme');
    if (!kutu) return;
    var goster = function () {
      var y = parseFloat(inp.getAttribute('data-fire-yukleme')) || 0, tol = parseFloat(inp.getAttribute('data-tolerans')) || 0.5;
      var b = inp.value.trim() === '' ? null : sayiKati(inp.value);
      if (b === null) { kutu.textContent = inp.value.trim() === '' ? '' : '⚠️ Sayı okunamadı (ör. 26950)'; kutu.className = 'fire-onizleme kirmizi'; return; }
      if (!y) { kutu.textContent = ''; return; }
      var fire = y - b, yuzde = fire / y * 100, asim = Math.abs(yuzde) > tol;
      kutu.textContent = 'Yükleme ' + y.toLocaleString('tr-TR') + ' kg → ' + (fire < 0 ? 'fazla ' : 'fire ') + Math.abs(fire).toLocaleString('tr-TR', { maximumFractionDigits: 3 }) +
        ' kg (%' + Math.abs(yuzde).toLocaleString('tr-TR', { maximumFractionDigits: 2 }) + ')' + (asim ? ' ⚠️ tolerans %' + tol.toLocaleString('tr-TR') + ' aşıldı' : ' ✅');
      kutu.className = 'fire-onizleme ' + (asim ? 'kirmizi' : 'soluk');
    };
    inp.addEventListener('input', goster);
    goster();
  });

  // Tablo satırına tıklayınca detaya git
  $$('tr[data-git]').forEach(function (tr) {
    tr.addEventListener('click', function (e) {
      if (!e.target.closest('a, button, form')) location.href = tr.getAttribute('data-git');
    });
  });

  // Filtre kutusu: masaüstünde açık başlasın
  var fk = $('#filtre-kutusu');
  if (fk && window.innerWidth > 760) fk.open = true;

  // Güvenlik testi (yönetici paneli): veri klasörü internetten okunabiliyor mu?
  var eu = $('#erisim-uyari');
  if (eu && window.fetch) {
    fetch(eu.getAttribute('data-url'), { cache: 'no-store', credentials: 'omit' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (t) { if (t && t.trim() === eu.getAttribute('data-deger').trim()) eu.hidden = false; })
      .catch(function () {});
  }

  // Dosya boyutu ön kontrolü: sunucu sınırı aşılırsa form gönderilmez (bilgiler kaybolmasın)
  function mb(b) { return (b / 1048576).toFixed(1).replace('.', ',') + ' MB'; }
  $$('form[data-max-toplam]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      var maxDosya = +f.getAttribute('data-max-dosya') || 0, maxToplam = +f.getAttribute('data-max-toplam') || 0,
        maxAdet = +f.getAttribute('data-max-adet') || 0, toplam = 0, adet = 0, sorun = '';
      $$('input[type=file]', f).forEach(function (inp) {
        Array.prototype.forEach.call(inp.files || [], function (d) {
          toplam += d.size; adet++;
          if (maxDosya && d.size > maxDosya && !sorun) sorun = '"' + d.name + '" çok büyük (' + mb(d.size) + '). Dosya başına sınır: ' + mb(maxDosya) + '.';
        });
      });
      if (!sorun && maxToplam && toplam > maxToplam * 0.95) sorun = 'Seçilen dosyalar toplamı ' + mb(toplam) + '. Tek seferde en fazla ' + mb(maxToplam) + ' gönderilebilir. Dosyaları birkaç seferde yükleyin.';
      if (!sorun && maxAdet && adet > maxAdet) sorun = 'Tek seferde en fazla ' + maxAdet + ' dosya yüklenebilir.';
      if (sorun) {
        e.preventDefault();
        e.stopImmediatePropagation();
        alert(sorun + '\n\nForm gönderilmedi; yazdığınız bilgiler duruyor.');
      }
    });
  });

  /* ------------------------------------------------------------ */
  /* Depo formu: koordinat                                          */
  /* ------------------------------------------------------------ */
  var lat = $('#lat'), lng = $('#lng');
  if (lat && lng) {
    var durum = $('#konum-durum'), onizleme = $('#harita-onizleme');
    var harita = function () {
      var a = tekKoordinat(lat.value), b = tekKoordinat(lng.value);
      if (typeof a !== 'number' || typeof b !== 'number') {
        onizleme.innerHTML = '';
        if (a === false || b === false) durum.textContent = '⚠️ Koordinat okunamadı. Ör. 40.8167 ve 32.8930';
        return;
      }
      if (!trIcinde(a, b)) {
        durum.textContent = trIcinde(b, a)
          ? '⚠️ Enlem ve boylam TERS girilmiş görünüyor (Türkiye: enlem 36-42, boylam 26-45).'
          : '⚠️ Bu konum Türkiye dışında görünüyor; haritadan kontrol edin.';
      }
      var d = 0.01;
      onizleme.innerHTML = '<iframe loading="lazy" title="Harita önizleme" src="https://www.openstreetmap.org/export/embed.html?bbox=' +
        (b - d) + '%2C' + (a - d) + '%2C' + (b + d) + '%2C' + (a + d) + '&layer=mapnik&marker=' + a + '%2C' + b + '"></iframe>' +
        '<a target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=' + a + ',' + b + '">Google Maps\'te kontrol et →</a>';
    };
    var yaz = function (a, b, mesaj) {
      lat.value = String(+(+a).toFixed(7));
      lng.value = String(+(+b).toFixed(7));
      durum.textContent = mesaj || '✅ Koordinat alındı.';
      harita();
    };
    // Kutulara elle derece biçimi yazılırsa ondalığa çevir
    [lat, lng].forEach(function (el) {
      el.addEventListener('change', function () {
        var v = tekKoordinat(el.value);
        if (typeof v === 'number' && !/^-?\d{1,3}([.,]\d+)?$/.test(el.value.trim())) el.value = String(+v.toFixed(7));
        harita();
      });
    });
    var coz = function () {
      var v = $('#konum-yapistir').value.trim();
      if (!v) { durum.textContent = 'Önce link veya koordinat yapıştırın.'; return; }
      var k = koordinatCoz(v);
      if (k) { yaz(k[0], k[1]); return; }
      if (!/^https?:\/\//i.test(v)) { durum.textContent = '⚠️ Koordinat okunamadı. Ör. 40.8167, 32.8930 veya 40°49\'00"N 32°53\'35"E'; return; }
      durum.textContent = '⏳ Link çözülüyor…';
      fetch('index.php?p=koordinat&url=' + encodeURIComponent(v), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok) yaz(j.lat, j.lng); else durum.textContent = '⚠️ ' + j.hata; })
        .catch(function () { durum.textContent = '⚠️ Link çözülemedi. Linki tarayıcıda açıp koordinatı kopyalayın.'; });
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
    harita();
  }

  /* ------------------------------------------------------------ */
  /* Sefer formu                                                    */
  /* ------------------------------------------------------------ */
  var sf = $('#sefer-form');
  if (sf) {
    var sofor = $('#sofor_id'), urun = $('#urun_id');
    var yeniSoforGoster = function () { $('#yeni-sofor').hidden = sofor.value !== 'yeni'; };
    var urunFiyatiniYaz = function () {
      var o = urun.options[urun.selectedIndex];
      if (o && o.getAttribute('data-fiyat') !== null) {
        $('#urun_ton_fiyat').value = o.getAttribute('data-fiyat');
        $('#urun_kdv').value = o.getAttribute('data-kdv');
      }
      hesapla();
    };
    sofor.addEventListener('change', function () {
      var o = sofor.options[sofor.selectedIndex];
      yeniSoforGoster();
      if (o && o.getAttribute('data-tel') !== null) {
        if (o.getAttribute('data-tel')) $('#sofor_telefon').value = o.getAttribute('data-tel');
        if (o.getAttribute('data-cekici')) $('#cekici_plaka').value = o.getAttribute('data-cekici');
        if (o.getAttribute('data-dorse')) $('#dorse_plaka').value = o.getAttribute('data-dorse');
      }
    });
    urun.addEventListener('change', urunFiyatiniYaz);

    var alanlar = { miktar_kg: 'Yükleme net', bosaltim_kg: 'Boşaltım net', urun_ton_fiyat: 'Ürün ton fiyatı', urun_kdv: 'Ürün KDV', nakliye_ton_fiyat: 'Nakliye ton fiyatı', nakliye_kdv: 'Nakliye KDV' };
    var esasAd = { yukleme: 'yükleme kantarı', bosaltim: 'boşaltım kantarı', dusuk: 'düşük olan' };
    var tolerans = parseFloat(sf.getAttribute('data-tolerans')) || 0.5;
    var hesapla = function () {
      var d = {}, sorunlar = [];
      Object.keys(alanlar).forEach(function (k) {
        var el = $('#' + k), v = sayiKati(el.value);
        el.classList.toggle('hatali', v === null || v < 0);
        if (v === null) sorunlar.push(alanlar[k] + ' okunamadı');
        else if (v < 0) sorunlar.push(alanlar[k] + ' negatif olamaz');
        d[k] = v || 0;
      });
      var y = d.miktar_kg, bVar = $('#bosaltim_kg').value.trim() !== '' && sayiKati($('#bosaltim_kg').value) !== null, b = bVar ? d.bosaltim_kg : null;
      var esasKg = function (esas) {
        if (b === null) return y;
        if (esas === 'bosaltim') return b;
        if (esas === 'dusuk') return Math.min(y, b);
        return y;
      };
      var malEsas = $('#mal_esas').value, nakEsas = $('#nakliye_esas').value;
      var malKg = esasKg(malEsas), nakKg = esasKg(nakEsas);
      var mal = malKg / 1000 * d.urun_ton_fiyat, malK = mal * d.urun_kdv / 100;
      var nak = nakKg / 1000 * d.nakliye_ton_fiyat, nakK = nak * d.nakliye_kdv / 100;
      var kg = function (n) { return n.toLocaleString('tr-TR', { maximumFractionDigits: 3 }); };
      var s = function (a, v, k) { return '<div class="satir' + (k ? ' kalin' : '') + '"><span>' + a + '</span><span>' + v + '</span></div>'; };
      var html = sorunlar.length ? '<div class="satir kirmizi">⚠️ ' + sorunlar.join(', ') + ' — sadece rakam girin (ör. 27000 veya 9.000,50)</div>' : '';
      if (y || b) {
        html += s('Yükleme kantarı', kg(y) + ' kg', true);
        if (b !== null) {
          var fire = y - b, yuzde = y > 0 ? fire / y * 100 : 0, asim = Math.abs(yuzde) > tolerans;
          html += s('Boşaltım kantarı', kg(b) + ' kg', true) +
            '<div class="satir' + (asim ? ' kirmizi' : '') + '"><span>' + (fire < 0 ? 'Fazla' : 'Fire') + '</span><span>' + kg(Math.abs(fire)) + ' kg (%' +
            Math.abs(yuzde).toLocaleString('tr-TR', { maximumFractionDigits: 2 }) + ')' + (asim ? ' ⚠️ tolerans %' + tolerans.toLocaleString('tr-TR') + ' aşıldı' : '') + '</span></div>';
        }
        var gec = function (esas) { return b === null && esas !== 'yukleme' ? ' — geçici, boşaltım girilmedi' : ''; };
        html += s('Mal bedeli esası', esasAd[malEsas] + ' · ' + kg(malKg) + ' kg' + gec(malEsas)) +
          s('Mal bedeli (KDV hariç)', tl(mal)) + s('Mal KDV', tl(malK)) + s('Mal bedeli (KDV dahil)', tl(mal + malK), true) +
          s('Nakliye esası', esasAd[nakEsas] + ' · ' + kg(nakKg) + ' kg' + gec(nakEsas)) +
          s('Nakliye (KDV hariç)', tl(nak)) + s('Nakliye KDV', tl(nakK)) + s('Nakliye (KDV dahil)', tl(nak + nakK), true);
        if (y > 60000) html += '<div class="satir kirmizi">⚠️ 60 tonun üzerinde, miktarı kontrol edin.</div>';
      }
      $('#hesap-ozet').innerHTML = html;
    };
    $('#mal_esas').addEventListener('change', hesapla);
    $('#nakliye_esas').addEventListener('change', hesapla);
    Object.keys(alanlar).forEach(function (i) { $('#' + i).addEventListener('input', hesapla); });

    // Taslak geri yüklendiğinde / başka sayfadan dönüşte ön seçimleri uygula
    sf.addEventListener('taslak-yuklendi', function () {
      var on = {};
      try { on = JSON.parse(sf.getAttribute('data-on-secim') || '{}'); } catch (e) {}
      Object.keys(on).forEach(function (ad) {
        var el = sf.elements[ad];
        if (el) el.value = on[ad];
      });
      yeniSoforGoster();
      if (on.urun_id) urunFiyatiniYaz(); else hesapla();
    });
    hesapla();

    sf.addEventListener('submit', function (e) {
      var bozuk = Object.keys(alanlar).filter(function (k) { var v = sayiKati($('#' + k).value); return v === null || v < 0; });
      if (bozuk.length) {
        e.preventDefault();
        alert('Lütfen şu alanları düzeltin: ' + bozuk.map(function (k) { return alanlar[k]; }).join(', ') + '.\nSadece rakam girin (ör. 27000 veya 9.000,50).');
        $('#' + bozuk[0]).focus();
        return;
      }
      if (e.defaultPrevented) return;
      var b = $('#kaydet');
      setTimeout(function () { b.disabled = true; b.textContent = 'Kaydediliyor…'; }, 0);
    });
  }

  /* ------------------------------------------------------------ */
  /* Form taslağı: yazılanlar kaybolmasın (yanlış link, büyük dosya, */
  /* iPhone'da sayfanın yeniden yüklenmesi vb.)                     */
  /* ------------------------------------------------------------ */
  function formVerisi(f) {
    var v = {};
    $$('input, select, textarea', f).forEach(function (el) {
      if (!el.name || el.type === 'file' || el.type === 'hidden' || el.type === 'password') return;
      if (el.type === 'checkbox') v[el.name] = el.checked ? '1' : '';
      else if (el.type === 'radio') { if (el.checked) v[el.name] = el.value; }
      else v[el.name] = el.value;
    });
    return v;
  }
  function formaYaz(f, v) {
    $$('input, select, textarea', f).forEach(function (el) {
      if (!el.name || !(el.name in v) || el.type === 'file' || el.type === 'hidden' || el.type === 'password') return;
      if (el.type === 'checkbox') el.checked = v[el.name] === '1';
      else if (el.type === 'radio') el.checked = el.value === v[el.name];
      else el.value = v[el.name];
    });
  }
  function ayni(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

  $$('form[data-taslak]').forEach(function (f) {
    var anahtar = 'taslak:' + f.getAttribute('data-taslak'), ls = depo();
    if (!ls) return;
    var kayit = null;
    try { kayit = JSON.parse(ls.getItem(anahtar) || 'null'); } catch (e) {}
    if (kayit && (Date.now() - kayit.zaman > 24 * 3600 * 1000)) { ls.removeItem(anahtar); kayit = null; }
    var kaydet = function () {
      try { ls.setItem(anahtar, JSON.stringify({ zaman: Date.now(), veri: formVerisi(f) })); } catch (e) {}
    };
    var geriYukle = function () {
      formaYaz(f, kayit.veri);
      f.dispatchEvent(new CustomEvent('taslak-yuklendi'));
    };
    if (kayit && !ayni(kayit.veri, formVerisi(f))) {
      if (f.getAttribute('data-taslak-oto') === '1') {
        geriYukle();
        toast('Yazdığınız bilgiler geri getirildi');
      } else {
        var bar = document.createElement('div');
        bar.className = 'uyari dikkat taslak-bar';
        var saat = new Date(kayit.zaman).toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
        bar.innerHTML = '📝 Bu formda kaydedilmemiş bilgiler var (' + saat + '). ';
        var b1 = document.createElement('button'); b1.type = 'button'; b1.className = 'btn kucuk ana'; b1.textContent = 'Taslağı geri yükle';
        var b2 = document.createElement('button'); b2.type = 'button'; b2.className = 'btn kucuk'; b2.textContent = 'Sil';
        b1.onclick = function () { geriYukle(); bar.remove(); toast('Taslak geri yüklendi'); };
        b2.onclick = function () { ls.removeItem(anahtar); bar.remove(); };
        bar.appendChild(b1); bar.appendChild(document.createTextNode(' ')); bar.appendChild(b2);
        f.parentNode.insertBefore(bar, f);
      }
    } else if (f.getAttribute('data-taslak-oto') === '1') {
      f.dispatchEvent(new CustomEvent('taslak-yuklendi'));
    }
    var zm;
    f.addEventListener('input', function () { clearTimeout(zm); zm = setTimeout(kaydet, 300); });
    f.addEventListener('change', kaydet);
    $$('.taslak-link', f).forEach(function (a) { a.addEventListener('click', kaydet); });
  });
})();
