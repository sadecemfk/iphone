<?php
$id = (int)get('id');
$s = $id ? one('SELECT * FROM seferler WHERE id = ?', [$id]) : null;
if ($id && !$s) {
    mesaj('Sefer bulunamadı.', 'hata');
    yonlendir('index.php?p=seferler');
}
$hatalar = [];
$taslakAnahtari = 'sefer_form:' . ($id ?: 'yeni');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $soforSecim = post('sofor_id');
    $veri = [
        'tarih' => post('tarih'),
        'teslim_tarihi' => post('teslim_tarihi') ?: null,
        'durum' => isset(DURUMLAR[post('durum')]) ? post('durum') : 'planlandi',
        'yukleme_depo_id' => (int)post('yukleme_depo_id') ?: null,
        'bosaltim_depo_id' => (int)post('bosaltim_depo_id') ?: null,
        'sofor_id' => ctype_digit($soforSecim) ? (int)$soforSecim : null,
        'sofor_telefon' => post('sofor_telefon'),
        'cekici_plaka' => plaka(post('cekici_plaka')),
        'dorse_plaka' => plaka(post('dorse_plaka')),
        'urun_id' => (int)post('urun_id') ?: null,
        'miktar_kg' => sayi_alani('miktar_kg', 'Miktar (kg)', $hatalar, 0, 1000000),
        'urun_ton_fiyat' => sayi_alani('urun_ton_fiyat', 'Ürün ton fiyatı', $hatalar, 0),
        'urun_kdv' => sayi_alani('urun_kdv', 'Ürün KDV oranı', $hatalar, 0, 100),
        'nakliye_ton_fiyat' => sayi_alani('nakliye_ton_fiyat', 'Nakliye ton fiyatı', $hatalar, 0),
        'nakliye_kdv' => sayi_alani('nakliye_kdv', 'Nakliye KDV oranı', $hatalar, 0, 100),
        'nakliye_odendi' => post('nakliye_odendi') ? 1 : 0,
        'irsaliye_no' => post('irsaliye_no'),
        'kantar_fis_no' => post('kantar_fis_no'),
        'notlar' => post('notlar'),
    ];

    // Tarihler
    if (!tarih_gecerli($veri['tarih'])) {
        $hatalar[] = 'Geçerli bir yükleme tarihi giriniz.';
    }
    if ($veri['teslim_tarihi'] !== null && !tarih_gecerli($veri['teslim_tarihi'])) {
        $hatalar[] = 'Teslim tarihi geçersiz.';
    } elseif ($veri['teslim_tarihi'] !== null && tarih_gecerli($veri['tarih']) && $veri['teslim_tarihi'] < $veri['tarih']) {
        $hatalar[] = 'Teslim tarihi, yükleme tarihinden önce olamaz.';
    }

    // Depolar gerçekten var mı ve doğru türde mi?
    $yd = $veri['yukleme_depo_id'] ? one('SELECT * FROM depolar WHERE id = ?', [$veri['yukleme_depo_id']]) : null;
    $bd = $veri['bosaltim_depo_id'] ? one('SELECT * FROM depolar WHERE id = ?', [$veri['bosaltim_depo_id']]) : null;
    if (!$yd || $yd['tip'] !== 'yukleme') {
        $hatalar[] = 'Yükleme (sarım) deposunu seçiniz.';
    }
    if (!$bd || $bd['tip'] !== 'bosaltim') {
        $hatalar[] = 'Boşaltım deposunu seçiniz.';
    }
    if ($veri['urun_id'] && !one('SELECT id FROM urunler WHERE id = ?', [$veri['urun_id']])) {
        $hatalar[] = 'Seçilen ürün bulunamadı.';
    }

    // Şoför: mevcut ya da yeni
    $yeniSofor = null;
    if ($soforSecim === 'yeni') {
        $yeniTc = preg_replace('/\D/', '', post('yeni_tc'));
        if (post('yeni_ad') === '') {
            $hatalar[] = 'Yeni şoför için ad giriniz.';
        } elseif ($yeniTc !== '' && strlen($yeniTc) !== 11) {
            $hatalar[] = 'TC kimlik no 11 haneli olmalıdır.';
        } elseif ($yeniTc !== '' && ($var = one('SELECT * FROM soforler WHERE tc = ? ORDER BY silindi LIMIT 1', [$yeniTc]))) {
            $hatalar[] = 'Bu TC ile kayıtlı şoför zaten var: ' . trim($var['ad'] . ' ' . $var['soyad'])
                . ($var['silindi'] ? ' (silinmiş; Silinenler sayfasından geri alabilirsiniz).' : '. Listeden seçildi, kontrol edip tekrar kaydedin.');
            if (!$var['silindi']) {
                $soforSecim = (string)$var['id'];
                $veri['sofor_id'] = (int)$var['id'];
            }
        } else {
            $yeniSofor = ['ad' => post('yeni_ad'), 'soyad' => post('yeni_soyad'), 'tc' => $yeniTc];
        }
    } elseif ($veri['sofor_id'] && !one('SELECT id FROM soforler WHERE id = ?', [$veri['sofor_id']])) {
        $hatalar[] = 'Seçilen şoför bulunamadı.';
    }

    if (!$hatalar) {
        otomatik_yedek(); // işlem (transaction) başlamadan önce
        db()->beginTransaction();
        try {
            if ($yeniSofor) {
                $veri['sofor_id'] = kayit_ekle('soforler', $yeniSofor + [
                    'telefon' => $veri['sofor_telefon'],
                    'cekici_plaka' => $veri['cekici_plaka'],
                    'dorse_plaka' => $veri['dorse_plaka'],
                ]);
            }
            if ($s) {
                kayit_guncelle('seferler', $id, $veri);
            } else {
                $veri['olusturan'] = aktif_kullanici_adi();
                $id = kayit_ekle('seferler', $veri);
            }
            db()->commit();
        } catch (Exception $e) {
            db()->rollBack();
            throw $e;
        }
        foreach (belgeleri_isle($id) as $e) {
            mesaj($e, 'hata');
        }

        // Kaydı engellemeyen uyarılar
        if ($veri['miktar_kg'] > 60000) {
            mesaj('Dikkat: Miktar ' . kg_fmt($veri['miktar_kg']) . ' kg (60 tonun üzerinde). Doğru mu?', 'dikkat');
        }
        if ($veri['miktar_kg'] <= 0 && in_array($veri['durum'], ['yuklendi', 'teslim'], true)) {
            mesaj('Dikkat: Araç yüklendi görünüyor ama miktar girilmemiş.', 'dikkat');
        }
        $tel = $veri['sofor_telefon'] !== '' ? $veri['sofor_telefon'] : (string)(one('SELECT telefon FROM soforler WHERE id = ?', [(int)$veri['sofor_id']])['telefon'] ?? '');
        if ($tel !== '' && !cep_telefonu_mu($tel)) {
            mesaj('Dikkat: Telefon numarası cep telefonu biçiminde değil (ör. 0544 406 74 94); WhatsApp açılmayabilir.', 'dikkat');
        }
        if ($yeniSofor && $yeniSofor['tc'] !== '' && !tc_gecerli($yeniSofor['tc'])) {
            mesaj('Dikkat: Şoförün TC kimlik numarası geçersiz görünüyor, kontrol edin.', 'dikkat');
        }
        foreach (['irsaliye_no' => 'irsaliye no', 'kantar_fis_no' => 'kantar fiş no'] as $alan => $ad) {
            if ($veri[$alan] !== '') {
                $ayni = all("SELECT id FROM seferler WHERE $alan = ? AND id != ? AND silindi = 0 LIMIT 5", [$veri[$alan], $id]);
                if ($ayni) {
                    mesaj('Dikkat: Bu ' . $ad . ' (' . $veri[$alan] . ') başka seferde de kayıtlı: #' . implode(', #', array_column($ayni, 'id')) . '. Mükerrer kayıt olmasın?', 'dikkat');
                }
            }
        }
        $_SESSION['taslak_temizle'][] = $taslakAnahtari;
        mesaj($s ? 'Sefer güncellendi.' : 'Sefer kaydedildi. Şimdi şoföre WhatsApp ile gönderebilirsiniz.');
        yonlendir('index.php?p=sefer&id=' . $id);
    }
    $s = array_merge($s ?: [], $veri);
    $s['sofor_id'] = $soforSecim === 'yeni' ? 'yeni' : $veri['sofor_id'];
    // Formda kullanıcının yazdığı ham değerler gösterilsin
    foreach (['miktar_kg', 'urun_ton_fiyat', 'urun_kdv', 'nakliye_ton_fiyat', 'nakliye_kdv'] as $alan) {
        $s[$alan . '_ham'] = post($alan);
    }
}

