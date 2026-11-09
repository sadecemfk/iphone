<?php
// Çekirdek: ayarlar, veritabanı, oturum, güvenlik, yardımcı fonksiyonlar

/* ------------------------------------------------------------------ */
/* Sunucu gereksinimleri (mb_* fonksiyonlarından önce kontrol edilir)  */
/* ------------------------------------------------------------------ */

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:2em"><h1>PHP sürümü eski</h1><p>Sefer Takip için PHP 7.4 veya üzeri gerekir (8.1+ önerilir). Hosting panelinden PHP sürümünü yükseltin.</p>');
}
foreach (['pdo_sqlite' => 'PDO SQLite (pdo_sqlite)', 'mbstring' => 'mbstring'] as $__eklenti => $__ad) {
    if (!extension_loaded($__eklenti)) {
        http_response_code(500);
        exit('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:2em"><h1>Sunucu gereksinimi eksik</h1><p>PHP <b>' . $__ad . '</b> eklentisi etkin değil. Hosting panelinizde "PHP Seçenekleri / Select PHP Version" bölümünden etkinleştirin.</p>');
    }
}
unset($__eklenti, $__ad);

/* ------------------------------------------------------------------ */
/* Hata yönetimi: ziyaretçiye ayrıntı gösterme, dosyaya yaz            */
/* ------------------------------------------------------------------ */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function hata_sayfasi_goster($kod)
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Hata</title><body style="font-family:-apple-system,Segoe UI,sans-serif;padding:24px;max-width:560px;margin:auto">'
        . '<h1 style="font-size:1.3rem">Bir hata oluştu</h1>'
        . '<p>İşlem tamamlanamadı. Sayfayı yenileyip tekrar deneyin. Sorun sürerse yöneticiye şu kodu iletin: <b>' . htmlspecialchars($kod) . '</b></p>'
        . '<p><a href="index.php">Ana sayfaya dön</a></p></body>';
}

set_exception_handler(function ($e) {
    $kod = bin2hex(random_bytes(4));
    error_log('[' . $kod . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    hata_sayfasi_goster($kod);
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $kod = bin2hex(random_bytes(4));
        error_log('[' . $kod . '] ÖLÜMCÜL: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        hata_sayfasi_goster($kod);
    }
});

/* ------------------------------------------------------------------ */
/* Ayarlar                                                            */
/* ------------------------------------------------------------------ */

$GLOBALS['CONFIG'] = require __DIR__ . '/../config.php';
date_default_timezone_set(cfg('timezone', 'Europe/Istanbul'));
mb_internal_encoding('UTF-8');

function cfg($k, $d = null)
{
    return array_key_exists($k, $GLOBALS['CONFIG']) ? $GLOBALS['CONFIG'][$k] : $d;
}

/** Uygulamanın kurulu olduğu klasör (sunucu yolu) */
function uygulama_dizini()
{
    return dirname(__DIR__);
}

/* ------------------------------------------------------------------ */
/* Veri klasörü                                                       */
/* ------------------------------------------------------------------ */

function data_dir()
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    $yol = rtrim((string)cfg('data_dir'), '/\\');
    foreach ([$yol, "$yol/belgeler", "$yol/yedekler"] as $x) {
        if (!is_dir($x)) {
            @mkdir($x, 0775, true);
        }
    }
    if ($yol === '' || !is_dir($yol) || !is_writable($yol)) {
        http_response_code(500);
        exit('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:2em"><h1>Veri klasörü kullanılamıyor</h1>'
            . '<p>Veri klasörü yok veya yazılabilir değil. <b>config.local.php</b> içindeki <b>data_dir</b> ayarını ve klasör izinlerini (775) kontrol edin.</p>');
    }
    // Web sunucusu klasörü yayınlasa bile içeriği vermesin
    if (!is_file("$yol/.htaccess")) {
        @file_put_contents("$yol/.htaccess", "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    foreach (['', '/belgeler', '/yedekler'] as $alt) {
        if (!is_file("$yol$alt/index.html")) {
            @file_put_contents("$yol$alt/index.html", '');
        }
    }
    // Erişim testi dosyası: bu dosya tarayıcıdan okunabiliyorsa veri klasörü internete açık demektir
    if (!is_file("$yol/erisim-testi.txt")) {
        @file_put_contents("$yol/erisim-testi.txt", 'SEFERTAKIP-' . bin2hex(random_bytes(8)));
    }
    // Hata kaydı
    $log = "$yol/hata.log";
    if (is_file($log) && filesize($log) > 5 * 1024 * 1024) {
        @rename($log, $log . '.1');
    }
    ini_set('error_log', $log);
    $d = $yol;
    return $d;
}

function db_path()
{
    return data_dir() . '/veritabani.sqlite';
}

/** Kuruluma özel gizli değer (oturum klasörü adı vb. için) */
function kurulum_gizli()
{
    static $g = null;
    if ($g !== null) {
        return $g;
    }
    $f = data_dir() . '/.gizli';
    $g = is_file($f) ? trim((string)file_get_contents($f)) : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $g)) {
        $g = bin2hex(random_bytes(16));
        @file_put_contents($f, $g);
        @chmod($f, 0640);
    }
    return $g;
}

