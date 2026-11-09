<?php
// Yönetici ayarları (veritabanında saklanır; config.php varsayılanlarının yerine geçer)
$hatalar = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tol = sayi_alani('fire_tolerans_yuzde', 'Fire toleransı', $hatalar, 0, 100);
    $mal = post('varsayilan_mal_esas');
    $nak = post('varsayilan_nakliye_esas');
    if (!isset(ESASLAR[$mal]) || !isset(ESASLAR[$nak])) {
        $hatalar[] = 'Geçersiz hesap esası.';
    }
    if (!$hatalar) {
        $yeni = [
            'firma_adi' => mb_substr(post('firma_adi'), 0, 120),
            'firma_bilgi' => mb_substr(post('firma_bilgi'), 0, 500),
            'varsayilan_mal_esas' => $mal,
            'varsayilan_nakliye_esas' => $nak,
            'fire_tolerans_yuzde' => (string)$tol,
        ];
        $eski = [];
        $degisen = [];
        foreach ($yeni as $k => $v) {
            $onceki = (string)ayar_deger($k);
            if ($onceki !== (string)$v) {
                $eski[$k] = $onceki;
                $degisen[$k] = $v;
            }
            ayar_yaz($k, $v);
        }
        if ($degisen) {
            gecmis_yaz('sistem', 0, 'ayar_degisti', $eski, $degisen);
        }
        mesaj('Ayarlar kaydedildi. Hesap esası değişikliği sadece YENİ seferlere uygulanır; mevcut seferler kendi seçimini korur.');
        yonlendir('index.php?p=ayarlar');
    }
}

$deger = function ($k) {
    return $_SERVER['REQUEST_METHOD'] === 'POST' ? post($k) : (string)ayar_deger($k);
};
$esasSec = function ($ad, $secili) {
    $o = '<select name="' . $ad . '">';
    foreach (ESASLAR as $k => $v) {
        $o .= '<option value="' . $k . '"' . ($secili === $k ? ' selected' : '') . '>' . h($v) . '</option>';
    }
    return $o . '</select>';
};
sayfa_bas('Ayarlar', 'ayarlar');
?>
<h1>⚙️ Ayarlar</h1>
<?php if ($hatalar): ?><div class="uyari hata"><ul><?php foreach ($hatalar as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="form">
  <?= csrf_alan() ?>
  <div class="kart">
    <h2>Fiş Başlığı</h2>
    <label>Firma adı (Sefer Fişi'nin üstünde görünür)<input name="firma_adi" value="<?= h($deger('firma_adi')) ?>" placeholder="ör. Örnek Lojistik Ltd. Şti."></label>
    <label>Firma bilgisi (adres, telefon, vergi no…)<textarea name="firma_bilgi" rows="3"><?= h($deger('firma_bilgi')) ?></textarea></label>
  </div>
  <div class="kart">
    <h2>Kantar ve Ücret Hesabı</h2>
    <p class="soluk">Yeni seferlerde ücretin hangi kantar ağırlığına göre hesaplanacağı. Her seferde ayrıca değiştirilebilir.</p>
    <div class="iki">
      <label>Mal bedeli varsayılan esası<?= $esasSec('varsayilan_mal_esas', $deger('varsayilan_mal_esas') ?: 'yukleme') ?></label>
      <label>Nakliye ücreti varsayılan esası<?= $esasSec('varsayilan_nakliye_esas', $deger('varsayilan_nakliye_esas') ?: 'yukleme') ?></label>
    </div>
    <label>Fire toleransı (%) — yükleme ile boşaltım kantarı farkı bunu aşarsa uyarı verilir
      <input name="fire_tolerans_yuzde" inputmode="decimal" value="<?= h($_SERVER['REQUEST_METHOD'] === 'POST' ? post('fire_tolerans_yuzde') : sayi_input(fire_toleransi())) ?>">
    </label>
    <p class="soluk kucuk-yazi">"Hangisi düşükse": yükleme ve boşaltım kantarından küçük olan esas alınır (fire varsa boşaltım, fazla çıktıysa yükleme).
      Hangi esasın kullanılacağı alıcı / taşıyıcı ile yaptığınız anlaşmaya bağlıdır.</p>
  </div>
  <div class="butonlar"><button class="btn ana">💾 Kaydet</button></div>
</form>
<?php sayfa_son();
