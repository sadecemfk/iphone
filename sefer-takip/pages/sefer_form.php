<?php
$id = (int)get('id');
$s = $id ? one('SELECT * FROM seferler WHERE id = ?', [$id]) : null;
if ($id && !$s) {
    yonlendir('index.php?p=seferler');
}
$hata = '';

$ydepolar = all("SELECT * FROM depolar WHERE tip = 'yukleme' AND (silindi = 0 OR id = ?) ORDER BY ad", [$s['yukleme_depo_id'] ?? 0]);
$bdepolar = all("SELECT * FROM depolar WHERE tip = 'bosaltim' AND (silindi = 0 OR id = ?) ORDER BY ad", [$s['bosaltim_depo_id'] ?? 0]);
$soforler = all('SELECT * FROM soforler WHERE silindi = 0 OR id = ? ORDER BY ad, soyad', [$s['sofor_id'] ?? 0]);
$urunler = all('SELECT * FROM urunler WHERE silindi = 0 OR id = ? ORDER BY ad', [$s['urun_id'] ?? 0]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $soforId = post('sofor_id');
    if ($soforId === 'yeni' && post('yeni_ad') === '') {
        $hata = 'Yeni şoför için ad giriniz.';
    }
    $veri = [
        'tarih' => post('tarih'),
        'teslim_tarihi' => post('teslim_tarihi') ?: null,
        'durum' => isset(DURUMLAR[post('durum')]) ? post('durum') : 'planlandi',
        'yukleme_depo_id' => (int)post('yukleme_depo_id') ?: null,
        'bosaltim_depo_id' => (int)post('bosaltim_depo_id') ?: null,
        'sofor_id' => (int)$soforId ?: null,
        'sofor_telefon' => post('sofor_telefon'),
        'cekici_plaka' => plaka(post('cekici_plaka')),
        'dorse_plaka' => plaka(post('dorse_plaka')),
        'urun_id' => (int)post('urun_id') ?: null,
        'miktar_kg' => sayi(post('miktar_kg')),
        'urun_ton_fiyat' => sayi(post('urun_ton_fiyat')),
        'urun_kdv' => sayi(post('urun_kdv')),
        'nakliye_ton_fiyat' => sayi(post('nakliye_ton_fiyat')),
        'nakliye_kdv' => sayi(post('nakliye_kdv')),
        'nakliye_odendi' => post('nakliye_odendi') ? 1 : 0,
        'irsaliye_no' => post('irsaliye_no'),
        'kantar_fis_no' => post('kantar_fis_no'),
        'notlar' => post('notlar'),
    ];
    if (!$hata) {
        if (!tarih_gecerli($veri['tarih'])) {
            $hata = 'Geçerli bir tarih giriniz.';
        } elseif ($veri['teslim_tarihi'] && !tarih_gecerli($veri['teslim_tarihi'])) {
            $hata = 'Teslim tarihi geçersiz.';
        } elseif (!$veri['yukleme_depo_id'] || !$veri['bosaltim_depo_id']) {
            $hata = 'Yükleme ve boşaltım deposunu seçiniz.';
        }
    }
    if (!$hata) {
        otomatik_yedek(); // işlem (transaction) başlamadan önce
        db()->beginTransaction();
        try {
            // Formdan yeni şoför ekleme
            if ($soforId === 'yeni') {
                $veri['sofor_id'] = kayit_ekle('soforler', [
                    'ad' => post('yeni_ad'),
                    'soyad' => post('yeni_soyad'),
                    'tc' => preg_replace('/\D/', '', post('yeni_tc')),
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
        $bh = belgeleri_isle($id);
        foreach ($bh as $e) {
            mesaj($e, 'hata');
        }
        mesaj($s ? 'Sefer güncellendi.' : 'Sefer kaydedildi. Şimdi şoföre WhatsApp ile gönderebilirsiniz.');
        yonlendir('index.php?p=sefer&id=' . $id);
    }
    $s = array_merge($s ?: [], $veri);
    if ($soforId === 'yeni') {
        $s['sofor_id'] = 'yeni';
    }
}

if (!$s) {
    // Yeni sefer: son seferdeki nakliye fiyatını öner
    $son = one('SELECT nakliye_ton_fiyat, nakliye_kdv FROM seferler WHERE silindi = 0 ORDER BY id DESC LIMIT 1');
    $s = [
        'tarih' => date('Y-m-d'), 'teslim_tarihi' => '', 'durum' => 'yuklendi',
        'yukleme_depo_id' => get('ydepo'), 'bosaltim_depo_id' => get('bdepo'), 'sofor_id' => '', 'sofor_telefon' => '',
        'cekici_plaka' => '', 'dorse_plaka' => '', 'urun_id' => '', 'miktar_kg' => '',
        'urun_ton_fiyat' => '', 'urun_kdv' => cfg('varsayilan_urun_kdv', 20),
        'nakliye_ton_fiyat' => $son ? $son['nakliye_ton_fiyat'] : '', 'nakliye_kdv' => $son ? $son['nakliye_kdv'] : cfg('varsayilan_nakliye_kdv', 20),
        'nakliye_odendi' => 0, 'irsaliye_no' => '', 'kantar_fis_no' => '', 'notlar' => '',
    ];
}
$mevcutBelgeler = $id ? all('SELECT * FROM belgeler WHERE sefer_id = ? AND silindi = 0 ORDER BY id', [$id]) : [];

sayfa_bas($id ? "Sefer #$id Düzenle" : 'Yeni Sefer', 'sefer_form');
?>
<h1><?= $id ? "Sefer #$id Düzenle" : 'Yeni Sefer' ?></h1>
<?php if ($hata): ?><div class="uyari hata"><?= h($hata) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form" id="sefer-form">
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
        <a class="kucuk-link" href="index.php?p=depo_form&tip=yukleme&don=sefer">+ yeni yükleme deposu</a>
      </label>
      <label>🏁 Boşaltım deposu *
        <select name="bosaltim_depo_id" required><?= secenekler($bdepolar, $s['bosaltim_depo_id'], '— Seçin —', 'depo_etiket') ?></select>
        <a class="kucuk-link" href="index.php?p=depo_form&tip=bosaltim&don=sefer">+ yeni boşaltım deposu</a>
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
          <option value="<?= $so['id'] ?>" <?= (string)$s['sofor_id'] === (string)$so['id'] ? 'selected' : '' ?>
            data-tel="<?= h($so['telefon']) ?>" data-cekici="<?= h($so['cekici_plaka']) ?>" data-dorse="<?= h($so['dorse_plaka']) ?>">
            <?= h(trim($so['ad'] . ' ' . $so['soyad']) . ($so['cekici_plaka'] ? ' · ' . $so['cekici_plaka'] : '') . ($so['tc'] ? ' · TC ' . $so['tc'] : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="uc" id="yeni-sofor" <?= $s['sofor_id'] === 'yeni' ? '' : 'hidden' ?>>
      <label>Ad<input name="yeni_ad" value="<?= h(post('yeni_ad')) ?>"></label>
      <label>Soyad<input name="yeni_soyad" value="<?= h(post('yeni_soyad')) ?>"></label>
      <label>TC No<input name="yeni_tc" inputmode="numeric" maxlength="11" value="<?= h(post('yeni_tc')) ?>"></label>
    </div>
    <div class="uc">
      <label>Telefon (WhatsApp)<input type="tel" name="sofor_telefon" id="sofor_telefon" value="<?= h($s['sofor_telefon']) ?>" placeholder="0544 406 74 94"></label>
      <label>Çekici plaka<input name="cekici_plaka" id="cekici_plaka" class="buyuk-harf" value="<?= h($s['cekici_plaka']) ?>" placeholder="45AAR75"></label>
      <label>Dorse plaka<input name="dorse_plaka" id="dorse_plaka" class="buyuk-harf" value="<?= h($s['dorse_plaka']) ?>" placeholder="45AAR12"></label>
    </div>
  </div>

  <div class="kart">
    <h2>Yük ve Ücret</h2>
    <div class="iki">
      <label>Ürün
        <select name="urun_id" id="urun_id">
          <option value="">— Seçin —</option>
          <?php foreach ($urunler as $u): ?>
            <option value="<?= $u['id'] ?>" <?= (string)$s['urun_id'] === (string)$u['id'] ? 'selected' : '' ?> data-fiyat="<?= h(sayi_input($u['ton_fiyat'])) ?>" data-kdv="<?= h(sayi_input($u['kdv_orani'])) ?>"><?= h($u['ad']) ?></option>
          <?php endforeach; ?>
        </select>
        <a class="kucuk-link" href="index.php?p=urun_form">+ yeni ürün</a>
      </label>
      <label>Miktar (kg)<input name="miktar_kg" id="miktar_kg" inputmode="decimal" value="<?= h(sayi_input($s['miktar_kg'])) ?>" placeholder="27000"></label>
    </div>
    <div class="iki">
      <label>Ürün ton fiyatı (₺, KDV hariç)<input name="urun_ton_fiyat" id="urun_ton_fiyat" inputmode="decimal" value="<?= h(sayi_input($s['urun_ton_fiyat'])) ?>" placeholder="9000"></label>
      <label>Ürün KDV (%)<input name="urun_kdv" id="urun_kdv" inputmode="decimal" value="<?= h(sayi_input($s['urun_kdv'])) ?>"></label>
      <label>Nakliye ton fiyatı (₺, KDV hariç)<input name="nakliye_ton_fiyat" id="nakliye_ton_fiyat" inputmode="decimal" value="<?= h(sayi_input($s['nakliye_ton_fiyat'])) ?>" placeholder="100"></label>
      <label>Nakliye KDV (%)<input name="nakliye_kdv" id="nakliye_kdv" inputmode="decimal" value="<?= h(sayi_input($s['nakliye_kdv'])) ?>"></label>
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
    <?php if ($mevcutBelgeler): ?>
      <p class="soluk">Mevcut belgeler: <?php foreach ($mevcutBelgeler as $b): ?><a target="_blank" href="belge.php?t=<?= h($b['token']) ?>"><?= h(BELGE_TURLERI[$b['tur']] ?? 'Belge') ?></a> <?php endforeach; ?> (silmek için sefer detay sayfasını kullanın)</p>
    <?php endif; ?>
  </div>

  <div class="kart">
    <label>Notlar<textarea name="notlar" rows="3"><?= h($s['notlar']) ?></textarea></label>
  </div>

  <div class="butonlar yapiskan">
    <button class="btn ana buyuk">💾 Kaydet</button>
    <a class="btn" href="<?= $id ? 'index.php?p=sefer&id=' . $id : 'index.php?p=seferler' ?>">Vazgeç</a>
  </div>
</form>
<?php sayfa_son();
