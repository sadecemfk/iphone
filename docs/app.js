/* Sefer Takip - tarayıcıda çalışan DENEME sürümü (GitHub Pages)
 * Tüm veriler bu cihazın tarayıcısında (IndexedDB) saklanır. Düzenli yedek alın!
 * Canlı kullanım için PHP sürümü (sefer-takip/) bir hostinge kurulmalıdır. */
(function () {
  'use strict';

  /* ================================================================ */
  /* Sabitler                                                         */
  /* ================================================================ */
  var DURUMLAR = { planlandi: 'Planlandı', yuklendi: 'Yüklendi / Yolda', teslim: 'Teslim Edildi', iptal: 'İptal' };
  var BELGE_TURLERI = {
    irsaliye: 'Yükleme İrsaliyesi', kantar: 'Yükleme Kantar Fişi',
    bosaltim_irsaliye: 'Boşaltım İrsaliyesi', bosaltim_kantar: 'Boşaltım Kantar Fişi', diger: 'Diğer Belge'
  };
  var BELGE_TARAFI = { irsaliye: 'yukleme', kantar: 'yukleme', bosaltim_irsaliye: 'bosaltim', bosaltim_kantar: 'bosaltim', diger: 'diger' };
  var ESASLAR = { yukleme: 'Yükleme kantarı', bosaltim: 'Boşaltım kantarı', dusuk: 'Hangisi düşükse' };
  var TABLOLAR = ['depolar', 'soforler', 'urunler', 'seferler', 'belgeler', 'gecmis'];
  var VARSAYILAN_KDV = 20;
  var AYAR_VARSAYILAN = {
    firma_adi: '', firma_bilgi: '', varsayilan_mal_esas: 'yukleme', varsayilan_nakliye_esas: 'yukleme', fire_tolerans_yuzde: '0,5'
  };
  var AYAR = Object.assign({}, AYAR_VARSAYILAN);
  var ALAN_ETIKETLERI = {
    tarih: 'Yükleme tarihi', teslim_tarihi: 'Boşaltım (teslim) tarihi', durum: 'Durum',
    yukleme_depo_id: 'Yükleme deposu', bosaltim_depo_id: 'Boşaltım deposu', sofor_id: 'Şoför', sofor_telefon: 'Telefon',
    cekici_plaka: 'Çekici plaka', dorse_plaka: 'Dorse plaka', urun_id: 'Ürün', miktar_kg: 'Yükleme net (kg)',
    irsaliye_no: 'Yükleme irsaliye no', kantar_fis_no: 'Yükleme kantar fiş no',
    bosaltim_kg: 'Boşaltım net (kg)', bosaltim_irsaliye_no: 'Boşaltım irsaliye no', bosaltim_kantar_fis_no: 'Boşaltım kantar fiş no',
    urun_ton_fiyat: 'Ürün ton fiyatı', urun_kdv: 'Ürün KDV %', nakliye_ton_fiyat: 'Nakliye ton fiyatı', nakliye_kdv: 'Nakliye KDV %',
    mal_esas: 'Mal bedeli hesap esası', nakliye_esas: 'Nakliye hesap esası', nakliye_odendi: 'Nakliye ödendi',
    notlar: 'Notlar', silindi: 'Silindi'
  };

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
  function iki(n) { return String(n).padStart(2, '0'); }
  function simdi() {
    var d = new Date();
    return d.getFullYear() + '-' + iki(d.getMonth() + 1) + '-' + iki(d.getDate()) + ' ' + iki(d.getHours()) + ':' + iki(d.getMinutes()) + ':' + iki(d.getSeconds());
  }
  function tarihIso(d) { return d.getFullYear() + '-' + iki(d.getMonth() + 1) + '-' + iki(d.getDate()); }
  function bugun(ofs) { var d = new Date(); if (ofs) d.setDate(d.getDate() + ofs); return tarihIso(d); }
  function tarihTr(t) {
    if (!t) return '';
    var m = String(t).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return h(t);
    return m[3] + '.' + m[2] + '.' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '');
  }
  /** Gerçekten var olan bir tarih mi? (31 Şubat reddedilir) */
  function tarihGecerli(t) {
    var m = String(t || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return false;
    var d = new Date(+m[1], +m[2] - 1, +m[3]);
    return d.getFullYear() === +m[1] && d.getMonth() === +m[2] - 1 && d.getDate() === +m[3] && +m[1] >= 2000 && +m[1] <= 2100;
  }
  /** Sıkı sayı okuma: "27.000" → 27000, "9.000,50" → 9000.5, "%20" → 20; boş → 0; okunamazsa null */
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
  function para(n) { return (+n || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function tl(n) { return para(n) + ' ₺'; }
  function kgFmt(n) { return (+n || 0).toLocaleString('tr-TR', { maximumFractionDigits: 3 }); }
  function sayiFmt(n, ond) { return (+n || 0).toLocaleString('tr-TR', { maximumFractionDigits: ond == null ? 3 : ond }); }
  function sayiInput(n) { if (n === '' || n == null) return ''; return String(+n).replace('.', ','); }
  // Plakalarda Türkçe İ yoktur: i / ı → I
  function plaka(s) { return String(s || '').replace(/\s+/g, '').replace(/[ıi]/g, 'I').toUpperCase(); }
  function trKucuk(s) { return String(s == null ? '' : s).replace(/I/g, 'ı').replace(/İ/g, 'i').toLowerCase(); }
  function telWa(t) {
    var d = String(t || '').replace(/\D+/g, '');
    if (d.indexOf('00') === 0) d = d.slice(2);
    if (d.length === 11 && d[0] === '0') d = '90' + d.slice(1);
    else if (d.length === 10 && d[0] === '5') d = '90' + d;
    return d;
  }
  function cepMi(t) { return /^905\d{9}$/.test(telWa(t)); }
  function tcGecerli(tc) {
    if (!/^[1-9]\d{10}$/.test(tc || '')) return false;
    var d = tc.split('').map(Number);
    var d10 = (((d[0] + d[2] + d[4] + d[6] + d[8]) * 7 - (d[1] + d[3] + d[5] + d[7])) % 10 + 10) % 10;
    var d11 = d.slice(0, 10).reduce(function (a, b) { return a + b; }, 0) % 10;
    return d[9] === d10 && d[10] === d11;
  }
  function waLink(tel, metin) { return 'https://wa.me/' + telWa(tel) + '?text=' + encodeURIComponent(metin); }
  function koor(x) { return String(+(+x).toFixed(7)); }
  function koorVar(lat) { return lat !== '' && lat != null; }
  function haritaLink(lat, lng) { return koorVar(lat) ? 'https://www.google.com/maps/search/?api=1&query=' + koor(lat) + ',' + koor(lng) : ''; }
  function yolLink(lat, lng) { return koorVar(lat) ? 'https://www.google.com/maps/dir/?api=1&destination=' + koor(lat) + ',' + koor(lng) : ''; }
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
  /** Tek kutudaki enlem/boylam: sayı; boş → null; okunamazsa false */
  function tekKoordinat(s) {
    s = String(s == null ? '' : s).trim();
    if (!s) return null;
    if (/^-?\d{1,3}([.,]\d+)?$/.test(s)) return parseFloat(s.replace(',', '.'));
    var p = dmsParcala(s);
    return p.length === 1 ? p[0].deger : false;
  }
  /** Link / metinden [lat, lng]: Google, Apple, Yandex linkleri, "40.81, 32.89", derece biçimleri */
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
  function toast(m) {
    var t = document.createElement('div'); t.className = 'toast'; t.textContent = m;
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 2600);
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
    })).then(function () { return ayarOku('ayarlar'); }).then(function (a) {
      AYAR = Object.assign({}, AYAR_VARSAYILAN, a || {});
    });
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
  function esitMi(a, b) {
    var aBos = a === null || a === undefined || a === '', bBos = b === null || b === undefined || b === '';
    if (aBos || bBos) return aBos && bBos;
    if (typeof b === 'number' && !isNaN(Number(a))) return Math.abs(Number(a) - b) < 1e-9;
    return String(a) === String(b);
  }
  function guncelle(tablo, id, degisiklik, ne) {
    var eski = bul(tablo, id);
    if (!eski) return Promise.resolve(false);
    var e = {}, y = {}, var_ = false;
    Object.keys(degisiklik).forEach(function (k) {
      if (!esitMi(eski[k], degisiklik[k])) {
        e[k] = eski[k] == null ? null : eski[k]; y[k] = degisiklik[k]; var_ = true;
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
  /* İş mantığı: iki taraflı sefer, fire, hesap esası                 */
  /* ================================================================ */
  function tolerans() {
    var v = sayiKati(String(AYAR.fire_tolerans_yuzde));
    return v === null || v < 0 ? 0.5 : v;
  }
  function bosVar(s) { return s.bosaltim_kg !== null && s.bosaltim_kg !== undefined && s.bosaltim_kg !== ''; }
  /** Ücrete esas ağırlık; boşaltım girilmediyse yükleme */
  function esasKg(s, esas) {
    var y = +s.miktar_kg || 0;
    if (!bosVar(s)) return y;
    var b = +s.bosaltim_kg;
    if (esas === 'bosaltim') return b;
    if (esas === 'dusuk') return Math.min(y, b);
    return y;
  }
  function hesap(s) {
    var malEsas = s.mal_esas || 'yukleme', nakEsas = s.nakliye_esas || 'yukleme';
    var malKg = esasKg(s, malEsas), nakKg = esasKg(s, nakEsas);
    var mal = malKg / 1000 * (+s.urun_ton_fiyat || 0), malK = mal * (+s.urun_kdv || 0) / 100;
    var nak = nakKg / 1000 * (+s.nakliye_ton_fiyat || 0), nakK = nak * (+s.nakliye_kdv || 0) / 100;
    var y = +s.miktar_kg || 0, bv = bosVar(s), fire = bv ? y - (+s.bosaltim_kg) : null;
    return {
      ton: y / 1000, bos_kg: bv ? +s.bosaltim_kg : null, fire_kg: fire, fire_yuzde: bv && y > 0 ? fire / y * 100 : null,
      mal_kg: malKg, nak_kg: nakKg, mal_gecici: !bv && malEsas !== 'yukleme', nak_gecici: !bv && nakEsas !== 'yukleme',
      mal: mal, mal_kdv: malK, mal_toplam: mal + malK, nak: nak, nak_kdv: nakK, nak_toplam: nak + nakK
    };
  }
  function fireDurumu(x) {
    if (x.fire_yuzde === null) return null;
    var t = tolerans();
    return x.fire_yuzde > t ? 'asim' : (x.fire_yuzde < -t ? 'fazla' : 'normal');
  }
  function fireMetni(x) { return x.fire_kg === null ? '' : kgFmt(x.fire_kg) + ' kg (%' + sayiFmt(x.fire_yuzde, 2) + ')'; }
  function fireRozet(x) {
    var d = fireDurumu(x);
    if (!d) return '';
    var sinif = { normal: 'd-teslim', asim: 'd-iptal', fazla: 'd-yuklendi' }[d], on = { normal: 'Fire ', asim: '⚠️ Fire ', fazla: '⚠️ Fazla ' }[d];
    return '<span class="rozet ' + sinif + '">' + h(on + fireMetni(x)) + '</span>';
  }
  function depoEtiket(d) {
    if (!d) return '';
    var yer = [d.ilce, d.il].filter(Boolean).join(' / ');
    return (d.ad || '') + (yer ? ' (' + yer + ')' : '') + (d.silindi ? ' [silinmiş]' : '');
  }
  /** Sefer + ilişkili kayıtlar */
  function seferTam(s) {
    var so = bul('soforler', s.sofor_id) || {};
    return Object.assign({}, s, {
      yd: bul('depolar', s.yukleme_depo_id) || {},
      bd: bul('depolar', s.bosaltim_depo_id) || {},
      so: so,
      soforAd: [so.ad, so.soyad].filter(Boolean).join(' '),
      tel: (s.sofor_telefon || '').trim() || so.telefon || '',
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
      var nolar = [];
      if (s.irsaliye_no) nolar.push('📄 Yükleme irsaliye no: ' + s.irsaliye_no);
      if (s.kantar_fis_no) nolar.push('⚖️ Yükleme kantar fiş no: ' + s.kantar_fis_no);
      if (s.bosaltim_irsaliye_no) nolar.push('📄 Boşaltım irsaliye no: ' + s.bosaltim_irsaliye_no);
      if (s.bosaltim_kantar_fis_no) nolar.push('⚖️ Boşaltım kantar fiş no: ' + s.bosaltim_kantar_fis_no);
      if (nolar.length) {
        sat.push('', '*BELGELER:*');
        Array.prototype.push.apply(sat, nolar);
      }
    }
    sat.push('', 'İyi yolculuklar.');
    return sat.join('\n');
  }
  function seferFiltrele(q) {
    var tol = tolerans();
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
      if (q.bosaltim === 'eksik' && (bosVar(s) || (s.durum !== 'yuklendi' && s.durum !== 'teslim'))) return false;
      if (q.bosaltim === 'girildi' && !bosVar(s)) return false;
      if (q.bosaltim === 'asim') {
        var x = hesap(s);
        if (x.fire_yuzde === null || Math.abs(x.fire_yuzde) <= tol) return false;
      }
      if (q.plaka) {
        var p = plaka(q.plaka);
        if (plaka(s.cekici_plaka).indexOf(p) < 0 && plaka(s.dorse_plaka).indexOf(p) < 0) return false;
      }
      if (q.ara) {
        var t = seferTam(s), a = trKucuk(q.ara).replace(/^#/, '');
        var metin = trKucuk([s.irsaliye_no, s.kantar_fis_no, s.bosaltim_irsaliye_no, s.bosaltim_kantar_fis_no, s.notlar,
          t.soforAd, t.so.tc, t.yd.ad, t.bd.ad, t.urunAd, s.cekici_plaka, s.dorse_plaka].join(' '));
        if (metin.indexOf(a) < 0 && String(s.id) !== a) return false;
      }
      return true;
    }).map(seferTam).sort(function (a, b) { return a.tarih < b.tarih ? 1 : a.tarih > b.tarih ? -1 : b.id - a.id; });
  }
  /** Liste toplamları (iptaller hariç) */
  function toplamlar(liste) {
    var t = { adet: liste.length, kg: 0, bos_kg: 0, fire_kg: 0, fire_taban: 0, mal: 0, mal_toplam: 0, nak: 0, nak_toplam: 0, fire_asim: 0, bos_eksik: 0 };
    var tol = tolerans();
    liste.forEach(function (s) {
      if (!bosVar(s) && (s.durum === 'yuklendi' || s.durum === 'teslim')) t.bos_eksik++;
      if (s.durum === 'iptal') return;
      var x = hesap(s);
      t.kg += +s.miktar_kg || 0; t.mal += x.mal; t.mal_toplam += x.mal_toplam; t.nak += x.nak; t.nak_toplam += x.nak_toplam;
      if (x.bos_kg !== null) {
        t.bos_kg += x.bos_kg; t.fire_kg += x.fire_kg; t.fire_taban += +s.miktar_kg || 0;
        if (x.fire_yuzde !== null && Math.abs(x.fire_yuzde) > tol) t.fire_asim++;
      }
    });
    t.fire_yuzde = t.fire_taban > 0 ? t.fire_kg / t.fire_taban * 100 : null;
    return t;
  }
  function urunOzet(liste) {
    var oz = {};
    liste.forEach(function (s) {
      if (s.durum === 'iptal') return;
      var k = s.urunAd || '(Ürün yok)';
      if (!oz[k]) oz[k] = [];
      oz[k].push(s);
    });
    return Object.keys(oz).sort().map(function (k) { var t = toplamlar(oz[k]); t.ad = k; return t; });
  }
  /** Kayıttan sonra kaydı engellemeyen uyarılar */
  function seferUyarilari(s, yeniTc) {
    var t = seferTam(s), x = hesap(s), fd = fireDurumu(x);
    if ((+s.miktar_kg || 0) > 60000) mesaj('Dikkat: Yükleme ' + kgFmt(s.miktar_kg) + ' kg (60 tonun üzerinde). Doğru mu?', 'dikkat');
    if (!(+s.miktar_kg) && (s.durum === 'yuklendi' || s.durum === 'teslim')) mesaj('Dikkat: Araç yüklendi görünüyor ama yükleme kantarı (kg) girilmemiş.', 'dikkat');
    if (fd === 'asim') mesaj('Dikkat: Fire ' + fireMetni(x) + ' — tolerans (%' + sayiFmt(tolerans(), 2) + ') aşıldı. Kantarları kontrol edin.', 'dikkat');
    if (fd === 'fazla') mesaj('Dikkat: Boşaltım kantarı yüklemeden ' + kgFmt(-x.fire_kg) + ' kg FAZLA çıktı. Kantar değerlerini kontrol edin.', 'dikkat');
    if (bosVar(s) && s.durum !== 'teslim' && s.durum !== 'iptal') mesaj('Dikkat: Boşaltım kantarı girildi ama durum hâlâ "' + DURUMLAR[s.durum] + '".', 'dikkat');
    if (t.tel && !cepMi(t.tel)) mesaj('Dikkat: Telefon cep telefonu biçiminde değil (ör. 0544 406 74 94); WhatsApp açılmayabilir.', 'dikkat');
    if (yeniTc && !tcGecerli(yeniTc)) mesaj('Dikkat: Şoförün TC kimlik numarası geçersiz görünüyor, kontrol edin.', 'dikkat');
    var adlar = { irsaliye_no: 'yükleme irsaliye no', kantar_fis_no: 'yükleme kantar fiş no', bosaltim_irsaliye_no: 'boşaltım irsaliye no', bosaltim_kantar_fis_no: 'boşaltım kantar fiş no' };
    Object.keys(adlar).forEach(function (alan) {
      if (!s[alan]) return;
      var ayni = aktif('seferler').filter(function (o) { return o.id !== s.id && String(o[alan] || '') === String(s[alan]); }).map(function (o) { return '#' + o.id; });
      if (ayni.length) mesaj('Dikkat: Bu ' + adlar[alan] + ' (' + s[alan] + ') başka seferde de kayıtlı: ' + ayni.slice(0, 5).join(', ') + '. Mükerrer kayıt olmasın?', 'dikkat');
    });
  }
  function gecmisDeger(alan, v) {
    if (v === null || v === undefined || v === '') return '—';
    switch (alan) {
      case 'yukleme_depo_id': case 'bosaltim_depo_id': return depoEtiket(bul('depolar', v)) || '#' + v;
      case 'sofor_id': var so = bul('soforler', v); return so ? [so.ad, so.soyad].filter(Boolean).join(' ') : '#' + v;
      case 'urun_id': return (bul('urunler', v) || {}).ad || '#' + v;
      case 'durum': return DURUMLAR[v] || v;
      case 'mal_esas': case 'nakliye_esas': return ESASLAR[v] || v;
      case 'nakliye_odendi': case 'silindi': return v ? 'Evet' : 'Hayır';
      case 'tarih': case 'teslim_tarihi': return tarihTr(v);
      case 'miktar_kg': case 'bosaltim_kg': return kgFmt(v) + ' kg';
      case 'urun_ton_fiyat': case 'nakliye_ton_fiyat': return tl(v);
      case 'urun_kdv': case 'nakliye_kdv': return '%' + sayiFmt(v, 2);
    }
    return String(v);
  }

  /* ================================================================ */
  /* Arayüz parçaları                                                 */
  /* ================================================================ */
  var MENU = [
    ['panel', '🏠', 'Panel'], ['seferler', '🚚', 'Seferler'], ['sefer-form', '➕', 'Yeni Sefer'],
    ['depolar', '📍', 'Depolar'], ['soforler', '👤', 'Şoförler'], ['urunler', '📦', 'Ürünler'],
    ['yedek', '💾', 'Yedek / Excel'], ['ayarlar', '⚙️', 'Ayarlar']
  ];
  function ciz(html, aktifMenu) {
    document.body.classList.remove('fis-modu');
    $('#menu').innerHTML = MENU.map(function (m) {
      return '<a href="#/' + m[0] + '" class="' + (m[0] === aktifMenu ? 'aktif' : '') + '"><span>' + m[1] + '</span>' + m[2] + '</a>';
    }).join('');
    var ust = mesajlar.map(function (m) { return '<div class="uyari ' + h(m[0]) + '">' + h(m[1]) + '</div>'; }).join('');
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
      o += '<option value="' + r.id + '"' + (String(secili) === String(r.id) ? ' selected' : '') + '>' + h(etiket ? etiket(r) : r.ad + (r.silindi ? ' [silinmiş]' : '')) + '</option>';
    });
    return o;
  }
  function esasSecenek(ad, secili) {
    return '<select name="' + ad + '" id="' + ad + '">' + Object.keys(ESASLAR).map(function (k) {
      return '<option value="' + k + '"' + (secili === k ? ' selected' : '') + '>' + ESASLAR[k] + '</option>';
    }).join('') + '</select>';
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
  function hataGoster(liste) {
    if (typeof liste === 'string') liste = [liste];
    var el = $('#form-hata');
    if (!el) { alert(liste.join('\n')); return; }
    el.innerHTML = '<b>Kaydedilmedi, lütfen düzeltin:</b><ul>' + liste.map(function (m) { return '<li>' + h(m) + '</li>'; }).join('') + '</ul>';
    el.hidden = false; el.scrollIntoView({ block: 'center' });
  }
  /** Sayı alanı okur; hata listesine ekler. bosOlabilir: boşsa null döner */
  function sayiAlani(v, ad, etiket, hatalar, bosOlabilir, max) {
    var ham = v[ad] == null ? '' : String(v[ad]);
    if (bosOlabilir && ham.trim() === '') return null;
    var x = sayiKati(ham);
    if (x === null) { hatalar.push(etiket + ' okunamadı: "' + ham + '". Sadece rakam girin (ör. 27000 veya 9.000,50).'); return 0; }
    if (x < 0) hatalar.push(etiket + ' negatif olamaz.');
    if (max != null && x > max) hatalar.push(etiket + ' en fazla ' + sayiFmt(max) + ' olabilir.');
    return x;
  }
  function dosyaGirdisi(id, etiket) {
    return '<label class="dosya">' + etiket + '<input type="file" id="' + id + '" multiple accept="application/pdf,image/*"></label>';
  }

  /* ================================================================ */
  /* Sayfalar                                                         */
  /* ================================================================ */
  var S = {};

  S.panel = function () {
    var ay = bugun().slice(0, 7);
    var buAy = seferFiltrele({}).filter(function (s) { return s.tarih.slice(0, 7) === ay; });
    var t = toplamlar(buAy), hepsi = toplamlar(seferFiltrele({}));
    var od = aktif('seferler').filter(function (s) { return !s.nakliye_odendi && s.durum !== 'iptal'; });
    var odT = od.reduce(function (a, s) { return a + hesap(s).nak_toplam; }, 0);
    var son = seferFiltrele({}).slice(0, 10);
    var eksik = !aktif('depolar').length || !aktif('soforler').length || !aktif('urunler').length;
    var ayBas = ay + '-01', aySon = tarihIso(new Date(+ay.slice(0, 4), +ay.slice(5, 7), 0));
    ciz(
      '<div class="baslik-satir"><h1>Panel</h1><a class="btn ana" href="#/sefer-form">➕ Yeni Sefer</a></div>' +
      (eksik ? '<div class="kart bilgi"><b>Başlarken:</b> ' +
        (!aktif('depolar').length ? '<a href="#/depo-form?tip=yukleme">Yükleme (sarım) deposu</a> ve <a href="#/depo-form?tip=bosaltim">boşaltım deposu</a> ekleyin. ' : '') +
        (!aktif('soforler').length ? '<a href="#/sofor-form">Şoför ekleyin.</a> ' : '') +
        (!aktif('urunler').length ? '<a href="#/urun-form">Ürün ekleyin</a> (ör. Fındık, ton fiyatı).' : '') + '</div>' : '') +
      '<div class="istatistik">' +
      '<div><small>Bugün</small><b>' + aktif('seferler').filter(function (s) { return s.tarih === bugun(); }).length + '</b><span>sefer</span></div>' +
      '<div><small>Yolda</small><b>' + aktif('seferler').filter(function (s) { return s.durum === 'yuklendi'; }).length + '</b><span>araç</span></div>' +
      '<div><small>Bu ay</small><b>' + buAy.length + '</b><span>' + kgFmt(t.kg / 1000) + ' ton yüklendi</span></div>' +
      '<div><small>Bu ay mal bedeli (KDV dahil)</small><b class="kucuk">' + tl(t.mal_toplam) + '</b></div>' +
      '<div><small>Bu ay nakliye (KDV dahil)</small><b class="kucuk">' + tl(t.nak_toplam) + '</b></div>' +
      '<div><a href="#/seferler?' + qs({ bas: ayBas, bit: aySon, bosaltim: 'asim' }) + '"><small>Bu ay fire</small><b class="kucuk' + (t.fire_asim ? ' kirmizi' : '') + '">' + kgFmt(t.fire_kg) + ' kg</b><span>' +
        (t.fire_yuzde !== null ? '%' + sayiFmt(t.fire_yuzde, 2) + ' · ' : '') + t.fire_asim + ' sefer tolerans üstü</span></a></div>' +
      (hepsi.bos_eksik ? '<div><a href="#/seferler?bosaltim=eksik"><small>Boşaltım kantarı bekleyen</small><b class="kirmizi">' + hepsi.bos_eksik + '</b><span>sefer</span></a></div>' : '') +
      '<div><a href="#/seferler?odendi=0"><small>Ödenmemiş nakliye</small><b class="kucuk">' + tl(odT) + '</b><span>' + od.length + ' sefer</span></a></div>' +
      '</div>' +
      '<div class="kart"><div class="baslik-satir"><h2>Son Seferler</h2><a href="#/seferler">Tümü →</a></div>' +
      (son.length ? '' : '<p class="soluk">Henüz sefer yok.</p>') +
      '<div class="liste">' + son.map(function (s) {
        var x = hesap(s);
        return '<a class="liste-oge" href="#/sefer/' + s.id + '"><div><b>#' + s.id + ' · ' + tarihTr(s.tarih) + '</b> ' + durumRozet(s.durum) + ' ' + fireRozet(x) + '</div>' +
          '<div>' + h(s.yd.ad) + ' → ' + h(s.bd.ad) + '</div>' +
          '<div class="soluk">' + h(s.urunAd) + ' · ' + kgFmt(s.miktar_kg) + ' kg' + (x.bos_kg !== null ? ' → ' + kgFmt(x.bos_kg) + ' kg' : '') + ' · ' + h(s.soforAd) + ' · ' + h(s.cekici_plaka) + '</div></a>';
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
      '<label>Harita linki veya koordinat yapıştırın<input id="konum-yapistir" autocomplete="off" placeholder="ör. 40.8167, 32.8930 · 40°49\'00&quot;N 32°53\'35&quot;E · Google / Apple / Yandex linki"></label>' +
      '<div class="butonlar"><button type="button" class="btn kucuk" id="konum-coz">🔎 Koordinatı Al</button>' +
      '<button type="button" class="btn kucuk" id="konum-gps">📡 Şu An Bulunduğum Konum</button></div>' +
      '<p class="soluk kucuk-yazi">İpucu: Google Haritalar\'da noktaya basılı tutun, üstte çıkan koordinata dokunup kopyalayın ve buraya yapıştırın. (Bu deneme sürümü kısa linkleri çözemez.)</p>' +
      '<div class="iki"><label>Enlem (lat)<input name="lat" id="lat" inputmode="decimal" value="' + h(koorVar(d.lat) ? koor(d.lat) : '') + '"></label>' +
      '<label>Boylam (lng)<input name="lng" id="lng" inputmode="decimal" value="' + h(koorVar(d.lng) ? koor(d.lng) : '') + '"></label></div>' +
      '<div id="konum-durum" class="soluk"></div><div id="harita-onizleme" class="harita"></div></fieldset>' +
      '<div class="iki"><label>Yetkili kişi<input name="yetkili" value="' + h(d.yetkili) + '"></label>' +
      '<label>Telefon<input name="telefon" type="tel" value="' + h(d.telefon) + '"></label></div>' +
      '<label>Notlar<textarea name="notlar" rows="2">' + h(d.notlar) + '</textarea></label>' +
      '<div class="butonlar"><button class="btn ana">💾 Kaydet</button><a class="btn" href="' + (q.don === 'sefer' ? '#/sefer-form' + (q.sid ? '/' + q.sid : '') : '#/depolar') + '">Vazgeç</a></div></form>' +
      (id ? '<div class="sil-form"><button class="btn tehlike kucuk" id="sil">🗑️ Depoyu Sil</button></div>' : ''), 'depolar');

    var lat = $('#lat'), lng = $('#lng'), durum = $('#konum-durum'), on = $('#harita-onizleme');
    function harita() {
      var a = tekKoordinat(lat.value), b = tekKoordinat(lng.value);
      if (typeof a !== 'number' || typeof b !== 'number') {
        on.innerHTML = '';
        if (a === false || b === false) durum.textContent = '⚠️ Koordinat okunamadı. Ör. 40.8167 ve 32.8930';
        return;
      }
      if (!trIcinde(a, b)) {
        durum.textContent = trIcinde(b, a) ? '⚠️ Enlem ve boylam TERS girilmiş görünüyor (Türkiye: enlem 36-42, boylam 26-45).' : '⚠️ Bu konum Türkiye dışında görünüyor; haritadan kontrol edin.';
      }
      var e = 0.01;
      on.innerHTML = '<iframe loading="lazy" title="Harita" src="https://www.openstreetmap.org/export/embed.html?bbox=' + (b - e) + '%2C' + (a - e) + '%2C' + (b + e) + '%2C' + (a + e) +
        '&layer=mapnik&marker=' + a + '%2C' + b + '"></iframe><a target="_blank" rel="noopener" href="' + haritaLink(a, b) + '">Google Maps\'te kontrol et →</a>';
    }
    function yaz(a, b, m) { lat.value = koor(a); lng.value = koor(b); durum.textContent = m || '✅ Koordinat alındı.'; harita(); }
    function coz() {
      var v = $('#konum-yapistir').value.trim();
      if (!v) { durum.textContent = 'Önce link veya koordinat yapıştırın.'; return; }
      var k = koordinatCoz(v);
      if (k) yaz(k[0], k[1]);
      else durum.textContent = /goo\.gl|maps\.apple|yandex/i.test(v)
        ? '⚠️ Kısa linkler bu deneme sürümünde çözülemiyor. Linki tarayıcıda açıp adres çubuğundaki uzun linki veya koordinatı yapıştırın.'
        : '⚠️ Koordinat bulunamadı. "40.81, 32.89" veya 40°49\'00"N 32°53\'35"E şeklinde yazın.';
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
    [lat, lng].forEach(function (el) {
      el.addEventListener('change', function () {
        var v = tekKoordinat(el.value);
        if (typeof v === 'number' && !/^-?\d{1,3}([.,]\d+)?$/.test(el.value.trim())) el.value = koor(v);
        harita();
      });
    });
    harita();

    $('#f').onsubmit = function (e) {
      e.preventDefault();
      var v = formVeri(this), hatalar = [];
      var la = tekKoordinat(v.lat), lo = tekKoordinat(v.lng);
      if (!v.ad) hatalar.push('Depo adı zorunludur.');
      if (la === false) hatalar.push('Enlem okunamadı: "' + v.lat + '"');
      if (lo === false) hatalar.push('Boylam okunamadı: "' + v.lng + '"');
      if (!hatalar.length && ((la === null) !== (lo === null))) hatalar.push('Enlem ve boylamın ikisi birden girilmelidir.');
      if (!hatalar.length && la !== null && (Math.abs(la) > 90 || Math.abs(lo) > 180)) hatalar.push('Koordinat geçersiz.');
      if (hatalar.length) return hataGoster(hatalar);
      if (la !== null && !trIcinde(la, lo) && !confirm(trIcinde(lo, la)
        ? 'Enlem ve boylam TERS girilmiş görünüyor. Yine de kaydedilsin mi?'
        : 'Bu konum Türkiye dışında görünüyor. Yine de kaydedilsin mi?')) return;
      var veri = {
        tip: v.tip === 'bosaltim' ? 'bosaltim' : 'yukleme', ad: v.ad, firma: v.firma, il: v.il, ilce: v.ilce, adres: v.adres,
        lat: la === null ? '' : +la.toFixed(7), lng: lo === null ? '' : +lo.toFixed(7), yetkili: v.yetkili, telefon: v.telefon, notlar: v.notlar
      };
      (id ? guncelle('depolar', id, veri).then(function () { return id; }) : ekle('depolar', veri)).then(function (yeniId) {
        mesaj(id ? 'Depo güncellendi.' : 'Depo eklendi.');
        if (la === null) mesaj('Dikkat: Bu deponun konumu girilmedi; şoföre konum gönderilemez.', 'dikkat');
        if (q.don === 'sefer') {
          location.hash = '#/sefer-form' + (q.sid ? '/' + q.sid : '') + '?' + qs({ taslak: 1, ydepo: veri.tip === 'yukleme' ? yeniId : '', bdepo: veri.tip === 'bosaltim' ? yeniId : '' });
        } else location.hash = '#/depolar?tip=' + veri.tip;
      });
    };
    if (id) $('#sil').onclick = function () {
      if (!confirm('Bu depo silinsin mi? (Geçmiş seferler etkilenmez, geri alınabilir)')) return;
      guncelle('depolar', id, { silindi: 1 }, 'sil').then(function () { mesaj('Depo silindi (Silinenler bölümünden geri alınabilir).'); location.hash = '#/depolar'; });
    };
  };

  /* ---------------- Şoförler ---------------- */
  S.soforler = function (id, q) {
    var ara = trKucuk(q.ara || '');
    var liste = aktif('soforler').filter(function (s) {
      return !ara || trKucuk([s.ad, s.soyad, s.tc, s.telefon, s.cekici_plaka, s.dorse_plaka].join(' ')).indexOf(ara) >= 0;
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
      var v = formVeri(this), hatalar = [];
      var veri = { ad: v.ad, soyad: v.soyad, tc: v.tc.replace(/\D/g, ''), telefon: v.telefon, cekici_plaka: plaka(v.cekici_plaka), dorse_plaka: plaka(v.dorse_plaka), iban: v.iban.replace(/\s/g, '').toUpperCase(), notlar: v.notlar };
      if (!veri.ad) hatalar.push('Ad zorunludur.');
      if (veri.tc && veri.tc.length !== 11) hatalar.push('TC kimlik no 11 haneli olmalıdır.');
      var ayni = veri.tc && V.soforler.filter(function (s) { return s.tc === veri.tc && s.id !== id; })[0];
      if (ayni) hatalar.push('Bu TC ile kayıtlı başka bir şoför var: ' + ayni.ad + ' ' + (ayni.soyad || '') + (ayni.silindi ? ' (silinmiş; Silinenler sayfasından geri alabilirsiniz)' : ''));
      if (hatalar.length) return hataGoster(hatalar);
      (id ? guncelle('soforler', id, veri) : ekle('soforler', veri)).then(function () {
        mesaj(id ? 'Şoför güncellendi.' : 'Şoför eklendi.');
        if (veri.tc && !tcGecerli(veri.tc)) mesaj('Dikkat: TC kimlik numarası geçersiz görünüyor, kontrol edin.', 'dikkat');
        if (veri.telefon && !cepMi(veri.telefon)) mesaj('Dikkat: Telefon cep telefonu biçiminde değil; WhatsApp çalışmayabilir.', 'dikkat');
        location.hash = '#/soforler';
      });
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
          '<td data-e="KDV" class="sag">%' + sayiFmt(u.kdv_orani, 2) + '</td><td data-e="Sefer" class="sag"><a href="#/seferler?urun=' + u.id + '">' + ss.length + '</a></td>' +
          '<td data-e="Toplam" class="sag">' + kgFmt(kg / 1000) + ' ton</td><td><a class="btn kucuk" href="#/urun-form/' + u.id + '">✏️</a></td></tr>';
      }).join('') + (liste.length ? '' : '<tr><td colspan="6" class="soluk">Kayıt yok.</td></tr>') + '</tbody></table></div>' +
      '<p class="soluk">Ürün fiyatı değiştirildiğinde eski seferlerin fiyatı <b>değişmez</b>; her sefer kendi fiyatını saklar.</p>', 'urunler');
  };

  S['urun-form'] = function (id, q) {
    var d = id ? bul('urunler', id) : null;
    if (id && !d) { location.hash = '#/urunler'; return; }
    d = d || { ad: '', ton_fiyat: '', kdv_orani: VARSAYILAN_KDV, notlar: '' };
    ciz('<h1>' + (id ? 'Ürün Düzenle' : 'Yeni Ürün') + '</h1><div class="uyari hata" id="form-hata" hidden></div>' +
      '<form class="kart form" id="f"><label>Ürün adı *<input name="ad" required value="' + h(d.ad) + '" placeholder="ör. Fındık"></label>' +
      '<div class="iki"><label>Ton başına fiyat (₺, KDV hariç)<input name="ton_fiyat" inputmode="decimal" value="' + h(sayiInput(d.ton_fiyat)) + '" placeholder="9000"></label>' +
      '<label>KDV oranı (%)<input name="kdv_orani" inputmode="decimal" value="' + h(sayiInput(d.kdv_orani)) + '"></label></div>' +
      '<p class="soluk kucuk-yazi">KDV oranını muhasebecinizle teyit edin; ürüne göre farklı olabilir.</p>' +
      '<label>Notlar<textarea name="notlar" rows="2">' + h(d.notlar) + '</textarea></label>' +
      '<div class="butonlar"><button class="btn ana">💾 Kaydet</button><a class="btn" href="' + (q.don === 'sefer' ? '#/sefer-form' + (q.sid ? '/' + q.sid : '') : '#/urunler') + '">Vazgeç</a></div></form>' +
      (id ? '<div class="sil-form"><button class="btn tehlike kucuk" id="sil">🗑️ Ürünü Sil</button></div>' : ''), 'urunler');
    $('#f').onsubmit = function (e) {
      e.preventDefault();
      var v = formVeri(this), hatalar = [];
      var veri = { ad: v.ad, ton_fiyat: sayiAlani(v, 'ton_fiyat', 'Ton fiyatı', hatalar), kdv_orani: sayiAlani(v, 'kdv_orani', 'KDV oranı', hatalar, false, 100), notlar: v.notlar };
      if (!veri.ad) hatalar.push('Ürün adı zorunludur.');
      else if (aktif('urunler').some(function (u) { return u.id !== id && trKucuk(u.ad) === trKucuk(veri.ad); })) hatalar.push('Bu adla kayıtlı bir ürün zaten var.');
      if (hatalar.length) return hataGoster(hatalar);
      (id ? guncelle('urunler', id, veri).then(function () { return id; }) : ekle('urunler', veri)).then(function (yeniId) {
        mesaj(id ? 'Ürün güncellendi. (Eski seferlerin fiyatı değişmez.)' : 'Ürün eklendi.');
        location.hash = q.don === 'sefer' ? '#/sefer-form' + (q.sid ? '/' + q.sid : '') + '?' + qs({ taslak: 1, urun: yeniId }) : '#/urunler';
      });
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
  /** [[inputId, tür], ...] → sırayla kaydet */
  function dosyalariKaydet(seferId, girdiler) {
    var isler = [];
    girdiler.forEach(function (x) {
      var el = $(x[0]);
      if (!el) return;
      Array.prototype.forEach.call(el.files || [], function (f) { isler.push(function () { return belgeKaydet(seferId, f, x[1]); }); });
    });
    return isler.reduce(function (p, fn) { return p.then(fn); }, Promise.resolve());
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

  /* ---------------- Sefer formu (iki taraflı) ---------------- */
  S['sefer-form'] = function (id, q) {
    var s = id ? bul('seferler', id) : null;
    if (id && !s) { location.hash = '#/seferler'; return; }
    if (!s) {
      var son = aktif('seferler').slice(-1)[0];
      s = {
        tarih: bugun(), teslim_tarihi: '', durum: 'yuklendi', yukleme_depo_id: '', bosaltim_depo_id: '', sofor_id: '',
        sofor_telefon: '', cekici_plaka: '', dorse_plaka: '', urun_id: '', miktar_kg: '', irsaliye_no: '', kantar_fis_no: '',
        bosaltim_kg: null, bosaltim_irsaliye_no: '', bosaltim_kantar_fis_no: '',
        urun_ton_fiyat: '', urun_kdv: VARSAYILAN_KDV, nakliye_ton_fiyat: son ? son.nakliye_ton_fiyat : '', nakliye_kdv: son ? son.nakliye_kdv : VARSAYILAN_KDV,
        mal_esas: AYAR.varsayilan_mal_esas || 'yukleme', nakliye_esas: AYAR.varsayilan_nakliye_esas || 'yukleme', nakliye_odendi: 0, notlar: ''
      };
    }
    s = Object.assign({}, s);
    var onSecim = {};
    if (q.ydepo) { s.yukleme_depo_id = +q.ydepo; onSecim.yukleme_depo_id = q.ydepo; }
    if (q.bdepo) { s.bosaltim_depo_id = +q.bdepo; onSecim.bosaltim_depo_id = q.bdepo; }
    if (q.urun) { s.urun_id = +q.urun; onSecim.urun_id = q.urun; }
    var sec = function (r) { return !r.silindi; };
    var yd = V.depolar.filter(function (d) { return d.tip === 'yukleme' && (sec(d) || d.id === s.yukleme_depo_id); });
    var bd = V.depolar.filter(function (d) { return d.tip === 'bosaltim' && (sec(d) || d.id === s.bosaltim_depo_id); });
    var sof = V.soforler.filter(function (d) { return sec(d) || d.id === s.sofor_id; });
    var ur = V.urunler.filter(function (d) { return sec(d) || d.id === s.urun_id; });
    var mevcut = id ? aktif('belgeler').filter(function (b) { return b.sefer_id === id; }) : [];
    var yuklu = function (turler) {
      var l = mevcut.filter(function (b) { return turler.indexOf(b.tur) >= 0; }).map(function (b) { return BELGE_TURLERI[b.tur]; });
      return l.length ? '<p class="soluk kucuk-yazi">Yüklü: ' + h(l.join(' · ')) + ' (silmek için sefer sayfasını kullanın)</p>' : '';
    };
    var sid = id ? '&sid=' + id : '';
    var esasAcik = (s.mal_esas || 'yukleme') !== 'yukleme' || (s.nakliye_esas || 'yukleme') !== 'yukleme';

    ciz('<h1>' + (id ? 'Sefer #' + id + ' Düzenle' : 'Yeni Sefer') + '</h1><div class="uyari hata" id="form-hata" hidden></div>' +
      '<form class="form" id="f">' +
      '<div class="kart"><h2>Genel</h2><div class="iki">' +
      '<label>Yükleme tarihi *<input type="date" name="tarih" required value="' + h(s.tarih) + '"></label>' +
      '<label>Durum<select name="durum">' + Object.keys(DURUMLAR).map(function (k) { return '<option value="' + k + '"' + (s.durum === k ? ' selected' : '') + '>' + DURUMLAR[k] + '</option>'; }).join('') + '</select></label></div></div>' +
      '<div class="kart"><h2>Güzergah</h2><div class="iki">' +
      '<label>📦 Yükleme (Sarım) deposu *<select name="yukleme_depo_id" required>' + secenekler(yd, s.yukleme_depo_id, '— Seçin —', depoEtiket) + '</select>' +
      '<a class="kucuk-link taslak-link" href="#/depo-form?tip=yukleme&don=sefer' + sid + '">+ yeni yükleme deposu</a></label>' +
      '<label>🏁 Boşaltım deposu *<select name="bosaltim_depo_id" required>' + secenekler(bd, s.bosaltim_depo_id, '— Seçin —', depoEtiket) + '</select>' +
      '<a class="kucuk-link taslak-link" href="#/depo-form?tip=bosaltim&don=sefer' + sid + '">+ yeni boşaltım deposu</a></label></div></div>' +
      '<div class="kart"><h2>Şoför ve Araç</h2><label>Şoför<select name="sofor_id" id="sofor_id"><option value="">— Seçin —</option><option value="yeni">➕ Yeni şoför ekle…</option>' +
      sof.map(function (o) {
        return '<option value="' + o.id + '"' + (String(s.sofor_id) === String(o.id) ? ' selected' : '') + ' data-tel="' + h(o.telefon) + '" data-cekici="' + h(o.cekici_plaka) + '" data-dorse="' + h(o.dorse_plaka) + '">' +
          h([o.ad, o.soyad].filter(Boolean).join(' ') + (o.cekici_plaka ? ' · ' + o.cekici_plaka : '') + (o.tc ? ' · TC ' + o.tc : '') + (o.silindi ? ' [silinmiş]' : '')) + '</option>';
      }).join('') + '</select></label>' +
      '<div class="uc" id="yeni-sofor" hidden><label>Ad *<input name="yeni_ad"></label><label>Soyad<input name="yeni_soyad"></label><label>TC No<input name="yeni_tc" inputmode="numeric" maxlength="11"></label></div>' +
      '<div class="uc"><label>Telefon (WhatsApp)<input type="tel" name="sofor_telefon" id="sofor_telefon" value="' + h(s.sofor_telefon) + '" placeholder="0544 406 74 94"></label>' +
      '<label>Çekici plaka<input name="cekici_plaka" id="cekici_plaka" class="buyuk-harf" value="' + h(s.cekici_plaka) + '" placeholder="45AAR75"></label>' +
      '<label>Dorse plaka<input name="dorse_plaka" id="dorse_plaka" class="buyuk-harf" value="' + h(s.dorse_plaka) + '" placeholder="45AAR12"></label></div>' +
      '<p class="soluk kucuk-yazi">Telefon boş bırakılırsa şoför kartındaki numara kullanılır.</p></div>' +

      '<div class="kart taraf yukleme-tarafi"><h2>📦 Yükleme Tarafı</h2><div class="uc">' +
      '<label>Yükleme kantarı net (kg)<input name="miktar_kg" id="miktar_kg" inputmode="decimal" value="' + h(sayiInput(s.miktar_kg)) + '" placeholder="27000"></label>' +
      '<label>Yükleme irsaliye no<input name="irsaliye_no" value="' + h(s.irsaliye_no) + '"></label>' +
      '<label>Yükleme kantar fiş no<input name="kantar_fis_no" value="' + h(s.kantar_fis_no) + '"></label></div>' +
      '<div class="iki">' + dosyaGirdisi('b-irsaliye', '📄 Yükleme irsaliyesi (PDF / foto)') + dosyaGirdisi('b-kantar', '⚖️ Yükleme kantar fişi (PDF / foto)') + '</div>' +
      yuklu(['irsaliye', 'kantar']) + '</div>' +

      '<div class="kart taraf bosaltim-tarafi"><h2>🏁 Boşaltım Tarafı</h2>' +
      '<p class="soluk kucuk-yazi">Araç boşaltınca doldurun. Sefer sayfasındaki "Boşaltımı Kaydet" kutusundan da girilebilir.</p><div class="iki">' +
      '<label>Boşaltım (teslim) tarihi<input type="date" name="teslim_tarihi" value="' + h(s.teslim_tarihi) + '"></label>' +
      '<label>Boşaltım kantarı net (kg)<input name="bosaltim_kg" id="bosaltim_kg" inputmode="decimal" value="' + h(bosVar(s) ? sayiInput(s.bosaltim_kg) : '') + '" placeholder="Boşaltımda tartılan"></label>' +
      '<label>Boşaltım irsaliye no<input name="bosaltim_irsaliye_no" value="' + h(s.bosaltim_irsaliye_no) + '"></label>' +
      '<label>Boşaltım kantar fiş no<input name="bosaltim_kantar_fis_no" value="' + h(s.bosaltim_kantar_fis_no) + '"></label></div>' +
      '<div class="iki">' + dosyaGirdisi('b-bos-irsaliye', '📄 Boşaltım irsaliyesi (PDF / foto)') + dosyaGirdisi('b-bos-kantar', '⚖️ Boşaltım kantar fişi (PDF / foto)') + '</div>' +
      yuklu(['bosaltim_irsaliye', 'bosaltim_kantar']) + '</div>' +

      '<div class="kart"><h2>Ürün ve Ücret</h2><div class="iki">' +
      '<label>Ürün<select name="urun_id" id="urun_id"><option value="">— Seçin —</option>' + ur.map(function (u) {
        return '<option value="' + u.id + '"' + (String(s.urun_id) === String(u.id) ? ' selected' : '') + ' data-fiyat="' + h(sayiInput(u.ton_fiyat)) + '" data-kdv="' + h(sayiInput(u.kdv_orani)) + '">' + h(u.ad + (u.silindi ? ' [silinmiş]' : '')) + '</option>';
      }).join('') + '</select><a class="kucuk-link taslak-link" href="#/urun-form?don=sefer' + sid + '">+ yeni ürün</a></label>' +
      '<label class="onay"><input type="checkbox" name="nakliye_odendi" value="1"' + (s.nakliye_odendi ? ' checked' : '') + '> Nakliye ücreti şoföre ödendi</label></div>' +
      '<div class="iki"><label>Ürün ton fiyatı (₺, KDV hariç)<input name="urun_ton_fiyat" id="urun_ton_fiyat" inputmode="decimal" value="' + h(sayiInput(s.urun_ton_fiyat)) + '" placeholder="9000"></label>' +
      '<label>Ürün KDV (%)<input name="urun_kdv" id="urun_kdv" inputmode="decimal" value="' + h(sayiInput(s.urun_kdv)) + '"></label>' +
      '<label>Nakliye ton fiyatı (₺, KDV hariç)<input name="nakliye_ton_fiyat" id="nakliye_ton_fiyat" inputmode="decimal" value="' + h(sayiInput(s.nakliye_ton_fiyat)) + '" placeholder="100"></label>' +
      '<label>Nakliye KDV (%)<input name="nakliye_kdv" id="nakliye_kdv" inputmode="decimal" value="' + h(sayiInput(s.nakliye_kdv)) + '"></label></div>' +
      '<details class="esas"' + (esasAcik ? ' open' : '') + '><summary>⚖️ Ücret hangi kantara göre hesaplansın? <span class="soluk">(mal: ' + ESASLAR[s.mal_esas || 'yukleme'] + ' · nakliye: ' + ESASLAR[s.nakliye_esas || 'yukleme'] + ')</span></summary>' +
      '<div class="iki"><label>Mal bedeli hesabı' + esasSecenek('mal_esas', s.mal_esas || 'yukleme') + '</label><label>Nakliye ücreti hesabı' + esasSecenek('nakliye_esas', s.nakliye_esas || 'yukleme') + '</label></div>' +
      '<p class="soluk kucuk-yazi">Boşaltım kantarı henüz girilmediyse tutar geçici olarak yükleme kantarına göre gösterilir. Varsayılanlar "Ayarlar" sayfasından değiştirilir.</p></details>' +
      '<div class="hesap" id="hesap-ozet"></div></div>' +

      '<div class="kart">' + dosyaGirdisi('b-diger', '📎 Diğer belge (PDF / foto)') +
      '<label>Notlar<textarea name="notlar" rows="3">' + h(s.notlar) + '</textarea></label></div>' +
      '<div class="butonlar yapiskan"><button class="btn ana buyuk" id="kaydet">💾 Kaydet</button><a class="btn" href="' + (id ? '#/sefer/' + id : '#/seferler') + '">Vazgeç</a></div></form>', 'sefer-form');

    var f = $('#f'), sofor = $('#sofor_id'), urun = $('#urun_id');
    var yeniSoforGoster = function () { $('#yeni-sofor').hidden = sofor.value !== 'yeni'; };
    sofor.onchange = function () {
      var o = sofor.options[sofor.selectedIndex];
      yeniSoforGoster();
      if (o && o.getAttribute('data-tel') !== null) {
        if (o.getAttribute('data-tel')) $('#sofor_telefon').value = o.getAttribute('data-tel');
        if (o.getAttribute('data-cekici')) $('#cekici_plaka').value = o.getAttribute('data-cekici');
        if (o.getAttribute('data-dorse')) $('#dorse_plaka').value = o.getAttribute('data-dorse');
      }
    };
    var urunFiyati = function () {
      var o = urun.options[urun.selectedIndex];
      if (o && o.getAttribute('data-fiyat') !== null) { $('#urun_ton_fiyat').value = o.getAttribute('data-fiyat'); $('#urun_kdv').value = o.getAttribute('data-kdv'); }
      hesapla();
    };
    urun.onchange = urunFiyati;
    var alanlar = { miktar_kg: 'Yükleme net', bosaltim_kg: 'Boşaltım net', urun_ton_fiyat: 'Ürün ton fiyatı', urun_kdv: 'Ürün KDV', nakliye_ton_fiyat: 'Nakliye ton fiyatı', nakliye_kdv: 'Nakliye KDV' };
    var esasAd = { yukleme: 'yükleme kantarı', bosaltim: 'boşaltım kantarı', dusuk: 'düşük olan' };
    function hesapla() {
      var d = {}, sorun = [];
      Object.keys(alanlar).forEach(function (k) {
        var el = $('#' + k), v = sayiKati(el.value);
        el.classList.toggle('hatali', v === null || v < 0);
        if (v === null) sorun.push(alanlar[k] + ' okunamadı'); else if (v < 0) sorun.push(alanlar[k] + ' negatif olamaz');
        d[k] = v || 0;
      });
      var bGir = $('#bosaltim_kg').value.trim() !== '' && sayiKati($('#bosaltim_kg').value) !== null;
      var tmp = { miktar_kg: d.miktar_kg, bosaltim_kg: bGir ? d.bosaltim_kg : null, urun_ton_fiyat: d.urun_ton_fiyat, urun_kdv: d.urun_kdv,
        nakliye_ton_fiyat: d.nakliye_ton_fiyat, nakliye_kdv: d.nakliye_kdv, mal_esas: $('#mal_esas').value, nakliye_esas: $('#nakliye_esas').value };
      var x = hesap(tmp), tol = tolerans();
      var sat = function (a, v, k) { return '<div class="satir' + (k ? ' kalin' : '') + '"><span>' + a + '</span><span>' + v + '</span></div>'; };
      var html = sorun.length ? '<div class="satir kirmizi">⚠️ ' + sorun.join(', ') + ' — sadece rakam girin (ör. 27000 veya 9.000,50)</div>' : '';
      if (d.miktar_kg || bGir) {
        html += sat('Yükleme kantarı', kgFmt(d.miktar_kg) + ' kg', true);
        if (x.bos_kg !== null) {
          var asim = Math.abs(x.fire_yuzde || 0) > tol;
          html += sat('Boşaltım kantarı', kgFmt(x.bos_kg) + ' kg', true) + '<div class="satir' + (asim ? ' kirmizi' : '') + '"><span>' + (x.fire_kg < 0 ? 'Fazla' : 'Fire') + '</span><span>' +
            kgFmt(Math.abs(x.fire_kg)) + ' kg (%' + sayiFmt(Math.abs(x.fire_yuzde || 0), 2) + ')' + (asim ? ' ⚠️ tolerans %' + sayiFmt(tol, 2) + ' aşıldı' : '') + '</span></div>';
        }
        var gec = function (g) { return g ? ' — geçici, boşaltım girilmedi' : ''; };
        html += sat('Mal bedeli esası', esasAd[tmp.mal_esas] + ' · ' + kgFmt(x.mal_kg) + ' kg' + gec(x.mal_gecici)) +
          sat('Mal bedeli (KDV hariç)', tl(x.mal)) + sat('Mal KDV', tl(x.mal_kdv)) + sat('Mal bedeli (KDV dahil)', tl(x.mal_toplam), true) +
          sat('Nakliye esası', esasAd[tmp.nakliye_esas] + ' · ' + kgFmt(x.nak_kg) + ' kg' + gec(x.nak_gecici)) +
          sat('Nakliye (KDV hariç)', tl(x.nak)) + sat('Nakliye KDV', tl(x.nak_kdv)) + sat('Nakliye (KDV dahil)', tl(x.nak_toplam), true);
        if (d.miktar_kg > 60000) html += '<div class="satir kirmizi">⚠️ 60 tonun üzerinde, miktarı kontrol edin.</div>';
      }
      $('#hesap-ozet').innerHTML = html;
    }
    Object.keys(alanlar).forEach(function (k) { $('#' + k).oninput = hesapla; });
    $('#mal_esas').onchange = hesapla; $('#nakliye_esas').onchange = hesapla;

    // Taslak: "+ yeni depo/ürün" linkine basınca ya da sayfa yenilenince yazılanlar kaybolmasın
    var taslakAnahtar = 'taslak:sefer-form:' + (id || 'yeni');
    var taslakKaydet = function () {
      var v = {};
      $$('input, select, textarea', f).forEach(function (el) {
        if (!el.name || el.type === 'file') return;
        v[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
      });
      try { localStorage.setItem(taslakAnahtar, JSON.stringify({ zaman: Date.now(), veri: v })); } catch (e) {}
    };
    var taslakYukle = function (kayit) {
      $$('input, select, textarea', f).forEach(function (el) {
        if (!el.name || el.type === 'file' || !(el.name in kayit.veri)) return;
        if (el.type === 'checkbox') el.checked = kayit.veri[el.name] === '1'; else el.value = kayit.veri[el.name];
      });
      Object.keys(onSecim).forEach(function (ad) { if (f.elements[ad]) f.elements[ad].value = onSecim[ad]; });
      yeniSoforGoster();
      if (onSecim.urun_id) urunFiyati(); else hesapla();
    };
    var kayit = null;
    try { kayit = JSON.parse(localStorage.getItem(taslakAnahtar) || 'null'); } catch (e) {}
    if (kayit && Date.now() - kayit.zaman < 24 * 3600 * 1000) {
      if (q.taslak) { taslakYukle(kayit); toast('Yazdığınız bilgiler geri getirildi'); }
      else {
        var bar = document.createElement('div');
        bar.className = 'uyari dikkat taslak-bar';
        bar.innerHTML = '📝 Bu formda kaydedilmemiş bilgiler var. <button type="button" class="btn kucuk ana">Taslağı geri yükle</button> <button type="button" class="btn kucuk">Sil</button>';
        bar.querySelector('.ana').onclick = function () { taslakYukle(kayit); bar.remove(); toast('Taslak geri yüklendi'); };
        bar.querySelector('.btn:not(.ana)').onclick = function () { try { localStorage.removeItem(taslakAnahtar); } catch (e) {} bar.remove(); };
        f.parentNode.insertBefore(bar, f);
      }
    } else if (q.urun) urunFiyati();
    f.addEventListener('input', taslakKaydet); f.addEventListener('change', taslakKaydet);
    $$('.taslak-link', f).forEach(function (a) { a.addEventListener('click', taslakKaydet); });
    yeniSoforGoster(); hesapla();

    f.onsubmit = function (e) {
      e.preventDefault();
      var v = formVeri(this), hatalar = [];
      var veri = {
        tarih: v.tarih, teslim_tarihi: v.teslim_tarihi, durum: DURUMLAR[v.durum] ? v.durum : 'planlandi',
        yukleme_depo_id: Number(v.yukleme_depo_id) || null, bosaltim_depo_id: Number(v.bosaltim_depo_id) || null,
        sofor_id: v.sofor_id === 'yeni' ? null : (Number(v.sofor_id) || null),
        sofor_telefon: v.sofor_telefon, cekici_plaka: plaka(v.cekici_plaka), dorse_plaka: plaka(v.dorse_plaka), urun_id: Number(v.urun_id) || null,
        miktar_kg: sayiAlani(v, 'miktar_kg', 'Yükleme net (kg)', hatalar, false, 1000000), irsaliye_no: v.irsaliye_no, kantar_fis_no: v.kantar_fis_no,
        bosaltim_kg: sayiAlani(v, 'bosaltim_kg', 'Boşaltım net (kg)', hatalar, true, 1000000), bosaltim_irsaliye_no: v.bosaltim_irsaliye_no, bosaltim_kantar_fis_no: v.bosaltim_kantar_fis_no,
        urun_ton_fiyat: sayiAlani(v, 'urun_ton_fiyat', 'Ürün ton fiyatı', hatalar), urun_kdv: sayiAlani(v, 'urun_kdv', 'Ürün KDV oranı', hatalar, false, 100),
        nakliye_ton_fiyat: sayiAlani(v, 'nakliye_ton_fiyat', 'Nakliye ton fiyatı', hatalar), nakliye_kdv: sayiAlani(v, 'nakliye_kdv', 'Nakliye KDV oranı', hatalar, false, 100),
        mal_esas: ESASLAR[v.mal_esas] ? v.mal_esas : 'yukleme', nakliye_esas: ESASLAR[v.nakliye_esas] ? v.nakliye_esas : 'yukleme',
        nakliye_odendi: v.nakliye_odendi ? 1 : 0, notlar: v.notlar
      };
      if (!tarihGecerli(veri.tarih)) hatalar.push('Geçerli bir yükleme tarihi giriniz.');
      if (veri.teslim_tarihi && !tarihGecerli(veri.teslim_tarihi)) hatalar.push('Boşaltım (teslim) tarihi geçersiz.');
      else if (veri.teslim_tarihi && tarihGecerli(veri.tarih) && veri.teslim_tarihi < veri.tarih) hatalar.push('Boşaltım (teslim) tarihi, yükleme tarihinden önce olamaz.');
      if (!veri.yukleme_depo_id) hatalar.push('Yükleme (sarım) deposunu seçiniz.');
      if (!veri.bosaltim_depo_id) hatalar.push('Boşaltım deposunu seçiniz.');
      var yeniTc = (v.yeni_tc || '').replace(/\D/g, ''), yeni = null;
      if (v.sofor_id === 'yeni') {
        var ayni = yeniTc && V.soforler.filter(function (x) { return x.tc === yeniTc; })[0];
        if (!v.yeni_ad) hatalar.push('Yeni şoför için ad giriniz.');
        else if (yeniTc && yeniTc.length !== 11) hatalar.push('TC kimlik no 11 haneli olmalıdır.');
        else if (ayni) {
          hatalar.push('Bu TC ile kayıtlı şoför zaten var: ' + ayni.ad + ' ' + (ayni.soyad || '') + (ayni.silindi ? ' (silinmiş; Silinenler sayfasından geri alabilirsiniz).' : '. Listeden seçildi, kontrol edip tekrar kaydedin.'));
          if (!ayni.silindi) { sofor.value = String(ayni.id); yeniSoforGoster(); }
        } else yeni = { ad: v.yeni_ad, soyad: v.yeni_soyad, tc: yeniTc, telefon: veri.sofor_telefon, cekici_plaka: veri.cekici_plaka, dorse_plaka: veri.dorse_plaka, iban: '', notlar: '' };
      }
      if (hatalar.length) return hataGoster(hatalar);
      var btn = $('#kaydet'); btn.disabled = true; btn.textContent = 'Kaydediliyor…';
      var sid2;
      (yeni ? ekle('soforler', yeni).then(function (x) { veri.sofor_id = x; }) : Promise.resolve()).then(function () {
        return id ? guncelle('seferler', id, veri).then(function () { return id; }) : ekle('seferler', veri);
      }).then(function (x) {
        sid2 = x;
        return dosyalariKaydet(sid2, [['#b-irsaliye', 'irsaliye'], ['#b-kantar', 'kantar'], ['#b-bos-irsaliye', 'bosaltim_irsaliye'], ['#b-bos-kantar', 'bosaltim_kantar'], ['#b-diger', 'diger']]);
      }).then(function () {
        try { localStorage.removeItem(taslakAnahtar); } catch (e2) {}
        seferUyarilari(bul('seferler', sid2), yeni ? yeni.tc : '');
        mesaj(id ? 'Sefer güncellendi.' : 'Sefer kaydedildi. Şimdi şoföre WhatsApp ile gönderebilirsiniz.');
        location.hash = '#/sefer/' + sid2;
      }).catch(function (err) {
        btn.disabled = false; btn.textContent = '💾 Kaydet';
        hataGoster('Kaydedilemedi: ' + (err && err.message ? err.message : err));
      });
    };
  };

  /* ---------------- Sefer detay (iki taraflı) ---------------- */
  S.sefer = function (id) {
    var ham = bul('seferler', id);
    if (!ham) { mesaj('Sefer bulunamadı.', 'hata'); location.hash = '#/seferler'; return; }
    var s = seferTam(ham), x = hesap(s), tel = s.tel, wt = telWa(tel), silinmis = !!s.silindi, bv = bosVar(s);
    var mKonum = waMesaj(s, true, false), mHepsi = waMesaj(s, true, true);
    var yH = haritaLink(s.yd.lat, s.yd.lng), bH = haritaLink(s.bd.lat, s.bd.lng);
    var taraf = { yukleme: [], bosaltim: [], diger: [] };
    s.belgeler.forEach(function (b) { taraf[BELGE_TARAFI[b.tur] || 'diger'].push(b); });
    var gecmis = V.gecmis.filter(function (g) {
      return (g.tablo === 'seferler' && g.kayit_id === s.id) || (g.tablo === 'belgeler' && (bul('belgeler', g.kayit_id) || {}).sefer_id === s.id);
    }).reverse();
    var yer = function (d) { return h([d.ilce, d.il].filter(Boolean).join(' / ')); };
    var konumHtml = function (d, link, depoId) {
      return link ? '<a target="_blank" rel="noopener" href="' + h(link) + '">🗺️ Harita</a> · <a target="_blank" rel="noopener" href="' + h(yolLink(d.lat, d.lng)) + '">Yol tarifi</a>'
        : '<span class="kirmizi">⚠️ Koordinat yok</span> <a href="#/depo-form/' + depoId + '">ekle</a>';
    };
    var belgeListesi = function (liste, bosMetin) {
      if (!liste.length) return '<p class="soluk kucuk-yazi">' + h(bosMetin) + '</p>';
      return '<div class="belgeler">' + liste.map(function (b) {
        return '<div class="belge"><span class="belge-ad">' + (/pdf/.test(b.mime) ? '📕' : '🖼️') + ' <b>' + h(BELGE_TURLERI[b.tur] || 'Belge') + '</b> <span class="soluk">' + h(b.orijinal_ad) + ' · ' + Math.round(b.boyut / 1024) + ' KB · ' + tarihTr(b.olusturma) + '</span></span>' +
          '<div class="butonlar"><button class="btn kucuk wa" data-paylas="' + b.id + '">Paylaş</button><button class="btn kucuk" data-ac="' + b.id + '">Aç</button>' +
          '<button class="btn kucuk" data-indir="' + b.id + '">İndir</button>' + (silinmis ? '' : '<button class="btn kucuk tehlike" data-kaldir="' + b.id + '">Kaldır</button>') + '</div></div>';
      }).join('') + '</div>';
    };
    var tol = tolerans(), fd = fireDurumu(x);
    var islemAd = { ekle: 'Oluşturuldu', duzenle: 'Düzenlendi', sil: 'Silindi', geri_al: 'Geri alındı' };

    ciz((silinmis ? '<div class="uyari hata silinmis-bant">🗑️ <b>Bu sefer silinmiş.</b> Listelerde ve toplamlarda görünmez. <button class="btn kucuk ana" id="geri-al">↩️ Seferi Geri Al</button></div>' : '') +
      '<div class="baslik-satir"><h1>Sefer #' + s.id + ' ' + durumRozet(s.durum) + ' ' + fireRozet(x) + '</h1><div class="butonlar">' +
      (silinmis ? '' : '<a class="btn" href="#/sefer-form/' + s.id + '">✏️ Düzenle</a><a class="btn" href="#/sefer-form?ydepo=' + (s.yukleme_depo_id || '') + '&bdepo=' + (s.bosaltim_depo_id || '') + '">➕ Aynı güzergahta yeni sefer</a>') +
      '<a class="btn" href="#/fis/' + s.id + '">🖨️ Sefer Fişi</a><a class="btn" href="#/fis/' + s.id + '?fiyatsiz=1">🖨️ Fiyatsız Fiş</a></div></div>' +

      (silinmis ? '' : '<div class="kart wa-kart"><h2>📲 Şoföre Gönder</h2>' +
        (wt ? '<p class="soluk">Gönderilecek numara: <b>wa.me/' + h(wt) + '</b>' + (!(s.sofor_telefon || '').trim() ? ' (şoför kartından)' : '') + (cepMi(tel) ? '' : ' <span class="kirmizi">⚠️ cep telefonu biçiminde değil</span>') + '</p>'
          : '<div class="uyari hata">Bu seferde ve şoför kartında telefon yok. <a href="#/sefer-form/' + s.id + '">Düzenle</a> sayfasından ekleyin.</div>') +
        '<div class="butonlar"><a class="btn wa" target="_blank" rel="noopener" href="' + h(waLink(tel, mKonum)) + '">📍 Konumları Gönder</a>' +
        '<a class="btn wa" target="_blank" rel="noopener" href="' + h(waLink(tel, mHepsi)) + '">📤 Konum + Belge No Gönder</a></div>' +
        (s.belgeler.length ? '<div class="butonlar"><button type="button" class="btn ana" id="hepsini-paylas">📎 PDF\'leri WhatsApp\'tan Gönder (' + s.belgeler.length + ')</button></div>' +
          '<p class="soluk">PDF butonuna basınca paylaşım menüsü açılır → WhatsApp → şoförü seçin.</p>' : '') +
        '<div class="butonlar"><button type="button" class="btn" data-kopyala="' + h(mHepsi) + '">📋 Mesajı Kopyala</button>' +
        '<button type="button" class="btn" data-wa-sor="' + h(mHepsi) + '">Başka numaraya gönder</button></div>' +
        '<details><summary>Mesaj önizleme</summary><pre class="onizleme">' + h(mHepsi) + '</pre></details></div>') +

      '<div class="iki-sutun">' +
      '<div class="kart taraf yukleme-tarafi"><h2>📦 Yükleme Tarafı</h2><b>' + h(s.yd.ad) + '</b><div class="soluk">' + yer(s.yd) + '</div><div>' + konumHtml(s.yd, yH, s.yukleme_depo_id) + '</div>' +
      '<dl class="bilgiler"><dt>Yükleme tarihi</dt><dd>' + tarihTr(s.tarih) + '</dd>' +
      '<dt>Kantar net</dt><dd><b>' + (+s.miktar_kg > 0 ? kgFmt(s.miktar_kg) + ' kg' : '<span class="kirmizi">girilmedi</span>') + '</b></dd>' +
      '<dt>İrsaliye no</dt><dd>' + (h(s.irsaliye_no) || '—') + '</dd><dt>Kantar fiş no</dt><dd>' + (h(s.kantar_fis_no) || '—') + '</dd></dl>' +
      belgeListesi(taraf.yukleme, 'Yükleme irsaliyesi / kantar fişi yüklenmedi.') + '</div>' +

      '<div class="kart taraf bosaltim-tarafi"><h2>🏁 Boşaltım Tarafı</h2><b>' + h(s.bd.ad) + '</b><div class="soluk">' + yer(s.bd) + '</div><div>' + konumHtml(s.bd, bH, s.bosaltim_depo_id) + '</div>' +
      '<dl class="bilgiler"><dt>Boşaltım tarihi</dt><dd>' + (tarihTr(s.teslim_tarihi) || '—') + '</dd>' +
      '<dt>Kantar net</dt><dd><b>' + (bv ? kgFmt(s.bosaltim_kg) + ' kg' : '<span class="soluk">henüz girilmedi</span>') + '</b></dd>' +
      '<dt>İrsaliye no</dt><dd>' + (h(s.bosaltim_irsaliye_no) || '—') + '</dd><dt>Kantar fiş no</dt><dd>' + (h(s.bosaltim_kantar_fis_no) || '—') + '</dd></dl>' +
      belgeListesi(taraf.bosaltim, 'Boşaltım irsaliyesi / kantar fişi yüklenmedi.') +
      (silinmis || s.durum === 'iptal' ? '' :
        '<details class="bosaltim-kutu"' + (!bv && s.durum !== 'planlandi' ? ' open' : '') + '><summary><b>⚖️ ' + (bv ? 'Boşaltım bilgilerini düzenle' : 'Boşaltımı Kaydet') + '</b></summary>' +
        '<form class="form" id="bosaltim-form"><div class="iki">' +
        '<label>Boşaltım kantarı net (kg)<input name="bosaltim_kg" id="bk-kg" inputmode="decimal" value="' + h(bv ? sayiInput(s.bosaltim_kg) : '') + '" placeholder="ör. 26950"></label>' +
        '<label>Boşaltım (teslim) tarihi<input type="date" name="teslim_tarihi" value="' + h(s.teslim_tarihi || bugun()) + '"></label>' +
        '<label>Boşaltım irsaliye no<input name="bosaltim_irsaliye_no" value="' + h(s.bosaltim_irsaliye_no) + '"></label>' +
        '<label>Boşaltım kantar fiş no<input name="bosaltim_kantar_fis_no" value="' + h(s.bosaltim_kantar_fis_no) + '"></label></div>' +
        '<div class="fire-onizleme soluk" id="bk-fire"></div><div class="iki">' +
        dosyaGirdisi('bk-irsaliye', '📄 Boşaltım irsaliyesi') + dosyaGirdisi('bk-kantar', '⚖️ Boşaltım kantar fişi') + '</div>' +
        (s.durum !== 'teslim' ? '<label class="onay"><input type="checkbox" name="teslim_yap" value="1" checked> Seferi "Teslim Edildi" olarak işaretle</label>' : '') +
        '<button class="btn ana">💾 Boşaltımı Kaydet</button></form></details>') +
      '</div></div>' +

      (bv ? '<div class="kart fire-kart fire-' + fd + '"><h2>⚖️ Kantar Karşılaştırması</h2><div class="fire-satir">' +
        '<div><small>Yükleme</small><b>' + kgFmt(s.miktar_kg) + ' kg</b></div><div><small>Boşaltım</small><b>' + kgFmt(s.bosaltim_kg) + ' kg</b></div>' +
        '<div><small>' + (x.fire_kg < 0 ? 'Fazla' : 'Fire') + '</small><b>' + h(fireMetni(x)) + '</b></div></div>' +
        '<p class="' + (fd === 'normal' ? 'soluk' : 'kirmizi') + '">' + (fd === 'normal' ? '✅ Tolerans içinde' : (fd === 'asim' ? '⚠️ Fire toleransı aşıldı' : '⚠️ Boşaltım yüklemeden fazla; kantarları kontrol edin')) +
        ' (tolerans %' + sayiFmt(tol, 2) + ')</p></div>' : '') +

      '<div class="iki-sutun"><div class="kart"><h2>Şoför ve Araç</h2><dl class="bilgiler">' +
      '<dt>Şoför</dt><dd>' + (h(s.soforAd) || '—') + (s.so.tc ? ' <span class="soluk">TC ' + h(s.so.tc) + '</span>' : '') + '</dd>' +
      '<dt>Telefon</dt><dd>' + (tel ? '<a href="tel:' + h(tel) + '">' + h(tel) + '</a>' : '—') + '</dd>' +
      '<dt>Çekici / Dorse</dt><dd>' + h(s.cekici_plaka) + ' / ' + h(s.dorse_plaka) + '</dd>' +
      (s.notlar ? '<dt>Notlar</dt><dd>' + h(s.notlar).replace(/\n/g, '<br>') + '</dd>' : '') +
      '<dt>Kayıt</dt><dd>' + tarihTr(s.olusturma) + '</dd></dl>' +
      (silinmis ? '' : '<div class="butonlar">' + Object.keys(DURUMLAR).filter(function (k) { return k !== s.durum; }).map(function (k) {
        return '<button class="btn kucuk" data-durum="' + k + '">' + DURUMLAR[k] + ' yap</button>';
      }).join('') + '</div>') + '</div>' +

      '<div class="kart"><h2>Yük ve Ücret</h2><table class="hesap-tablo">' +
      '<tr><th colspan="2">' + h(s.urunAd || 'Ürün') + '</th></tr>' +
      '<tr><td>Hesap esası</td><td>' + ESASLAR[s.mal_esas || 'yukleme'] + ' · ' + kgFmt(x.mal_kg) + ' kg' + (x.mal_gecici ? ' <span class="kirmizi kucuk-yazi">(geçici)</span>' : '') + '</td></tr>' +
      '<tr><td>Ton fiyatı</td><td>' + tl(s.urun_ton_fiyat) + ' + %' + sayiFmt(s.urun_kdv, 2) + ' KDV</td></tr>' +
      '<tr><td>Mal bedeli (KDV hariç)</td><td>' + tl(x.mal) + '</td></tr><tr><td>KDV</td><td>' + tl(x.mal_kdv) + '</td></tr>' +
      '<tr class="toplam"><td>Mal bedeli (KDV dahil)</td><td>' + tl(x.mal_toplam) + '</td></tr>' +
      '<tr><th colspan="2">🚚 Nakliye (taşıyıcıya)</th></tr>' +
      '<tr><td>Hesap esası</td><td>' + ESASLAR[s.nakliye_esas || 'yukleme'] + ' · ' + kgFmt(x.nak_kg) + ' kg' + (x.nak_gecici ? ' <span class="kirmizi kucuk-yazi">(geçici)</span>' : '') + '</td></tr>' +
      '<tr><td>Ton başı nakliye</td><td>' + tl(s.nakliye_ton_fiyat) + ' + %' + sayiFmt(s.nakliye_kdv, 2) + ' KDV</td></tr>' +
      '<tr><td>Nakliye (KDV hariç)</td><td>' + tl(x.nak) + '</td></tr><tr><td>KDV</td><td>' + tl(x.nak_kdv) + '</td></tr>' +
      '<tr class="toplam"><td>Nakliye (KDV dahil)</td><td>' + tl(x.nak_toplam) + '</td></tr></table>' +
      '<div class="butonlar">' + (s.nakliye_odendi ? '<span class="rozet d-teslim">✅ Nakliye ödendi</span>' : '<span class="rozet d-planlandi">⏳ Nakliye ödenmedi</span>') +
      (silinmis ? '' : '<button class="btn kucuk" id="odendi">' + (s.nakliye_odendi ? 'Ödenmedi olarak işaretle' : 'Ödendi olarak işaretle') + '</button>') + '</div></div></div>' +

      '<div class="kart"><h2>📎 Diğer Belgeler ve Belge Yükleme</h2>' + belgeListesi(taraf.diger, 'Diğer belge yok.') +
      (silinmis ? '' : '<div class="yukle-form"><select id="yukle-tur">' + Object.keys(BELGE_TURLERI).map(function (k) { return '<option value="' + k + '">' + BELGE_TURLERI[k] + '</option>'; }).join('') + '</select>' +
        '<input type="file" id="yukle-dosya" multiple accept="application/pdf,image/*"><button class="btn ana" id="yukle">⬆️ Yükle</button></div>') + '</div>' +

      '<div class="kart"><details><summary><b>🕓 Değişiklik Geçmişi</b> (' + gecmis.length + ')</summary><table class="tablo gecmis"><thead><tr><th>Zaman</th><th>İşlem</th><th>Değişiklik</th></tr></thead><tbody>' +
      gecmis.map(function (g) {
        var belge = g.tablo === 'belgeler';
        var d = g.islem === 'ekle'
          ? (belge ? h((BELGE_TURLERI[(g.yeni || {}).tur] || 'Belge') + ' yüklendi: ' + ((g.yeni || {}).orijinal_ad || '')) : 'Kayıt oluşturuldu')
          : Object.keys(g.yeni || {}).filter(function (k) { return k !== 'guncelleme'; }).map(function (k) {
            return '<div><b>' + h(ALAN_ETIKETLERI[k] || k) + ':</b> ' + h(gecmisDeger(k, (g.eski || {})[k])) + ' → ' + h(gecmisDeger(k, g.yeni[k])) + '</div>';
          }).join('');
        return '<tr><td data-e="Zaman">' + tarihTr(g.zaman) + '</td><td data-e="İşlem">' + h((belge ? 'Belge: ' : '') + (islemAd[g.islem] || g.islem)) + '</td><td class="kucuk-yazi">' + d + '</td></tr>';
      }).join('') + '</tbody></table></details></div>' +
      (silinmis ? '' : '<div class="sil-form"><button class="btn tehlike kucuk" id="sil">🗑️ Seferi Sil</button></div>'), 'seferler');

    var yenile = function () { S.sefer(id); };
    var ga = $('#geri-al');
    if (ga) ga.onclick = function () { guncelle('seferler', ham.id, { silindi: 0 }, 'geri_al').then(function () { mesaj('Sefer geri alındı.'); yenile(); }); };
    $$('[data-durum]').forEach(function (b) {
      b.onclick = function () {
        var v = { durum: b.getAttribute('data-durum') };
        if (v.durum === 'teslim' && !ham.teslim_tarihi) v.teslim_tarihi = bugun() >= ham.tarih ? bugun() : ham.tarih;
        guncelle('seferler', ham.id, v).then(function () {
          mesaj('Durum güncellendi.');
          if (v.durum === 'teslim' && !bv) mesaj('Boşaltım kantarını ve irsaliyesini "Boşaltım Tarafı" kutusundan girmeyi unutmayın.', 'dikkat');
          yenile();
        });
      };
    });
    var od = $('#odendi');
    if (od) od.onclick = function () { guncelle('seferler', ham.id, { nakliye_odendi: ham.nakliye_odendi ? 0 : 1 }).then(function () { mesaj('Nakliye ödeme durumu güncellendi.'); yenile(); }); };
    var bp = $('#hepsini-paylas');
    if (bp) bp.onclick = function () { dosyalariPaylas(s.belgeler, waMesaj(s, false, true)); };
    $$('[data-paylas]').forEach(function (b) { b.onclick = function () { var bg = bul('belgeler', b.getAttribute('data-paylas')); dosyalariPaylas([bg], (BELGE_TURLERI[bg.tur] || 'Belge') + ' - Sefer #' + s.id + ' (' + s.cekici_plaka + ')'); }; });
    $$('[data-ac]').forEach(function (b) {
      b.onclick = function () {
        var w = window.open('', '_blank');
        belgeDosyasi(bul('belgeler', b.getAttribute('data-ac'))).then(function (f) { var u = URL.createObjectURL(f); if (w) w.location = u; else location.href = u; })
          .catch(function (e) { if (w) w.close(); alert(e.message); });
      };
    });
    $$('[data-indir]').forEach(function (b) { b.onclick = function () { belgeDosyasi(bul('belgeler', b.getAttribute('data-indir'))).then(function (f) { dosyaIndir(f, f.name); }); }; });
    $$('[data-kaldir]').forEach(function (b) {
      b.onclick = function () {
        if (!confirm('Belge kaldırılsın mı? (Silinenler bölümünden geri alınabilir)')) return;
        guncelle('belgeler', Number(b.getAttribute('data-kaldir')), { silindi: 1 }, 'sil').then(function () { mesaj('Belge kaldırıldı.'); yenile(); });
      };
    });
    var yk = $('#yukle');
    if (yk) yk.onclick = function () {
      var fl = Array.prototype.slice.call($('#yukle-dosya').files), tur = $('#yukle-tur').value;
      if (!fl.length) { alert('Önce dosya seçin.'); return; }
      fl.reduce(function (p, f) { return p.then(function () { return belgeKaydet(s.id, f, tur); }); }, Promise.resolve())
        .then(function () { mesaj(fl.length + ' belge yüklendi.'); yenile(); });
    };
    // Boşaltım kutusu
    var bf = $('#bosaltim-form');
    if (bf) {
      var kgEl = $('#bk-kg'), fireEl = $('#bk-fire');
      var goster = function () {
        var y = +s.miktar_kg || 0, ham2 = kgEl.value.trim(), b = ham2 === '' ? null : sayiKati(ham2);
        if (ham2 !== '' && b === null) { fireEl.textContent = '⚠️ Sayı okunamadı (ör. 26950)'; fireEl.className = 'fire-onizleme kirmizi'; return; }
        if (b === null || !y) { fireEl.textContent = ''; return; }
        var fire = y - b, yuzde = fire / y * 100, asim = Math.abs(yuzde) > tol;
        fireEl.textContent = 'Yükleme ' + kgFmt(y) + ' kg → ' + (fire < 0 ? 'fazla ' : 'fire ') + kgFmt(Math.abs(fire)) + ' kg (%' + sayiFmt(Math.abs(yuzde), 2) + ')' + (asim ? ' ⚠️ tolerans %' + sayiFmt(tol, 2) + ' aşıldı' : ' ✅');
        fireEl.className = 'fire-onizleme ' + (asim ? 'kirmizi' : 'soluk');
      };
      kgEl.oninput = goster; goster();
      bf.onsubmit = function (e) {
        e.preventDefault();
        var v = formVeri(this), hatalar = [];
        var veri = {
          bosaltim_kg: sayiAlani(v, 'bosaltim_kg', 'Boşaltım net (kg)', hatalar, true, 1000000),
          bosaltim_irsaliye_no: v.bosaltim_irsaliye_no, bosaltim_kantar_fis_no: v.bosaltim_kantar_fis_no, teslim_tarihi: v.teslim_tarihi || ''
        };
        if (veri.teslim_tarihi && !tarihGecerli(veri.teslim_tarihi)) hatalar.push('Boşaltım (teslim) tarihi geçersiz.');
        else if (veri.teslim_tarihi && veri.teslim_tarihi < ham.tarih) hatalar.push('Boşaltım (teslim) tarihi, yükleme tarihinden (' + tarihTr(ham.tarih) + ') önce olamaz.');
        if (v.teslim_yap) { veri.durum = 'teslim'; if (!veri.teslim_tarihi) veri.teslim_tarihi = bugun() >= ham.tarih ? bugun() : ham.tarih; }
        if (hatalar.length) { alert(hatalar.join('\n')); return; }
        guncelle('seferler', ham.id, veri).then(function () {
          return dosyalariKaydet(ham.id, [['#bk-irsaliye', 'bosaltim_irsaliye'], ['#bk-kantar', 'bosaltim_kantar']]);
        }).then(function () {
          seferUyarilari(bul('seferler', ham.id));
          mesaj('Boşaltım bilgileri kaydedildi.');
          yenile();
        });
      };
    }
    var sl = $('#sil');
    if (sl) sl.onclick = function () {
      if (!confirm('Sefer #' + s.id + ' silinsin mi? (Sonradan geri alınabilir)')) return;
      guncelle('seferler', s.id, { silindi: 1 }, 'sil').then(function () { mesaj('Sefer #' + s.id + ' silindi. İstediğiniz zaman geri alabilirsiniz.'); location.hash = '#/seferler'; });
    };
  };

  /* ---------------- Sefer Fişi (yazdırılabilir) ---------------- */
  S.fis = function (id, q) {
    var ham = bul('seferler', id);
    if (!ham) { location.hash = '#/seferler'; return; }
    var s = seferTam(ham), x = hesap(s), fd = fireDurumu(x), fiyatsiz = q.fiyatsiz === '1', bv = bosVar(s);
    var depoAdres = function (d) {
      var l = [h(d.ad || '—')], yer2 = [d.ilce, d.il].filter(Boolean).join(' / ');
      if (yer2) l.push(h(yer2));
      if (d.adres) l.push(h(d.adres));
      if (koorVar(d.lat)) l.push('<span class="kucuk">Konum: ' + koor(d.lat) + ', ' + koor(d.lng) + '</span>');
      return l.join('<br>');
    };
    var belgeAdlari = function (t) {
      var l = s.belgeler.filter(function (b) { return (BELGE_TARAFI[b.tur] || 'diger') === t; }).map(function (b) { return BELGE_TURLERI[b.tur]; });
      return l.length ? h(l.join(', ')) : '—';
    };
    var taraf = function (baslik, d, tarih, kg, irs, kantar, belgeT) {
      return '<div class="fis-taraf"><h2>' + baslik + '</h2><div>' + depoAdres(d) + '</div><table>' +
        '<tr><td>Tarih</td><td>' + (tarihTr(tarih) || '—') + '</td></tr><tr><td>Kantar net</td><td>' + kg + '</td></tr>' +
        '<tr><td>İrsaliye no</td><td>' + (h(irs) || '—') + '</td></tr><tr><td>Kantar fiş no</td><td>' + (h(kantar) || '—') + '</td></tr>' +
        '<tr><td>Belgeler</td><td>' + belgeAdlari(belgeT) + '</td></tr></table></div>';
    };
    ciz('', '');
    document.body.classList.add('fis-modu');
    $('#icerik').innerHTML =
      '<div class="fis-araclar"><button class="btn ana" id="yazdir">🖨️ Yazdır / PDF olarak kaydet</button>' +
      (fiyatsiz ? '<a class="btn" href="#/fis/' + s.id + '">Fiyatlı fiş</a>' : '<a class="btn" href="#/fis/' + s.id + '?fiyatsiz=1">Fiyatsız fiş (şoför / fabrika için)</a>') +
      '<a class="btn" href="#/sefer/' + s.id + '">← Sefere dön</a></div>' +
      '<div class="fis-sayfa"><div class="fis-ust"><div><h1>' + h(AYAR.firma_adi || 'Sefer Takip') + '</h1>' + (AYAR.firma_bilgi ? '<div class="kucuk">' + h(AYAR.firma_bilgi).replace(/\n/g, '<br>') + '</div>' : '') + '</div>' +
      '<div class="fis-no"><div>SEFER FİŞİ</div><b>No: ' + s.id + '</b><div class="kucuk">Durum: ' + h(DURUMLAR[s.durum]) + (s.silindi ? ' · SİLİNMİŞ KAYIT' : '') + '</div><div class="kucuk">Düzenlenme: ' + tarihTr(simdi()) + '</div></div></div>' +
      '<table class="fis-bilgi"><tr><td>Ürün</td><td><b>' + (h(s.urunAd) || '—') + '</b></td></tr>' +
      '<tr><td>Şoför</td><td>' + (h(s.soforAd) || '—') + (s.so.tc ? ' · TC ' + h(s.so.tc) : '') + (s.tel ? ' · Tel ' + h(s.tel) : '') + '</td></tr>' +
      '<tr><td>Araç</td><td>Çekici <b>' + (h(s.cekici_plaka) || '—') + '</b> · Dorse <b>' + (h(s.dorse_plaka) || '—') + '</b></td></tr></table>' +
      '<div class="fis-taraflar">' +
      taraf('📦 YÜKLEME', s.yd, s.tarih, +s.miktar_kg > 0 ? kgFmt(s.miktar_kg) + ' kg' : '—', s.irsaliye_no, s.kantar_fis_no, 'yukleme') +
      taraf('🏁 BOŞALTIM', s.bd, s.teslim_tarihi, bv ? kgFmt(s.bosaltim_kg) + ' kg' : '—', s.bosaltim_irsaliye_no, s.bosaltim_kantar_fis_no, 'bosaltim') + '</div>' +
      '<div class="fis-fire ' + (fd || '') + '">' + (bv
        ? '<span>Yükleme <b>' + kgFmt(s.miktar_kg) + ' kg</b> · Boşaltım <b>' + kgFmt(s.bosaltim_kg) + ' kg</b></span><span>' + (x.fire_kg < 0 ? 'Fazla' : 'Fire') + ': <b>' + h(fireMetni(x)) + '</b> ' +
          (fd === 'normal' ? '· tolerans içinde' : '· ⚠️ tolerans (%' + sayiFmt(tolerans(), 2) + ') aşıldı') + '</span>'
        : '<span>Boşaltım kantarı henüz girilmedi; fire hesaplanmadı.</span>') + '</div>' +
      (fiyatsiz ? '' : '<div class="fis-ucret-kap"><table class="fis-ucret"><tr><th>Ücret</th><th>Esas</th><th>Ton fiyatı</th><th>KDV hariç</th><th>KDV</th><th>KDV dahil</th></tr>' +
        '<tr><td>Mal bedeli</td><td>' + ESASLAR[s.mal_esas || 'yukleme'] + '<br><span class="kucuk">' + kgFmt(x.mal_kg) + ' kg' + (x.mal_gecici ? ' (geçici)' : '') + '</span></td><td>' + tl(s.urun_ton_fiyat) + '</td><td>' + tl(x.mal) + '</td><td>%' + sayiFmt(s.urun_kdv, 2) + ' · ' + tl(x.mal_kdv) + '</td><td><b>' + tl(x.mal_toplam) + '</b></td></tr>' +
        '<tr><td>Nakliye <span class="kucuk">(' + (s.nakliye_odendi ? 'ödendi' : 'ödenmedi') + ')</span></td><td>' + ESASLAR[s.nakliye_esas || 'yukleme'] + '<br><span class="kucuk">' + kgFmt(x.nak_kg) + ' kg' + (x.nak_gecici ? ' (geçici)' : '') + '</span></td><td>' + tl(s.nakliye_ton_fiyat) + '</td><td>' + tl(x.nak) + '</td><td>%' + sayiFmt(s.nakliye_kdv, 2) + ' · ' + tl(x.nak_kdv) + '</td><td><b>' + tl(x.nak_toplam) + '</b></td></tr></table></div>') +
      (s.notlar ? '<div class="fis-not"><b>Not:</b> ' + h(s.notlar).replace(/\n/g, '<br>') + '</div>' : '') +
      '<div class="fis-imzalar"><div class="fis-imza">Yükleme Yetkilisi<br><span class="kucuk">Ad Soyad / İmza</span></div><div class="fis-imza">Şoför<br><span class="kucuk">' + h(s.soforAd) + '</span></div>' +
      '<div class="fis-imza">Teslim Alan (Boşaltım)<br><span class="kucuk">Ad Soyad / İmza</span></div></div>' +
      '<p class="kucuk fis-deneme">Bu fiş Sefer Takip DENEME sürümünden oluşturulmuştur.</p></div>';
    $('#yazdir').onclick = function () { window.print(); };
  };

  /* ---------------- Sefer listesi ---------------- */
  S.seferler = function (id, q) {
    var tum = seferFiltrele(q), t = toplamlar(tum), oz = urunOzet(tum), tol = tolerans();
    var limit = 100, sayfa = Math.max(1, +q.s || 1), liste = tum.slice((sayfa - 1) * limit, sayfa * limit);
    var d = new Date();
    var pzt = new Date(d); pzt.setDate(d.getDate() - ((d.getDay() + 6) % 7));
    var paz = new Date(pzt); paz.setDate(pzt.getDate() + 6);
    var hizli = [['Bugün', bugun(), bugun()], ['Dün', bugun(-1), bugun(-1)], ['Bu hafta', tarihIso(pzt), tarihIso(paz)],
      ['Bu ay', tarihIso(new Date(d.getFullYear(), d.getMonth(), 1)), tarihIso(new Date(d.getFullYear(), d.getMonth() + 1, 0))],
      ['Geçen ay', tarihIso(new Date(d.getFullYear(), d.getMonth() - 1, 1)), tarihIso(new Date(d.getFullYear(), d.getMonth(), 0))],
      ['Bu yıl', d.getFullYear() + '-01-01', d.getFullYear() + '-12-31']];
    var sirali = function (t2) { return V[t2].slice().sort(function (a, b) { return (a.silindi - b.silindi) || String(a.ad).localeCompare(String(b.ad), 'tr'); }); };
    var filtreVar = Object.keys(q).some(function (k) { return k !== 's' && q[k]; });
    var sayfalar = Math.ceil(tum.length / limit);
    var secili = function (ad, deger) { return q[ad] === deger ? ' selected' : ''; };

    ciz('<div class="baslik-satir"><h1>Seferler</h1><div class="butonlar"><button class="btn" id="excel">📊 Excel\'e Aktar</button><a class="btn ana" href="#/sefer-form">➕ Yeni Sefer</a></div></div>' +
      '<details class="kart filtre"' + (filtreVar || window.innerWidth > 760 ? ' open' : '') + '><summary><b>🔎 Filtre</b>' + (filtreVar ? ' <span class="rozet d-planlandi">aktif</span>' : '') + '</summary>' +
      '<div class="hizli">' + hizli.map(function (x) {
        return '<a class="cip ' + (q.bas === x[1] && q.bit === x[2] ? 'aktif' : '') + '" href="#/seferler?' + qs(Object.assign({}, q, { s: '', bas: x[1], bit: x[2] })) + '">' + x[0] + '</a>';
      }).join('') + '</div>' +
      '<form id="f"><div class="filtre-izgara">' +
      '<label>Başlangıç<input type="date" name="bas" value="' + h(q.bas || '') + '"></label><label>Bitiş<input type="date" name="bit" value="' + h(q.bit || '') + '"></label>' +
      '<label>Ürün<select name="urun">' + secenekler(sirali('urunler'), q.urun, 'Tümü') + '</select></label>' +
      '<label>Yükleme deposu<select name="ydepo">' + secenekler(sirali('depolar').filter(function (x) { return x.tip === 'yukleme'; }), q.ydepo, 'Tümü', depoEtiket) + '</select></label>' +
      '<label>Boşaltım deposu<select name="bdepo">' + secenekler(sirali('depolar').filter(function (x) { return x.tip === 'bosaltim'; }), q.bdepo, 'Tümü', depoEtiket) + '</select></label>' +
      '<label>Şoför<select name="sofor">' + secenekler(sirali('soforler'), q.sofor, 'Tümü', function (r) { return r.ad + ' ' + (r.soyad || '') + (r.silindi ? ' [silinmiş]' : ''); }) + '</select></label>' +
      '<label>Durum<select name="durum"><option value="">Tümü</option>' + Object.keys(DURUMLAR).map(function (k) { return '<option value="' + k + '"' + secili('durum', k) + '>' + DURUMLAR[k] + '</option>'; }).join('') + '</select></label>' +
      '<label>Boşaltım / fire<select name="bosaltim"><option value="">Tümü</option><option value="eksik"' + secili('bosaltim', 'eksik') + '>Boşaltım kantarı girilmemiş</option>' +
      '<option value="girildi"' + secili('bosaltim', 'girildi') + '>Boşaltım kantarı girilmiş</option><option value="asim"' + secili('bosaltim', 'asim') + '>Fire toleransı (%' + sayiFmt(tol, 2) + ') aşılan</option></select></label>' +
      '<label>Nakliye ödemesi<select name="odendi"><option value="">Tümü</option><option value="1"' + secili('odendi', '1') + '>Ödendi</option><option value="0"' + secili('odendi', '0') + '>Ödenmedi</option></select></label>' +
      '<label>Plaka<input name="plaka" class="buyuk-harf" value="' + h(q.plaka || '') + '" placeholder="45AAR75"></label>' +
      '<label>Ara<input name="ara" value="' + h(q.ara || '') + '" placeholder="İrsaliye no, TC, isim…"></label></div>' +
      '<div class="butonlar"><button class="btn ana">🔎 Filtrele</button>' + (filtreVar ? '<a class="btn" href="#/seferler">Temizle</a>' : '') + '</div></form></details>' +
      '<div class="istatistik"><div><small>Sefer</small><b>' + tum.length + '</b></div>' +
      '<div><small>Yüklenen (iptaller hariç)</small><b>' + kgFmt(t.kg / 1000) + '</b><span>ton · boşaltılan ' + kgFmt(t.bos_kg / 1000) + ' t</span></div>' +
      '<div><a href="#/seferler?' + qs(Object.assign({}, q, { s: '', bosaltim: 'asim' })) + '"><small>Fire (boşaltımı girilenlerde)</small><b class="kucuk' + (t.fire_asim ? ' kirmizi' : '') + '">' + kgFmt(t.fire_kg) + ' kg</b><span>' +
        (t.fire_yuzde !== null ? '%' + sayiFmt(t.fire_yuzde, 2) + ' · ' : '') + t.fire_asim + ' sefer tolerans üstü</span></a></div>' +
      (t.bos_eksik ? '<div><a href="#/seferler?' + qs(Object.assign({}, q, { s: '', bosaltim: 'eksik' })) + '"><small>Boşaltım kantarı bekleyen</small><b class="kirmizi">' + t.bos_eksik + '</b><span>sefer</span></a></div>' : '') +
      '<div><small>Mal bedeli KDV hariç</small><b class="kucuk">' + tl(t.mal) + '</b><span>dahil: ' + tl(t.mal_toplam) + '</span></div>' +
      '<div><small>Nakliye KDV hariç</small><b class="kucuk">' + tl(t.nak) + '</b><span>dahil: ' + tl(t.nak_toplam) + '</span></div></div>' +
      (oz.length > 1 || (oz.length && !q.urun) ? '<div class="kart"><h2>Ürün Bazında Özet</h2><div class="tablo-kap"><table class="tablo"><thead><tr><th>Ürün</th><th class="sag">Sefer</th><th class="sag">Yükleme (t)</th><th class="sag">Boşaltım (t)</th><th class="sag">Fire</th><th class="sag">Mal Bedeli (KDV hariç)</th><th class="sag">Nakliye (KDV hariç)</th></tr></thead><tbody>' +
        oz.map(function (o) {
          return '<tr><td data-e="Ürün"><b>' + h(o.ad) + '</b></td><td data-e="Sefer" class="sag">' + o.adet + '</td><td data-e="Yükleme (t)" class="sag">' + kgFmt(o.kg / 1000) + '</td>' +
            '<td data-e="Boşaltım (t)" class="sag">' + kgFmt(o.bos_kg / 1000) + '</td><td data-e="Fire" class="sag">' + kgFmt(o.fire_kg) + ' kg' + (o.fire_yuzde !== null ? ' (%' + sayiFmt(o.fire_yuzde, 2) + ')' : '') + '</td>' +
            '<td data-e="Mal Bedeli" class="sag">' + tl(o.mal) + '</td><td data-e="Nakliye" class="sag">' + tl(o.nak) + '</td></tr>';
        }).join('') + '</tbody></table></div></div>' : '') +
      '<div class="kart tablo-kap"><table class="tablo seferler"><thead><tr><th>No</th><th>Tarih</th><th>Durum</th><th>Yükleme → Boşaltım</th><th>Şoför / Araç</th><th>Ürün</th><th class="sag">kg (yükleme → boşaltım)</th><th class="sag">Mal Bedeli</th><th class="sag">Nakliye</th><th>Belge</th></tr></thead><tbody>' +
      liste.map(function (s) {
        var x = hesap(s);
        return '<tr class="tikla" data-git="#/sefer/' + s.id + '"><td data-e="No"><a href="#/sefer/' + s.id + '"><b>#' + s.id + '</b></a></td><td data-e="Tarih">' + tarihTr(s.tarih) + '</td>' +
          '<td data-e="Durum">' + durumRozet(s.durum) + '</td><td data-e="Güzergah">' + h(s.yd.ad) + ' → ' + h(s.bd.ad) + (s.bd.il ? ' <span class="soluk">(' + h(s.bd.il) + ')</span>' : '') + '</td>' +
          '<td data-e="Şoför">' + h(s.soforAd) + ' <span class="soluk">' + h(s.cekici_plaka) + (s.dorse_plaka ? ' / ' + h(s.dorse_plaka) : '') + '</span></td>' +
          '<td data-e="Ürün">' + h(s.urunAd) + '</td><td data-e="kg" class="sag"><span>' + kgFmt(s.miktar_kg) +
          (x.bos_kg !== null ? ' → ' + kgFmt(x.bos_kg) + '<br>' + fireRozet(x) : ((s.durum === 'yuklendi' || s.durum === 'teslim') ? '<br><span class="soluk kucuk-yazi">boşaltım bekleniyor</span>' : '')) + '</span></td>' +
          '<td data-e="Mal Bedeli" class="sag">' + tl(x.mal) + '</td>' +
          '<td data-e="Nakliye" class="sag">' + tl(x.nak) + ' ' + (s.nakliye_odendi ? '✅' : '⏳') + '</td><td data-e="Belge">' + (s.belgeler.length ? '📄 ' + s.belgeler.length : '<span class="kirmizi">yok</span>') + '</td></tr>';
      }).join('') + (liste.length ? '' : '<tr><td colspan="10" class="soluk">Bu kriterlere uygun sefer yok.</td></tr>') + '</tbody></table>' +
      (sayfalar > 1 ? '<div class="sayfalama">' + Array.apply(null, Array(sayfalar)).map(function (_, i) {
        return '<a class="' + (i + 1 === sayfa ? 'aktif' : '') + '" href="#/seferler?' + qs(Object.assign({}, q, { s: i + 1 })) + '">' + (i + 1) + '</a>';
      }).join('') + '</div>' : '') + '</div>', 'seferler');

    $('#f').onsubmit = function (e) { e.preventDefault(); location.hash = '#/seferler?' + qs(formVeri(this)); };
    $$('tr[data-git]').forEach(function (tr) { tr.onclick = function (e) { if (!e.target.closest('a')) location.hash = tr.getAttribute('data-git'); }; });
    $('#excel').onclick = function () { excelSeferler(tum, 'seferler-' + dosyaAdiZaman() + '.xlsx'); };
  };

  /* ---------------- Ayarlar ---------------- */
  S.ayarlar = function () {
    var esasSec = function (ad, secili) {
      return '<select name="' + ad + '">' + Object.keys(ESASLAR).map(function (k) { return '<option value="' + k + '"' + (secili === k ? ' selected' : '') + '>' + ESASLAR[k] + '</option>'; }).join('') + '</select>';
    };
    ciz('<h1>⚙️ Ayarlar</h1><div class="uyari hata" id="form-hata" hidden></div><form class="form" id="f">' +
      '<div class="kart"><h2>Fiş Başlığı</h2><label>Firma adı (Sefer Fişi\'nin üstünde görünür)<input name="firma_adi" value="' + h(AYAR.firma_adi) + '" placeholder="ör. Örnek Lojistik Ltd. Şti."></label>' +
      '<label>Firma bilgisi (adres, telefon, vergi no…)<textarea name="firma_bilgi" rows="3">' + h(AYAR.firma_bilgi) + '</textarea></label></div>' +
      '<div class="kart"><h2>Kantar ve Ücret Hesabı</h2><p class="soluk">Yeni seferlerde ücretin hangi kantar ağırlığına göre hesaplanacağı. Her seferde ayrıca değiştirilebilir.</p>' +
      '<div class="iki"><label>Mal bedeli varsayılan esası' + esasSec('varsayilan_mal_esas', AYAR.varsayilan_mal_esas) + '</label>' +
      '<label>Nakliye ücreti varsayılan esası' + esasSec('varsayilan_nakliye_esas', AYAR.varsayilan_nakliye_esas) + '</label></div>' +
      '<label>Fire toleransı (%) — yükleme ile boşaltım kantarı farkı bunu aşarsa uyarı verilir<input name="fire_tolerans_yuzde" inputmode="decimal" value="' + h(sayiInput(tolerans())) + '"></label>' +
      '<p class="soluk kucuk-yazi">"Hangisi düşükse": yükleme ve boşaltım kantarından küçük olan esas alınır. Hangi esasın kullanılacağı alıcı / taşıyıcı ile yaptığınız anlaşmaya bağlıdır.</p></div>' +
      '<div class="butonlar"><button class="btn ana">💾 Kaydet</button></div></form>', 'ayarlar');
    $('#f').onsubmit = function (e) {
      e.preventDefault();
      var v = formVeri(this), hatalar = [];
      var tol = sayiAlani(v, 'fire_tolerans_yuzde', 'Fire toleransı', hatalar, false, 100);
      if (hatalar.length) return hataGoster(hatalar);
      AYAR = Object.assign({}, AYAR, {
        firma_adi: v.firma_adi.slice(0, 120), firma_bilgi: (v.firma_bilgi || '').slice(0, 500),
        varsayilan_mal_esas: ESASLAR[v.varsayilan_mal_esas] ? v.varsayilan_mal_esas : 'yukleme',
        varsayilan_nakliye_esas: ESASLAR[v.varsayilan_nakliye_esas] ? v.varsayilan_nakliye_esas : 'yukleme',
        fire_tolerans_yuzde: String(tol)
      });
      ayarYaz('ayarlar', AYAR).then(function () { mesaj('Ayarlar kaydedildi. Hesap esası değişikliği sadece YENİ seferlere uygulanır.'); S.ayarlar(); });
    };
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
  var SEFER_BASLIK = ['Sefer No', 'Yükleme Tarihi', 'Boşaltım (Teslim) Tarihi', 'Durum', 'Yükleme Deposu', 'Yükleme İl', 'Yükleme İlçe', 'Yükleme Koordinat',
    'Boşaltım Deposu', 'Boşaltım İl', 'Boşaltım İlçe', 'Boşaltım Koordinat', 'Şoför', 'TC No', 'Telefon', 'Çekici Plaka', 'Dorse Plaka', 'Ürün',
    'Yükleme Net (kg)', 'Yükleme İrsaliye No', 'Yükleme Kantar Fiş No', 'Boşaltım Net (kg)', 'Boşaltım İrsaliye No', 'Boşaltım Kantar Fiş No',
    'Fire (kg)', 'Fire %', 'Fire Durumu', 'Ürün Ton Fiyatı (₺)', 'Ürün KDV %', 'Mal Hesap Esası', 'Mal Hesap Miktarı (kg)',
    'Mal Bedeli KDV Hariç (₺)', 'Mal KDV (₺)', 'Mal Bedeli KDV Dahil (₺)', 'Nakliye Ton Fiyatı (₺)', 'Nakliye KDV %', 'Nakliye Hesap Esası', 'Nakliye Hesap Miktarı (kg)',
    'Nakliye KDV Hariç (₺)', 'Nakliye KDV (₺)', 'Nakliye KDV Dahil (₺)', 'Nakliye Ödendi', 'Belgeler', 'Notlar', 'Oluşturma', 'Son Güncelleme', 'Silindi'];
  function seferSatir(s) {
    var x = hesap(s), r2 = function (n) { return Math.round(n * 100) / 100; }, fd = fireDurumu(x);
    var k = function (d) { return koorVar(d.lat) ? koor(d.lat) + ',' + koor(d.lng) : ''; };
    return [s.id, tarihTr(s.tarih), tarihTr(s.teslim_tarihi), DURUMLAR[s.durum] || s.durum, s.yd.ad || '', s.yd.il || '', s.yd.ilce || '', k(s.yd),
      s.bd.ad || '', s.bd.il || '', s.bd.ilce || '', k(s.bd), s.soforAd, s.so.tc || '', s.tel || '', s.cekici_plaka || '', s.dorse_plaka || '', s.urunAd,
      +s.miktar_kg || 0, s.irsaliye_no || '', s.kantar_fis_no || '', x.bos_kg === null ? '' : x.bos_kg, s.bosaltim_irsaliye_no || '', s.bosaltim_kantar_fis_no || '',
      x.fire_kg === null ? '' : r2(x.fire_kg), x.fire_yuzde === null ? '' : r2(x.fire_yuzde),
      fd === null ? 'Boşaltım girilmedi' : { normal: 'Normal', asim: 'Tolerans aşıldı (eksik)', fazla: 'Tolerans aşıldı (fazla)' }[fd],
      +s.urun_ton_fiyat || 0, +s.urun_kdv || 0, ESASLAR[s.mal_esas || 'yukleme'] + (x.mal_gecici ? ' (geçici: yükleme)' : ''), r2(x.mal_kg),
      r2(x.mal), r2(x.mal_kdv), r2(x.mal_toplam), +s.nakliye_ton_fiyat || 0, +s.nakliye_kdv || 0, ESASLAR[s.nakliye_esas || 'yukleme'] + (x.nak_gecici ? ' (geçici: yükleme)' : ''), r2(x.nak_kg),
      r2(x.nak), r2(x.nak_kdv), r2(x.nak_toplam), s.nakliye_odendi ? 'Evet' : 'Hayır',
      s.belgeler.map(function (b) { return (BELGE_TURLERI[b.tur] || '') + ': ' + b.orijinal_ad; }).join('\n'),
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
  var OZET_BASLIK = ['Ürün', 'Sefer Sayısı', 'Yükleme (kg)', 'Boşaltım (kg)', 'Fire (kg)', 'Fire %', 'Mal Bedeli KDV Hariç (₺)', 'Mal Bedeli KDV Dahil (₺)', 'Nakliye KDV Hariç (₺)', 'Nakliye KDV Dahil (₺)'];
  function ozetSatirlar(liste) {
    var r = function (n) { return Math.round(n * 100) / 100; };
    return urunOzet(liste).map(function (o) { return [o.ad, o.adet, r(o.kg), r(o.bos_kg), r(o.fire_kg), o.fire_yuzde === null ? '' : r(o.fire_yuzde), r(o.mal), r(o.mal_toplam), r(o.nak), r(o.nak_toplam)]; });
  }
  function excelSeferler(liste, ad) {
    xlsxYukle().then(function (X) {
      var wb = X.utils.book_new();
      sayfaEkle(X, wb, 'Seferler', SEFER_BASLIK, liste.slice().reverse().map(seferSatir));
      sayfaEkle(X, wb, 'Ürün Özeti (iptaller hariç)', OZET_BASLIK, ozetSatirlar(liste));
      X.writeFile(wb, ad);
    }).catch(function (e) { alert(e.message); });
  }
  function excelTam() {
    xlsxYukle().then(function (X) {
      var wb = X.utils.book_new(), ss = V.seferler.map(seferTam);
      sayfaEkle(X, wb, 'Seferler', SEFER_BASLIK, ss.map(seferSatir));
      sayfaEkle(X, wb, 'Ürün Özeti (iptaller hariç)', OZET_BASLIK, ozetSatirlar(ss.filter(function (s) { return !s.silindi; })));
      sayfaEkle(X, wb, 'Depolar', ['ID', 'Tip', 'Ad', 'Firma', 'İl', 'İlçe', 'Adres', 'Enlem', 'Boylam', 'Harita', 'Yetkili', 'Telefon', 'Notlar', 'Silindi'],
        V.depolar.map(function (d) { return [d.id, d.tip === 'yukleme' ? 'Yükleme (Sarım)' : 'Boşaltım', d.ad, d.firma, d.il, d.ilce, d.adres, d.lat, d.lng, haritaLink(d.lat, d.lng), d.yetkili, d.telefon, d.notlar, d.silindi ? 'Evet' : '']; }));
      sayfaEkle(X, wb, 'Şoförler', ['ID', 'Ad', 'Soyad', 'TC No', 'Telefon', 'Çekici Plaka', 'Dorse Plaka', 'IBAN', 'Notlar', 'Silindi'],
        V.soforler.map(function (d) { return [d.id, d.ad, d.soyad, d.tc, d.telefon, d.cekici_plaka, d.dorse_plaka, d.iban, d.notlar, d.silindi ? 'Evet' : '']; }));
      sayfaEkle(X, wb, 'Ürünler', ['ID', 'Ürün', 'Ton Fiyatı (₺)', 'KDV %', 'Notlar', 'Silindi'],
        V.urunler.map(function (d) { return [d.id, d.ad, d.ton_fiyat, d.kdv_orani, d.notlar, d.silindi ? 'Evet' : '']; }));
      sayfaEkle(X, wb, 'Belgeler', ['ID', 'Sefer No', 'Tür', 'Dosya Adı', 'Boyut (KB)', 'Yükleme', 'Silindi'],
        V.belgeler.map(function (d) { return [d.id, d.sefer_id, BELGE_TURLERI[d.tur] || d.tur, d.orijinal_ad, Math.round(d.boyut / 1024), tarihTr(d.olusturma), d.silindi ? 'Evet' : '']; }));
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
    var y = { uygulama: 'sefer-takip', surum: 2, tarih: simdi(), veriler: {}, dosyalar: {}, ayarlar: AYAR };
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
    }).then(function () { return y.ayarlar ? ayarYaz('ayarlar', y.ayarlar) : null; }).then(yukle);
  }

  S.yedek = function () {
    var boyut = V.belgeler.reduce(function (a, b) { return a + (b.silindi ? 0 : b.boyut || 0); }, 0);
    ciz('<h1>💾 Yedek ve Excel</h1>' +
      '<div class="kart bilgi"><b>⚠️ Önemli: Bu DENEME sürümünde veriler sadece bu cihazda duruyor.</b><ul>' +
      '<li>Tüm kayıtlar ve PDF\'ler <b>bu telefonun/bilgisayarın tarayıcısında</b> saklanır; başka cihazda görünmez ve canlı sisteme aktarılamaz.</li>' +
      '<li>Telefon kaybolur, Safari geçmişi temizlenir veya site verileri silinirse kayıtlar gider. <b>Düzenli yedek alın.</b></li>' +
      '<li>iPhone\'da siteyi <b>Paylaş → Ana Ekrana Ekle</b> ile ekleyin ve oradan açın; Safari verileri daha güvenli tutar.</li>' +
      '<li>Silinen kayıtlar kalıcı silinmez: <a href="#/silinenler">Silinenler</a> sayfasından geri alınabilir.</li></ul>' +
      '<p>Son yedek: <b>' + (sonYedek ? tarihTr(sonYedek) : 'hiç alınmadı') + '</b> · Kalıcı depolama: <b id="kalici">kontrol ediliyor…</b></p></div>' +
      '<div class="iki-sutun"><div class="kart"><h2>🗜️ Tam Yedek</h2><p>Tüm kayıtlar + tüm PDF belgeler (' + Math.round(boyut / 1024) + ' KB) + ayarlar tek dosyada.</p>' +
      '<div class="butonlar"><button class="btn ana" id="yedek-paylas">📤 Yedeği Kaydet / Paylaş</button><button class="btn" id="yedek-indir">⬇️ Dosya Olarak İndir</button></div>' +
      '<p class="soluk">iPhone\'da "Kaydet/Paylaş" → <b>Dosyalar\'a Kaydet</b> (iCloud Drive) veya kendinize WhatsApp/e-posta ile gönderin.</p></div>' +
      '<div class="kart"><h2>📊 Excel</h2><p>Tüm seferler (iki taraf, fire, hesap esası dahil), depolar, şoförler, ürünler, belge listesi ve değişiklik geçmişi ayrı sayfalarda.</p>' +
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
      ['depolar', 'Depolar', function (r) { return depoEtiket(Object.assign({}, r, { silindi: 0 })) + ' (' + (r.tip === 'yukleme' ? 'Yükleme' : 'Boşaltım') + ')'; }],
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
        var p = b.getAttribute('data-geri').split(':');
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
      if (!cepMi(no) && !confirm('Bu numara cep telefonu biçiminde görünmüyor. Yine de açılsın mı?')) return;
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
