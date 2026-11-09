<?php
// Çekirdek: ayarlar, veritabanı, oturum, yardımcı fonksiyonlar

$GLOBALS['CONFIG'] = require __DIR__ . '/../config.php';
date_default_timezone_set(cfg('timezone', 'Europe/Istanbul'));
mb_internal_encoding('UTF-8');

function cfg($k, $d = null)
{
    return array_key_exists($k, $GLOBALS['CONFIG']) ? $GLOBALS['CONFIG'][$k] : $d;
}

/* ------------------------------------------------------------------ */
/* Klasörler                                                          */
/* ------------------------------------------------------------------ */

function data_dir()
{
    static $hazir = false;
    $d = rtrim(cfg('data_dir'), '/');
    if (!$hazir) {
        foreach ([$d, "$d/belgeler", "$d/yedekler"] as $x) {
            if (!is_dir($x)) {
                @mkdir($x, 0775, true);
            }
        }
        // Web üzerinden doğrudan erişimi engelle
        if (!file_exists("$d/.htaccess")) {
            @file_put_contents("$d/.htaccess", "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        if (!file_exists("$d/index.html")) {
            @file_put_contents("$d/index.html", '');
        }
        if (!is_writable($d)) {
            die('Veri klasörü yazılabilir değil: ' . htmlspecialchars($d) . ' (klasör izinlerini 775 yapın)');
        }
        $hazir = true;
    }
    return $d;
}

function db_path()
{
    return data_dir() . '/veritabani.sqlite';
}

/* ------------------------------------------------------------------ */
/* Veritabanı                                                         */
/* ------------------------------------------------------------------ */

function db()
{
    if (!empty($GLOBALS['__pdo'])) {
        return $GLOBALS['__pdo'];
    }
    $pdo = new PDO('sqlite:' . db_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA synchronous = FULL');
    $GLOBALS['__pdo'] = $pdo;
    db_migrate($pdo);
    return $pdo;
}

function db_close()
{
    $GLOBALS['__pdo'] = null;
    gc_collect_cycles();
}

function db_migrate(PDO $pdo)
{
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v < 1) {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS kullanicilar (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kullanici_adi TEXT NOT NULL UNIQUE,
            ad TEXT,
            sifre_hash TEXT NOT NULL,
            olusturma TEXT
        );
        CREATE TABLE IF NOT EXISTS depolar (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tip TEXT NOT NULL CHECK (tip IN ('yukleme','bosaltim')),
            ad TEXT NOT NULL,
            firma TEXT,
            il TEXT,
            ilce TEXT,
            adres TEXT,
            lat REAL,
            lng REAL,
            yetkili TEXT,
            telefon TEXT,
            notlar TEXT,
            silindi INTEGER NOT NULL DEFAULT 0,
            olusturma TEXT,
            guncelleme TEXT
        );
        CREATE TABLE IF NOT EXISTS soforler (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ad TEXT NOT NULL,
            soyad TEXT,
            tc TEXT,
            telefon TEXT,
            cekici_plaka TEXT,
            dorse_plaka TEXT,
            iban TEXT,
            notlar TEXT,
            silindi INTEGER NOT NULL DEFAULT 0,
            olusturma TEXT,
            guncelleme TEXT
        );
        CREATE TABLE IF NOT EXISTS urunler (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ad TEXT NOT NULL,
            ton_fiyat REAL NOT NULL DEFAULT 0,
            kdv_orani REAL NOT NULL DEFAULT 20,
            notlar TEXT,
            silindi INTEGER NOT NULL DEFAULT 0,
            olusturma TEXT,
            guncelleme TEXT
        );
        CREATE TABLE IF NOT EXISTS seferler (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tarih TEXT NOT NULL,
            teslim_tarihi TEXT,
            durum TEXT NOT NULL DEFAULT 'planlandi',
            yukleme_depo_id INTEGER REFERENCES depolar(id),
            bosaltim_depo_id INTEGER REFERENCES depolar(id),
            sofor_id INTEGER REFERENCES soforler(id),
            sofor_telefon TEXT,
            cekici_plaka TEXT,
            dorse_plaka TEXT,
            urun_id INTEGER REFERENCES urunler(id),
            miktar_kg REAL NOT NULL DEFAULT 0,
            urun_ton_fiyat REAL NOT NULL DEFAULT 0,
            urun_kdv REAL NOT NULL DEFAULT 0,
            nakliye_ton_fiyat REAL NOT NULL DEFAULT 0,
            nakliye_kdv REAL NOT NULL DEFAULT 0,
            nakliye_odendi INTEGER NOT NULL DEFAULT 0,
            irsaliye_no TEXT,
            kantar_fis_no TEXT,
            notlar TEXT,
            silindi INTEGER NOT NULL DEFAULT 0,
            olusturan TEXT,
            olusturma TEXT,
            guncelleme TEXT
        );
        CREATE INDEX IF NOT EXISTS ix_sefer_tarih ON seferler(tarih);
        CREATE INDEX IF NOT EXISTS ix_sefer_urun ON seferler(urun_id);
        CREATE TABLE IF NOT EXISTS belgeler (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sefer_id INTEGER NOT NULL REFERENCES seferler(id),
            tur TEXT NOT NULL DEFAULT 'diger',
            dosya TEXT NOT NULL,
            orijinal_ad TEXT,
            mime TEXT,
            boyut INTEGER,
            token TEXT NOT NULL UNIQUE,
            silindi INTEGER NOT NULL DEFAULT 0,
            olusturan TEXT,
            olusturma TEXT
        );
        CREATE INDEX IF NOT EXISTS ix_belge_sefer ON belgeler(sefer_id);
        CREATE TABLE IF NOT EXISTS islem_gecmisi (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            zaman TEXT NOT NULL,
            kullanici TEXT,
            tablo TEXT NOT NULL,
            kayit_id INTEGER,
            islem TEXT NOT NULL,
            eski TEXT,
            yeni TEXT
        );
        CREATE INDEX IF NOT EXISTS ix_gecmis ON islem_gecmisi(tablo, kayit_id);
        PRAGMA user_version = 1;
        ");
    }
}

function q($sql, array $p = [])
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}