/** Veri klasörü uygulama (web) klasörünün içinde mi? İçindeyse göreli yolunu döner. */
function data_dir_web_icinde()
{
    $uyg = realpath(uygulama_dizini());
    $veri = realpath(data_dir());
    if (!$uyg || !$veri) {
        return null;
    }
    $uyg = rtrim(str_replace('\\', '/', $uyg), '/') . '/';
    $veri = rtrim(str_replace('\\', '/', $veri), '/') . '/';
    return strpos($veri, $uyg) === 0 ? trim(substr($veri, strlen($uyg)), '/') : null;
}

/* ------------------------------------------------------------------ */
/* Veritabanı                                                         */
/* ------------------------------------------------------------------ */

const TABLOLAR = ['kullanicilar', 'depolar', 'soforler', 'urunler', 'seferler', 'belgeler'];
const GUNCELLEME_KOLONLU = ['depolar', 'soforler', 'urunler', 'seferler'];

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
    // Türkçe büyük/küçük harf duyarsız arama için
    $GLOBALS['__tr_kucuk_sql'] = false;
    if (method_exists($pdo, 'sqliteCreateFunction')) {
        $GLOBALS['__tr_kucuk_sql'] = $pdo->sqliteCreateFunction('tr_kucuk', function ($s) {
            return $s === null ? null : tr_kucuk((string)$s);
        }, 1, defined('PDO::SQLITE_DETERMINISTIC') ? PDO::SQLITE_DETERMINISTIC : 0);
    }
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
    $hedef = 2;
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= $hedef) {
        return;
    }
    // Aynı anda iki istek gelirse ikincisi beklesin (BEGIN IMMEDIATE)
    $pdo->exec('BEGIN IMMEDIATE');
    try {
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
            ");
        }
        if ($v < 2) {
            // Şema v2: kullanıcı yetkileri, giriş denemesi kilidi, sistem ayarları
            $kolonlar = array_column($pdo->query('PRAGMA table_info(kullanicilar)')->fetchAll(), 'name');
            $ekle = [
                'yonetici' => 'INTEGER NOT NULL DEFAULT 0',
                'aktif' => 'INTEGER NOT NULL DEFAULT 1',
                'oturum_surumu' => 'INTEGER NOT NULL DEFAULT 1',
                'son_giris' => 'TEXT',
            ];
            foreach ($ekle as $kolon => $tanim) {
                if (!in_array($kolon, $kolonlar, true)) {
                    $pdo->exec("ALTER TABLE kullanicilar ADD COLUMN $kolon $tanim");
                }
            }
            // Mevcut kurulumda ilk kullanıcı yönetici olur
            $pdo->exec('UPDATE kullanicilar SET yonetici = 1 WHERE id = (SELECT MIN(id) FROM kullanicilar) AND NOT EXISTS (SELECT 1 FROM kullanicilar WHERE yonetici = 1)');
            $pdo->exec("
            CREATE TABLE IF NOT EXISTS giris_denemeleri (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zaman INTEGER NOT NULL,
                ip TEXT,
                kullanici_adi TEXT,
                basarili INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS ix_giris_ip ON giris_denemeleri(ip, zaman);
            CREATE INDEX IF NOT EXISTS ix_giris_kullanici ON giris_denemeleri(kullanici_adi, zaman);
            CREATE TABLE IF NOT EXISTS ayarlar (
                anahtar TEXT PRIMARY KEY,
                deger TEXT
            );
            ");
        }
        $pdo->exec('PRAGMA user_version = ' . $hedef);
        $pdo->exec('COMMIT');
    } catch (Exception $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
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

function tablo_dogrula($tablo)
{
    if (!in_array($tablo, TABLOLAR, true)) {
        throw new InvalidArgumentException('Geçersiz tablo: ' . $tablo);
    }
}

/**
 * İki değer aynı mı? Kodun sayıya çevirdiği alanlar (int/float) sayısal karşılaştırılır
 * (PHP 7.4/8.0'da SQLite "27000.0" döndürür). Telefon, TC gibi metinler metin olarak karşılaştırılır.
 */
function deger_esit($eski, $yeni)
{
    $eskiBos = $eski === null || $eski === '';
    $yeniBos = $yeni === null || $yeni === '';
    if ($eskiBos || $yeniBos) {
        return $eskiBos && $yeniBos;
    }
    if ((is_int($yeni) || is_float($yeni)) && is_numeric($eski)) {
        return abs((float)$eski - (float)$yeni) < 1e-9;
    }
    return (string)$eski === (string)$yeni;
}

/** Kayıt ekler, geçmişe yazar, id döner */
function kayit_ekle($tablo, array $veri)
{
    tablo_dogrula($tablo);
    otomatik_yedek();
    $veri['olusturma'] = simdi();
    if (in_array($tablo, GUNCELLEME_KOLONLU, true)) {
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
    tablo_dogrula($tablo);
    $eski = one("SELECT * FROM $tablo WHERE id = ?", [$id]);
    if (!$eski) {
        return false;
    }
    $eskiD = [];
    $yeniD = [];
    foreach ($veri as $k => $v) {
        $a = $eski[$k] ?? null;
        if (!deger_esit($a, $v)) {
            $eskiD[$k] = $a;
            $yeniD[$k] = $v;
        }
    }
    if (!$yeniD) {
        return true;
    }
    otomatik_yedek();
    if (in_array($tablo, GUNCELLEME_KOLONLU, true)) {
        $yeniD['guncelleme'] = simdi();
    }
    $set = implode(',', array_map(function ($k) {
        return "$k = ?";
    }, array_keys($yeniD)));
    $p = array_values($yeniD);
    $p[] = $id;
    q("UPDATE $tablo SET $set WHERE id = ?", $p);
    unset($yeniD['guncelleme']);
    gecmis_yaz($tablo, $id, $islem, $eskiD, $yeniD);
    return true;
}

function gecmis_yaz($tablo, $id, $islem, $eski, $yeni)
{
    if (is_array($eski)) {
        unset($eski['sifre_hash']);
    }
    if (is_array($yeni)) {
        unset($yeni['sifre_hash']);
    }
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

/** Sistem ayarı (veritabanında) */
function ayar($k, $d = null)
{
    $r = one('SELECT deger FROM ayarlar WHERE anahtar = ?', [$k]);
    return $r ? $r['deger'] : $d;
}

function ayar_yaz($k, $v)
{
    q('INSERT OR REPLACE INTO ayarlar (anahtar, deger) VALUES (?, ?)', [$k, (string)$v]);
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
        // Eski SQLite (3.27 öncesi): düz kopya
        copy(db_path(), $hedef);
    }
    return file_exists($hedef);
}

/** Yedek dosyası adı: tahmin edilemesin diye rastgele ek içerir */
function yedek_dosya_adi($onEk)
{
    return data_dir() . '/yedekler/' . $onEk . '-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
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
    if (glob(data_dir() . '/yedekler/oto-' . date('Y-m-d') . '*.sqlite')) {
        return;
    }
    db_kopyala(yedek_dosya_adi('oto'));
    // Eski otomatik yedekleri temizle
    $sinir = time() - 86400 * (int)cfg('backup_keep_days', 180);
    foreach (glob(data_dir() . '/yedekler/oto-*.sqlite') ?: [] as $f) {
        if (filemtime($f) < $sinir) {
            @unlink($f);
        }
    }
}

/* ------------------------------------------------------------------ */
/* HTTPS, oturum ve güvenlik                                           */
/* ------------------------------------------------------------------ */

function is_https()
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
}

/** Ayar açıksa HTTP isteğini HTTPS'e yönlendirir */
function https_zorla()
{
    if (!cfg('https_zorunlu', false) || PHP_SAPI === 'cli') {
        return;
    }
    if (!is_https()) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }
    }
    header('Strict-Transport-Security: max-age=31536000');
}

