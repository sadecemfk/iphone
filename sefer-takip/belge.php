<?php
// Belgeyi gizli link (token) ile gösterir. Şoföre WhatsApp'tan gönderilen linkler buraya gelir.
// Giriş gerektirmez; link 32 haneli rastgele anahtar içerdiği için tahmin edilemez.
require __DIR__ . '/lib/app.php';

$t = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $t)) {
    http_response_code(404);
    die('Belge bulunamadı.');
}
$b = one('SELECT * FROM belgeler WHERE token = ?', [$t]);
if (!$b) {
    http_response_code(404);
    die('Belge bulunamadı.');
}
// Silinmiş belgeyi sadece giriş yapmış kullanıcı görebilir
if ($b['silindi']) {
    oturum_baslat();
    if (!aktif_kullanici()) {
        http_response_code(404);
        die('Bu belge kaldırılmış.');
    }
}
$yol = data_dir() . '/belgeler/' . $b['dosya'];
$gercek = realpath($yol);
if (!$gercek || strpos($gercek, realpath(data_dir() . '/belgeler')) !== 0 || !is_file($gercek)) {
    http_response_code(404);
    die('Dosya bulunamadı.');
}
$ad = $b['orijinal_ad'] ?: basename($b['dosya']);
$asciiAd = preg_replace('/[^A-Za-z0-9._-]/', '_', $ad);
header('Content-Type: ' . ($b['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($gercek));
header('Content-Disposition: ' . (isset($_GET['indir']) ? 'attachment' : 'inline') . '; filename="' . $asciiAd . '"; filename*=UTF-8\'\'' . rawurlencode($ad));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($gercek);