if (!$s) {
    // Yeni sefer: son seferdeki nakliye fiyatını öner
    $son = one('SELECT nakliye_ton_fiyat, nakliye_kdv FROM seferler WHERE silindi = 0 ORDER BY id DESC LIMIT 1');
    $s = [
        'tarih' => date('Y-m-d'), 'teslim_tarihi' => '', 'durum' => 'yuklendi',
        'yukleme_depo_id' => '', 'bosaltim_depo_id' => '', 'sofor_id' => '', 'sofor_telefon' => '',
        'cekici_plaka' => '', 'dorse_plaka' => '', 'urun_id' => '', 'miktar_kg' => '',
        'urun_ton_fiyat' => '', 'urun_kdv' => cfg('varsayilan_urun_kdv', 20),
        'nakliye_ton_fiyat' => $son ? $son['nakliye_ton_fiyat'] : '', 'nakliye_kdv' => $son ? $son['nakliye_kdv'] : cfg('varsayilan_nakliye_kdv', 20),
        'nakliye_odendi' => 0, 'irsaliye_no' => '', 'kantar_fis_no' => '', 'notlar' => '',
    ];
}

// Başka sayfadan dönüşte (yeni depo / ürün eklendi, "aynı güzergah") ön seçim
$onSecim = [];
foreach (['ydepo' => 'yukleme_depo_id', 'bdepo' => 'bosaltim_depo_id', 'urun' => 'urun_id'] as $g => $alan) {
    if (ctype_digit(get($g)) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $s[$alan] = (int)get($g);
        $onSecim[$alan] = (string)(int)get($g);
    }
}

