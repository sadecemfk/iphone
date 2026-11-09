<?php
$id = (int)get('id');
$d = $id ? one('SELECT * FROM soforler WHERE id = ?', [$id]) : null;
if ($id && !$d) {
    yonlendir('index.php?p=soforler');
}
$hatalar = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('islem') === 'sil' && $d) {
        kayit_guncelle('soforler', $id, ['silindi' => 1], 'sil');
        mesaj('Şoför silindi (geri alınabilir). Geçmiş seferler etkilenmez.');
        yonlendir('index.php?p=soforler');
    }
    $veri = [
        'ad' => post('ad'),
        'soyad' => post('soyad'),
        'tc' => preg_replace('/\D/', '', post('tc')),
        'telefon' => post('telefon'),
        'cekici_plaka' => plaka(post('cekici_plaka')),
        'dorse_plaka' => plaka(post('dorse_plaka')),
        'iban' => strtoupper(str_replace(' ', '', post('iban'))),
        'notlar' => post('notlar'),
    ];
    if ($veri['ad'] === '') {
        $hatalar[] = 'Ad zorunludur.';
    }
    if ($veri['tc'] !== '' && strlen($veri['tc']) !== 11) {
        $hatalar[] = 'TC kimlik no 11 haneli olmalıdır.';
    } elseif ($veri['tc'] !== '' && ($var = one('SELECT * FROM soforler WHERE tc = ? AND id != ? ORDER BY silindi LIMIT 1', [$veri['tc'], $id]))) {
        $hatalar[] = 'Bu TC ile kayıtlı başka bir şoför var: ' . trim($var['ad'] . ' ' . $var['soyad'])
            . ($var['silindi'] ? ' (silinmiş; Silinenler sayfasından geri alabilirsiniz).' : ' (Şoförler listesinde).');
    }
    if (!$hatalar) {
        if ($d) {
            kayit_guncelle('soforler', $id, $veri);
            mesaj('Şoför güncellendi.');
        } else {
            $id = kayit_ekle('soforler', $veri);
            mesaj('Şoför eklendi.');
        }
        // Kaydı engellemeyen uyarılar
        if ($veri['tc'] !== '' && !tc_gecerli($veri['tc'])) {
            mesaj('Dikkat: TC kimlik numarası geçersiz görünüyor (doğrulama algoritmasına uymuyor). Kontrol edin.', 'dikkat');
        }
        if ($veri['telefon'] !== '' && !cep_telefonu_mu($veri['telefon'])) {
            mesaj('Dikkat: Telefon cep telefonu biçiminde değil (ör. 0544 406 74 94); WhatsApp gönderimi çalışmayabilir.', 'dikkat');
        }
        if ($veri['iban'] !== '' && !iban_gecerli($veri['iban'])) {
            mesaj('Dikkat: IBAN geçersiz görünüyor (TR ile başlayan 26 karakter olmalı). Kontrol edin.', 'dikkat');
        }
        yonlendir('index.php?p=soforler');
    }
    $d = array_merge($d ?: [], $veri);
}
$d = $d ?: ['ad' => '', 'soyad' => '', 'tc' => '', 'telefon' => '', 'cekici_plaka' => '', 'dorse_plaka' => '', 'iban' => '', 'notlar' => ''];
sayfa_bas($id ? 'Şoför Düzenle' : 'Yeni Şoför', 'soforler');
?>
<h1><?= $id ? 'Şoför Düzenle' : 'Yeni Şoför' ?></h1>
<?php if ($hatalar): ?><div class="uyari hata"><ul><?php foreach ($hatalar as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="kart form">
  <?= csrf_alan() ?>
  <div class="iki">
    <label>Ad *<input name="ad" required value="<?= h($d['ad']) ?>"></label>
    <label>Soyad<input name="soyad" value="<?= h($d['soyad']) ?>"></label>
    <label>TC Kimlik No<input name="tc" inputmode="numeric" maxlength="11" value="<?= h($d['tc']) ?>"></label>
    <label>Telefon (WhatsApp)<input name="telefon" type="tel" value="<?= h($d['telefon']) ?>" placeholder="0544 406 74 94"></label>
    <label>Çekici Plaka<input name="cekici_plaka" class="buyuk-harf" value="<?= h($d['cekici_plaka']) ?>" placeholder="45AAR75"></label>
    <label>Dorse Plaka<input name="dorse_plaka" class="buyuk-harf" value="<?= h($d['dorse_plaka']) ?>" placeholder="45AAR12"></label>
  </div>
  <label>IBAN (nakliye ödemesi için)<input name="iban" value="<?= h($d['iban']) ?>" placeholder="TR.."></label>
  <label>Notlar<textarea name="notlar" rows="2"><?= h($d['notlar']) ?></textarea></label>
  <p class="soluk kucuk-yazi">TC, telefon ve IBAN kişisel veridir (KVKK). Sadece iş için gerekli olanları girin.</p>
  <div class="butonlar">
    <button class="btn ana">💾 Kaydet</button>
    <a class="btn" href="index.php?p=soforler">Vazgeç</a>
  </div>
</form>
<?php if ($id): ?>
<form method="post" class="sil-form" onsubmit="return confirm('Şoför silinsin mi? (Geçmiş seferler etkilenmez, geri alınabilir)')">
  <?= csrf_alan() ?><input type="hidden" name="islem" value="sil">
  <button class="btn tehlike kucuk">🗑️ Şoförü Sil</button>
</form>
<?php endif; ?>
<?php sayfa_son();
