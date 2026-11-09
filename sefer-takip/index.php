<?php
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/xlsx.php';
require __DIR__ . '/lib/rapor.php';
require __DIR__ . '/lib/belge.php';

https_zorla();
oturum_baslat();
guvenlik_basliklari();

$p = preg_replace('/[^a-z_]/', '', get('p', 'panel')) ?: 'panel';

// Sunucu sınırını aşan gönderim: PHP formu tamamen boşaltır (dosyalar çok büyük)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $sinir = yukleme_sinirlari();
    mesaj('Gönderilen dosyalar çok büyük (' . mb_fmt((int)$_SERVER['CONTENT_LENGTH']) . '). Sunucu sınırı: ' . mb_fmt($sinir['toplam'])
        . '. Bilgiler KAYDEDİLMEDİ. Formdaki bilgileri aşağıdaki "Taslağı geri yükle" ile geri getirip daha az / daha küçük dosyayla tekrar deneyin.', 'hata');
    yonlendir('index.php?' . http_build_query(get_hepsi()));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_kontrol();
}

acil_sifre_sifirlama();
oturum_dogrula();

// İlk kurulum: hiç kullanıcı yoksa
$kullaniciVar = (int)one('SELECT COUNT(*) c FROM kullanicilar')['c'] > 0;
if (!$kullaniciVar) {
    $p = 'kurulum';
} elseif (!aktif_kullanici()) {
    if ($p !== 'giris') {
        $p = 'giris';
    }
}

$sayfalar = [
    'kurulum', 'giris', 'cikis', 'panel',
    'seferler', 'sefer_form', 'sefer', 'excel',
    'depolar', 'depo_form', 'soforler', 'sofor_form', 'urunler', 'urun_form',
    'yedek', 'silinenler', 'kullanicilar', 'koordinat',
];
if (!in_array($p, $sayfalar, true)) {
    $p = 'panel';
}

// Sadece yöneticilerin açabileceği sayfalar
if ($p === 'yedek' && aktif_kullanici() && !yonetici_mi()) {
    yetkisiz();
}

require __DIR__ . '/pages/' . $p . '.php';
