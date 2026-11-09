<?php
$id = (int)get('id');
$d = $id ? one('SELECT * FROM depolar WHERE id = ?', [$id]) : null;
if ($id && !$d) {
    yonlendir('index.php?p=depolar');
}
$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('islem') === 'sil' && $d) {
        kayit_guncelle('depolar', $id, ['silindi' => 1], 'sil');
        mesaj('Depo silindi (Yedek sayfasındaki "Silinenler" bölümünden geri alınabilir).');
        yonlendir('index.php?p=depolar');
    }
    $tip = post('tip') === 'bosaltim' ? 'bosaltim' : 'yukleme';
    $lat = post('lat');
    $lng = post('lng');
    $veri = [
        'tip' => $tip,
        'ad' => post('ad'),
        'firma' => post('firma'),
        'il' => post('il'),
        'ilce' => post('ilce'),
        'adres' => post('adres'),
        'lat' => $lat === '' ? null : (float)str_replace(',', '.', $lat),
        'lng' => $lng === '' ? null : (float)str_replace(',', '.', $lng),
        'yetkili' => post('yetkili'),
        'telefon' => post('telefon'),
        'notlar' => post('notlar'),
    ];
    if ($veri['ad'] === '') {
        $hata = 'Depo adı zorunludur.';
    } elseif (($veri['lat'] === null) !== ($veri['lng'] === null)) {
        $hata = 'Enlem ve boylamın ikisi birden girilmelidir.';
    } elseif ($veri['lat'] !== null && (abs($veri['lat']) > 90 || abs($veri['lng']) > 180)) {
        $hata = 'Koordinat geçersiz.';
    } else {
        if ($d) {
            kayit_guncelle('depolar', $id, $veri);
            mesaj('Depo güncellendi.');
        } else {
            $id = kayit_ekle('depolar', $veri);
            mesaj('Depo eklendi.');
        }
        $don = get('don');
        if ($don === 'sefer') {
            yonlendir('index.php?p=sefer_form');
        }
        yonlendir('index.php?p=depolar&tip=' . $tip);
    }
    $d = array_merge($d ?: [], $veri);
}

$d = $d ?: ['tip' => get('tip') === 'bosaltim' ? 'bosaltim' : 'yukleme', 'ad' => '', 'firma' => '', 'il' => '', 'ilce' => '', 'adres' => '', 'lat' => null, 'lng' => null, 'yetkili' => '', 'telefon' => '', 'notlar' => ''];
sayfa_bas($id ? 'Depo Düzenle' : 'Yeni Depo', 'depolar');
?>
<h1><?= $id ? 'Depo Düzenle' : 'Yeni Depo' ?></h1>
<?php if ($hata): ?><div class="uyari hata"><?= h($hata) ?></div><?php endif; ?>
<form method="post" class="kart form">
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
    <label>Google Maps linki veya koordinat yapıştırın
      <input id="konum-yapistir" placeholder="ör. 40.8167, 32.8930  veya  https://maps.app.goo.gl/...  veya  https://www.google.com/maps/@40.81,32.89,15z" autocomplete="off">
    </label>
    <div class="butonlar">
      <button type="button" class="btn kucuk" id="konum-coz">🔎 Linkten Koordinatı Al</button>
      <button type="button" class="btn kucuk" id="konum-gps">📡 Şu An Bulunduğum Konum</button>
    </div>
    <div class="iki">
      <label>Enlem (lat)<input name="lat" id="lat" inputmode="decimal" value="<?= h($d['lat'] !== null ? koordinat_fmt($d['lat']) : '') ?>"></label>
      <label>Boylam (lng)<input name="lng" id="lng" inputmode="decimal" value="<?= h($d['lng'] !== null ? koordinat_fmt($d['lng']) : '') ?>"></label>
    </div>
    <div id="konum-durum" class="soluk"></div>
    <div id="harita-onizleme" class="harita"></div>
  </fieldset>

  <div class="iki">
    <label>Yetkili kişi<input name="yetkili" value="<?= h($d['yetkili']) ?>"></label>
    <label>Telefon<input name="telefon" type="tel" value="<?= h($d['telefon']) ?>"></label>
  </div>
  <label>Notlar<textarea name="notlar" rows="2"><?= h($d['notlar']) ?></textarea></label>
  <div class="butonlar">
    <button class="btn ana">💾 Kaydet</button>
    <a class="btn" href="index.php?p=depolar">Vazgeç</a>
  </div>
</form>
<?php if ($id): ?>
<form method="post" class="sil-form" onsubmit="return confirm('Bu depo silinsin mi? (Geçmiş seferler etkilenmez, sonradan geri alınabilir)')">
  <?= csrf_alan() ?><input type="hidden" name="islem" value="sil">
  <button class="btn tehlike kucuk">🗑️ Depoyu Sil</button>
</form>
<?php endif; ?>
<?php sayfa_son();