function one($sql, array $p = [])
{
    $r = q($sql, $p)->fetch();
    return $r ?: null;
}

function all($sql, array $p = [])
{
    return q($sql, $p)->fetchAll();
}

function simdi()
{
    return date('Y-m-d H:i:s');
}

/** Kayıt ekler, geçmişe yazar, id döner */
function kayit_ekle($tablo, array $veri)
{
    otomatik_yedek();
    $veri['olusturma'] = simdi();
    if (in_array($tablo, ['depolar', 'soforler', 'urunler', 'seferler'], true)) {
        $veri['guncelleme'] = $veri['olusturma'];
    }
    $k = array_keys($veri);
    q("INSERT INTO $tablo (" . implode(',', $k) . ') VALUES (' . implode(',', array_fill(0, count($k), '?')) . ')', array_values($veri));
    $id = (int)db()->lastInsertId();
    gecmis_yaz($tablo, $id, 'ekle', null, $veri);
    return $id;
}

/** Kaydı günceller; sadece değişen alanları geçmişe yazar */
function kayit_guncelle($tablo, $id, array $veri, $islem = 'duzenle')
{
    $eski = one("SELECT * FROM $tablo WHERE id = ?", [$id]);
    if (!$eski) {
        return false;
    }
    $eskiD = [];
    $yeniD = [];
    foreach ($veri as $k => $v) {
        $a = $eski[$k] ?? null;
        if ((string)$a !== (string)$v) {
            $eskiD[$k] = $a;
            $yeniD[$k] = $v;
        }
    }
    if (!$yeniD) {
        return true;
    }
    otomatik_yedek();
    if (in_array($tablo, ['depolar', 'soforler', 'urunler', 'seferler'], true)) {
        $veri['guncelleme'] = simdi();
    }
    $set = implode(',', array_map(function ($k) {
        return "$k = ?";
    }, array_keys($veri)));
    $p = array_values($veri);
    $p[] = $id;
    q("UPDATE $tablo SET $set WHERE id = ?", $p);
    gecmis_yaz($tablo, $id, $islem, $eskiD, $yeniD);
    return true;
}