/** Uygulamanın web yolu, ör. "/takip/" */
function uygulama_web_yolu()
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($dir, '/') . '/';
}

function oturum_baslat()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('sefertakip');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => uygulama_web_yolu(),
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.use_strict_mode', '1');
    $sd = data_dir() . '/oturumlar-' . substr(kurulum_gizli(), 0, 12);
    if (!is_dir($sd)) {
        @mkdir($sd, 0770, true);
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

function yonetici_mi()
{
    $k = aktif_kullanici();
    return $k && !empty($k['yonetici']);
}

/** Oturumu kullanıcıya bağlar (giriş, kurulum, şifre değişimi sonrası) */
function oturum_ac(array $u)
{
    $_SESSION['kullanici'] = [
        'id' => (int)$u['id'],
        'kullanici_adi' => $u['kullanici_adi'],
        'ad' => $u['ad'],
        'yonetici' => (int)$u['yonetici'],
        'oturum_surumu' => (int)$u['oturum_surumu'],
    ];
}

/**
 * Her istekte: kullanıcı hâlâ var mı, aktif mi, oturumu iptal edilmiş mi?
 * (Şifre değişince / yönetici "oturumları kapat" deyince oturum_surumu artar.)
 */
function oturum_dogrula()
{
    $k = aktif_kullanici();
    if (!$k) {
        return;
    }
    $u = one('SELECT * FROM kullanicilar WHERE id = ?', [(int)$k['id']]);
    if (!$u || !(int)$u['aktif'] || (int)$u['oturum_surumu'] !== (int)($k['oturum_surumu'] ?? -1) || $u['kullanici_adi'] !== $k['kullanici_adi']) {
        unset($_SESSION['kullanici']);
        session_regenerate_id(true);
        mesaj('Oturumunuz sona erdi. Lütfen tekrar giriş yapın.', 'dikkat');
        return;
    }
    oturum_ac($u);
}

function istemci_ip()
{
    $baslik = (string)cfg('ip_basligi', '');
    if ($baslik !== '' && !empty($_SERVER[$baslik])) {
        return trim(explode(',', (string)$_SERVER[$baslik])[0]);
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Çok fazla hatalı giriş varsa true */
function giris_kilitli($kullaniciAdi)
{
    $pencere = time() - 60 * (int)cfg('giris_kilit_dakika', 15);
    $limit = max(3, (int)cfg('giris_deneme_limiti', 5));
    $ip = istemci_ip();
    $ikili = (int)one('SELECT COUNT(*) c FROM giris_denemeleri WHERE basarili = 0 AND ip = ? AND kullanici_adi = ? AND zaman > ?', [$ip, $kullaniciAdi, $pencere])['c'];
    $ipToplam = (int)one('SELECT COUNT(*) c FROM giris_denemeleri WHERE basarili = 0 AND ip = ? AND zaman > ?', [$ip, $pencere])['c'];
    $kulToplam = (int)one('SELECT COUNT(*) c FROM giris_denemeleri WHERE basarili = 0 AND kullanici_adi = ? AND zaman > ?', [$kullaniciAdi, $pencere])['c'];
    return $ikili >= $limit || $ipToplam >= $limit * 4 || $kulToplam >= $limit * 4;
}

function giris_denemesi_yaz($kullaniciAdi, $basarili)
{
    q('INSERT INTO giris_denemeleri (zaman, ip, kullanici_adi, basarili) VALUES (?, ?, ?, ?)', [time(), istemci_ip(), $kullaniciAdi, $basarili ? 1 : 0]);
    q('DELETE FROM giris_denemeleri WHERE zaman < ?', [time() - 30 * 86400]);
}

const SIFRE_MIN = 8;

/**
 * Acil şifre sıfırlama: tek yönetici şifresini unutursa config.local.php içine
 * 'acil_sifre_sifirlama' => ['kullanici' => 'admin', 'yeni_sifre' => 'YeniSifre123'] yazılır.
 * Bir kez uygulanır; ayar silinene kadar yöneticiye uyarı gösterilir.
 * @return bool Ayar tanımlı mı
 */
function acil_sifre_sifirlama()
{
    $a = cfg('acil_sifre_sifirlama');
    if (!is_array($a) || empty($a['kullanici']) || !isset($a['yeni_sifre']) || mb_strlen((string)$a['yeni_sifre']) < SIFRE_MIN) {
        return false;
    }
    $iz = hash('sha256', mb_strtolower((string)$a['kullanici']) . "\0" . $a['yeni_sifre']);
    if (ayar('acil_sifirlama_izi') === $iz) {
        return true;
    }
    $u = one('SELECT * FROM kullanicilar WHERE kullanici_adi = ?', [mb_strtolower((string)$a['kullanici'])]);
    if ($u) {
        q('UPDATE kullanicilar SET sifre_hash = ?, aktif = 1, oturum_surumu = oturum_surumu + 1 WHERE id = ?', [password_hash((string)$a['yeni_sifre'], PASSWORD_DEFAULT), $u['id']]);
        q('DELETE FROM giris_denemeleri WHERE kullanici_adi = ?', [$u['kullanici_adi']]);
        gecmis_yaz('kullanicilar', $u['id'], 'acil_sifre_sifirlama', null, null);
        ayar_yaz('acil_sifirlama_izi', $iz);
    }
    return true;
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
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || $t === '' || !hash_equals(csrf_token(), $t)) {
        mesaj('Güvenlik doğrulaması başarısız (sayfa çok uzun süre açık kalmış olabilir). Lütfen tekrar deneyin.', 'hata');
        yonlendir('index.php?' . http_build_query(array_filter($_GET, 'is_string')));
    }
}

function guvenlik_basliklari()
{
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/* ------------------------------------------------------------------ */
/* Sunucu gereksinimleri ve yükleme sınırları                           */
/* ------------------------------------------------------------------ */

function ini_bayt($v)
{
    $v = trim((string)$v);
    if ($v === '') {
        return 0;
    }
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g':
            $n *= 1024;
            // no break
        case 'm':
            $n *= 1024;
            // no break
        case 'k':
            $n *= 1024;
    }
    return (int)$n;
}

/** ['dosya' => tek dosya sınırı, 'toplam' => form toplam sınırı] (bayt) */
function yukleme_sinirlari()
{
    $dosya = ini_bayt(ini_get('upload_max_filesize'));
    $uyg = (int)cfg('max_upload_mb', 20) * 1024 * 1024;
    $dosya = $dosya > 0 ? min($dosya, $uyg) : $uyg;
    $toplam = ini_bayt(ini_get('post_max_size'));
    if ($toplam <= 0) {
        $toplam = 1024 * 1024 * 1024;
    }
    return ['dosya' => $dosya, 'toplam' => $toplam, 'adet' => (int)ini_get('max_file_uploads') ?: 20];
}

function mb_fmt($bayt)
{
    return str_replace('.', ',', (string)round($bayt / 1048576, 1)) . ' MB';
}

/** Sunucu durum listesi: [ad, durum (ok|uyari|hata), açıklama] */
function gereksinimler()
{
    $l = [];
    $l[] = ['PHP sürümü', PHP_VERSION_ID >= 80100 ? 'ok' : 'uyari', PHP_VERSION . (PHP_VERSION_ID >= 80100 ? '' : ' (8.1 veya üzeri önerilir)')];
    $l[] = ['pdo_sqlite', 'ok', 'Var'];
    $l[] = ['mbstring', 'ok', 'Var'];
    $l[] = ['zip', class_exists('ZipArchive') ? 'ok' : 'hata', class_exists('ZipArchive') ? 'Var' : 'YOK — Excel ve ZIP yedek çalışmaz! Hosting panelinden etkinleştirin.'];
    $l[] = ['fileinfo', function_exists('finfo_open') ? 'ok' : 'uyari', function_exists('finfo_open') ? 'Var' : 'Yok — dosya türü sadece uzantıdan kontrol edilir'];
    $l[] = ['curl', function_exists('curl_init') ? 'ok' : 'uyari', function_exists('curl_init') ? 'Var' : 'Yok — kısa harita linkleri (maps.app.goo.gl) çözülemez'];
    try {
        $sv = (string)db()->query('SELECT sqlite_version()')->fetchColumn();
        $l[] = ['SQLite', version_compare($sv, '3.27', '>=') ? 'ok' : 'uyari', $sv . (version_compare($sv, '3.27', '>=') ? '' : ' (eski; yedekler düz kopya ile alınır)')];
    } catch (Exception $e) {
        $l[] = ['SQLite', 'hata', 'Veritabanı açılamadı'];
    }
    $web = data_dir_web_icinde();
    $l[] = ['Veri klasörü konumu', $web === null ? 'ok' : 'uyari', $web === null
        ? 'Web klasörünün dışında (önerilen)'
        : 'Web klasörünün içinde ("' . $web . '"). Koruma .htaccess dosyasına bağlı; config.local.php ile public_html dışına taşımanız önerilir.'];
    $l[] = ['HTTPS', is_https() ? 'ok' : 'uyari', is_https() ? 'Aktif' : 'Kapalı — şifreler şifrelenmeden gider. SSL kurup config.local.php içinde https_zorunlu => true yapın.'];
    $s = yukleme_sinirlari();
    $l[] = ['Yükleme sınırı', $s['toplam'] >= 20 * 1048576 ? 'ok' : 'uyari', 'Dosya başına ' . mb_fmt($s['dosya']) . ', form toplamı ' . mb_fmt($s['toplam'])];
    $bos = @disk_free_space(data_dir());
    if ($bos !== false) {
        $l[] = ['Boş disk', $bos > 500 * 1048576 ? 'ok' : 'uyari', mb_fmt($bos)];
    }
    if (strlen((string)cfg('kurulum_anahtari', '')) < 12) {
        $l[] = ['Kurulum anahtarı', 'uyari', 'config.local.php içinde kurulum_anahtari tanımlı değil (yeni kurulum yapılamaz).'];
    }
    return $l;
}

/* ------------------------------------------------------------------ */
/* Genel yardımcılar                                                   */
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

/** Ekranda gösterilecek mesaj. Tür: ok | hata | dikkat */
function mesaj($metin, $tur = 'ok')
{
    $_SESSION['mesajlar'][] = [$tur, $metin];
}

function post($k, $d = '')
{
    return isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : $d;
}

function get($k, $d = '')
{
    return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $d;
}

/** GET parametrelerinden sadece metin olanları */
function get_hepsi()
{
    return array_filter($_GET, 'is_string');
}

function yetkisiz()
{
    http_response_code(403);
    sayfa_bas('Yetki yok');
    echo '<div class="kart dar"><h1>⛔ Yetki yok</h1><p>Bu sayfa sadece <b>yönetici</b> kullanıcılar içindir.</p><p><a class="btn" href="index.php">Ana sayfa</a></p></div>';
    sayfa_son();
    exit;
}

/* ------------------------------------------------------------------ */
/* Sayılar                                                             */
/* ------------------------------------------------------------------ */

/**
 * Kullanıcının yazdığı sayıyı okur. Boşsa $bos döner, okunamazsa NULL.
 * "27.000" → 27000, "9.000,50" → 9000.5, "1,5" → 1.5, "%20" → 20, "1,234.56" → 1234.56
 */
function sayi_kati($s, $bos = 0.0)
{
    $s = str_replace(["\xc2\xa0", ' ', '₺', '%'], '', trim((string)$s));
    $s = preg_replace('/(tl|try)$/i', '', $s);
    if ($s === '') {
        return $bos;
    }
    if (!preg_match('/^-?[0-9][0-9.,]*$/', $s) || preg_match('/[.,]$/', $s)) {
        return null;
    }
    $virgul = strrpos($s, ',');
    $nokta = strrpos($s, '.');
    if ($virgul !== false && $nokta !== false) {
        if ($nokta > $virgul) {
            $s = str_replace(',', '', $s);                         // 1,234.56
        } else {
            $s = str_replace(',', '.', str_replace('.', '', $s));  // 1.234,56
        }
    } elseif ($virgul !== false) {
        if (substr_count($s, ',') > 1) {
            return null;
        }
        $s = str_replace(',', '.', $s);                            // 1234,56
    } elseif ($nokta !== false) {
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);                         // 27.000 (binlik)
        } elseif (substr_count($s, '.') > 1) {
            return null;
        }
    }
    return is_numeric($s) ? (float)$s : null;
}