$ydepolar = all("SELECT * FROM depolar WHERE tip = 'yukleme' AND (silindi = 0 OR id = ?) ORDER BY ad", [(int)$s['yukleme_depo_id']]);
$bdepolar = all("SELECT * FROM depolar WHERE tip = 'bosaltim' AND (silindi = 0 OR id = ?) ORDER BY ad", [(int)$s['bosaltim_depo_id']]);
$soforler = all('SELECT * FROM soforler WHERE silindi = 0 OR id = ? ORDER BY ad, soyad', [(int)$s['sofor_id']]);
$urunler = all('SELECT * FROM urunler WHERE silindi = 0 OR id = ? ORDER BY ad', [(int)$s['urun_id']]);
$mevcutBelgeler = $id ? all('SELECT * FROM belgeler WHERE sefer_id = ? AND silindi = 0 ORDER BY id', [$id]) : [];
$sinir = yukleme_sinirlari();
$sid = $id ? '&sid=' . $id : '';

/** Sayı alanının formdaki değeri: hatalı gönderimde kullanıcının yazdığı metin */
$deger = function ($alan) use ($s) {
    return array_key_exists($alan . '_ham', $s) ? $s[$alan . '_ham'] : sayi_input($s[$alan]);
};

sayfa_bas($id ? "Sefer #$id Düzenle" : 'Yeni Sefer', 'sefer_form');
?>
<h1><?= $id ? "Sefer #$id Düzenle" : 'Yeni Sefer' ?></h1>
<?php if ($hatalar): ?>
  <div class="uyari hata"><b>Kaydedilmedi, lütfen düzeltin:</b><ul><?php foreach ($hatalar as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  <?php if (dosya_secildi_mi()): ?><small>Not: Seçtiğiniz dosyalar kaydedilmedi; lütfen dosyaları tekrar seçin.</small><?php endif; ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form" id="sefer-form"
  data-taslak="<?= h($taslakAnahtari) ?>" <?= get('taslak') === '1' ? 'data-taslak-oto="1"' : '' ?>
  data-on-secim="<?= h(json_encode($onSecim)) ?>"
  data-max-dosya="<?= (int)$sinir['dosya'] ?>" data-max-toplam="<?= (int)$sinir['toplam'] ?>" data-max-adet="<?= (int)$sinir['adet'] ?>">
  <?= csrf_alan() ?>
  <div class="kart">
    <h2>Genel</h2>
    <div class="uc">
      <label>Yükleme tarihi *<input type="date" name="tarih" required value="<?= h($s['tarih']) ?>"></label>
      <label>Durum<select name="durum"><?php foreach (DURUMLAR as $k => $v): ?><option value="<?= $k ?>" <?= $s['durum'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
      <label>Teslim tarihi<input type="date" name="teslim_tarihi" value="<?= h($s['teslim_tarihi']) ?>"></label>
    </div>
  </div>

  <div class="kart">
    <h2>Güzergah</h2>
    <div class="iki">
      <label>📦 Yükleme (Sarım) deposu *
        <select name="yukleme_depo_id" required><?= secenekler($ydepolar, $s['yukleme_depo_id'], '— Seçin —', 'depo_etiket') ?></select>
        <a class="kucuk-link taslak-link" href="index.php?p=depo_form&tip=yukleme&don=sefer<?= $sid ?>">+ yeni yükleme deposu</a>
      </label>
      <label>🏁 Boşaltım deposu *
        <select name="bosaltim_depo_id" required><?= secenekler($bdepolar, $s['bosaltim_depo_id'], '— Seçin —', 'depo_etiket') ?></select>
        <a class="kucuk-link taslak-link" href="index.php?p=depo_form&tip=bosaltim&don=sefer<?= $sid ?>">+ yeni boşaltım deposu</a>
      </label>
    </div>
  </div>

  <div class="kart">
    <h2>Şoför ve Araç</h2>
    <label>Şoför
      <select name="sofor_id" id="sofor_id">
        <option value="">— Seçin —</option>
        <option value="yeni" <?= $s['sofor_id'] === 'yeni' ? 'selected' : '' ?>>➕ Yeni şoför ekle…</option>
        <?php foreach ($soforler as $so): ?>
          <option value="<?= (int)$so['id'] ?>" <?= (string)$s['sofor_id'] === (string)$so['id'] ? 'selected' : '' ?>
            data-tel="<?= h($so['telefon']) ?>" data-cekici="<?= h($so['cekici_plaka']) ?>" data-dorse="<?= h($so['dorse_plaka']) ?>">
            <?= h(trim($so['ad'] . ' ' . $so['soyad']) . ($so['cekici_plaka'] ? ' · ' . $so['cekici_plaka'] : '') . ($so['tc'] ? ' · TC ' . $so['tc'] : '') . ($so['silindi'] ? ' [silinmiş]' : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="uc" id="yeni-sofor" <?= $s['sofor_id'] === 'yeni' ? '' : 'hidden' ?>>
      <label>Ad *<input name="yeni_ad" value="<?= h(post('yeni_ad')) ?>"></label>
      <label>Soyad<input name="yeni_soyad" value="<?= h(post('yeni_soyad')) ?>"></label>
      <label>TC No<input name="yeni_tc" inputmode="numeric" maxlength="11" value="<?= h(post('yeni_tc')) ?>"></label>
    </div>
    <div class="uc">
      <label>Telefon (WhatsApp)<input type="tel" name="sofor_telefon" id="sofor_telefon" value="<?= h($s['sofor_telefon']) ?>" placeholder="0544 406 74 94"></label>
      <label>Çekici plaka<input name="cekici_plaka" id="cekici_plaka" class="buyuk-harf" value="<?= h($s['cekici_plaka']) ?>" placeholder="45AAR75"></label>
      <label>Dorse plaka<input name="dorse_plaka" id="dorse_plaka" class="buyuk-harf" value="<?= h($s['dorse_plaka']) ?>" placeholder="45AAR12"></label>
    </div>
    <p class="soluk kucuk-yazi">Telefon boş bırakılırsa şoför kartındaki numara kullanılır.</p>
  </div>

  <div class="kart">
    <h2>Yük ve Ücret</h2>
    <div class="iki">
      <label>Ürün
        <select name="urun_id" id="urun_id">
          <option value="">— Seçin —</option>
          <?php foreach ($urunler as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (string)$s['urun_id'] === (string)$u['id'] ? 'selected' : '' ?> data-fiyat="<?= h(sayi_input($u['ton_fiyat'])) ?>" data-kdv="<?= h(sayi_input($u['kdv_orani'])) ?>"><?= h($u['ad'] . ($u['silindi'] ? ' [silinmiş]' : '')) ?></option>
          <?php endforeach; ?>
        </select>
        <a class="kucuk-link taslak-link" href="index.php?p=urun_form&don=sefer<?= $sid ?>">+ yeni ürün</a>
      </label>
      <label>Miktar (kg)<input name="miktar_kg" id="miktar_kg" inputmode="decimal" value="<?= h($deger('miktar_kg')) ?>" placeholder="27000"></label>
    </div>
    <div class="iki">
      <label>Ürün ton fiyatı (₺, KDV hariç)<input name="urun_ton_fiyat" id="urun_ton_fiyat" inputmode="decimal" value="<?= h($deger('urun_ton_fiyat')) ?>" placeholder="9000"></label>
      <label>Ürün KDV (%)<input name="urun_kdv" id="urun_kdv" inputmode="decimal" value="<?= h($deger('urun_kdv')) ?>"></label>
      <label>Nakliye ton fiyatı (₺, KDV hariç)<input name="nakliye_ton_fiyat" id="nakliye_ton_fiyat" inputmode="decimal" value="<?= h($deger('nakliye_ton_fiyat')) ?>" placeholder="100"></label>
      <label>Nakliye KDV (%)<input name="nakliye_kdv" id="nakliye_kdv" inputmode="decimal" value="<?= h($deger('nakliye_kdv')) ?>"></label>
    </div>
    <label class="onay"><input type="checkbox" name="nakliye_odendi" value="1" <?= $s['nakliye_odendi'] ? 'checked' : '' ?>> Nakliye ücreti şoföre ödendi</label>
    <div class="hesap" id="hesap-ozet"></div>
  </div>

  <div class="kart">
    <h2>İrsaliye ve Kantar Fişi</h2>
    <div class="iki">
      <label>İrsaliye no<input name="irsaliye_no" value="<?= h($s['irsaliye_no']) ?>"></label>
      <label>Kantar fiş no<input name="kantar_fis_no" value="<?= h($s['kantar_fis_no']) ?>"></label>
    </div>
    <div class="uc">
      <label class="dosya">📄 İrsaliye PDF / foto<input type="file" name="belge_irsaliye[]" multiple accept="application/pdf,image/*"></label>
      <label class="dosya">⚖️ Kantar fişi PDF / foto<input type="file" name="belge_kantar[]" multiple accept="application/pdf,image/*"></label>
      <label class="dosya">📎 Diğer belge<input type="file" name="belge_diger[]" multiple accept="application/pdf,image/*"></label>
    </div>
    <p class="soluk kucuk-yazi">Dosya başına en fazla <?= h(mb_fmt($sinir['dosya'])) ?>, tek seferde toplam <?= h(mb_fmt($sinir['toplam'])) ?>.</p>
    <?php if ($mevcutBelgeler): ?>
      <p class="soluk">Mevcut belgeler: <?php foreach ($mevcutBelgeler as $b): ?><a target="_blank" href="belge.php?t=<?= h($b['token']) ?>"><?= h(BELGE_TURLERI[$b['tur']] ?? 'Belge') ?></a> <?php endforeach; ?> (silmek için sefer detay sayfasını kullanın)</p>
    <?php endif; ?>
  </div>

  <div class="kart">
    <label>Notlar<textarea name="notlar" rows="3"><?= h($s['notlar']) ?></textarea></label>
  </div>

  <div class="butonlar yapiskan">
    <button class="btn ana buyuk" id="kaydet">💾 Kaydet</button>
    <a class="btn" href="<?= $id ? 'index.php?p=sefer&id=' . $id : 'index.php?p=seferler' ?>">Vazgeç</a>
  </div>
</form>
<?php sayfa_son();
