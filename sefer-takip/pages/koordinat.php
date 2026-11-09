<?php
// Google Maps kısa linklerini (maps.app.goo.gl) çözüp koordinat döner. JSON API.
header('Content-Type: application/json; charset=utf-8');
$url = trim((string)($_GET['url'] ?? ''));

$k = koordinat_coz($url);
if ($k) {
    echo json_encode(['ok' => true, 'lat' => $k[0], 'lng' => $k[1]]);
    exit;
}

$host = strtolower((string)parse_url($url, PHP_URL_HOST));
$izinli = ['maps.app.goo.gl', 'goo.gl', 'maps.google.com', 'www.google.com', 'google.com', 'g.co', 'www.google.com.tr', 'google.com.tr'];
if (!preg_match('#^https?://#i', $url) || !in_array($host, $izinli, true)) {
    echo json_encode(['ok' => false, 'hata' => 'Koordinat bulunamadı. "enlem, boylam" şeklinde veya Google Maps linki girin.']);
    exit;
}

$sonUrl = $url;
$govde = '';
if (function_exists('curl_init')) {
    // Yönlendirmeleri tek tek takip et; sadece Google alan adlarına izin ver
    for ($i = 0; $i < 6; $i++) {
        $h = strtolower((string)parse_url($sonUrl, PHP_URL_HOST));
        if (!in_array($h, $izinli, true) && !preg_match('/(^|\.)google\.(com|com\.tr)$/', $h)) {
            break;
        }
        $ch = curl_init($sonUrl);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SeferTakip)',
        ]);
        $govde = (string)curl_exec($ch);
        $yeni = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        if ($yeni === '') {
            break;
        }
        $sonUrl = $yeni;
        if (koordinat_coz($sonUrl)) {
            break;
        }
    }
}

$k = koordinat_coz($sonUrl);
if (!$k && $govde !== '') {
    // Sayfa içindeki ilk koordinat ipucunu dene
    if (preg_match('/@(-?\d{1,2}\.\d{4,}),(-?\d{1,3}\.\d{4,})/', $govde, $m)
        || preg_match('/!3d(-?\d{1,2}\.\d{4,})!4d(-?\d{1,3}\.\d{4,})/', $govde, $m)
        || preg_match('/center=(-?\d{1,2}\.\d{4,})%2C(-?\d{1,3}\.\d{4,})/', $govde, $m)) {
        $k = [(float)$m[1], (float)$m[2]];
    }
}
echo json_encode($k
    ? ['ok' => true, 'lat' => $k[0], 'lng' => $k[1]]
    : ['ok' => false, 'hata' => 'Linkten koordinat okunamadı. Google Maps\'te noktaya basılı tutup çıkan koordinatı (ör. 40.81, 32.89) kopyalayıp yapıştırın.']);