/** Eski davranış: okunamayan değer 0 (sadece filtre gibi zararsız yerlerde) */
function sayi($s)
{
    $v = sayi_kati($s);
    return $v === null ? 0.0 : $v;
}

/**
 * Formdan sayı alanı okur ve doğrular; hata varsa $hatalar dizisine ekler.
 */
function sayi_alani($ad, $etiket, array &$hatalar, $min = 0.0, $max = null)
{
    $ham = post($ad);
    $v = sayi_kati($ham);
    if ($v === null) {
        $hatalar[] = $etiket . ' okunamadı: "' . $ham . '". Sadece rakam girin (ör. 27000 veya 9.000,50).';
        return 0.0;
    }
    if ($min !== null && $v < $min) {
        $hatalar[] = $etiket . ' negatif olamaz.';
    }
    if ($max !== null && $v > $max) {
        $hatalar[] = $etiket . ' en fazla ' . sayi_fmt($max) . ' olabilir.';
    }
    return $v;
}

function para($n, $ond = 2)
{
    return number_format((float)$n, $ond, ',', '.');
}

function tl($n)
{
    return para($n) . ' ₺';
}

/** Gereksiz sıfırlar olmadan biçimler: 27000 → "27.000", 27.125 → "27,125" */
function sayi_fmt($n, $maxOnd = 3)
{
    $s = number_format((float)$n, $maxOnd, ',', '.');
    if ($maxOnd > 0) {
        $s = rtrim(rtrim($s, '0'), ',');
    }
    return $s === '-0' ? '0' : $s;
}

