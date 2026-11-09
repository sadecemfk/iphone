<?php
$id = (int)get('id');
$d = $id ? one('SELECT * FROM depolar WHERE id = ?', [$id]) : null;
if ($id && !$d) {
    yonlendir('index.php?p=depolar');
}
$hatalar = [];
$konumOnaySor = false;
$don = get('don') === 'sefer';
$sid = (int)get('sid');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('islem') === 'sil' && $d) {
        kayit_guncelle('depolar', $id, ['silindi' => 1], 'sil');
        mesaj('Depo silindi (Silinenler sayfasından geri alınabilir). Geçmiş seferler etkilenmez.');
        yonlendir('index.php?p=depolar');
    }
    $tip = post('tip') === 'bosaltim' ? 'bosaltim' : 'yukleme';
    $lat = tek_koordinat(post('lat'));
    $lng = tek_koordinat(post('lng'));
    $veri = [
        'tip' => $tip,
        'ad' => post('ad'),
        'firma' => post('firma'),
        'il' => post('il'),
        'ilce' => post('ilce'),
        'adres' => post('adres'),
        'lat' => is_float($lat) ? round($lat, 7) : null,
        'lng' => is_float($lng) ? round($lng, 7) : null,
        'yetkili' => post('yetkili'),
        'telefon' => post('telefon'),
        'notlar' => post('notlar'),
    ];
    if ($veri['ad'] === '') {
        $hatalar[] = 'Depo adı zorunludur.';
    }
    if ($lat === false) {
        $hatalar[] = 'Enlem okunamadı: "' . post('lat') . '". Ör. 40.8167 veya 40°49\'00"N';
    }
    if ($lng === false) {
        $hatalar[] = 'Boylam okunamadı: "' . post('lng') . '". Ör. 32.8930 veya 32°53\'35"E';
    }
    if (!$hatalar) {
        if (($veri['lat'] === null) !== ($veri['lng'] === null)) {
            $hatalar[] = 'Enlem ve boylamın ikisi birden girilmelidir.';
        } elseif ($veri['lat'] !== null && (abs($veri['lat']) > 90 || abs($veri['lng']) > 180)) {
            $hatalar[] = 'Koordinat geçersiz (enlem -90..90, boylam -180..180 olmalı).';
        } elseif ($veri['lat'] !== null && !tr_icinde($veri['lat'], $veri['lng']) && !post('konum_onay')) {
            $konumOnaySor = true;
            $hatalar[] = tr_icinde($veri['lng'], $veri['lat'])
                ? 'Enlem ve boylam TERS girilmiş görünüyor (Türkiye için enlem 36-42, boylam 26-45 arasıdır). Kutuları kontrol edin.'
                : 'Bu koordinat Türkiye dışında görünüyor. Haritadan kontrol edin.';
        }
    }
    if (!$hatalar) {
        if ($d) {
            kayit_guncelle('depolar', $id, $veri);
            mesaj('Depo güncellendi.');
        } else {
            $id = kayit_ekle('depolar', $veri);
            mesaj('Depo eklendi.');
        }
        if ($veri['lat'] === null) {
            mesaj('Dikkat: Bu deponun konumu (koordinat) girilmedi; şoföre konum gönderilemez.', 'dikkat');
        }
        if ($veri['telefon'] !== '' && !cep_telefonu_mu($veri['telefon']) && strlen(preg_replace('/\D/', '', $veri['telefon'])) < 10) {
            mesaj('Dikkat: Depo telefon numarası eksik görünüyor.', 'dikkat');
        }
        if ($don) {
            // Sefer formuna dön; yeni depo seçili gelsin, yazılan bilgiler taslaktan geri yüklensin
            yonlendir('index.php?p=sefer_form&taslak=1' . ($sid ? '&id=' . $sid : '') . '&' . ($tip === 'yukleme' ? 'ydepo' : 'bdepo') . '=' . $id);
        }
        yonlendir('index.php?p=depolar&tip=' . $tip);
    }
    $d = array_merge($d ?: [], $veri);
    $d['lat_ham'] = post('lat');
    $d['lng_ham'] = post('lng');
}

