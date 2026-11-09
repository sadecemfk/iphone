<?php
// Belge (irsaliye / kantar fişi PDF, fotoğraf) yükleme

const BELGE_UZANTILAR = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heic',
];

/** $_FILES içindeki çoklu dosya alanını düz listeye çevirir */
function dosya_listesi($alan)
{
    if (empty($_FILES[$alan])) {
        return [];
    }
    $f = $_FILES[$alan];
    if (!is_array($f['name'])) {
        return [$f];
    }
    $l = [];
    foreach ($f['name'] as $i => $n) {
        $l[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $l;
}

/**
 * Yüklenen dosyayı kaydeder.
 * @return string|null hata mesajı (başarılıysa null)
 */
function belge_kaydet($seferId, array $f, $tur)
{
    if ($f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        return $f['name'] . ': dosya çok büyük (sunucu sınırı ' . ini_get('upload_max_filesize') . ').';
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return $f['name'] . ': yüklenemedi (hata kodu ' . (int)$f['error'] . ').';
    }
    if ($f['size'] > cfg('max_upload_mb', 20) * 1024 * 1024) {
        return $f['name'] . ': en fazla ' . cfg('max_upload_mb', 20) . ' MB olabilir.';
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($fi, $f['tmp_name']);
        finfo_close($fi);
    }
    $uz = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset(BELGE_UZANTILAR[$mime])) {
        // finfo HEIC'i tanımayabilir: uzantıya bak
        if (in_array($uz, ['heic', 'heif'], true)) {
            $mime = 'image/heic';
        } elseif ($mime === '' && $uz === 'pdf') {
            $mime = 'application/pdf';
        } else {
            return $f['name'] . ': sadece PDF, JPG, PNG, WEBP, HEIC yüklenebilir.';
        }
    }
    $uz = BELGE_UZANTILAR[$mime];
    if (!isset(BELGE_TURLERI[$tur])) {
        $tur = 'diger';
    }
    $klasor = data_dir() . '/belgeler/' . (int)$seferId;
    if (!is_dir($klasor)) {
        mkdir($klasor, 0775, true);
    }
    $ad = date('Ymd-His') . '-' . $tur . '-' . bin2hex(random_bytes(4)) . '.' . $uz;
    if (!move_uploaded_file($f['tmp_name'], "$klasor/$ad")) {
        return $f['name'] . ': sunucuya kaydedilemedi.';
    }
    kayit_ekle('belgeler', [
        'sefer_id' => (int)$seferId,
        'tur' => $tur,
        'dosya' => (int)$seferId . '/' . $ad,
        'orijinal_ad' => mb_substr(basename($f['name']), 0, 200),
        'mime' => $mime,
        'boyut' => (int)$f['size'],
        'token' => bin2hex(random_bytes(16)),
        'olusturan' => aktif_kullanici_adi(),
    ]);
    return null;
}

/** Formdaki irsaliye / kantar / diğer alanlarını işler, hataları döner */
function belgeleri_isle($seferId)
{
    $hatalar = [];
    foreach (['belge_irsaliye' => 'irsaliye', 'belge_kantar' => 'kantar', 'belge_diger' => 'diger'] as $alan => $tur) {
        foreach (dosya_listesi($alan) as $f) {
            $e = belge_kaydet($seferId, $f, $tur);
            if ($e) {
                $hatalar[] = $e;
            }
        }
    }
    return $hatalar;
}