function kg_fmt($n)
{
    return sayi_fmt($n, 3);
}

/** Form alanında gösterim: 27000 → "27000", 9000.5 → "9000,5" */
function sayi_input($n)
{
    if ($n === null || $n === '') {
        return '';
    }
    $s = rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.');
    return str_replace('.', ',', $s);
}

/* ------------------------------------------------------------------ */
/* Tarih, metin, telefon, kimlik                                        */
/* ------------------------------------------------------------------ */

function tarih_tr($t)
{
    if (!$t) {
        return '';
    }
    $ts = strtotime($t);
    return $ts ? date(strlen($t) > 10 ? 'd.m.Y H:i' : 'd.m.Y', $ts) : $t;
}

/** YYYY-AA-GG biçiminde, gerçekten var olan bir tarih mi? (31 Şubat reddedilir) */
function tarih_gecerli($t)
{
    if (!is_string($t) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[1] >= 2000 && (int)$m[1] <= 2100;
}

/** SQL içinde Türkçe küçük harfe çeviren ifade (PHP fonksiyonu kayıtlı değilse lower()) */
function tr_kucuk_sql($ifade)
{
    db();
    return (!empty($GLOBALS['__tr_kucuk_sql']) ? 'tr_kucuk(' : 'lower(') . $ifade . ')';
}

/** Türkçe küçük harf: "ŞAHİN" → "şahin", "YILMAZ" → "yılmaz" */
function tr_kucuk($s)
{
    return mb_strtolower(strtr((string)$s, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
}

/** Plaka: boşluksuz, büyük harf; Türkçe ı/i → I */
function plaka($s)
{
    $s = strtr(preg_replace('/\s+/', '', (string)$s), ['ı' => 'I', 'i' => 'I', 'İ' => 'I']);
    return mb_strtoupper($s, 'UTF-8');
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

/** Türkiye cep telefonu mu (WhatsApp için)? */
function cep_telefonu_mu($tel)
{
    return (bool)preg_match('/^905\d{9}$/', telefon_wa($tel));
}

/** TC Kimlik No algoritma kontrolü */
function tc_gecerli($tc)
{
    if (!preg_match('/^[1-9]\d{10}$/', (string)$tc)) {
        return false;
    }
    $d = array_map('intval', str_split($tc));
    $tek = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
    $cift = $d[1] + $d[3] + $d[5] + $d[7];
    $d10 = ((($tek * 7) - $cift) % 10 + 10) % 10;
    $d11 = array_sum(array_slice($d, 0, 10)) % 10;
    return $d[9] === $d10 && $d[10] === $d11;
}

/** TR IBAN kontrolü (TR + 24 hane, mod-97) */
function iban_gecerli($iban)
{
    $iban = strtoupper(str_replace(' ', '', (string)$iban));
    if (!preg_match('/^TR\d{24}$/', $iban)) {
        return false;
    }
    $yeni = substr($iban, 4) . substr($iban, 0, 4);
    $sayisal = '';
    foreach (str_split($yeni) as $c) {
        $sayisal .= ctype_alpha($c) ? (string)(ord($c) - 55) : $c;
    }
    $kalan = 0;
    foreach (str_split($sayisal, 7) as $parca) {
        $kalan = (int)(($kalan . $parca)) % 97;
    }
    return $kalan === 1;
}

function wa_link($tel, $metin)
{
    return 'https://wa.me/' . telefon_wa($tel) . '?text=' . rawurlencode($metin);
}

/* ------------------------------------------------------------------ */
/* Koordinatlar                                                        */
/* ------------------------------------------------------------------ */

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

/** Koordinat yaklaşık olarak Türkiye sınırları içinde mi? */
function tr_icinde($lat, $lng)
{
    return $lat >= 35.5 && $lat <= 42.5 && $lng >= 25.5 && $lng <= 45.0;
}

/**
 * Derece / derece-dakika-saniye biçimlerini çözer.
 * "40° 49′ 0.1″ N, 32° 53′ 34.8″ E", "40.81669° N, 32.89300° E", "N 40.81669 E 32.89300",
 * Türkçe yönler de olur: K (kuzey), G (güney), D (doğu), B (batı).
 * @return array Bulunan koordinat değerleri: [['deger' => float, 'yon' => 'N'|'S'|'E'|'W'|null], ...]
 */
function dms_parcala($s)
{
    $s = strtr((string)$s, [
        'º' => '°', '˚' => '°',
        '′' => "'", '’' => "'", '‘' => "'", '´' => "'", '`' => "'",
        '″' => '"', '”' => '"', '“' => '"', "''" => '"',
    ]);
    $s = strtoupper(strtr($s, ['ı' => 'i', 'İ' => 'I', 'ğ' => 'g', 'Ğ' => 'G', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U', 'ö' => 'o', 'Ö' => 'O', 'ç' => 'c', 'Ç' => 'C']));
    // Uzun yön adları (KUZEY, DOGU ...) tek harfe
    $s = preg_replace(['/\bKUZEY\b/', '/\bGUNEY\b/', '/\bDOGU\b/', '/\bBATI\b/', '/\bNORTH\b/', '/\bSOUTH\b/', '/\bEAST\b/', '/\bWEST\b/'], ['K', 'G', 'D', 'B', 'N', 'S', 'E', 'W'], $s);
    if (!preg_match_all('/(?<![A-Z])[NSEWKGDB](?![A-Z])|\d+(?:[.,]\d+)?|[°\'"]/u', $s, $m)) {
        return [];
    }
    $yonMap = ['N' => 'N', 'K' => 'N', 'S' => 'S', 'G' => 'S', 'E' => 'E', 'D' => 'E', 'W' => 'W', 'B' => 'W'];
    $tok = $m[0];
    $sonuc = [];
    $cur = null;
    $bekleyen = null;
    $n = count($tok);
    for ($i = 0; $i < $n; $i++) {
        $t = $tok[$i];
        if (isset($yonMap[$t])) {
            if ($cur !== null && $cur['yon'] === null) {
                $cur['yon'] = $yonMap[$t];       // sonek: "40° N"
                $sonuc[] = $cur;
                $cur = null;
            } else {
                if ($cur !== null) {
                    $sonuc[] = $cur;
                    $cur = null;
                }
                $bekleyen = $yonMap[$t];         // önek: "N 40°"
            }
            continue;
        }
        if ($t === '°' || $t === "'" || $t === '"') {
            continue;
        }
        $deger = (float)str_replace(',', '.', $t);
        $sonraki = $tok[$i + 1] ?? '';
        if ($cur !== null && $sonraki === "'") {
            $cur['dk'] = $deger;
            $i++;
        } elseif ($cur !== null && $sonraki === '"') {
            $cur['sn'] = $deger;
            $i++;
        } else {
            if ($cur !== null) {
                $sonuc[] = $cur;
            }
            $cur = ['der' => $deger, 'dk' => 0.0, 'sn' => 0.0, 'yon' => $bekleyen];
            $bekleyen = null;
            if ($sonraki === '°') {
                $i++;
            }
        }
    }
    if ($cur !== null) {
        $sonuc[] = $cur;
    }
    $cikti = [];
    foreach ($sonuc as $c) {
        if ($c['dk'] >= 60 || $c['sn'] >= 60) {
            return [];
        }
        $v = $c['der'] + $c['dk'] / 60 + $c['sn'] / 3600;
        if ($c['yon'] === 'S' || $c['yon'] === 'W') {
            $v = -$v;
        }
        $cikti[] = ['deger' => $v, 'yon' => $c['yon']];
    }
    return $cikti;
}

/** İki parçalı derece metnini [lat, lng] olarak çözer */
function dms_coz($s)
{
    $p = dms_parcala($s);
    if (count($p) !== 2) {
        return null;
    }
    list($a, $b) = $p;
    $boylamYon = ['E', 'W'];
    $enlemYon = ['N', 'S'];
    if (in_array($a['yon'], $boylamYon, true) && ($b['yon'] === null || in_array($b['yon'], $enlemYon, true))) {
        list($a, $b) = [$b, $a];   // "E 32 N 40" gibi ters sıra
    }
    $lat = $a['deger'];
    $lng = $b['deger'];
    if (abs($lat) > 90 || abs($lng) > 180) {
        return null;
    }
    return [$lat, $lng];
}

/** Tek bir enlem/boylam kutusundaki değeri okur: "40.8167", "40,8167", "40°49'00.1\"N" */
function tek_koordinat($s)
{
    $s = trim((string)$s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^-?\d{1,3}([.,]\d+)?$/', $s)) {
        return (float)str_replace(',', '.', $s);
    }
    $p = dms_parcala($s);
    return count($p) === 1 ? $p[0]['deger'] : false;
}

/**
 * Metinden koordinat çıkarır: "41.01, 28.97", derece biçimleri, Google / Apple / Yandex harita linkleri.
 * @return array|null [lat, lng]
 */
function koordinat_coz($s)
{
    $s = trim(urldecode((string)$s));
    if ($s === '') {
        return null;
    }
    $gecerli = function ($lat, $lng) {
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 ? [$lat, $lng] : null;
    };
    // Yandex linklerinde sıra BOYLAM,ENLEM'dir
    if (stripos($s, 'yandex.') !== false) {
        if (preg_match('/[?&](?:pt|ll|whatshere\[point\])=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $s, $m)) {
            return $gecerli((float)$m[2], (float)$m[1]);
        }
    }
    $desen = [
        '/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/',
        '/@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/',
        '/[?&](?:q|query|ll|sll|coordinate|destination|daddr|center)=(?:loc:)?(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/',
        '/^\s*(-?\d{1,2}(?:[.,]\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:[.,]\d+)?)\s*$/',
    ];
    foreach ($desen as $d) {
        if (preg_match($d, $s, $m)) {
            $k = $gecerli((float)str_replace(',', '.', $m[1]), (float)str_replace(',', '.', $m[2]));
            if ($k) {
                return $k;
            }
        }
    }
    if (strpos($s, '°') !== false || preg_match('/(^|[\s,;0-9])[NSEWKGDB]([\s,;0-9]|$)/iu', $s)) {
        $k = dms_coz($s);
        if ($k) {
            return $k;
        }
    }
    if (preg_match('/(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/', $s, $m)) {
        return $gecerli((float)$m[1], (float)$m[2]);
    }
    // "Enlem: 40.8167, Boylam: 32.893" gibi: metinde tam iki ondalıklı sayı varsa
    if (preg_match_all('/-?\d{1,3}[.,]\d{3,}/', $s, $m) && count($m[0]) === 2) {
        return $gecerli((float)str_replace(',', '.', $m[0][0]), (float)str_replace(',', '.', $m[0][1]));
    }
    return null;
}

function base_url()
{
    $b = rtrim((string)cfg('base_url', ''), '/');
    if ($b !== '') {
        return $b;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https' : 'http') . '://' . $host . rtrim(uygulama_web_yolu(), '/');
}

function belge_link($token)
{
    return base_url() . '/belge.php?t=' . rawurlencode($token);
}

/* ------------------------------------------------------------------ */
/* İş kuralları                                                        */
/* ------------------------------------------------------------------ */

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

/** Değişiklik geçmişinde gösterilecek alan adları */
const ALAN_ETIKETLERI = [
    'tarih' => 'Yükleme tarihi', 'teslim_tarihi' => 'Teslim tarihi', 'durum' => 'Durum',
    'yukleme_depo_id' => 'Yükleme deposu', 'bosaltim_depo_id' => 'Boşaltım deposu',
    'sofor_id' => 'Şoför', 'sofor_telefon' => 'Telefon', 'cekici_plaka' => 'Çekici plaka', 'dorse_plaka' => 'Dorse plaka',
    'urun_id' => 'Ürün', 'miktar_kg' => 'Miktar (kg)', 'urun_ton_fiyat' => 'Ürün ton fiyatı', 'urun_kdv' => 'Ürün KDV %',
    'nakliye_ton_fiyat' => 'Nakliye ton fiyatı', 'nakliye_kdv' => 'Nakliye KDV %', 'nakliye_odendi' => 'Nakliye ödendi',
    'irsaliye_no' => 'İrsaliye no', 'kantar_fis_no' => 'Kantar fiş no', 'notlar' => 'Notlar', 'silindi' => 'Silindi',
    'tur' => 'Belge türü', 'orijinal_ad' => 'Dosya adı', 'olusturan' => 'Oluşturan',
];

/** Geçmişteki bir değeri okunur hale getirir (ID → isim, 1/0 → Evet/Hayır) */
function gecmis_deger($alan, $deger)
{
    if ($deger === null || $deger === '') {
        return '—';
    }
    switch ($alan) {
        case 'yukleme_depo_id':
        case 'bosaltim_depo_id':
            $r = one('SELECT ad, il, ilce FROM depolar WHERE id = ?', [(int)$deger]);
            return $r ? depo_etiket($r) : '#' . $deger;
        case 'sofor_id':
            $r = one('SELECT ad, soyad FROM soforler WHERE id = ?', [(int)$deger]);
            return $r ? trim($r['ad'] . ' ' . $r['soyad']) : '#' . $deger;
        case 'urun_id':
            $r = one('SELECT ad FROM urunler WHERE id = ?', [(int)$deger]);
            return $r ? $r['ad'] : '#' . $deger;
        case 'durum':
            return DURUMLAR[$deger] ?? $deger;
        case 'tur':
            return BELGE_TURLERI[$deger] ?? $deger;
        case 'nakliye_odendi':
        case 'silindi':
            return $deger ? 'Evet' : 'Hayır';
        case 'tarih':
        case 'teslim_tarihi':
            return tarih_tr($deger);
        case 'miktar_kg':
            return kg_fmt($deger);
        case 'urun_ton_fiyat':
        case 'nakliye_ton_fiyat':
            return tl($deger);
        case 'urun_kdv':
        case 'nakliye_kdv':
            return '%' . sayi_fmt($deger, 2);
    }
    return (string)$deger;
}

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
    return ($d['ad'] ?? '') . ($yer ? " ($yer)" : '') . (!empty($d['silindi']) ? ' [silinmiş]' : '');
}

/** Sefer sorgularının FROM + JOIN kısmı */
function sefer_from()
{
    return " FROM seferler s
      LEFT JOIN depolar yd ON yd.id = s.yukleme_depo_id
      LEFT JOIN depolar bd ON bd.id = s.bosaltim_depo_id
      LEFT JOIN soforler so ON so.id = s.sofor_id
      LEFT JOIN urunler u ON u.id = s.urun_id";
}

/** Sefer + ilişkili bilgileri tek satırda getiren SQL */
function sefer_sql()
{
    return "SELECT s.*,
        yd.ad AS ydepo_ad, yd.il AS ydepo_il, yd.ilce AS ydepo_ilce, yd.adres AS ydepo_adres, yd.lat AS ydepo_lat, yd.lng AS ydepo_lng,
        bd.ad AS bdepo_ad, bd.il AS bdepo_il, bd.ilce AS bdepo_ilce, bd.adres AS bdepo_adres, bd.lat AS bdepo_lat, bd.lng AS bdepo_lng,
        so.ad AS sofor_ad, so.soyad AS sofor_soyad, so.tc AS sofor_tc, so.telefon AS sofor_kart_telefon,
        u.ad AS urun_ad,
        (SELECT COUNT(*) FROM belgeler b WHERE b.sefer_id = s.id AND b.silindi = 0) AS belge_sayisi" . sefer_from();
}

function sofor_adsoyad($s)
{
    return trim(($s['sofor_ad'] ?? '') . ' ' . ($s['sofor_soyad'] ?? ''));
}

/** Seferin WhatsApp numarası: seferdeki telefon, yoksa şoför kartındaki */
function sefer_telefon(array $s)
{
    return trim((string)($s['sofor_telefon'] ?? '')) !== '' ? $s['sofor_telefon'] : (string)($s['sofor_kart_telefon'] ?? '');
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