$d = $d ?: ['tip' => get('tip') === 'bosaltim' ? 'bosaltim' : 'yukleme', 'ad' => '', 'firma' => '', 'il' => '', 'ilce' => '', 'adres' => '', 'lat' => null, 'lng' => null, 'yetkili' => '', 'telefon' => '', 'notlar' => ''];
$latDeger = array_key_exists('lat_ham', $d) ? $d['lat_ham'] : ($d['lat'] !== null ? koordinat_fmt($d['lat']) : '');
$lngDeger = array_key_exists('lng_ham', $d) ? $d['lng_ham'] : ($d['lng'] !== null ? koordinat_fmt($d['lng']) : '');
$vazgec = $don ? 'index.php?p=sefer_form&taslak=1' . ($sid ? '&id=' . $sid : '') : 'index.php?p=depolar';
sayfa_bas($id ? 'Depo Düzenle' : 'Yeni Depo', 'depolar');
?>
<h1><?= $id ? 'Depo Düzenle' : 'Yeni Depo' ?></h1>
<?php if ($hatalar): ?><div class="uyari hata"><ul><?php foreach ($hatalar as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="kart form" id="depo-form">
  <?= csrf_alan() ?>
  <div class="secim-grup">
    <label class="radyo"><input type="radio" name="tip" value="yukleme" <?= $d['tip'] === 'yukleme' ? 'checked' : '' ?>> 📦 Yükleme (Sarım) Deposu</label>
    <label class="radyo"><input type="radio" name="tip" value="bosaltim" <?= $d['tip'] === 'bosaltim' ? 'checked' : '' ?>> 🏁 Boşaltım Deposu</label>
  </div>
  <div class="iki">
    <label>Depo adı *<input name="ad" required value="<?= h($d['ad']) ?>" placeholder="ör. Erpa Depo"></label>
    <label>Firma<input name="firma" value="<?= h($d['firma']) ?>" placeholder="ör. Alparslan Kağıt Fabrikası"></label>
    <label>İl<input name="il" value="<?= h($d['il']) ?>" placeholder="ör. Çankırı"></label>
    <label>İlçe<input name="ilce" value="<?= h($d['ilce']) ?>" placeholder="ör. Çerkes"></label>
  </div>
  <label>Açık adres<textarea name="adres" rows="2"><?= h($d['adres']) ?></textarea></label>

  <fieldset>
    <legend>📍 Konum (Koordinat)</legend>
    <label>Harita linki veya koordinat yapıştırın
      <input id="konum-yapistir" placeholder="ör. 40.8167, 32.8930 · 40°49'00&quot;N 32°53'35&quot;E · Google / Apple / Yandex harita linki" autocomplete="off">
    </label>
    <div class="butonlar">
      <button type="button" class="btn kucuk" id="konum-coz">🔎 Koordinatı Al</button>
      <button type="button" class="btn kucuk" id="konum-gps">📡 Şu An Bulunduğum Konum</button>
    </div>
    <p class="soluk kucuk-yazi">İpucu: Google Haritalar'da noktaya basılı tutun, üstte çıkan koordinata dokunup kopyalayın ve buraya yapıştırın.</p>
    <div class="iki">
      <label>Enlem (lat)<input name="lat" id="lat" inputmode="decimal" value="<?= h($latDeger) ?>"></label>
      <label>Boylam (lng)<input name="lng" id="lng" inputmode="decimal" value="<?= h($lngDeger) ?>"></label>
    </div>
    <?php if ($konumOnaySor): ?>
      <label class="onay"><input type="checkbox" name="konum_onay" value="1"> Koordinatı kontrol ettim, doğru — yine de kaydet</label>
    <?php endif; ?>
    <div id="konum-durum" class="soluk"></div>
    <div id="harita-onizleme" class="harita"></div>
  </fieldset>

  <div class="iki">
    <label>Yetkili kişi<input name="yetkili" value="<?= h($d['yetkili']) ?>"></label>
    <label>Telefon<input name="telefon" type="tel" value="<?= h($d['telefon']) ?>"></label>
  </div>
  <label>Notlar<textarea name="notlar" rows="2"><?= h($d['notlar']) ?></textarea></label>
  <p class="soluk kucuk-yazi">Not: Konumu değiştirirseniz bu depoyu kullanan eski seferlerin mesajlarında da yeni konum görünür. Depo taşındıysa yeni depo olarak ekleyin.</p>
  <div class="butonlar">
    <button class="btn ana">💾 Kaydet</button>
    <a class="btn" href="<?= h($vazgec) ?>">Vazgeç</a>
  </div>
</form>
<?php if ($id): ?>
<form method="post" class="sil-form" onsubmit="return confirm('Bu depo silinsin mi? (Geçmiş seferler etkilenmez, sonradan geri alınabilir)')">
  <?= csrf_alan() ?><input type="hidden" name="islem" value="sil">
  <button class="btn tehlike kucuk">🗑️ Depoyu Sil</button>
</form>
<?php endif; ?>
<?php sayfa_son();
