<?php
$id = (int)get('id');
$d = $id ? one('SELECT * FROM urunler WHERE id = ?', [$id]) : null;
if ($id && !$d) {
    yonlendir('index.php?p=urunler');
}
$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('islem') === 'sil' && $d) {
        kayit_guncelle('urunler', $id, ['silindi' => 1], 'sil');
        mesaj('Ürün silindi (geri alınabilir).');
        yonlendir('index.php?p=urunler');
    }
    $veri = [
        'ad' => post('ad'),
        'ton_fiyat' => sayi(post('ton_fiyat')),
        'kdv_orani' => sayi(post('kdv_orani')),
        'notlar' => post('notlar'),
    ];
    if ($veri['ad'] === '') {
        $hata = 'Ürün adı zorunludur.';
    } else {
        if ($d) {
            kayit_guncelle('urunler', $id, $veri);
            mesaj('Ürün güncellendi.');
        } else {
            kayit_ekle('urunler', $veri);
            mesaj('Ürün eklendi.');
        }
        yonlendir('index.php?p=urunler');
    }
    $d = array_merge($d ?: [], $veri);
}
$d = $d ?: ['ad' => '', 'ton_fiyat' => '', 'kdv_orani' => cfg('varsayilan_urun_kdv', 20), 'notlar' => ''];
sayfa_bas($id ? 'Ürün Düzenle' : 'Yeni Ürün', 'urunler');
?>
<h1><?= $id ? 'Ürün Düzenle' : 'Yeni Ürün' ?></h1>
<?php if ($hata): ?><div class="uyari hata"><?= h($hata) ?></div><?php endif; ?>
<form method="post" class="kart form">
  <?= csrf_alan() ?>
  <label>Ürün adı *<input name="ad" required value="<?= h($d['ad']) ?>" placeholder="ör. Fındık"></label>
  <div class="iki">
    <label>Ton başına fiyat (₺, KDV hariç)<input name="ton_fiyat" inputmode="decimal" value="<?= h(sayi_input($d['ton_fiyat'])) ?>" placeholder="9000"></label>
    <label>KDV oranı (%)<input name="kdv_orani" inputmode="decimal" value="<?= h(sayi_input($d['kdv_orani'])) ?>"></label>
  </div>
  <label>Notlar<textarea name="notlar" rows="2"><?= h($d['notlar']) ?></textarea></label>
  <div class="butonlar">
    <button class="btn ana">💾 Kaydet</button>
    <a class="btn" href="index.php?p=urunler">Vazgeç</a>
  </div>
</form>
<?php if ($id): ?>
<form method="post" class="sil-form" onsubmit="return confirm('Ürün silinsin mi? (Geçmiş seferler etkilenmez, geri alınabilir)')">
  <?= csrf_alan() ?><input type="hidden" name="islem" value="sil">
  <button class="btn tehlike kucuk">🗑️ Ürünü Sil</button>
</form>
<?php endif; ?>
<?php sayfa_son();
