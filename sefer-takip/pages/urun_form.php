<?php
$id = (int)get('id');
$d = $id ? one('SELECT * FROM urunler WHERE id = ?', [$id]) : null;
if ($id && !$d) {
    yonlendir('index.php?p=urunler');
}
$hatalar = [];
$don = get('don') === 'sefer';
$sid = (int)get('sid');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('islem') === 'sil' && $d) {
        kayit_guncelle('urunler', $id, ['silindi' => 1], 'sil');
        mesaj('Ürün silindi (geri alınabilir). Geçmiş seferler etkilenmez.');
        yonlendir('index.php?p=urunler');
    }
    $veri = [
        'ad' => post('ad'),
        'ton_fiyat' => sayi_alani('ton_fiyat', 'Ton fiyatı', $hatalar, 0),
        'kdv_orani' => sayi_alani('kdv_orani', 'KDV oranı', $hatalar, 0, 100),
        'notlar' => post('notlar'),
    ];
    if ($veri['ad'] === '') {
        $hatalar[] = 'Ürün adı zorunludur.';
    } elseif (($var = one('SELECT id FROM urunler WHERE silindi = 0 AND id != ? AND ' . tr_kucuk_sql('ad') . ' = ?', [$id, tr_kucuk($veri['ad'])]))) {
        $hatalar[] = 'Bu adla kayıtlı bir ürün zaten var.';
    }
    if (!$hatalar) {
        if ($d) {
            kayit_guncelle('urunler', $id, $veri);
            mesaj('Ürün güncellendi. (Eski seferlerin fiyatı değişmez.)');
        } else {
            $id = kayit_ekle('urunler', $veri);
            mesaj('Ürün eklendi.');
        }
        if ($don) {
            yonlendir('index.php?p=sefer_form&taslak=1' . ($sid ? '&id=' . $sid : '') . '&urun=' . $id);
        }
        yonlendir('index.php?p=urunler');
    }
    $d = array_merge($d ?: [], $veri);
    $d['ton_fiyat_ham'] = post('ton_fiyat');
    $d['kdv_orani_ham'] = post('kdv_orani');
}
$d = $d ?: ['ad' => '', 'ton_fiyat' => '', 'kdv_orani' => cfg('varsayilan_urun_kdv', 20), 'notlar' => ''];
$vazgec = $don ? 'index.php?p=sefer_form&taslak=1' . ($sid ? '&id=' . $sid : '') : 'index.php?p=urunler';
sayfa_bas($id ? 'Ürün Düzenle' : 'Yeni Ürün', 'urunler');
?>
<h1><?= $id ? 'Ürün Düzenle' : 'Yeni Ürün' ?></h1>
<?php if ($hatalar): ?><div class="uyari hata"><ul><?php foreach ($hatalar as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="kart form">
  <?= csrf_alan() ?>
  <label>Ürün adı *<input name="ad" required value="<?= h($d['ad']) ?>" placeholder="ör. Fındık"></label>
  <div class="iki">
    <label>Ton başına fiyat (₺, KDV hariç)<input name="ton_fiyat" inputmode="decimal" value="<?= h($d['ton_fiyat_ham'] ?? sayi_input($d['ton_fiyat'])) ?>" placeholder="9000"></label>
    <label>KDV oranı (%)<input name="kdv_orani" inputmode="decimal" value="<?= h($d['kdv_orani_ham'] ?? sayi_input($d['kdv_orani'])) ?>"></label>
  </div>
  <p class="soluk kucuk-yazi">KDV oranını muhasebecinizle teyit edin; ürüne göre farklı olabilir.</p>
  <label>Notlar<textarea name="notlar" rows="2"><?= h($d['notlar']) ?></textarea></label>
  <div class="butonlar">
    <button class="btn ana">💾 Kaydet</button>
    <a class="btn" href="<?= h($vazgec) ?>">Vazgeç</a>
  </div>
</form>
<?php if ($id): ?>
<form method="post" class="sil-form" onsubmit="return confirm('Ürün silinsin mi? (Geçmiş seferler etkilenmez, geri alınabilir)')">
  <?= csrf_alan() ?><input type="hidden" name="islem" value="sil">
  <button class="btn tehlike kucuk">🗑️ Ürünü Sil</button>
</form>
<?php endif; ?>
<?php sayfa_son();
