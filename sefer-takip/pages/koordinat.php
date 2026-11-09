<?php
// Harita kısa linklerini (Google maps.app.goo.gl, Apple maps.apple, Yandex) çözüp koordinat döner. JSON API.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$url = get('url');

$k = koordinat_coz($url);
if ($k) {
    echo json_encode(['ok' => true, 'lat' => $k[0], 'lng' => $k[1]]);
    exit;
}

/** Sadece bilinen harita servislerine istek atılır (sunucu başka adreslere istek göndermez) */
function izinli_host($h)
{
    $h = strtolower((string)$h);
    $tam = ['maps.app.goo.gl', 'goo.gl', 'g.co', 'maps.apple', 'maps.apple.com', 'yandex.com.tr', 'yandex.com', 'yandex.ru', 'yandex.az', 'yandex.kz'];
    return in_array($h, $tam, true) || (bool)preg_match('/(^|\.)google\.(com|com\.tr)$/', $h);
}

$host = parse_url($url, PHP_URL_HOST);
if (!preg_match('#^https?://#i', $url) || !izinli_host($host)) {
    echo json_encode(['ok' => false, 'hata' => 'Koordinat bulunamadı. "enlem, boylam" şeklinde veya Google / Apple / Yandex harita linki girin.']);
    exit;
}
if (!function_exists('curl_init')) {
    echo json_encode(['ok' => false, 'hata' => 'Sunucu kısa linkleri çözemiyor (curl yok). Linki tarayıcıda açıp koordinatı kopyalayın.']);
    exit;
}

$sonUrl = $url;
$govde = '';
// Yönlendirmeleri tek tek takip et; her adımda adres izinli mi kontrol et
for ($i = 0; $i < 6; $i++) {
    if (!izinli_host(parse_url($sonUrl, PHP_URL_HOST))) {
        break;
    }
    $ch = curl_init($sonUrl);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
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

$k = koordinat_coz($sonUrl);
if (!$k && $govde !== '') {
    // Sayfa içindeki koordinat ipuçlarını dene
    if (preg_match('/!3d(-?\d{1,2}\.\d{4,})!4d(-?\d{1,3}\.\d{4,})/', $govde, $m)
        || preg_match('/@(-?\d{1,2}\.\d{4,}),(-?\d{1,3}\.\d{4,})/', $govde, $m)
        || preg_match('/center=(-?\d{1,2}\.\d{4,})%2C(-?\d{1,3}\.\d{4,})/', $govde, $m)
        || preg_match('/[?&;]ll=(-?\d{1,2}\.\d{4,})(?:,|%2C)(-?\d{1,3}\.\d{4,})/', $govde, $m)) {
        $k = [(float)$m[1], (float)$m[2]];
    }
}
echo json_encode($k
    ? ['ok' => true, 'lat' => $k[0], 'lng' => $k[1]]
    : ['ok' => false, 'hata' => 'Linkten koordinat okunamadı. Haritada noktaya basılı tutup çıkan koordinatı (ör. 40.81, 32.89) kopyalayıp yapıştırın.']);
