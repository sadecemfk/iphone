/* Sefer Takip - tarayıcıda çalışan sürüm (GitHub Pages)
 * Tüm veriler bu cihazın tarayıcısında (IndexedDB) saklanır. Düzenli yedek alın! */
(function () {
  'use strict';

  /* ================================================================ */
  /* Sabitler                                                         */
  /* ================================================================ */
  var DURUMLAR = { planlandi: 'Planlandı', yuklendi: 'Yüklendi / Yolda', teslim: 'Teslim Edildi', iptal: 'İptal' };
  var BELGE_TURLERI = { irsaliye: 'İrsaliye', kantar: 'Kantar Fişi', diger: 'Diğer' };
  var TABLOLAR = ['depolar', 'soforler', 'urunler', 'seferler', 'belgeler', 'gecmis'];
  var VARSAYILAN_KDV = 20;

  /* ================================================================ */
  /* Yardımcılar                                                      */
  /* ================================================================ */
  function $(s, k) { return (k || document).querySelector(s); }
  function $$(s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); }
  function h(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function simdi() {
    var d = new Date(), p = function (n) { return String(n).padStart(2, '0'); };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }
  function bugun(ofs) {
    var d = new Date(); if (ofs) d.setDate(d.getDate() + ofs);
    return tarihIso(d);
  }
  function tarihIso(d) {
    var p = function (n) { return String(n).padStart(2, '0'); };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }
  function tarihTr(t) {
    if (!t) return '';
    var m = String(t).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return t;
    return m[3] + '.' + m[2] + '.' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '');
  }
  /** "27.000" / "9.000,50" / "9000.5" → sayı */
  function sayi(s) {
    s = String(s == null ? '' : s).replace(/[\s₺]/g, '').replace(/TL/gi, '');
    if (!s) return 0;
    if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.');
    else if (/^-?\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  }
  function para(n) { return (+n || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function tl(n) { return para(n) + ' ₺'; }
  function kgFmt(n) { return (+n || 0).toLocaleString('tr-TR', { maximumFractionDigits: 3 }); }
  function sayiInput(n) { if (n === '' || n == null) return ''; return String(+n).replace('.', ','); }
  // Plakalarda Türkçe İ yoktur: i / ı → I (toLocaleUpperCase('tr-TR') "i"yi "İ" yapıyordu)
  function plaka(s) { return String(s || '').replace(/\s+/g, '').replace(/[ıi]/g, 'I').toUpperCase(); }
  function telWa(t) {
    var d = String(t || '').replace(/\D+/g, '');
    if (d.indexOf('00') === 0) d = d.slice(2);
    if (d.length === 11 && d[0] === '0') d = '90' + d.slice(1);
    else if (d.length === 10 && d[0] === '5') d = '90' + d;
    return d;
  }
  function waLink(tel, metin) { return 'https://wa.me/' + telWa(tel) + '?text=' + encodeURIComponent(metin); }
  function koor(x) { return String(+(+x).toFixed(7)); }
  function haritaLink(lat, lng) {
    if (lat === '' || lat == null || lng === '' || lng == null) return '';
    return 'https://www.google.com/maps/search/?api=1&query=' + koor(lat) + ',' + koor(lng);
  }
  function yolLink(lat, lng) {
    if (lat === '' || lat == null) return '';
    return 'https://www.google.com/maps/dir/?api=1&destination=' + koor(lat) + ',' + koor(lng);
  }
  function koordinatCoz(s) {
    try { s = decodeURIComponent(s); } catch (e) {}
    var d = [
      /!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/,
      /@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/,
      /[?&](?:q|query|ll|destination|daddr|center)=(?:loc:)?(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/,
      /^\s*(-?\d{1,2}(?:[.,]\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:[.,]\d+)?)\s*$/,
      /(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/
    ];
    for (var i = 0; i < d.length; i++) {
      var m = String(s).match(d[i]);
      if (m) {
        var a = parseFloat(m[1].replace(',', '.')), b = parseFloat(m[2].replace(',', '.'));
        if (Math.abs(a) <= 90 && Math.abs(b) <= 180) return [a, b];
      }
    }
    return null;
  }
  function toast(m) {
    var t = document.createElement('div'); t.className = 'toast'; t.textContent = m;
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 2400);
  }
  function kopyala(m) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(m).then(function () { toast('Kopyalandı'); }, function () { eskiKopyala(m); });
    } else eskiKopyala(m);
  }
  function eskiKopyala(m) {
    var ta = document.createElement('textarea'); ta.value = m; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); toast('Kopyalandı'); } catch (e) {} ta.remove();
  }
  function dosyaIndir(blob, ad) {
    var u = URL.createObjectURL(blob), a = document.createElement('a');
    a.href = u; a.download = ad; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(u); }, 60000);
  }
  function dosyaAdiZaman() { return simdi().replace(/[: ]/g, '-'); }
  var mesajlar = [];
  function mesaj(m, tur) { mesajlar.push([tur || 'ok', m]); }

  /* ================================================================ */
  /* Veritabanı (IndexedDB)                                           */
  /* ================================================================ */
  var idb, V = {};
  function dbAc() {
    return new Promise(function (res, rej) {
      var r = indexedDB.open('seferTakip', 1);
      r.onupgradeneeded = function () {
        var d = r.result;
        TABLOLAR.forEach(function (s) { if (!d.objectStoreNames.contains(s)) d.createObjectStore(s, { keyPath: 'id', autoIncrement: true }); });
        if (!d.objectStoreNames.contains('dosyalar')) d.createObjectStore('dosyalar');
        if (!d.objectStoreNames.contains('ayar')) d.createObjectStore('ayar');
      };
      r.onsuccess = function () { idb = r.result; res(); };
      r.onerror = function () { rej(r.error); };
    });
  }
  function islem(depo, mod, fn) {
    return new Promise(function (res, rej) {
      var t = idb.transaction(depo, mod), sonuc;
      var r = fn(t.objectStore(depo));
      if (r) r.onsuccess = function () { sonuc = r.result; };
      t.oncomplete = function () { res(sonuc); };
      t.onerror = function () { rej(t.error); };
      t.onabort = function () { rej(t.error || new Error('İşlem iptal')); };
    });
  }
  function tumunuOku(depo) { return islem(depo, 'readonly', function (s) { return s.getAll(); }); }
  function ayarOku(k) { return islem('ayar', 'readonly', function (s) { return s.get(k); }); }
  function ayarYaz(k, v) { return islem('ayar', 'readwrite', function (s) { return s.put(v, k); }); }
  function dosyaOku(id) { return islem('dosyalar', 'readonly', function (s) { return s.get(Number(id)); }); }
  function dosyaYaz(id, blob) { return islem('dosyalar', 'readwrite', function (s) { return s.put(blob, Number(id)); }); }

  function yukle() {
    return Promise.all(TABLOLAR.map(function (t) {
      return tumunuOku(t).then(function (l) { V[t] = l; });
    }));
  }
  function bul(t, id) {
    id = Number(id);
    for (var i = 0; i < V[t].length; i++) if (V[t][i].id === id) return V[t][i];
    return null;
  }
  function aktif(t) { return V[t].filter(function (r) { return !r.silindi; }); }

  function gecmisYaz(tablo, id, ne, eski, yeni) {
    var g = { zaman: simdi(), tablo: tablo, kayit_id: id, islem: ne, eski: eski, yeni: yeni };
    return islem('gecmis', 'readwrite', function (s) { return s.add(g); }).then(function (gid) { g.id = gid; V.gecmis.push(g); });
  }
  function ekle(tablo, veri) {
    veri.olusturma = veri.guncelleme = simdi();
    veri.silindi = 0;
    return islem(tablo, 'readwrite', function (s) { return s.add(veri); }).then(function (id) {
      veri.id = id; V[tablo].push(veri);
      return gecmisYaz(tablo, id, 'ekle', null, veri).then(function () { return id; });
    });
  }
  function guncelle(tablo, id, degisiklik, ne) {
    var eski = bul(tablo, id);
    if (!eski) return Promise.resolve(false);
    var e = {}, y = {}, var_ = false;
    Object.keys(degisiklik).forEach(function (k) {
      if (String(eski[k] == null ? '' : eski[k]) !== String(degisiklik[k] == null ? '' : degisiklik[k])) {
        e[k] = eski[k] == null ? '' : eski[k]; y[k] = degisiklik[k]; var_ = true;
      }
    });
    if (!var_) return Promise.resolve(true);
    var yeni = Object.assign({}, eski, degisiklik, { guncelleme: simdi() });
    return islem(tablo, 'readwrite', function (s) { return s.put(yeni); }).then(function () {
      V[tablo][V[tablo].indexOf(eski)] = yeni;
      return gecmisYaz(tablo, id, ne || 'duzenle', e, y);
    }).then(function () { return true; });
  }

  /* ================================================================ */
  /* İş mantığı                                                       */
  /* ================================================================ */
  function hesap(s) {
    var ton = (+s.miktar_kg || 0) / 1000;
    var mal = ton * (+s.urun_ton_fiyat || 0), malK = mal * (+s.urun_kdv || 0) / 100;
    var nak = ton * (+s.nakliye_ton_fiyat || 0), nakK = nak * (+s.nakliye_kdv || 0) / 100;
    return { ton: ton, mal: mal, mal_kdv: malK, mal_toplam: mal + malK, nak: nak, nak_kdv: nakK, nak_toplam: nak + nakK };
  }
  function depoEtiket(d) {
    if (!d) return '';
    var yer = [d.ilce, d.il].filter(Boolean).join(' / ');
    return d.ad + (yer ? ' (' + yer + ')' : '');
  }
  /** Sefer + ilişkili kayıtlar */
  function seferTam(s) {
    var so = bul('soforler', s.sofor_id) || {};
    return Object.assign({}, s, {
      yd: bul('depolar', s.yukleme_depo_id) || {},
      bd: bul('depolar', s.bosaltim_depo_id) || {},
      so: so,
      soforAd: [so.ad, so.soyad].filter(Boolean).join(' '),
      urunAd: (bul('urunler', s.urun_id) || {}).ad || '',
      belgeler: aktif('belgeler').filter(function (b) { return b.sefer_id === s.id; })
    });
  }
  function waMesaj(s, konum, belge) {
    var sat = [];
    sat.push('Merhaba' + (s.soforAd ? ' ' + s.soforAd : '') + ',', '');
    sat.push('*Sefer No:* ' + s.id + ' - ' + tarihTr(s.tarih));
    if (s.urunAd) sat.push('*Ürün:* ' + s.urunAd + (s.miktar_kg > 0 ? ' - ' + kgFmt(s.miktar_kg) + ' kg' : ''));
    var arac = [s.cekici_plaka, s.dorse_plaka].filter(Boolean).join(' / ');
    if (arac) sat.push('*Araç:* ' + arac);
    if (konum) {
      sat.push('', '📍 *YÜKLEME YERİ:* ' + depoEtiket(s.yd));
      if (s.yd.adres) sat.push(s.yd.adres);
      sat.push(haritaLink(s.yd.lat, s.yd.lng) || '(konum girilmemiş)');
      sat.push('', '🏁 *BOŞALTIM YERİ:* ' + depoEtiket(s.bd));
      if (s.bd.adres) sat.push(s.bd.adres);
      sat.push(haritaLink(s.bd.lat, s.bd.lng) || '(konum girilmemiş)');
    }
    if (belge) {
      if (s.irsaliye_no) sat.push('', '📄 *İrsaliye No:* ' + s.irsaliye_no);
      if (s.kantar_fis_no) sat.push((s.irsaliye_no ? '' : '\n') + '⚖️ *Kantar Fiş No:* ' + s.kantar_fis_no);
    }
    sat.push('', 'İyi yolculuklar.');
    return sat.join('\n');
  }
  function seferFiltrele(q) {
    return V.seferler.filter(function (s) {
      if (s.silindi) return false;
      if (q.bas && s.tarih < q.bas) return false;
      if (q.bit && s.tarih > q.bit) return false;
      if (q.urun && String(s.urun_id) !== q.urun) return false;
      if (q.ydepo && String(s.yukleme_depo_id) !== q.ydepo) return false;
      if (q.bdepo && String(s.bosaltim_depo_id) !== q.bdepo) return false;
      if (q.sofor && String(s.sofor_id) !== q.sofor) return false;
      if (q.durum && s.durum !== q.durum) return false;
      if (q.odendi === '1' && !s.nakliye_odendi) return false;
      if (q.odendi === '0' && s.nakliye_odendi) return false;
      if (q.plaka) {
        var p = plaka(q.plaka);
        if (plaka(s.cekici_plaka).indexOf(p) < 0 && plaka(s.dorse_plaka).indexOf(p) < 0) return false;
      }
      if (q.ara) {
        var t = seferTam(s), a = q.ara.toLocaleLowerCase('tr-TR');
        var metin = [s.id, s.irsaliye_no, s.kantar_fis_no, s.notlar, t.soforAd, t.so.tc, t.yd.ad, t.bd.ad, t.urunAd].join(' ').toLocaleLowerCase('tr-TR');
        if (metin.indexOf(a) < 0 && String(s.id) !== q.ara) return false;
      }
      return true;
    }).map(seferTam).sort(function (a, b) { return a.tarih < b.tarih ? 1 : a.tarih > b.tarih ? -1 : b.id - a.id; });
  }
  function urunOzet(liste) {
    var oz = {};
    liste.forEach(function (s) {
      if (s.durum === 'iptal') return;
      var k = s.urunAd || '(Ürün yok)', hs = hesap(s);
      if (!oz[k]) oz[k] = { ad: k, adet: 0, kg: 0, ton: 0, mal: 0, malT: 0, nak: 0, nakT: 0 };
      var o = oz[k];
      o.adet++; o.kg += +s.miktar_kg || 0; o.ton += hs.ton; o.mal += hs.mal; o.malT += hs.mal_toplam; o.nak += hs.nak; o.nakT += hs.nak_toplam;
    });
    return Object.keys(oz).sort().map(function (k) { return oz[k]; });
  }

  /* ================================================================ */
  /* Arayüz parçaları                                                 */
  /* ================================================================ */
  var MENU = [
    ['panel', '🏠', 'Panel'], ['seferler', '🚚', 'Seferler'], ['sefer-form', '➕', 'Yeni Sefer'],
    ['depolar', '📍', 'Depolar'], ['soforler', '👤', 'Şoförler'], ['urunler', '📦', 'Ürünler'], ['yedek', '💾', 'Yedek / Excel']
  ];
  function ciz(html, aktifMenu) {
    $('#menu').innerHTML = MENU.map(function (m) {
      return '<a href="#/' + m[0] + '" class="' + (m[0] === aktifMenu ? 'aktif' : '') + '"><span>' + m[1] + '</span>' + m[2] + '</a>';
    }).join('');
    var ust = mesajlar.map(function (m) { return '<div class="uyari ' + (m[0] === 'hata' ? 'hata' : '') + '">' + h(m[1]) + '</div>'; }).join('');
    mesajlar = [];
    $('#icerik').innerHTML = yedekUyarisi() + ust + html;
    window.scrollTo(0, 0);
  }
  var sonYedek = null;
  function yedekUyarisi() {
    if (!V.seferler || !V.seferler.length) return '';
    var gun = sonYedek ? (Date.now() - new Date(sonYedek.replace(' ', 'T')).getTime()) / 86400000 : Infinity;
    if (gun < 7) return '';
    return '<div class="uyari hata">⚠️ ' + (sonYedek ? 'Son yedek ' + Math.floor(gun) + ' gün önce alındı.' : 'Henüz hiç yedek almadınız.') +
      ' Veriler sadece bu cihazda duruyor. <a href="#/yedek"><b>Şimdi yedek alın →</b></a></div>';
  }
  function secenekler(liste, secili, bos, etiket) {
    var o = bos != null ? '<option value="">' + h(bos) + '</option>' : '';
    liste.forEach(function (r) {
      o += '<option value="' + r.id + '"' + (String(secili) === String(r.id) ? ' selected' : '') + '>' + h(etiket ? etiket(r) : r.ad) + '</option>';
    });
    return o;
  }
  function durumRozet(d) { return '<span class="rozet d-' + h(d) + '">' + h(DURUMLAR[d] || d) + '</span>'; }
  function formVeri(f) {
    var o = {};
    new FormData(f).forEach(function (v, k) { if (typeof v === 'string') o[k] = v.trim(); });
    return o;
  }
  function qs(o) {
    return Object.keys(o).filter(function (k) { return o[k] !== '' && o[k] != null; })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&');
  }
  function hataGoster(m) {
    var el = $('#form-hata'); if (el) { el.textContent = m; el.hidden = false; el.scrollIntoView({ block: 'center' }); } else alert(m);
  }

  /* ================================================================ */
  /* Sayfalar                                                         */
  /* ================================================================ */
  var S = {};

  S.panel = function () {
    var ay = bugun().slice(0, 7);
    var buAy = aktif('seferler').filter(function (s) { return s.tarih.slice(0, 7) === ay && s.durum !== 'iptal'; });
    var t = { kg: 0, mal: 0, nak: 0 };
    buAy.forEach(function (s) { var x = hesap(s); t.kg += +s.miktar_kg || 0; t.mal += x.mal_toplam; t.nak += x.nak_toplam; });
    var od = aktif('seferler').filter(function (s) { return !s.nakliye_odendi && s.durum !== 'iptal'; });
    var odT = od.reduce(function (a, s) { return a + hesap(s).nak_toplam; }, 0);
    var son = seferFiltrele({}).slice(0, 10);
    var eksik = !aktif('depolar').length || !aktif('soforler').length || !aktif('urunler').length;
    ciz(
      '<div class="baslik-satir"><h1>Panel</h1><a class="btn ana" href="#/sefer-form">➕ Yeni Sefer</a></div>' +
      (eksik ? '<div class="kart bilgi"><b>Başlarken:</b> ' +
        (!aktif('depolar').length ? '<a href="#/depo-form?tip=yukleme">Yükleme (sarım) deposu</a> ve <a href="#/depo-form?tip=bosaltim">boşaltım deposu</a> ekleyin. ' : '') +
        (!aktif('soforler').length ? '<a href="#/sofor-form">Şoför ekleyin.</a> ' : '') +
        (!aktif('urunler').length ? '<a href="#/urun-form">Ürün ekleyin</a> (ör. Fındık, ton fiyatı).' : '') + '</div>' : '') +
      '<div class="istatistik">' +
      '<div><small>Bugün</small><b>' + aktif('seferler').filter(function (s) { return s.tarih === bugun(); }).length + '</b><span>sefer</span></div>' +
      '<div><small>Yolda</small><b>' + aktif('seferler').filter(function (s) { return s.durum === 'yuklendi'; }).length + '</b><span>araç</span></div>' +
      '<div><small>Bu ay</small><b>' + buAy.length + '</b><span>' + kgFmt(t.kg / 1000) + ' ton</span></div>' +
      '<div><small>Bu ay mal bedeli (KDV dahil)</small><b class="kucuk">' + tl(t.mal) + '</b></div>' +
      '<div><small>Bu ay nakliye (KDV dahil)</small><b class="kucuk">' + tl(t.nak) + '</b></div>' +
      '<div><a href="#/seferler?odendi=0"><small>Ödenmemiş nakliye</small><b class="kucuk">' + tl(odT) + '</b><span>' + od.length + ' sefer</span></a></div>' +
      '</div>' +
      '<div class="kart"><div class="baslik-satir"><h2>Son Seferler</h2><a href="#/seferler">Tümü →</a></div>' +
      (son.length ? '' : '<p class="soluk">Henüz sefer yok.</p>') +
      '<div class="liste">' + son.map(function (s) {
        return '<a class="liste-oge" href="#/sefer/' + s.id + '"><div><b>#' + s.id + ' · ' + tarihTr(s.tarih) + '</b> ' + durumRozet(s.durum) + '</div>' +
          '<div>' + h(s.yd.ad) + ' → ' + h(s.bd.ad) + '</div>' +
          '<div class="soluk">' + h(s.urunAd) + ' · ' + kgFmt(s.miktar_kg) + ' kg · ' + h(s.soforAd) + ' · ' + h(s.cekici_plaka) + '</div></a>';
      }).join('') + '</div></div>', 'panel');
  };

  /* ---------------- Depolar ---------------- */
  S.depolar = function (id, q) {
    var tip = q.tip || '';
    var liste = aktif('depolar').filter(function (d) { return !tip || d.tip === tip; })
      .sort(function (a, b) { return a.tip === b.tip ? a.ad.localeCompare(b.ad, 'tr') : (a.tip === 'yukleme' ? -1 : 1); });
    var seferSay = function (d) { return aktif('seferler').filter(function (s) { return s.yukleme_depo_id === d.id || s.bosaltim_depo_id === d.id; }).length; };
    ciz('<div class="baslik-satir"><h1>Depolar</h1><div class="butonlar">' +
      '<a class="btn ana" href="#/depo-form?tip=yukleme">➕ Yükleme (Sarım) Deposu</a>' +
      '<a class="btn ana" href="#/depo-form?tip=bosaltim">➕ Boşaltım Deposu</a></div></div>' +
      '<div class="sekmeler"><a class="' + (!tip ? 'aktif' : '') + '" href="#/depolar">Tümü</a>' +
      '<a class="' + (tip === 'yukleme' ? 'aktif' : '') + '" href="#/depolar?tip=yukleme">Yükleme (Sarım)</a>' +
      '<a class="' + (tip === 'bosaltim' ? 'aktif' : '') + '" href="#/depolar?tip=bosaltim">Boşaltım</a></div>' +
      (liste.length ? '' : '<div class="kart"><p class="soluk">Kayıtlı depo yok.</p></div>') +
      '<div class="izgara">' + liste.map(function (d) {
        var hl = haritaLink(d.lat, d.lng);
        var m = (d.tip === 'yukleme' ? '📍 YÜKLEME YERİ: ' : '🏁 BOŞALTIM YERİ: ') + depoEtiket(d) + (d.adres ? '\n' + d.adres : '') + (hl ? '\n' + hl : '');
        return '<div class="kart depo"><div class="baslik-satir"><div><span class="rozet ' + d.tip + '">' + (d.tip === 'yukleme' ? 'Yükleme (Sarım)' : 'Boşaltım') + '</span>' +
          '<h3>' + h(d.ad) + '</h3></div><a class="btn kucuk" href="#/depo-form/' + d.id + '">✏️ Düzenle</a></div>' +
          (d.firma ? '<div>' + h(d.firma) + '</div>' : '') +
          '<div class="soluk">' + h([d.ilce, d.il].filter(Boolean).join(' / ')) + '</div>' +
          (d.adres ? '<div class="soluk">' + h(d.adres) + '</div>' : '') +
          (d.yetkili || d.telefon ? '<div>👤 ' + h(d.yetkili) + (d.telefon ? ' · <a href="tel:' + h(d.telefon) + '">' + h(d.telefon) + '</a>' : '') + '</div>' : '') +
          '<div class="soluk">' + (hl ? '🧭 ' + koor(d.lat) + ', ' + koor(d.lng) : '<span class="kirmizi">⚠️ Koordinat girilmemiş</span>') + ' · ' + seferSay(d) + ' sefer</div>' +
          (hl ? '<div class="butonlar"><a class="btn kucuk" target="_blank" rel="noopener" href="' + h(hl) + '">🗺️ Haritada Aç</a>' +
            '<button type="button" class="btn kucuk wa" data-wa-sor="' + h(m) + '">WhatsApp ile Gönder</button>' +
            '<button type="button" class="btn kucuk" data-kopyala="' + h(m) + '">📋 Kopyala</button></div>' : '') +
          '</div>';
      }).join('') + '</div>', 'depolar');
  };

  S['depo-form'] = function (id, q) {
    var d = id ? bul('depolar', id) : null;
    if (id && !d) { location.hash = '#/depolar'; return; }
    d = d || { tip: q.tip === 'bosaltim' ? 'bosaltim' : 'yukleme', ad: '', firma: '', il: '', ilce: '', adres: '', lat: '', lng: '', yetkili: '', telefon: '', notlar: '' };
    ciz('<h1>' + (id ? 'Depo Düzenle' : 'Yeni Depo') + '</h1><div class="uyari hata" id="form-hata" hidden></div>' +
      '<form class="kart form" id="f">' +
      '<div class="secim-grup"><label class="radyo"><input type="radio" name="tip" value="yukleme"' + (d.tip === 'yukleme' ? ' checked' : '') + '> 📦 Yükleme (Sarım) Deposu</label>' +
      '<label class="radyo"><input type="radio" name="tip" value="bosaltim"' + (d.tip === 'bosaltim' ? ' checked' : '') + '> 🏁 Boşaltım Deposu</label></div>' +
      '<div class="iki"><label>Depo adı *<input name="ad" required value="' + h(d.ad) + '" placeholder="ör. Erpa Depo"></label>' +
      '<label>Firma<input name="firma" value="' + h(d.firma) + '" placeholder="ör. Alparslan Kağıt Fabrikası"></label>' +
      '<label>İl<input name="il" value="' + h(d.il) + '" placeholder="ör. Çankırı"></label>' +
      '<label>İlçe<input name="ilce" value="' + h(d.ilce) + '" placeholder="ör. Çerkes"></label></div>' +
      '<label>Açık adres<textarea name="adres" rows="2">' + h(d.adres) + '</textarea></label>' +
      '<fieldset><legend>📍 Konum (Koordinat)</legend>' +
      '<label>Google Maps linki veya koordinat yapıştırın<input id="konum-yapistir" autocomplete="off" placeholder="ör. 40.8167, 32.8930  veya  https://www.google.com/maps/@40.81,32.89,15z"></label>' +
      '<div class="butonlar"><button type="button" class="btn kucuk" id="konum-coz">🔎 Koordinatı Al</button>' +
      '<button type="button" class="btn kucuk" id="konum-gps">📡 Şu An Bulunduğum Konum</button></div>' +
      '<p class="soluk">İpucu: Google Maps\'te noktaya basılı tutun, üstte çıkan koordinata dokunup kopyalayın ve buraya yapıştırın.</p>' +
      '<div class="iki"><label>Enlem (lat)<input name="lat" id="lat" inputmode="decimal" value="' + h(d.lat === '' || d.lat == null ? '' : koor(d.lat)) + '"></label>' +
      '<label>Boylam (lng)<input name="lng" id="lng" inputmode="decimal" value="' + h(d.lng === '' || d.lng == null ? '' : koor(d.lng)) + '"></label></div>' +
      '<div id="konum-durum" class="soluk"></div><div id="harita-onizleme" class="harita"></div></fieldset>' +
      '<div class="iki"><label>Yetkili kişi<input name="yetkili" value="' + h(d.yetkili) + '"></label>' +
      '<label>Telefon<input name="telefon" type="tel" value="' + h(d.telefon) + '"></label></div>' +
      '<label>Notlar<textarea name="notlar" rows="2">' + h(d.notlar) + '</textarea></label>' +
      '<div class="butonlar"><button class="btn ana">💾 Kaydet</button><a class="btn" href="#/depolar">Vazgeç</a></div></form>' +
      (id ? '<div class="sil-form"><button class="btn tehlike kucuk" id="sil">🗑️ Depoyu Sil</button></div>' : ''), 'depolar');

    var lat = $('#lat'), lng = $('#lng'), durum = $('#konum-durum'), on = $('#harita-onizleme');
    function harita() {
      var a = parseFloat(String(lat.value).replace(',', '.')), b = parseFloat(String(lng.value).replace(',', '.'));
      if (isNaN(a) || isNaN(b)) { on.innerHTML = ''; return; }
      var e = 0.01;
      on.innerHTML = '<iframe loading="lazy" src="https://www.openstreetmap.org/export/embed.html?bbox=' + (b - e) + '%2C' + (a - e) + '%2C' + (b + e) + '%2C' + (a + e) +
        '&layer=mapnik&marker=' + a + '%2C' + b + '"></iframe><a target="_blank" rel="noopener" href="' + haritaLink(a, b) + '">Google Maps\'te kontrol et →</a>';
    }
    function yaz(a, b, m) { lat.value = koor(a); lng.value = koor(b); durum.textContent = m || '✅ Koordinat alındı.'; harita(); }
    function coz() {
      var v = $('#konum-yapistir').value.trim();
      if (!v) { durum.textContent = 'Önce link veya koordinat yapıştırın.'; return; }
      var k = koordinatCoz(v);
      if (k) yaz(k[0], k[1]);
      else durum.textContent = /goo\.gl|app\.goo/.test(v)
        ? '⚠️ Kısa linkler (maps.app.goo.gl) bu sürümde çözülemiyor. Linki tarayıcıda açıp adres çubuğundaki uzun linki veya koordinatı yapıştırın.'
        : '⚠️ Koordinat bulunamadı. "40.81, 32.89" şeklinde yazın.';
    }
    $('#konum-coz').onclick = coz;
    $('#konum-yapistir').addEventListener('paste', function () { setTimeout(coz, 50); });
    $('#konum-yapistir').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); coz(); } });
    $('#konum-gps').onclick = function () {
      if (!navigator.geolocation) { durum.textContent = 'Cihaz konum desteklemiyor.'; return; }
      durum.textContent = '⏳ Konum alınıyor…';
      navigator.geolocation.getCurrentPosition(function (p) {
        yaz(p.coords.latitude, p.coords.longitude, '✅ Bulunduğunuz konum alındı (±' + Math.round(p.coords.accuracy) + ' m).');
      }, function (e) { durum.textContent = '⚠️ Konum alınamadı: ' + e.message; }, { enableHighAccuracy: true, timeout: 20000 });
    };
    lat.onchange = lng.onchange = harita; harita();

    $('#f').onsubmit = function (e) {
      e.preventDefault();
      var v = formVeri(this);
      var veri = {
        tip: v.tip === 'bosaltim' ? 'bosaltim' : 'yukleme', ad: v.ad, firma: v.firma, il: v.il, ilce: v.ilce, adres: v.adres,
        lat: v.lat === '' ? '' : parseFloat(v.lat.replace(',', '.')), lng: v.lng === '' ? '' : parseFloat(v.lng.replace(',', '.')),
        yetkili: v.yetkili, telefon: v.telefon, notlar: v.notlar
      };
      if (!veri.ad) return hataGoster('Depo adı zorunludur.');
      if ((veri.lat === '') !== (veri.lng === '')) return hataGoster('Enlem ve boylamın ikisi birden girilmelidir.');
      if (veri.lat !== '' && (isNaN(veri.lat) || isNaN(veri.lng) || Math.abs(veri.lat) > 90 || Math.abs(veri.lng) > 180)) return hataGoster('Koordinat geçersiz.');
      (id ? guncelle('depolar', id, veri) : ekle('depolar', veri)).then(function () {
        mesaj(id ? 'Depo güncellendi.' : 'Depo eklendi.');
        location.hash = q.don === 'sefer' ? '#/sefer-form' : '#/depolar?tip=' + veri.tip;
      });
    };
    if (id) $('#sil').onclick = function () {
      if (!confirm('Bu depo silinsin mi? (Geçmiş seferler etkilenmez, geri alınabilir)')) return;
      guncelle('depolar', id, { silindi: 1 }, 'sil').then(function () { mesaj('Depo silindi (Silinenler bölümünden geri alınabilir).'); location.hash = '#/depolar'; });
    };
  };

  /* ---------------- Şoförler ---------------- */
  S.soforler = function (id, q) {
    var ara = (q.ara || '').toLocaleLowerCase('tr-TR');
    var liste = aktif('soforler').filter(function (s) {
      return !ara || [s.ad, s.soyad, s.tc, s.telefon, s.cekici_plaka, s.dorse_plaka].join(' ').toLocaleLowerCase('tr-TR').indexOf(ara) >= 0;
    }).sort(function (a, b) { return (a.ad + a.soyad).localeCompare(b.ad + b.soyad, 'tr'); });
    ciz('<div class="baslik-satir"><h1>Şoförler</h1><a class="btn ana" href="#/sofor-form">➕ Yeni Şoför</a></div>' +
      '<form class="kart filtre-satir" id="f"><input name="ara" value="' + h(q.ara || '') + '" placeholder="Ad, TC, telefon veya plaka ara"><button class="btn">Ara</button></form>' +
      '<div class="tablo-kap kart"><table class="tablo"><thead><tr><th>Ad Soyad</th><th>TC</th><th>Telefon</th><th>Çekici</th><th>Dorse</th><th>Sefer</th><th></th></tr></thead><tbody>' +
      liste.map(function (s) {
        var n = aktif('seferler').filter(function (x) { return x.sofor_id === s.id; }).length;
        return '<tr><td data-e="Ad Soyad"><b>' + h(s.ad + ' ' + (s.soyad || '')) + '</b></td><td data-e="TC">' + h(s.tc) + '</td>' +
          '<td data-e="Telefon">' + (s.telefon ? '<a href="tel:' + h(s.telefon) + '">' + h(s.telefon) + '</a> · <a target="_blank" rel="noopener" href="https://wa.me/' + telWa(s.telefon) + '">WhatsApp</a>' : '') + '</td>' +
          '<td data-e="Çekici">' + h(s.cekici_plaka) + '</td><td data-e="Dorse">' + h(s.dorse_plaka) + '</td>' +
          '<td data-e="Sefer"><a href="#/seferler?sofor=' + s.id + '">' + n + '</a></td><td><a class="btn kucuk" href="#/sofor-form/' + s.id + '">✏️</a></td></tr>';
      }).join('') + (liste.length ? '' : '<tr><td colspan="7" class="soluk">Kayıt yok.</td></tr>') + '</tbody></table></div>', 'soforler');
    $('#f').onsubmit = function (e) { e.preventDefault(); location.hash = '#/soforler?' + qs(formVeri(this)); };
  };

  S['sofor-form'] = function (id) {
    var d = id ? bul('soforler', id) : null;
    if (id && !d) { location.hash = '#/soforler'; return; }
    d = d || { ad: '', soyad: '', tc: '', telefon: '', cekici_plaka: '', dorse_plaka: '', iban: '', notlar: '' };
    ciz('<h1>' + (id ? 'Şoför Düzenle' : 'Yeni Şoför') + '</h1><div class="uyari hata" id="form-hata" hidden></div>' +
      '<form class="kart form" id="f"><div class="iki">' +
      '<label>Ad *<input name="ad" required value="' + h(d.ad) + '"></label>' +
      '<label>Soyad<input name="soyad" value="' + h(d.soyad) + '"></label>' +
      '<label>TC Kimlik No<input name="tc" inputmode="numeric" maxlength="11" value="' + h(d.tc) + '"></label>' +
      '<label>Telefon (WhatsApp)<input name="telefon" type="tel" value="' + h(d.telefon) + '" placeholder="0544 406 74 94"></label>' +
      '<label>Çekici Plaka<input name="cekici_plaka" class="buyuk-harf" value="' + h(d.cekici_plaka) + '" placeholder="45AAR75"></label>' +
      '<label>Dorse Plaka<input name="dorse_plaka" class="buyuk-harf" value="' + h(d.dorse_plaka) + '" placeholder="45AAR12"></label></div>' +
      '<label>IBAN (nakliye ödemesi için)<input name="iban" value="' + h(d.iban) + '" placeholder="TR.."></label>' +
      '<label>Notlar<textarea name="notlar" rows="2">' + h(d.notlar) + '</textarea></label>' +
      '<div class="butonlar"><button class="btn ana">💾 Kaydet</button><a class="btn" href="#/soforler">Vazgeç</a></div></form>' +
      (id ? '<div class="sil-form"><button class="btn tehlike kucuk" id="sil">🗑️ Şoförü Sil</button></div>' : ''), 'soforler');
    $('#f').onsubmit = function (e) {
      e.preventDefault();
      var v = formVeri(this);
      var veri = { ad: v.ad, soyad: v.soyad, tc: v.tc.replace(/\D/g, ''), telefon: v.telefon, cekici_plaka: plaka(v.cekici_plaka), dorse_plaka: plaka(v.dorse_plaka), iban: v.iban.replace(/\s/g, '').toUpperCase(), notlar: v.notlar };
      if (!veri.ad) return hataGoster('Ad zorunludur.');
      if (veri.tc && veri.tc.length !== 11) return hataGoster('TC kimlik no 11 haneli olmalıdır.');
      (id ? guncelle('soforler', id, veri) : ekle('soforler', veri)).then(function () { mesaj(id ? 'Şoför güncellendi.' : 'Şoför eklendi.'); location.hash = '#/soforler'; });
    };
    if (id) $('#sil').onclick = function () {
      if (!confirm('Şoför silinsin mi? (Geçmiş seferler etkilenmez, geri alınabilir)')) return;
      guncelle('soforler', id, { silindi: 1 }, 'sil').then(function () { mesaj('Şoför silindi (geri alınabilir).'); location.hash = '#/soforler'; });
    };
  };

  /* ---------------- Ürünler ---------------- */
  S.urunler = function () {
    var liste = aktif('urunler').sort(function (a, b) { return a.ad.localeCompare(b.ad, 'tr'); });
    ciz('<div class="baslik-satir"><h1>Ürünler</h1><a class="btn ana" href="#/urun-form">➕ Yeni Ürün</a></div>' +
      '<div class="tablo-kap kart"><table class="tablo"><thead><tr><th>Ürün</th><th class="sag">Ton Fiyatı</th><th class="sag">KDV</th><th class="sag">Sefer</th><th class="sag">Toplam</th><th></th></tr></thead><tbody>' +
      liste.map(function (u) {
        var ss = aktif('seferler').filter(function (s) { return s.urun_id === u.id && s.durum !== 'iptal'; });
        var kg = ss.reduce(function (a, s) { return a + (+s.miktar_kg || 0); }, 0);
        return '<tr><td data-e="Ürün"><b>' + h(u.ad) + '</b></td><td data-e="Ton Fiyatı" class="sag">' + tl(u.ton_fiyat) + ' + KDV</td>' +
          '<td data-e="KDV" class="sag">%' + sayiInput(u.kdv_orani) + '</td><td data-e="Sefer" class="sag"><a href="#/seferler?urun=' + u.id + '">' + ss.length + '</a></td>' +
          '<td data-e="Toplam" class="sag">' + kgFmt(kg / 1000) + ' ton</td><td><a class="btn kucuk" href="#/urun-form/' + u.id + '">✏️</a></td></tr>';
      }).join('') + (liste.length ? '' : '<tr><td colspan="6" class="soluk">Kayıt yok.</td></tr>') + '</tbody></table></div>' +
      '<p class="soluk">Ürün fiyatı değiştirildiğinde eski seferlerin fiyatı <b>değişmez</b>; her sefer kendi fiyatını saklar.</p>', 'urunler');
  };

  S['urun-form'] = function (id) {
    var d = id ? bul('urunler', id) : null;
    if (id && !d) { location.hash = '#/urunler'; return; }
    d = d || { ad: '', ton_fiyat: '', kdv_orani: VARSAYILAN_KDV, notlar: '' };
    ciz('<h1>' + (id ? 'Ürün Düzenle' : 'Yeni Ürün') + '</h1><div class="uyari hata" id="form-hata" hidden></div>' +
      '<form class="kart form" id="f"><label>Ürün adı *<input name="ad" required value="' + h(d.ad) + '" placeholder="ör. Fındık"></label>' +
      '<div class="iki"><label>Ton başına fiyat (₺, KDV hariç)<input name="ton_fiyat" inputmode="decimal" value="' + h(sayiInput(d.ton_fiyat)) + '" placeholder="9000"></label>' +
      '<label>KDV oranı (%)<input name="kdv_orani" inputmode="decimal" value="' + h(sayiInput(d.kdv_orani)) + '"></label></div>' +
      '<label>Notlar<textarea name="notlar" rows="2">' + h(d.notlar) + '</textarea></label>' +
      '<div class="butonlar"><button class="btn ana">💾 Kaydet</button><a class="btn" href="#/urunler">Vazgeç</a></div></form>' +
      (id ? '<div class="sil-form"><button class="btn tehlike kucuk" id="sil">🗑️ Ürünü Sil</button></div>' : ''), 'urunler');
    $('#f').onsubmit = function (e) {
      e.preventDefault();
      var v = formVeri(this);
      var veri = { ad: v.ad, ton_fiyat: sayi(v.ton_fiyat), kdv_orani: sayi(v.kdv_orani), notlar: v.notlar };
      if (!veri.ad) return hataGoster('Ürün adı zorunludur.');
      (id ? guncelle('urunler', id, veri) : ekle('urunler', veri)).then(function () { mesaj(id ? 'Ürün güncellendi.' : 'Ürün eklendi.'); location.hash = '#/urunler'; });
    };
    if (id) $('#sil').onclick = function () {
      if (!confirm('Ürün silinsin mi? (Geçmiş seferler etkilenmez, geri alınabilir)')) return;
      guncelle('urunler', id, { silindi: 1 }, 'sil').then(function () { mesaj('Ürün silindi (geri alınabilir).'); location.hash = '#/urunler'; });
    };
  };

  /* ---------------- Belgeler ---------------- */
  function belgeKaydet(seferId, dosya, tur) {
    if (dosya.size > 30 * 1024 * 1024) { mesaj(dosya.name + ': en fazla 30 MB olabilir.', 'hata'); return Promise.resolve(); }
    var meta = { sefer_id: seferId, tur: tur, orijinal_ad: dosya.name, mime: dosya.type || 'application/octet-stream', boyut: dosya.size };
    return ekle('belgeler', meta).then(function (bid) { return dosyaYaz(bid, dosya); });
  }
  function belgeDosyasi(b) {
    return dosyaOku(b.id).then(function (blob) {
      if (!blob) throw new Error('Dosya bulunamadı');
      return new File([blob], b.orijinal_ad || ('belge-' + b.id), { type: b.mime || blob.type });
    });
  }
  function dosyalariPaylas(belgeler, metin) {
    if (!navigator.canShare) { alert('Bu tarayıcı dosya paylaşımını desteklemiyor (iPhone Safari veya Android Chrome kullanın). Belgeyi "İndir" ile kaydedip WhatsApp\'tan gönderebilirsiniz.'); return; }
    Promise.all(belgeler.map(belgeDosyasi)).then(function (files) {
      var v = { files: files, text: metin };
      if (!navigator.canShare(v)) delete v.text;
      if (!navigator.canShare(v)) throw new Error('Bu dosyalar paylaşılamıyor.');
      return navigator.share(v);
    }).catch(function (e) { if (e && e.name !== 'AbortError') alert('Paylaşım yapılamadı: ' + e.message); });
  }

  /* ---------------- Sefer formu ---------------- */
  S['sefer-form'] = function (id, q) {
    var s = id ? bul('seferler', id) : null;
    if (id && !s) { location.hash = '#/seferler'; return; }
    if (!s) {
      var son = aktif('seferler').slice(-1)[0];
      s = {
        tarih: bugun(), teslim_tarihi: '', durum: 'yuklendi', yukleme_depo_id: q.ydepo || '', bosaltim_depo_id: q.bdepo || '', sofor_id: '',
        sofor_telefon: '', cekici_plaka: '', dorse_plaka: '', urun_id: '', miktar_kg: '', urun_ton_fiyat: '', urun_kdv: VARSAYILAN_KDV,
        nakliye_ton_fiyat: son ? son.nakliye_ton_fiyat : '', nakliye_kdv: son ? son.nakliye_kdv : VARSAYILAN_KDV, nakliye_odendi: 0,
        irsaliye_no: '', kantar_fis_no: '', notlar: ''
      };
    }
    var sec = function (r) { return r.silindi === 0 || r.silindi === undefined; };
    var yd = V.depolar.filter(function (d) { return d.tip === 'yukleme' && (sec(d) || d.id === s.yukleme_depo_id); });
    var bd = V.depolar.filter(function (d) { return d.tip === 'bosaltim' && (sec(d) || d.id === s.bosaltim_depo_id); });
    var sof = V.soforler.filter(function (d) { return sec(d) || d.id === s.sofor_id; });
    var ur = V.urunler.filter(function (d) { return sec(d) || d.id === s.urun_id; });
    var mevcut = id ? aktif('belgeler').filter(function (b) { return b.sefer_id === id; }) : [];

    ciz('<h1>' + (id ? 'Sefer #' + id + ' Düzenle' : 'Yeni Sefer') + '</h1><div class="uyari hata" id="form-hata" hidden></div>' +
      '<form class="form" id="f">' +
      '<div class="kart"><h2>Genel</h2><div class="uc">' +
      '<label>Yükleme tarihi *<input type="date" name="tarih" required value="' + h(s.tarih) + '"></label>' +
      '<label>Durum<select name="durum">' + Object.keys(DURUMLAR).map(function (k) { return '<option value="' + k + '"' + (s.durum === k ? ' selected' : '') + '>' + DURUMLAR[k] + '</option>'; }).join('') + '</select></label>' +
      '<label>Teslim tarihi<input type="date" name="teslim_tarihi" value="' + h(s.teslim_tarihi) + '"></label></div></div>' +
      '<div class="kart"><h2>Güzergah</h2><div class="iki">' +
      '<label>📦 Yükleme (Sarım) deposu *<select name="yukleme_depo_id" required>' + secenekler(yd, s.yukleme_depo_id, '— Seçin —', depoEtiket) + '</select>' +
      '<a class="kucuk-link" href="#/depo-form?tip=yukleme&don=sefer">+ yeni yükleme deposu</a></label>' +
      '<label>🏁 Boşaltım deposu *<select name="bosaltim_depo_id" required>' + secenekler(bd, s.bosaltim_depo_id, '— Seçin —', depoEtiket) + '</select>' +
      '<a class="kucuk-link" href="#/depo-form?tip=bosaltim&don=sefer">+ yeni boşaltım deposu</a></label></div></div>' +
      '<div class="kart"><h2>Şoför ve Araç</h2><label>Şoför<select name="sofor_id" id="sofor_id"><option value="">— Seçin —</option><option value="yeni">➕ Yeni şoför ekle…</option>' +
      sof.map(function (o) {
        return '<option value="' + o.id + '"' + (String(s.sofor_id) === String(o.id) ? ' selected' : '') + ' data-tel="' + h(o.telefon) + '" data-cekici="' + h(o.cekici_plaka) + '" data-dorse="' + h(o.dorse_plaka) + '">' +
          h([o.ad, o.soyad].filter(Boolean).join(' ') + (o.cekici_plaka ? ' · ' + o.cekici_plaka : '') + (o.tc ? ' · TC ' + o.tc : '')) + '</option>';
      }).join('') + '</select></label>' +
      '<div class="uc" id="yeni-sofor" hidden><label>Ad<input name="yeni_ad"></label><label>Soyad<input name="yeni_soyad"></label><label>TC No<input name="yeni_tc" inputmode="numeric" maxlength="11"></label></div>' +
      '<div class="uc"><label>Telefon (WhatsApp)<input type="tel" name="sofor_telefon" id="sofor_telefon" value="' + h(s.sofor_telefon) + '" placeholder="0544 406 74 94"></label>' +
      '<label>Çekici plaka<input name="cekici_plaka" id="cekici_plaka" class="buyuk-harf" value="' + h(s.cekici_plaka) + '" placeholder="45AAR75"></label>' +
      '<label>Dorse plaka<input name="dorse_plaka" id="dorse_plaka" class="buyuk-harf" value="' + h(s.dorse_plaka) + '" placeholder="45AAR12"></label></div></div>' +
      '<div class="kart"><h2>Yük ve Ücret</h2><div class="iki">' +
      '<label>Ürün<select name="urun_id" id="urun_id"><option value="">— Seçin —</option>' + ur.map(function (u) {
        return '<option value="' + u.id + '"' + (String(s.urun_id) === String(u.id) ? ' selected' : '') + ' data-fiyat="' + h(sayiInput(u.ton_fiyat)) + '" data-kdv="' + h(sayiInput(u.kdv_orani)) + '">' + h(u.ad) + '</option>';
      }).join('') + '</select><a class="kucuk-link" href="#/urun-form">+ yeni ürün</a></label>' +
      '<label>Miktar (kg)<input name="miktar_kg" id="miktar_kg" inputmode="decimal" value="' + h(sayiInput(s.miktar_kg)) + '" placeholder="27000"></label></div>' +
      '<div class="iki"><label>Ürün ton fiyatı (₺, KDV hariç)<input name="urun_ton_fiyat" id="urun_ton_fiyat" inputmode="decimal" value="' + h(sayiInput(s.urun_ton_fiyat)) + '" placeholder="9000"></label>' +
      '<label>Ürün KDV (%)<input name="urun_kdv" id="urun_kdv" inputmode="decimal" value="' + h(sayiInput(s.urun_kdv)) + '"></label>' +
      '<label>Nakliye ton fiyatı (₺, KDV hariç)<input name="nakliye_ton_fiyat" id="nakliye_ton_fiyat" inputmode="decimal" value="' + h(sayiInput(s.nakliye_ton_fiyat)) + '" placeholder="100"></label>' +
      '<label>Nakliye KDV (%)<input name="nakliye_kdv" id="nakliye_kdv" inputmode="decimal" value="' + h(sayiInput(s.nakliye_kdv)) + '"></label></div>' +
      '<label class="onay"><input type="checkbox" name="nakliye_odendi" value="1"' + (s.nakliye_odendi ? ' checked' : '') + '> Nakliye ücreti şoföre ödendi</label>' +
      '<div class="hesap" id="hesap-ozet"></div></div>' +
      '<div class="kart"><h2>İrsaliye ve Kantar Fişi</h2><div class="iki">' +
      '<label>İrsaliye no<input name="irsaliye_no" value="' + h(s.irsaliye_no) + '"></label>' +
      '<label>Kantar fiş no<input name="kantar_fis_no" value="' + h(s.kantar_fis_no) + '"></label></div>' +
      '<div class="uc"><label class="dosya">📄 İrsaliye PDF / foto<input type="file" id="b-irsaliye" multiple accept="application/pdf,image/*"></label>' +
      '<label class="dosya">⚖️ Kantar fişi PDF / foto<input type="file" id="b-kantar" multiple accept="application/pdf,image/*"></label>' +
      '<label class="dosya">📎 Diğer belge<input type="file" id="b-diger" multiple accept="application/pdf,image/*"></label></div>' +
      (mevcut.length ? '<p class="soluk">Mevcut belgeler: ' + mevcut.map(function (b) { return h(BELGE_TURLERI[b.tur]); }).join(', ') + ' (silmek için sefer detay sayfasını kullanın)</p>' : '') + '</div>' +
      '<div class="kart"><label>Notlar<textarea name="notlar" rows="3">' + h(s.notlar) + '</textarea></label></div>' +
      '<div class="butonlar yapiskan"><button class="btn ana buyuk" id="kaydet">💾 Kaydet</button><a class="btn" href="' + (id ? '#/sefer/' + id : '#/seferler') + '">Vazgeç</a></div></form>', 'sefer-form');

    var sofor = $('#sofor_id'), urun = $('#urun_id');
    sofor.onchange = function () {
      var o = sofor.options[sofor.selectedIndex];
      $('#yeni-sofor').hidden = sofor.value !== 'yeni';
      if (o && o.dataset.tel !== undefined) {
        if (o.dataset.tel) $('#sofor_telefon').value = o.dataset.tel;
        if (o.dataset.cekici) $('#cekici_plaka').value = o.dataset.cekici;
        if (o.dataset.dorse) $('#dorse_plaka').value = o.dataset.dorse;
      }
    };
    urun.onchange = function () {
      var o = urun.options[urun.selectedIndex];
      if (o && o.dataset.fiyat !== undefined) { $('#urun_ton_fiyat').value = o.dataset.fiyat; $('#urun_kdv').value = o.dataset.kdv; hesapla(); }
    };
    function hesapla() {
      var x = hesap({ miktar_kg: sayi($('#miktar_kg').value), urun_ton_fiyat: sayi($('#urun_ton_fiyat').value), urun_kdv: sayi($('#urun_kdv').value), nakliye_ton_fiyat: sayi($('#nakliye_ton_fiyat').value), nakliye_kdv: sayi($('#nakliye_kdv').value) });
      var el = $('#hesap-ozet');
      if (!x.ton) { el.innerHTML = ''; return; }
      var r = function (a, b, k) { return '<div class="satir' + (k ? ' kalin' : '') + '"><span>' + a + '</span><span>' + b + '</span></div>'; };
      el.innerHTML = r('Miktar', kgFmt(x.ton * 1000) + ' kg = ' + kgFmt(x.ton) + ' ton', true) +
        r('Mal bedeli (KDV hariç)', tl(x.mal)) + r('Mal KDV', tl(x.mal_kdv)) + r('Mal bedeli (KDV dahil)', tl(x.mal_toplam), true) +
        r('Nakliye (KDV hariç)', tl(x.nak)) + r('Nakliye KDV', tl(x.nak_kdv)) + r('Nakliye (KDV dahil)', tl(x.nak_toplam), true);
    }
    ['miktar_kg', 'urun_ton_fiyat', 'urun_kdv', 'nakliye_ton_fiyat', 'nakliye_kdv'].forEach(function (i) { $('#' + i).oninput = hesapla; });
    hesapla();

    $('#f').onsubmit = function (e) {
      e.preventDefault();
      var v = formVeri(this);
      var veri = {
        tarih: v.tarih, teslim_tarihi: v.teslim_tarihi, durum: DURUMLAR[v.durum] ? v.durum : 'planlandi',
        yukleme_depo_id: Number(v.yukleme_depo_id) || null, bosaltim_depo_id: Number(v.bosaltim_depo_id) || null,
        sofor_id: v.sofor_id === 'yeni' ? null : (Number(v.sofor_id) || null),
        sofor_telefon: v.sofor_telefon, cekici_plaka: plaka(v.cekici_plaka), dorse_plaka: plaka(v.dorse_plaka),
        urun_id: Number(v.urun_id) || null, miktar_kg: sayi(v.miktar_kg), urun_ton_fiyat: sayi(v.urun_ton_fiyat), urun_kdv: sayi(v.urun_kdv),
        nakliye_ton_fiyat: sayi(v.nakliye_ton_fiyat), nakliye_kdv: sayi(v.nakliye_kdv), nakliye_odendi: v.nakliye_odendi ? 1 : 0,
        irsaliye_no: v.irsaliye_no, kantar_fis_no: v.kantar_fis_no, notlar: v.notlar
      };
      if (!/^\d{4}-\d{2}-\d{2}$/.test(veri.tarih)) return hataGoster('Geçerli bir tarih giriniz.');
      if (!veri.yukleme_depo_id || !veri.bosaltim_depo_id) return hataGoster('Yükleme ve boşaltım deposunu seçiniz.');
      if (v.sofor_id === 'yeni' && !v.yeni_ad) return hataGoster('Yeni şoför için ad giriniz.');
      var btn = $('#kaydet'); btn.disabled = true; btn.textContent = 'Kaydediliyor…';
      var once = v.sofor_id === 'yeni'
        ? ekle('soforler', { ad: v.yeni_ad, soyad: v.yeni_soyad, tc: (v.yeni_tc || '').replace(/\D/g, ''), telefon: veri.sofor_telefon, cekici_plaka: veri.cekici_plaka, dorse_plaka: veri.dorse_plaka, iban: '', notlar: '' })
          .then(function (sid) { veri.sofor_id = sid; })
        : Promise.resolve();
      var sid;
      once.then(function () {
        return id ? guncelle('seferler', id, veri).then(function () { return id; }) : ekle('seferler', veri);
      }).then(function (yeniId) {
        sid = yeniId;
        var isler = [];
        [['#b-irsaliye', 'irsaliye'], ['#b-kantar', 'kantar'], ['#b-diger', 'diger']].forEach(function (x) {
          Array.prototype.forEach.call($(x[0]).files, function (f) { isler.push(function () { return belgeKaydet(sid, f, x[1]); }); });
        });
        return isler.reduce(function (p, fn) { return p.then(fn); }, Promise.resolve());
      }).then(function () {
        mesaj(id ? 'Sefer güncellendi.' : 'Sefer kaydedildi. Şimdi şoföre WhatsApp ile gönderebilirsiniz.');
        location.hash = '#/sefer/' + sid;
      }).catch(function (err) {
        btn.disabled = false; btn.textContent = '💾 Kaydet';
        hataGoster('Kaydedilemedi: ' + (err && err.message ? err.message : err));
      });
    };
  };

  /* ---------------- Sefer detay ---------------- */
  S.sefer = function (id) {
    var ham = bul('seferler', id);
    if (!ham) { mesaj('Sefer bulunamadı.', 'hata'); location.hash = '#/seferler'; return; }
    var s = seferTam(ham), x = hesap(s), tel = s.sofor_telefon, wt = telWa(tel);
    var mKonum = waMesaj(s, true, false), mHepsi = waMesaj(s, true, true);
    var yH = haritaLink(s.yd.lat, s.yd.lng), bH = haritaLink(s.bd.lat, s.bd.lng);
    var gecmis = V.gecmis.filter(function (g) {
      return (g.tablo === 'seferler' && g.kayit_id === s.id) || (g.tablo === 'belgeler' && (bul('belgeler', g.kayit_id) || {}).sefer_id === s.id);
    }).reverse();
    var yer = function (d) { return h([d.ilce, d.il].filter(Boolean).join(' / ')); };
    var konumHtml = function (d, link, depoId) {
      return link ? '<a target="_blank" rel="noopener" href="' + h(link) + '">🗺️ Harita</a> · <a target="_blank" rel="noopener" href="' + h(yolLink(d.lat, d.lng)) + '">Yol tarifi</a>'
        : '<span class="kirmizi">⚠️ Koordinat yok</span> <a href="#/depo-form/' + depoId + '">ekle</a>';
    };
    ciz('<div class="baslik-satir"><h1>Sefer #' + s.id + ' ' + durumRozet(s.durum) + (s.silindi ? ' <span class="rozet d-iptal">SİLİNMİŞ</span>' : '') + '</h1>' +
      '<div class="butonlar"><a class="btn" href="#/sefer-form/' + s.id + '">✏️ Düzenle</a>' +
      '<a class="btn" href="#/sefer-form?ydepo=' + (s.yukleme_depo_id || '') + '&bdepo=' + (s.bosaltim_depo_id || '') + '">➕ Aynı güzergahta yeni sefer</a></div></div>' +

      '<div class="kart wa-kart"><h2>📲 Şoföre Gönder</h2>' +
      (wt ? '<p class="soluk">Gönderilecek numara: <b>wa.me/' + h(wt) + '</b></p>' : '<div class="uyari hata">Bu seferde şoför telefonu yok. <a href="#/sefer-form/' + s.id + '">Düzenle</a> sayfasından ekleyin.</div>') +
      '<div class="butonlar"><a class="btn wa" target="_blank" rel="noopener" href="' + h(waLink(tel, mKonum)) + '">📍 Konumları Gönder</a>' +
      '<a class="btn wa" target="_blank" rel="noopener" href="' + h(waLink(tel, mHepsi)) + '">📤 Konum + İrsaliye/Fiş No Gönder</a></div>' +
      (s.belgeler.length ? '<div class="butonlar"><button type="button" class="btn ana" id="hepsini-paylas">📎 PDF\'leri WhatsApp\'tan Gönder (' + s.belgeler.length + ')</button></div>' +
        '<p class="soluk">PDF butonuna basınca paylaşım menüsü açılır → WhatsApp → şoförü seçin. Önce "Konumları Gönder" ile sohbeti açarsanız şoför listenin başında çıkar.</p>' : '') +
      '<div class="butonlar"><button type="button" class="btn" data-kopyala="' + h(mHepsi) + '">📋 Mesajı Kopyala</button>' +
      '<button type="button" class="btn" data-wa-sor="' + h(mHepsi) + '">Başka numaraya gönder</button></div>' +
      '<details><summary>Mesaj önizleme</summary><pre class="onizleme">' + h(mHepsi) + '</pre></details></div>' +

      '<div class="iki-sutun"><div class="kart"><h2>Güzergah</h2><div class="guzergah">' +
      '<div><small>📦 YÜKLEME (SARIM)</small><b>' + h(s.yd.ad) + '</b><div class="soluk">' + yer(s.yd) + '</div>' + konumHtml(s.yd, yH, s.yukleme_depo_id) + '</div>' +
      '<div class="ok">→</div>' +
      '<div><small>🏁 BOŞALTIM</small><b>' + h(s.bd.ad) + '</b><div class="soluk">' + yer(s.bd) + '</div>' + konumHtml(s.bd, bH, s.bosaltim_depo_id) + '</div></div>' +
      '<dl class="bilgiler"><dt>Yükleme tarihi</dt><dd>' + tarihTr(s.tarih) + '</dd><dt>Teslim tarihi</dt><dd>' + (tarihTr(s.teslim_tarihi) || '—') + '</dd>' +
      '<dt>Şoför</dt><dd>' + (h(s.soforAd) || '—') + (s.so.tc ? ' <span class="soluk">TC ' + h(s.so.tc) + '</span>' : '') + '</dd>' +
      '<dt>Telefon</dt><dd>' + (tel ? '<a href="tel:' + h(tel) + '">' + h(tel) + '</a>' : '—') + '</dd>' +
      '<dt>Çekici / Dorse</dt><dd>' + h(s.cekici_plaka) + ' / ' + h(s.dorse_plaka) + '</dd>' +
      '<dt>İrsaliye no</dt><dd>' + (h(s.irsaliye_no) || '—') + '</dd><dt>Kantar fiş no</dt><dd>' + (h(s.kantar_fis_no) || '—') + '</dd>' +
      (s.notlar ? '<dt>Notlar</dt><dd>' + h(s.notlar).replace(/\n/g, '<br>') + '</dd>' : '') +
      '<dt>Kayıt</dt><dd>' + tarihTr(s.olusturma) + '</dd></dl>' +
      '<div class="butonlar">' + Object.keys(DURUMLAR).filter(function (k) { return k !== s.durum; }).map(function (k) {
        return '<button class="btn kucuk" data-durum="' + k + '">' + DURUMLAR[k] + ' yap</button>';
      }).join('') + '</div></div>' +

      '<div class="kart"><h2>Yük ve Ücret</h2><table class="hesap-tablo">' +
      '<tr><th colspan="2">' + h(s.urunAd || 'Ürün') + ' · ' + kgFmt(s.miktar_kg) + ' kg (' + kgFmt(x.ton) + ' ton)</th></tr>' +
      '<tr><td>Ton fiyatı</td><td>' + tl(s.urun_ton_fiyat) + ' + %' + sayiInput(s.urun_kdv) + ' KDV</td></tr>' +
      '<tr><td>Mal bedeli (KDV hariç)</td><td>' + tl(x.mal) + '</td></tr><tr><td>KDV</td><td>' + tl(x.mal_kdv) + '</td></tr>' +
      '<tr class="toplam"><td>Mal bedeli (KDV dahil)</td><td>' + tl(x.mal_toplam) + '</td></tr>' +
      '<tr><th colspan="2">🚚 Nakliye (taşıyıcıya)</th></tr>' +
      '<tr><td>Ton başı nakliye</td><td>' + tl(s.nakliye_ton_fiyat) + ' + %' + sayiInput(s.nakliye_kdv) + ' KDV</td></tr>' +
      '<tr><td>Nakliye (KDV hariç)</td><td>' + tl(x.nak) + '</td></tr><tr><td>KDV</td><td>' + tl(x.nak_kdv) + '</td></tr>' +
      '<tr class="toplam"><td>Nakliye (KDV dahil)</td><td>' + tl(x.nak_toplam) + '</td></tr></table>' +
      '<div class="butonlar">' + (s.nakliye_odendi ? '<span class="rozet d-teslim">✅ Nakliye ödendi</span>' : '<span class="rozet d-planlandi">⏳ Nakliye ödenmedi</span>') +
      '<button class="btn kucuk" id="odendi">' + (s.nakliye_odendi ? 'Ödenmedi olarak işaretle' : 'Ödendi olarak işaretle') + '</button></div></div></div>' +

      '<div class="kart"><h2>📄 Belgeler (İrsaliye / Kantar Fişi)</h2>' + (s.belgeler.length ? '' : '<p class="soluk">Henüz belge yüklenmedi.</p>') +
      '<div class="belgeler">' + s.belgeler.map(function (b) {
        return '<div class="belge"><span class="belge-ad">' + (/pdf/.test(b.mime) ? '📕' : '🖼️') + ' <b>' + h(BELGE_TURLERI[b.tur] || 'Belge') + '</b> <span class="soluk">' + h(b.orijinal_ad) + ' · ' + Math.round(b.boyut / 1024) + ' KB · ' + tarihTr(b.olusturma) + '</span></span>' +
          '<div class="butonlar"><button class="btn kucuk wa" data-paylas="' + b.id + '">Paylaş</button><button class="btn kucuk" data-ac="' + b.id + '">Aç</button>' +
          '<button class="btn kucuk" data-indir="' + b.id + '">İndir</button><button class="btn kucuk tehlike" data-kaldir="' + b.id + '">Kaldır</button></div></div>';
      }).join('') + '</div>' +
      '<div class="yukle-form"><select id="yukle-tur">' + Object.keys(BELGE_TURLERI).map(function (k) { return '<option value="' + k + '">' + BELGE_TURLERI[k] + '</option>'; }).join('') + '</select>' +
      '<input type="file" id="yukle-dosya" multiple accept="application/pdf,image/*"><button class="btn ana" id="yukle">⬆️ Yükle</button></div></div>' +

      '<div class="kart"><details><summary><b>🕓 Değişiklik Geçmişi</b> (' + gecmis.length + ')</summary><table class="tablo gecmis"><thead><tr><th>Zaman</th><th>İşlem</th><th>Değişiklik</th></tr></thead><tbody>' +
      gecmis.map(function (g) {
        var d = g.islem === 'ekle' ? 'Kayıt oluşturuldu' : Object.keys(g.yeni || {}).filter(function (k) { return k !== 'guncelleme'; }).map(function (k) {
          return '<div><b>' + h(k) + ':</b> ' + h((g.eski || {})[k]) + ' → ' + h(g.yeni[k]) + '</div>';
        }).join('');
        return '<tr><td>' + tarihTr(g.zaman) + '</td><td>' + h((g.tablo === 'belgeler' ? 'Belge ' : '') + g.islem) + '</td><td class="kucuk-yazi">' + d + '</td></tr>';
      }).join('') + '</tbody></table></details></div>' +
      (s.silindi ? '' : '<div class="sil-form"><button class="btn tehlike kucuk" id="sil">🗑️ Seferi Sil</button></div>'), 'seferler');

    var yenile = function () { S.sefer(id); };
    $$('[data-durum]').forEach(function (b) {
      b.onclick = function () {
        var v = { durum: b.dataset.durum };
        if (v.durum === 'teslim' && !ham.teslim_tarihi) v.teslim_tarihi = bugun();
        guncelle('seferler', ham.id, v).then(function () { mesaj('Durum güncellendi.'); yenile(); });
      };
    });
    $('#odendi').onclick = function () { guncelle('seferler', ham.id, { nakliye_odendi: ham.nakliye_odendi ? 0 : 1 }).then(function () { mesaj('Nakliye ödeme durumu güncellendi.'); yenile(); }); };
    var bp = $('#hepsini-paylas');
    if (bp) bp.onclick = function () { dosyalariPaylas(s.belgeler, waMesaj(s, false, true)); };
    $$('[data-paylas]').forEach(function (b) { b.onclick = function () { var bg = bul('belgeler', b.dataset.paylas); dosyalariPaylas([bg], (BELGE_TURLERI[bg.tur] || 'Belge') + ' - Sefer #' + s.id + ' (' + s.cekici_plaka + ')'); }; });
    $$('[data-ac]').forEach(function (b) {
      b.onclick = function () {
        var w = window.open('', '_blank');
        belgeDosyasi(bul('belgeler', b.dataset.ac)).then(function (f) { var u = URL.createObjectURL(f); if (w) w.location = u; else location.href = u; })
          .catch(function (e) { if (w) w.close(); alert(e.message); });
      };
    });
    $$('[data-indir]').forEach(function (b) { b.onclick = function () { var bg = bul('belgeler', b.dataset.indir); belgeDosyasi(bg).then(function (f) { dosyaIndir(f, f.name); }); }; });
    $$('[data-kaldir]').forEach(function (b) {
      b.onclick = function () {
        if (!confirm('Belge kaldırılsın mı? (Silinenler bölümünden geri alınabilir)')) return;
        guncelle('belgeler', Number(b.dataset.kaldir), { silindi: 1 }, 'sil').then(function () { mesaj('Belge kaldırıldı.'); yenile(); });
      };
    });
    $('#yukle').onclick = function () {
      var fl = Array.prototype.slice.call($('#yukle-dosya').files), tur = $('#yukle-tur').value;
      if (!fl.length) { alert('Önce dosya seçin.'); return; }
      fl.reduce(function (p, f) { return p.then(function () { return belgeKaydet(s.id, f, tur); }); }, Promise.resolve())
        .then(function () { mesaj(fl.length + ' belge yüklendi.'); yenile(); });
    };
    var sl = $('#sil');
    if (sl) sl.onclick = function () {
      if (!confirm('Sefer #' + s.id + ' silinsin mi? (Sonradan geri alınabilir)')) return;
      guncelle('seferler', s.id, { silindi: 1 }, 'sil').then(function () { mesaj('Sefer #' + s.id + ' silindi. "Silinenler" bölümünden geri alınabilir.'); location.hash = '#/seferler'; });
    };
  };

  /* ---------------- Sefer listesi ---------------- */
  S.seferler = function (id, q) {
    var tum = seferFiltrele(q), t = { kg: 0, mal: 0, malT: 0, nak: 0, nakT: 0 };
    tum.forEach(function (s) {
      if (s.durum === 'iptal') return;
      var x = hesap(s); t.kg += +s.miktar_kg || 0; t.mal += x.mal; t.malT += x.mal_toplam; t.nak += x.nak; t.nakT += x.nak_toplam;
    });
    var oz = urunOzet(tum);
    var limit = 100, sayfa = Math.max(1, +q.s || 1), liste = tum.slice((sayfa - 1) * limit, sayfa * limit);
    var d = new Date();
    var pzt = new Date(d); pzt.setDate(d.getDate() - ((d.getDay() + 6) % 7));
    var paz = new Date(pzt); paz.setDate(pzt.getDate() + 6);
    var ayBas = new Date(d.getFullYear(), d.getMonth(), 1), aySon = new Date(d.getFullYear(), d.getMonth() + 1, 0);
    var gaBas = new Date(d.getFullYear(), d.getMonth() - 1, 1), gaSon = new Date(d.getFullYear(), d.getMonth(), 0);
    var hizli = [['Bugün', bugun(), bugun()], ['Dün', bugun(-1), bugun(-1)], ['Bu hafta', tarihIso(pzt), tarihIso(paz)],
      ['Bu ay', tarihIso(ayBas), tarihIso(aySon)], ['Geçen ay', tarihIso(gaBas), tarihIso(gaSon)], ['Bu yıl', d.getFullYear() + '-01-01', d.getFullYear() + '-12-31']];
    var sirali = function (t2) { return V[t2].slice().sort(function (a, b) { return (a.silindi - b.silindi) || String(a.ad).localeCompare(String(b.ad), 'tr'); }); };
    var filtreVar = Object.keys(q).some(function (k) { return k !== 's' && q[k]; });
    var sayfalar = Math.ceil(tum.length / limit);

    ciz('<div class="baslik-satir"><h1>Seferler</h1><div class="butonlar"><button class="btn" id="excel">📊 Excel\'e Aktar</button><a class="btn ana" href="#/sefer-form">➕ Yeni Sefer</a></div></div>' +
      '<details class="kart filtre"' + (filtreVar || window.innerWidth > 760 ? ' open' : '') + '><summary><b>🔎 Filtre</b>' + (filtreVar ? ' <span class="rozet d-planlandi">aktif</span>' : '') + '</summary>' +
      '<div class="hizli" style="margin-top:10px">' + hizli.map(function (x) {
        return '<a class="cip ' + (q.bas === x[1] && q.bit === x[2] ? 'aktif' : '') + '" href="#/seferler?' + qs(Object.assign({}, q, { s: '', bas: x[1], bit: x[2] })) + '">' + x[0] + '</a>';
      }).join('') + '</div>' +
      '<form id="f"><div class="filtre-izgara">' +
      '<label>Başlangıç<input type="date" name="bas" value="' + h(q.bas || '') + '"></label><label>Bitiş<input type="date" name="bit" value="' + h(q.bit || '') + '"></label>' +
      '<label>Ürün<select name="urun">' + secenekler(sirali('urunler'), q.urun, 'Tümü') + '</select></label>' +
      '<label>Yükleme deposu<select name="ydepo">' + secenekler(sirali('depolar').filter(function (x) { return x.tip === 'yukleme'; }), q.ydepo, 'Tümü', depoEtiket) + '</select></label>' +
      '<label>Boşaltım deposu<select name="bdepo">' + secenekler(sirali('depolar').filter(function (x) { return x.tip === 'bosaltim'; }), q.bdepo, 'Tümü', depoEtiket) + '</select></label>' +
      '<label>Şoför<select name="sofor">' + secenekler(sirali('soforler'), q.sofor, 'Tümü', function (r) { return r.ad + ' ' + (r.soyad || ''); }) + '</select></label>' +
      '<label>Durum<select name="durum"><option value="">Tümü</option>' + Object.keys(DURUMLAR).map(function (k) { return '<option value="' + k + '"' + (q.durum === k ? ' selected' : '') + '>' + DURUMLAR[k] + '</option>'; }).join('') + '</select></label>' +
      '<label>Nakliye ödemesi<select name="odendi"><option value="">Tümü</option><option value="1"' + (q.odendi === '1' ? ' selected' : '') + '>Ödendi</option><option value="0"' + (q.odendi === '0' ? ' selected' : '') + '>Ödenmedi</option></select></label>' +
      '<label>Plaka<input name="plaka" class="buyuk-harf" value="' + h(q.plaka || '') + '" placeholder="45AAR75"></label>' +
      '<label>Ara<input name="ara" value="' + h(q.ara || '') + '" placeholder="İrsaliye no, TC, isim…"></label></div>' +
      '<div class="butonlar"><button class="btn ana">🔎 Filtrele</button>' + (filtreVar ? '<a class="btn" href="#/seferler">Temizle</a>' : '') + '</div></form></details>' +
      '<div class="istatistik"><div><small>Sefer</small><b>' + tum.length + '</b></div><div><small>Toplam</small><b>' + kgFmt(t.kg / 1000) + '</b><span>ton</span></div>' +
      '<div><small>Mal bedeli KDV hariç</small><b class="kucuk">' + tl(t.mal) + '</b><span>dahil: ' + tl(t.malT) + '</span></div>' +
      '<div><small>Nakliye KDV hariç</small><b class="kucuk">' + tl(t.nak) + '</b><span>dahil: ' + tl(t.nakT) + '</span></div></div>' +
      (oz.length > 1 || (oz.length && !q.urun) ? '<div class="kart"><h2>Ürün Bazında Özet</h2><div class="tablo-kap"><table class="tablo"><thead><tr><th>Ürün</th><th class="sag">Sefer</th><th class="sag">Ton</th><th class="sag">Mal Bedeli (KDV hariç)</th><th class="sag">Nakliye (KDV hariç)</th></tr></thead><tbody>' +
        oz.map(function (o) { return '<tr><td data-e="Ürün"><b>' + h(o.ad) + '</b></td><td data-e="Sefer" class="sag">' + o.adet + '</td><td data-e="Ton" class="sag">' + kgFmt(o.ton) + '</td><td data-e="Mal Bedeli" class="sag">' + tl(o.mal) + '</td><td data-e="Nakliye" class="sag">' + tl(o.nak) + '</td></tr>'; }).join('') +
        '</tbody></table></div></div>' : '') +
      '<div class="kart tablo-kap"><table class="tablo seferler"><thead><tr><th>No</th><th>Tarih</th><th>Durum</th><th>Yükleme → Boşaltım</th><th>Şoför / Araç</th><th>Ürün</th><th class="sag">kg</th><th class="sag">Mal Bedeli</th><th class="sag">Nakliye</th><th>Belge</th></tr></thead><tbody>' +
      liste.map(function (s) {
        var x = hesap(s);
        return '<tr class="tikla" data-git="#/sefer/' + s.id + '"><td data-e="No"><a href="#/sefer/' + s.id + '"><b>#' + s.id + '</b></a></td><td data-e="Tarih">' + tarihTr(s.tarih) + '</td>' +
          '<td data-e="Durum">' + durumRozet(s.durum) + '</td><td data-e="Güzergah">' + h(s.yd.ad) + ' → ' + h(s.bd.ad) + (s.bd.il ? ' <span class="soluk">(' + h(s.bd.il) + ')</span>' : '') + '</td>' +
          '<td data-e="Şoför">' + h(s.soforAd) + ' <span class="soluk">' + h(s.cekici_plaka) + (s.dorse_plaka ? ' / ' + h(s.dorse_plaka) : '') + '</span></td>' +
          '<td data-e="Ürün">' + h(s.urunAd) + '</td><td data-e="kg" class="sag">' + kgFmt(s.miktar_kg) + '</td><td data-e="Mal Bedeli" class="sag">' + tl(x.mal) + '</td>' +
          '<td data-e="Nakliye" class="sag">' + tl(x.nak) + ' ' + (s.nakliye_odendi ? '✅' : '⏳') + '</td><td data-e="Belge">' + (s.belgeler.length ? '📄 ' + s.belgeler.length : '<span class="kirmizi">yok</span>') + '</td></tr>';
      }).join('') + (liste.length ? '' : '<tr><td colspan="10" class="soluk">Bu kriterlere uygun sefer yok.</td></tr>') + '</tbody></table>' +
      (sayfalar > 1 ? '<div class="sayfalama">' + Array.apply(null, Array(sayfalar)).map(function (_, i) {
        return '<a class="' + (i + 1 === sayfa ? 'aktif' : '') + '" href="#/seferler?' + qs(Object.assign({}, q, { s: i + 1 })) + '">' + (i + 1) + '</a>';
      }).join('') + '</div>' : '') + '</div>', 'seferler');

    $('#f').onsubmit = function (e) { e.preventDefault(); location.hash = '#/seferler?' + qs(formVeri(this)); };
    $$('tr[data-git]').forEach(function (tr) { tr.onclick = function (e) { if (!e.target.closest('a')) location.hash = tr.dataset.git; }; });
    $('#excel').onclick = function () { excelSeferler(tum, 'seferler-' + dosyaAdiZaman() + '.xlsx'); };
  };

  /* ================================================================ */
  /* Excel                                                            */
  /* ================================================================ */
  function xlsxYukle() {
    if (window.XLSX) return Promise.resolve(window.XLSX);
    return new Promise(function (res, rej) {
      var sc = document.createElement('script');
      sc.src = 'xlsx.full.min.js';
      sc.onload = function () { res(window.XLSX); };
      sc.onerror = function () { rej(new Error('Excel kütüphanesi yüklenemedi. Sayfayı yenileyip tekrar deneyin.')); };
      document.head.appendChild(sc);
    });
  }
  var SEFER_BASLIK = ['Sefer No', 'Tarih', 'Teslim Tarihi', 'Durum', 'Yükleme Deposu', 'Yükleme İl', 'Yükleme İlçe', 'Yükleme Koordinat',
    'Boşaltım Deposu', 'Boşaltım İl', 'Boşaltım İlçe', 'Boşaltım Koordinat', 'Şoför', 'TC No', 'Telefon', 'Çekici Plaka', 'Dorse Plaka',
    'Ürün', 'Miktar (kg)', 'Miktar (ton)', 'Ürün Ton Fiyatı (₺)', 'Ürün KDV %', 'Mal Bedeli KDV Hariç (₺)', 'Mal KDV (₺)', 'Mal Bedeli KDV Dahil (₺)',
    'Nakliye Ton Fiyatı (₺)', 'Nakliye KDV %', 'Nakliye KDV Hariç (₺)', 'Nakliye KDV (₺)', 'Nakliye KDV Dahil (₺)', 'Nakliye Ödendi',
    'İrsaliye No', 'Kantar Fiş No', 'Belgeler', 'Notlar', 'Oluşturma', 'Son Güncelleme', 'Silindi'];
  function seferSatir(s) {
    var x = hesap(s), r2 = function (n) { return Math.round(n * 100) / 100; };
    var k = function (d) { return d.lat !== '' && d.lat != null ? koor(d.lat) + ',' + koor(d.lng) : ''; };
    return [s.id, tarihTr(s.tarih), tarihTr(s.teslim_tarihi), DURUMLAR[s.durum] || s.durum, s.yd.ad || '', s.yd.il || '', s.yd.ilce || '', k(s.yd),
      s.bd.ad || '', s.bd.il || '', s.bd.ilce || '', k(s.bd), s.soforAd, s.so.tc || '', s.sofor_telefon || '', s.cekici_plaka || '', s.dorse_plaka || '',
      s.urunAd, +s.miktar_kg || 0, Math.round(x.ton * 1000) / 1000, +s.urun_ton_fiyat || 0, +s.urun_kdv || 0, r2(x.mal), r2(x.mal_kdv), r2(x.mal_toplam),
      +s.nakliye_ton_fiyat || 0, +s.nakliye_kdv || 0, r2(x.nak), r2(x.nak_kdv), r2(x.nak_toplam), s.nakliye_odendi ? 'Evet' : 'Hayır',
      s.irsaliye_no || '', s.kantar_fis_no || '', s.belgeler.map(function (b) { return (BELGE_TURLERI[b.tur] || '') + ': ' + b.orijinal_ad; }).join('\n'),
      s.notlar || '', tarihTr(s.olusturma), tarihTr(s.guncelleme), s.silindi ? 'Evet' : ''];
  }
  function sayfaEkle(X, wb, ad, basliklar, satirlar) {
    var ws = X.utils.aoa_to_sheet([basliklar].concat(satirlar));
    ws['!cols'] = basliklar.map(function (b, i) {
      var w = b.length;
      satirlar.forEach(function (r) { w = Math.max(w, String(r[i] == null ? '' : r[i]).length); });
      return { wch: Math.min(50, w + 2) };
    });
    if (satirlar.length) ws['!autofilter'] = { ref: ws['!ref'] };
    X.utils.book_append_sheet(wb, ws, ad);
  }
  function ozetSatirlar(liste) {
    return urunOzet(liste).map(function (o) {
      var r = function (n) { return Math.round(n * 100) / 100; };
      return [o.ad, o.adet, o.kg, Math.round(o.ton * 1000) / 1000, r(o.mal), r(o.malT), r(o.nak), r(o.nakT)];
    });
  }
  var OZET_BASLIK = ['Ürün', 'Sefer Sayısı', 'Toplam kg', 'Toplam ton', 'Mal Bedeli KDV Hariç (₺)', 'Mal Bedeli KDV Dahil (₺)', 'Nakliye KDV Hariç (₺)', 'Nakliye KDV Dahil (₺)'];
  function excelSeferler(liste, ad) {
    xlsxYukle().then(function (X) {
      var wb = X.utils.book_new();
      sayfaEkle(X, wb, 'Seferler', SEFER_BASLIK, liste.slice().reverse().map(seferSatir));
      sayfaEkle(X, wb, 'Ürün Özeti', OZET_BASLIK, ozetSatirlar(liste));
      X.writeFile(wb, ad);
    }).catch(function (e) { alert(e.message); });
  }
  function excelTam() {
    xlsxYukle().then(function (X) {
      var wb = X.utils.book_new(), ss = V.seferler.map(seferTam);
      sayfaEkle(X, wb, 'Seferler', SEFER_BASLIK, ss.map(seferSatir));
      sayfaEkle(X, wb, 'Ürün Özeti', OZET_BASLIK, ozetSatirlar(ss.filter(function (s) { return !s.silindi; })));
      sayfaEkle(X, wb, 'Depolar', ['ID', 'Tip', 'Ad', 'Firma', 'İl', 'İlçe', 'Adres', 'Enlem', 'Boylam', 'Harita', 'Yetkili', 'Telefon', 'Notlar', 'Silindi'],
        V.depolar.map(function (d) { return [d.id, d.tip === 'yukleme' ? 'Yükleme (Sarım)' : 'Boşaltım', d.ad, d.firma, d.il, d.ilce, d.adres, d.lat, d.lng, haritaLink(d.lat, d.lng), d.yetkili, d.telefon, d.notlar, d.silindi ? 'Evet' : '']; }));
      sayfaEkle(X, wb, 'Şoförler', ['ID', 'Ad', 'Soyad', 'TC No', 'Telefon', 'Çekici Plaka', 'Dorse Plaka', 'IBAN', 'Notlar', 'Silindi'],
        V.soforler.map(function (d) { return [d.id, d.ad, d.soyad, d.tc, d.telefon, d.cekici_plaka, d.dorse_plaka, d.iban, d.notlar, d.silindi ? 'Evet' : '']; }));
      sayfaEkle(X, wb, 'Ürünler', ['ID', 'Ürün', 'Ton Fiyatı (₺)', 'KDV %', 'Notlar', 'Silindi'],
        V.urunler.map(function (d) { return [d.id, d.ad, d.ton_fiyat, d.kdv_orani, d.notlar, d.silindi ? 'Evet' : '']; }));
      sayfaEkle(X, wb, 'Belgeler', ['ID', 'Sefer No', 'Tür', 'Dosya Adı', 'Boyut (KB)', 'Yükleme', 'Silindi'],
        V.belgeler.map(function (d) { return [d.id, d.sefer_id, BELGE_TURLERI[d.tur], d.orijinal_ad, Math.round(d.boyut / 1024), tarihTr(d.olusturma), d.silindi ? 'Evet' : '']; }));
      sayfaEkle(X, wb, 'İşlem Geçmişi', ['Zaman', 'Tablo', 'Kayıt ID', 'İşlem', 'Eski', 'Yeni'],
        V.gecmis.slice().reverse().slice(0, 20000).map(function (g) { return [tarihTr(g.zaman), g.tablo, g.kayit_id, g.islem, g.eski ? JSON.stringify(g.eski) : '', g.yeni ? JSON.stringify(g.yeni) : '']; }));
      X.writeFile(wb, 'sefer-takip-tum-veriler-' + dosyaAdiZaman() + '.xlsx');
    }).catch(function (e) { alert(e.message); });
  }

  /* ================================================================ */
  /* Yedek                                                            */
  /* ================================================================ */
  function blobBase64(b) {
    return new Promise(function (res, rej) {
      var r = new FileReader(); r.onload = function () { res(String(r.result).split(',')[1]); }; r.onerror = function () { rej(r.error); }; r.readAsDataURL(b);
    });
  }
  function base64Blob(s, tip) {
    var bin = atob(s), u = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
    return new Blob([u], { type: tip });
  }
  function yedekOlustur() {
    var y = { uygulama: 'sefer-takip', surum: 1, tarih: simdi(), veriler: {}, dosyalar: {} };
    TABLOLAR.forEach(function (t) { y.veriler[t] = V[t]; });
    return V.belgeler.reduce(function (p, b) {
      return p.then(function () {
        return dosyaOku(b.id).then(function (blob) { if (blob) return blobBase64(blob).then(function (s) { y.dosyalar[b.id] = { mime: b.mime, veri: s }; }); });
      });
    }, Promise.resolve()).then(function () {
      return new File([JSON.stringify(y)], 'sefer-takip-yedek-' + dosyaAdiZaman() + '.json', { type: 'application/json' });
    });
  }
  function yedekAlindi() { sonYedek = simdi(); return ayarYaz('sonYedek', sonYedek); }
  function yedektenDon(json) {
    var y = JSON.parse(json);
    if (y.uygulama !== 'sefer-takip' || !y.veriler) throw new Error('Bu dosya bir Sefer Takip yedeği değil.');
    var depolar = TABLOLAR.concat(['dosyalar']);
    return new Promise(function (res, rej) {
      var t = idb.transaction(depolar, 'readwrite');
      depolar.forEach(function (d) { t.objectStore(d).clear(); });
      TABLOLAR.forEach(function (d) { (y.veriler[d] || []).forEach(function (r) { t.objectStore(d).put(r); }); });
      Object.keys(y.dosyalar || {}).forEach(function (id) { t.objectStore('dosyalar').put(base64Blob(y.dosyalar[id].veri, y.dosyalar[id].mime), Number(id)); });
      t.oncomplete = res; t.onerror = function () { rej(t.error); }; t.onabort = function () { rej(t.error); };
    }).then(yukle);
  }

  S.yedek = function () {
    var boyut = V.belgeler.reduce(function (a, b) { return a + (b.silindi ? 0 : b.boyut || 0); }, 0);
    ciz('<h1>💾 Yedek ve Excel</h1>' +
      '<div class="kart bilgi"><b>⚠️ Önemli: Veriler sadece bu cihazda duruyor.</b><ul>' +
      '<li>Bu sürüm sunucusuz çalışır; tüm kayıtlar ve PDF\'ler <b>bu telefonun/bilgisayarın tarayıcısında</b> saklanır.</li>' +
      '<li>Telefon kaybolur, Safari geçmişi temizlenir veya site verileri silinirse kayıtlar gider. <b>Bu yüzden düzenli yedek alın</b> (en az haftada bir).</li>' +
      '<li>iPhone\'da siteyi <b>Paylaş → Ana Ekrana Ekle</b> ile ekleyin ve oradan açın; Safari verileri daha güvenli tutar.</li>' +
      '<li>Silinen kayıtlar kalıcı silinmez: <a href="#/silinenler">Silinenler</a> sayfasından geri alınabilir. Her değişiklik geçmişe yazılır.</li></ul>' +
      '<p>Son yedek: <b>' + (sonYedek ? tarihTr(sonYedek) : 'hiç alınmadı') + '</b> · Kalıcı depolama: <b id="kalici">kontrol ediliyor…</b></p></div>' +
      '<div class="iki-sutun"><div class="kart"><h2>🗜️ Tam Yedek</h2><p>Tüm kayıtlar + tüm PDF belgeler (' + Math.round(boyut / 1024) + ' KB) tek dosyada. Başka cihaza taşımak için de kullanılır.</p>' +
      '<div class="butonlar"><button class="btn ana" id="yedek-paylas">📤 Yedeği Kaydet / Paylaş</button><button class="btn" id="yedek-indir">⬇️ Dosya Olarak İndir</button></div>' +
      '<p class="soluk">iPhone\'da "Kaydet/Paylaş" → <b>Dosyalar\'a Kaydet</b> (iCloud Drive) veya kendinize WhatsApp/e-posta ile gönderin.</p></div>' +
      '<div class="kart"><h2>📊 Excel</h2><p>Tüm seferler, depolar, şoförler, ürünler, belge listesi ve değişiklik geçmişi ayrı sayfalarda.</p>' +
      '<button class="btn ana" id="excel-tam">📊 Tüm Verileri Excel\'e İndir</button>' +
      '<p class="soluk">Sadece belli tarih / ürün için: <a href="#/seferler">Seferler</a> sayfasında filtreleyip "Excel\'e Aktar"a basın.</p></div></div>' +
      '<div class="kart"><h2>Yedekten Geri Yükle</h2><p class="soluk">Daha önce aldığınız <b>.json</b> yedek dosyasını seçin. Bu cihazdaki mevcut veriler yedektekilerle <b>değiştirilir</b> (önce mevcut halin yedeği indirilir).</p>' +
      '<div class="yukle-form"><input type="file" id="yedek-dosya" accept=".json,application/json"><button class="btn tehlike" id="geri-yukle">Yedeği Geri Yükle</button></div></div>', 'yedek');

    if (navigator.storage && navigator.storage.persisted) {
      navigator.storage.persisted().then(function (p) { var el = $('#kalici'); if (el) el.textContent = p ? 'açık ✅' : 'tarayıcı onay vermedi (Ana Ekrana Ekle önerilir)'; });
    } else $('#kalici').textContent = 'bilinmiyor';

    $('#yedek-paylas').onclick = function () {
      var b = this; b.disabled = true;
      yedekOlustur().then(function (f) {
        if (navigator.canShare && navigator.canShare({ files: [f] })) {
          return navigator.share({ files: [f], title: 'Sefer Takip Yedek' }).then(yedekAlindi).catch(function (e) { if (e.name !== 'AbortError') throw e; });
        }
        dosyaIndir(f, f.name); return yedekAlindi();
      }).then(function () { b.disabled = false; if (sonYedek) toast('Yedek hazır'); S.yedek(); }).catch(function (e) { b.disabled = false; alert('Yedek alınamadı: ' + e.message); });
    };
    $('#yedek-indir').onclick = function () {
      yedekOlustur().then(function (f) { dosyaIndir(f, f.name); return yedekAlindi(); }).then(function () { toast('Yedek indirildi'); S.yedek(); });
    };
    $('#excel-tam').onclick = excelTam;
    $('#geri-yukle').onclick = function () {
      var f = $('#yedek-dosya').files[0];
      if (!f) { alert('Önce yedek dosyasını seçin.'); return; }
      if (!confirm('Bu cihazdaki TÜM veriler seçilen yedekle değiştirilecek. Devam etmeden önce mevcut verilerin yedeği indirilecek. Devam?')) return;
      var once = V.seferler.length || V.depolar.length ? yedekOlustur().then(function (y) { dosyaIndir(y, 'geri-yukleme-oncesi-' + y.name); }) : Promise.resolve();
      once.then(function () { return f.text(); }).then(yedektenDon).then(function () {
        mesaj('Yedek geri yüklendi: ' + V.seferler.length + ' sefer, ' + V.belgeler.length + ' belge.'); location.hash = '#/panel'; git();
      }).catch(function (e) { alert('Geri yüklenemedi: ' + e.message); });
    };
  };

  S.silinenler = function () {
    var gruplar = [
      ['seferler', 'Seferler', function (r) { var s = seferTam(r); return '#' + s.id + ' · ' + tarihTr(s.tarih) + ' · ' + (s.yd.ad || '') + ' → ' + (s.bd.ad || '') + ' · ' + s.urunAd + ' ' + kgFmt(s.miktar_kg) + ' kg'; }],
      ['depolar', 'Depolar', function (r) { return depoEtiket(r) + ' (' + (r.tip === 'yukleme' ? 'Yükleme' : 'Boşaltım') + ')'; }],
      ['soforler', 'Şoförler', function (r) { return r.ad + ' ' + (r.soyad || '') + ' · ' + (r.cekici_plaka || ''); }],
      ['urunler', 'Ürünler', function (r) { return r.ad; }],
      ['belgeler', 'Belgeler', function (r) { return 'Sefer #' + r.sefer_id + ' · ' + (BELGE_TURLERI[r.tur] || '') + ' · ' + r.orijinal_ad; }]
    ];
    ciz('<h1>🗑️ Silinen Kayıtlar</h1><p class="soluk">Hiçbir kayıt kalıcı olarak silinmez. Buradan geri alabilirsiniz.</p>' + gruplar.map(function (g) {
      var l = V[g[0]].filter(function (r) { return r.silindi; });
      return '<div class="kart"><h2>' + g[1] + ' (' + l.length + ')</h2>' + (l.length ? '' : '<p class="soluk">Silinmiş kayıt yok.</p>') + l.map(function (r) {
        return '<div class="silinen"><span>' + h(g[2](r)) + '</span><span class="butonlar">' + (g[0] === 'seferler' ? '<a class="btn kucuk" href="#/sefer/' + r.id + '">Gör</a>' : '') +
          '<button class="btn kucuk ana" data-geri="' + g[0] + ':' + r.id + '">↩️ Geri Al</button></span></div>';
      }).join('') + '</div>';
    }).join(''), 'yedek');
    $$('[data-geri]').forEach(function (b) {
      b.onclick = function () {
        var p = b.dataset.geri.split(':');
        guncelle(p[0], Number(p[1]), { silindi: 0 }, 'geri_al').then(function () { mesaj('Kayıt geri alındı.'); S.silinenler(); });
      };
    });
  };

  /* ================================================================ */
  /* Yönlendirme ve başlangıç                                         */
  /* ================================================================ */
  function git() {
    var hs = location.hash.replace(/^#\/?/, ''), parca = hs.split('?'), yol = parca[0].split('/');
    var q = {};
    new URLSearchParams(parca[1] || '').forEach(function (v, k) { q[k] = v; });
    var sayfa = S[yol[0]] || S.panel;
    try { sayfa(yol[1] ? Number(yol[1]) : null, q); } catch (e) { console.error(e); $('#icerik').innerHTML = '<div class="uyari hata">Hata: ' + h(e.message) + '</div>'; }
  }

  // Ortak tıklama işleyicileri (kopyala, başka numaraya gönder)
  document.addEventListener('click', function (e) {
    var k = e.target.closest('[data-kopyala]');
    if (k) { kopyala(k.getAttribute('data-kopyala')); return; }
    var w = e.target.closest('[data-wa-sor]');
    if (w) {
      var son = ''; try { son = localStorage.getItem('sonWaNo') || ''; } catch (x) {}
      var no = prompt('WhatsApp numarası (ör. 0544 406 74 94):', son);
      if (!no) return;
      try { localStorage.setItem('sonWaNo', no); } catch (x) {}
      window.open(waLink(no, w.getAttribute('data-wa-sor')), '_blank');
    }
  });

  if (!window.indexedDB) {
    $('#icerik').innerHTML = '<div class="uyari hata">Bu tarayıcı veri saklamayı desteklemiyor. Safari veya Chrome kullanın (gizli sekme olmadan).</div>';
    return;
  }
  dbAc().then(yukle).then(function () { return ayarOku('sonYedek'); }).then(function (sy) {
    sonYedek = sy || null;
    if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(function () {});
    window.addEventListener('hashchange', git);
    git();
  }).catch(function (e) {
    $('#icerik').innerHTML = '<div class="uyari hata">Veritabanı açılamadı: ' + h(e && e.message) + '. Gizli sekmede iseniz normal sekmede açın.</div>';
  });
})();