function gecmis_yaz($tablo, $id, $islem, $eski, $yeni)
{
    unset($eski['sifre_hash'], $yeni['sifre_hash']);
    q('INSERT INTO islem_gecmisi (zaman, kullanici, tablo, kayit_id, islem, eski, yeni) VALUES (?,?,?,?,?,?,?)', [
        simdi(),
        aktif_kullanici_adi(),
        $tablo,
        $id,
        $islem,
        $eski === null ? null : json_encode($eski, JSON_UNESCAPED_UNICODE),
        $yeni === null ? null : json_encode($yeni, JSON_UNESCAPED_UNICODE),
    ]);
}

/* ------------------------------------------------------------------ */
/* Yedekleme                                                          */
/* ------------------------------------------------------------------ */

/** Veritabanının tutarlı bir kopyasını $hedef dosyaya yazar */
function db_kopyala($hedef)
{
    if (file_exists($hedef)) {
        @unlink($hedef);
    }
    try {
        db()->exec('VACUUM INTO ' . db()->quote($hedef));
    } catch (Exception $e) {
        copy(db_path(), $hedef);
    }
    return file_exists($hedef);
}

/** Her gün ilk değişiklikten ÖNCE veritabanının kopyasını alır */
function otomatik_yedek()
{
    static $yapildi = false;
    if ($yapildi || !file_exists(db_path())) {
        return;
    }
    $yapildi = true;
    // Boş (yeni kurulmuş) veritabanının yedeğini alma
    if ((int)db()->query('SELECT COUNT(*) FROM kullanicilar')->fetchColumn() === 0) {
        return;
    }
    $dosya = data_dir() . '/yedekler/oto-' . date('Y-m-d') . '.sqlite';
    if (!file_exists($dosya)) {
        db_kopyala($dosya);
        // Eski otomatik yedekleri temizle
        $sinir = time() - 86400 * (int)cfg('backup_keep_days', 180);
        foreach (glob(data_dir() . '/yedekler/oto-*.sqlite') as $f) {
            if (filemtime($f) < $sinir) {
                @unlink($f);
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Oturum / güvenlik                                                  */
/* ------------------------------------------------------------------ */

function oturum_baslat()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('sefertakip');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
    $sd = data_dir() . '/oturumlar';
    if (!is_dir($sd)) {
        @mkdir($sd, 0775, true);
    }
    if (is_writable($sd)) {
        session_save_path($sd);
    }
    session_start();
}

function aktif_kullanici()
{
    return $_SESSION['kullanici'] ?? null;
}

function aktif_kullanici_adi()
{
    $k = aktif_kullanici();
    return $k ? $k['kullanici_adi'] : null;
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_alan()
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_kontrol()
{
    if (empty($_POST['csrf']) || !hash_equals(csrf_token(), (string)$_POST['csrf'])) {
        http_response_code(400);
        die('Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
    }
}

/* ------------------------------------------------------------------ */
/* Yardımcılar                                                        */
/* ------------------------------------------------------------------ */

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function yonlendir($url)
{
    header('Location: ' . $url);
    exit;
}

function mesaj($metin, $tur = 'ok')
{
    $_SESSION['mesajlar'][] = [$tur, $metin];
}

function post($k, $d = '')
{
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d;
}

function get($k, $d = '')
{
    return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}

/** "27.000", "27000", "9.000,50", "9000.5" → float */
function sayi($s)
{
    $s = str_replace([' ', '₺', 'TL', 'tl', "\xc2\xa0"], '', (string)$s);
    if ($s === '') {
        return 0.0;
    }
    if (strpos($s, ',') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
        $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? (float)$s : 0.0;
}

function para($n, $ond = 2)
{
    return number_format((float)$n, $ond, ',', '.');
}

function tl($n)
{
    return para($n) . ' ₺';
}

function kg_fmt($n)
{
    return number_format((float)$n, (floor((float)$n) == (float)$n) ? 0 : 2, ',', '.');
}

/** Form alanında gösterim: 27000 → "27000", 9000.5 → "9000,5" */
function sayi_input($n)
{
    if ($n === null || $n === '') {
        return '';
    }
    $n = (float)$n;
    $s = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    return str_replace('.', ',', $s);
}

function tarih_tr($t)
{
    if (!$t) {
        return '';
    }
    $ts = strtotime($t);
    return $ts ? date(strlen($t) > 10 ? 'd.m.Y H:i' : 'd.m.Y', $ts) : $t;
}

function tarih_gecerli($t)
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && strtotime($t) !== false;
}

function plaka($s)
{
    return mb_strtoupper(preg_replace('/\s+/', '', (string)$s), 'UTF-8');
}

/** 0544 406 74 94 / 5444067494 / +90 544... → 905444067494 */
function telefon_wa($tel)
{
    $d = preg_replace('/\D+/', '', (string)$tel);
    if ($d === '') {
        return '';
    }
    if (strpos($d, '00') === 0) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 11 && $d[0] === '0') {
        $d = '90' . substr($d, 1);
    } elseif (strlen($d) === 10 && $d[0] === '5') {
        $d = '90' . $d;
    }
    return $d;
}

function wa_link($tel, $metin)
{
    $t = telefon_wa($tel);
    return 'https://wa.me/' . $t . '?text=' . rawurlencode($metin);
}

function harita_link($lat, $lng)
{
    if ($lat === null || $lat === '' || $lng === null || $lng === '') {
        return '';
    }
    return 'https://www.google.com/maps/search/?api=1&query=' . koordinat_fmt($lat) . ',' . koordinat_fmt($lng);
}

function yol_tarifi_link($lat, $lng)
{
    if ($lat === null || $lat === '' || $lng === null || $lng === '') {
        return '';
    }
    return 'https://www.google.com/maps/dir/?api=1&destination=' . koordinat_fmt($lat) . ',' . koordinat_fmt($lng);
}

function koordinat_fmt($x)
{
    return rtrim(rtrim(number_format((float)$x, 7, '.', ''), '0'), '.');
}

/**
 * Metinden koordinat çıkarır: "41.01, 28.97", Google Maps linkleri (@lat,lng / q= / query= / !3d!4d)
 * @return array|null [lat, lng]
 */
function koordinat_coz($s)
{
    $s = trim(urldecode((string)$s));
    $desen = [
        '/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/',
        '/@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/',
        '/[?&](?:q|query|ll|destination|daddr|center)=(?:loc:)?(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/',
        '/^\s*(-?\d{1,2}(?:[.,]\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:[.,]\d+)?)\s*$/',
        '/(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/',
    ];
    foreach ($desen as $d) {
        if (preg_match($d, $s, $m)) {
            $lat = (float)str_replace(',', '.', $m[1]);
            $lng = (float)str_replace(',', '.', $m[2]);
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                return [$lat, $lng];
            }
        }
    }
    return null;
}

function base_url()
{
    $b = rtrim((string)cfg('base_url', ''), '/');
    if ($b !== '') {
        return $b;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function belge_link($token)
{
    return base_url() . '/belge.php?t=' . rawurlencode($token);
}

const DURUMLAR = [
    'planlandi' => 'Planlandı',
    'yuklendi' => 'Yüklendi / Yolda',
    'teslim' => 'Teslim Edildi',
    'iptal' => 'İptal',
];

const BELGE_TURLERI = [
    'irsaliye' => 'İrsaliye',
    'kantar' => 'Kantar Fişi',
    'diger' => 'Diğer',
];

/** Sefer tutar hesapları */
function hesap(array $s)
{
    $ton = (float)$s['miktar_kg'] / 1000;
    $mal = $ton * (float)$s['urun_ton_fiyat'];
    $malKdv = $mal * (float)$s['urun_kdv'] / 100;
    $nak = $ton * (float)$s['nakliye_ton_fiyat'];
    $nakKdv = $nak * (float)$s['nakliye_kdv'] / 100;
    return [
        'ton' => $ton,
        'mal' => $mal,
        'mal_kdv' => $malKdv,
        'mal_toplam' => $mal + $malKdv,
        'nak' => $nak,
        'nak_kdv' => $nakKdv,
        'nak_toplam' => $nak + $nakKdv,
    ];
}

function depo_etiket($d)
{
    if (!$d) {
        return '';
    }
    $yer = trim(implode(' / ', array_filter([$d['ilce'] ?? '', $d['il'] ?? ''])));
    return $d['ad'] . ($yer ? " ($yer)" : '');
}

/** Sefer + ilişkili bilgileri tek satırda getiren SQL */
function sefer_sql()
{
    return "SELECT s.*,
        yd.ad AS ydepo_ad, yd.il AS ydepo_il, yd.ilce AS ydepo_ilce, yd.adres AS ydepo_adres, yd.lat AS ydepo_lat, yd.lng AS ydepo_lng,
        bd.ad AS bdepo_ad, bd.il AS bdepo_il, bd.ilce AS bdepo_ilce, bd.adres AS bdepo_adres, bd.lat AS bdepo_lat, bd.lng AS bdepo_lng,
        so.ad AS sofor_ad, so.soyad AS sofor_soyad, so.tc AS sofor_tc,
        u.ad AS urun_ad,
        (SELECT COUNT(*) FROM belgeler b WHERE b.sefer_id = s.id AND b.silindi = 0) AS belge_sayisi
      FROM seferler s
      LEFT JOIN depolar yd ON yd.id = s.yukleme_depo_id
      LEFT JOIN depolar bd ON bd.id = s.bosaltim_depo_id
      LEFT JOIN soforler so ON so.id = s.sofor_id
      LEFT JOIN urunler u ON u.id = s.urun_id";
}

function sofor_adsoyad($s)
{
    return trim(($s['sofor_ad'] ?? '') . ' ' . ($s['sofor_soyad'] ?? ''));
}

/** Şoföre gidecek WhatsApp mesajı */
function wa_mesaj(array $s, $konum = true, $belge = true)
{
    $sat = [];
    $ad = sofor_adsoyad($s);
    $sat[] = 'Merhaba' . ($ad ? ' ' . $ad : '') . ',';
    $sat[] = '';
    $sat[] = '*Sefer No:* ' . $s['id'] . ' - ' . tarih_tr($s['tarih']);
    if ($s['urun_ad']) {
        $sat[] = '*Ürün:* ' . $s['urun_ad'] . ($s['miktar_kg'] > 0 ? ' - ' . kg_fmt($s['miktar_kg']) . ' kg' : '');
    }
    $arac = trim($s['cekici_plaka'] . ($s['dorse_plaka'] ? ' / ' . $s['dorse_plaka'] : ''));
    if ($arac) {
        $sat[] = '*Araç:* ' . $arac;
    }
    if ($konum) {
        $sat[] = '';
        $sat[] = '📍 *YÜKLEME YERİ:* ' . depo_etiket(['ad' => $s['ydepo_ad'], 'il' => $s['ydepo_il'], 'ilce' => $s['ydepo_ilce']]);
        if ($s['ydepo_adres']) {
            $sat[] = $s['ydepo_adres'];
        }
        $l = harita_link($s['ydepo_lat'], $s['ydepo_lng']);
        $sat[] = $l ?: '(konum girilmemiş)';
        $sat[] = '';
        $sat[] = '🏁 *BOŞALTIM YERİ:* ' . depo_etiket(['ad' => $s['bdepo_ad'], 'il' => $s['bdepo_il'], 'ilce' => $s['bdepo_ilce']]);
        if ($s['bdepo_adres']) {
            $sat[] = $s['bdepo_adres'];
        }
        $l = harita_link($s['bdepo_lat'], $s['bdepo_lng']);
        $sat[] = $l ?: '(konum girilmemiş)';
    }
    if ($belge) {
        $belgeler = all('SELECT * FROM belgeler WHERE sefer_id = ? AND silindi = 0 ORDER BY id', [$s['id']]);
        if ($belgeler) {
            $sat[] = '';
            $sat[] = '📄 *BELGELER:*';
            foreach ($belgeler as $b) {
                $no = '';
                if ($b['tur'] === 'irsaliye' && $s['irsaliye_no']) {
                    $no = ' (No: ' . $s['irsaliye_no'] . ')';
                }
                if ($b['tur'] === 'kantar' && $s['kantar_fis_no']) {
                    $no = ' (No: ' . $s['kantar_fis_no'] . ')';
                }
                $sat[] = (BELGE_TURLERI[$b['tur']] ?? 'Belge') . $no . ': ' . belge_link($b['token']);
            }
        }
    }
    $sat[] = '';
    $sat[] = 'İyi yolculuklar.';
    return implode("\n", $sat);
}
