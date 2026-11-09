<?php
// Belgeyi gizli link (token) ile gösterir. Şoföre WhatsApp'tan gönderilen linkler buraya gelir.
// Giriş gerektirmez; link 32 haneli rastgele anahtar içerdiği için tahmin edilemez.
// Belge sistemden "Kaldır"ılırsa link artık açılmaz.
require __DIR__ . '/lib/app.php';

https_zorla();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function belge_yok($mesaj)
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><body style="font-family:sans-serif;padding:24px"><h1 style="font-size:1.2rem">' . htmlspecialchars($mesaj) . '</h1></body>');
}

$t = isset($_GET['t']) && is_string($_GET['t']) ? $_GET['t'] : '';
if (!preg_match('/^[a-f0-9]{32}$/', $t)) {
    belge_yok('Belge bulunamadı.');
}
$b = one('SELECT * FROM belgeler WHERE token = ?', [$t]);
if (!$b) {
    belge_yok('Belge bulunamadı.');
}
// Kaldırılmış belgeyi sadece giriş yapmış kullanıcı görebilir
if ($b['silindi']) {
    oturum_baslat();
    oturum_dogrula();
    if (!aktif_kullanici()) {
        belge_yok('Bu belge kaldırılmış.');
    }
}
$kok = realpath(data_dir() . '/belgeler');
$gercek = realpath(data_dir() . '/belgeler/' . $b['dosya']);
if (!$gercek || !$kok || strpos($gercek, $kok . DIRECTORY_SEPARATOR) !== 0 || !is_file($gercek)) {
    belge_yok('Dosya bulunamadı.');
}
$ad = $b['orijinal_ad'] ?: basename($b['dosya']);
$asciiAd = preg_replace('/[^A-Za-z0-9._-]/', '_', $ad);
header('Content-Type: ' . ($b['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($gercek));
header('Content-Disposition: ' . (isset($_GET['indir']) ? 'attachment' : 'inline') . '; filename="' . $asciiAd . '"; filename*=UTF-8\'\'' . rawurlencode($ad));
header('Cache-Control: private, no-store');
readfile($gercek);
