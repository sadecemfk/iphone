<?php
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/xlsx.php';
require __DIR__ . '/lib/rapor.php';
require __DIR__ . '/lib/belge.php';

oturum_baslat();
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$p = preg_replace('/[^a-z_]/', '', get('p', 'panel')) ?: 'panel';

// İlk kurulum: hiç kullanıcı yoksa
$kullaniciVar = (int)one('SELECT COUNT(*) c FROM kullanicilar')['c'] > 0;
if (!$kullaniciVar) {
    $p = 'kurulum';
} elseif (!aktif_kullanici() && $p !== 'giris') {
    $p = 'giris';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_kontrol();
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
require __DIR__ . '/pages/' . $p . '.php';
