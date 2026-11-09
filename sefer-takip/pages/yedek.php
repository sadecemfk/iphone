<?php
// Yedekleme, Excel, geri yükleme ve sistem durumu (sadece yönetici — index.php kontrol eder)
$yd = data_dir() . '/yedekler';

/** Yedek klasöründeki güvenli dosya adı → tam yol (yoksa null) */
function yedek_yolu($ad)
{
    $ad = basename((string)$ad);
    if (!preg_match('/^[A-Za-z0-9._-]+\.(sqlite|zip)$/', $ad)) {
        return null;
    }
    $yol = data_dir() . '/yedekler/' . $ad;
    return is_file($yol) ? $yol : null;
}

/** SQLite yedeğini doğrular: tablolar, bütünlük ve en az bir kullanıcı. Hata metni veya null döner. */
function yedek_dogrula($dosya)
{
    try {
        $t = new PDO('sqlite:' . $dosya);
        $t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ok = (int)$t->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('kullanicilar','seferler','depolar','soforler','urunler','belgeler','islem_gecmisi')")->fetchColumn();
        if ($ok !== 7) {
            return 'Dosya bir Sefer Takip veritabanı değil.';
        }
        if ($t->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            return 'Yedek dosyası bozuk (bütünlük kontrolü başarısız).';
        }
        if ((int)$t->query('SELECT COUNT(*) FROM kullanicilar')->fetchColumn() === 0) {
            return 'Yedekte hiç kullanıcı yok; bu yedek geri yüklenemez.';
        }
        $t = null;
        return null;
    } catch (Exception $e) {
        return 'Dosya okunamadı (geçerli bir SQLite veritabanı değil).';
    }
}

/** Veritabanı dosyasının özet bilgileri */
function yedek_ozet($dosya)
{
    $t = new PDO('sqlite:' . $dosya);
    $t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tek = function ($sql) use ($t) {
        return $t->query($sql)->fetchColumn();
    };
    return [
        'sefer' => (int)$tek('SELECT COUNT(*) FROM seferler WHERE silindi = 0'),
        'silinen_sefer' => (int)$tek('SELECT COUNT(*) FROM seferler WHERE silindi = 1'),
        'belge' => (int)$tek('SELECT COUNT(*) FROM belgeler WHERE silindi = 0'),
        'depo' => (int)$tek('SELECT COUNT(*) FROM depolar WHERE silindi = 0'),
        'sofor' => (int)$tek('SELECT COUNT(*) FROM soforler WHERE silindi = 0'),
        'son_sefer' => (string)$tek('SELECT MAX(tarih) FROM seferler WHERE silindi = 0'),
        'son_degisiklik' => (string)$tek('SELECT MAX(zaman) FROM islem_gecmisi'),
        'kullanicilar' => implode(', ', $t->query('SELECT kullanici_adi FROM kullanicilar ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)),
    ];
}

/** Canlı veritabanını yedekle değiştirir; önceki hali saklar */
function yedekten_don($kaynak)
{
    $once = yedek_dosya_adi('geri-yukleme-oncesi');
    db_kopyala($once);
    db_close();
    foreach (['-journal', '-wal'] as $ek) {
        if (file_exists(db_path() . $ek)) {
            throw new RuntimeException('Veritabanı şu an kullanımda. Birkaç saniye sonra tekrar deneyin.');
        }
    }
    $tmp = db_path() . '.yeni';
    if (!copy($kaynak, $tmp) || !rename($tmp, db_path())) {
        throw new RuntimeException('Yedek dosyası kopyalanamadı.');
    }
    gecmis_yaz('sistem', 0, 'yedekten_geri_yukle', null, ['kaynak' => basename($kaynak), 'onceki_hal' => basename($once)]);
    return basename($once);
}

/**
 * ZIP yedeği açar: veritabanını yedekler klasörüne çıkarır, belgeleri (var olanların üzerine yazmadan) geri koyar.
 * @return array ['sqlite' => yol, 'belge' => çıkarılan sayısı, 'atlanan' => zaten var olan] veya ['hata' => metin]
 */
function zip_yedek_ac($zipYol, $sifre)
{
    if (!class_exists('ZipArchive')) {
        return ['hata' => 'Sunucuda zip eklentisi yok.'];
    }
    $z = new ZipArchive();
    if ($z->open($zipYol) !== true) {
        return ['hata' => 'ZIP dosyası açılamadı.'];
    }
    if ($sifre !== '') {
        $z->setPassword($sifre);
    }
    $db = $z->getFromName('veritabani.sqlite');
    if ($db === false) {
        $z->close();
        return ['hata' => 'ZIP içinde veritabani.sqlite bulunamadı' . ($sifre === '' ? ' (yedek şifreliyse şifreyi girin).' : ' veya şifre hatalı.')];
    }
    $hedef = yedek_dosya_adi('yuklenen');
    file_put_contents($hedef, $db);
    unset($db);
    $belgeKok = realpath(data_dir() . '/belgeler');
    $cikan = 0;
    $atlanan = 0;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $ad = (string)$z->getNameIndex($i);
        if (strpos($ad, 'belgeler/') !== 0 || substr($ad, -1) === '/') {
            continue;
        }
        $goreli = substr($ad, strlen('belgeler/'));
        // Klasör dışına çıkma (zip-slip) ve garip adlar engellenir
        if (!preg_match('#^[0-9]+/[A-Za-z0-9._-]+$#', $goreli) || strpos($goreli, '..') !== false) {
            continue;
        }
        $yol = $belgeKok . '/' . $goreli;
        if (file_exists($yol)) {
            $atlanan++;
            continue;
        }
        if (!is_dir(dirname($yol))) {
            @mkdir(dirname($yol), 0775, true);
        }
        $icerik = $z->getFromIndex($i);
        if ($icerik !== false && file_put_contents($yol, $icerik) !== false) {
            $cikan++;
        }
    }
    $z->close();
    return ['sqlite' => $hedef, 'belge' => $cikan, 'atlanan' => $atlanan];
}

// POST isteklerinde formdaki işlem geçerlidir (adres çubuğundaki islem=onay gibi değerler değil)
$islem = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('islem') : get('islem');

/* ---------------- İndirmeler ---------------- */

if ($islem === 'excel_tam') {
    if (!class_exists('ZipArchive')) {
        mesaj('Sunucuda "zip" PHP eklentisi olmadığı için Excel oluşturulamıyor.', 'hata');
        yonlendir('index.php?p=yedek');
    }
    $tmp = tempnam(gecici_dizin(), 'xls');
    excel_tam_yedek_olustur($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="sefer-takip-tum-veriler-' . date('Y-m-d-His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

if ($islem === 'zip' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!class_exists('ZipArchive')) {
        mesaj('Sunucuda "zip" PHP eklentisi olmadığı için ZIP yedek alınamıyor.', 'hata');
        yonlendir('index.php?p=yedek');
    }
    $sifre = (string)($_POST['zip_sifre'] ?? '');
    if ($sifre !== '' && mb_strlen($sifre) < 8) {
        mesaj('ZIP şifresi en az 8 karakter olmalı.', 'hata');
        yonlendir('index.php?p=yedek');
    }
    if ($sifre !== '' && !method_exists('ZipArchive', 'setEncryptionName')) {
        mesaj('Bu sunucu şifreli ZIP desteklemiyor; şifresiz yedek alabilirsiniz.', 'hata');
        yonlendir('index.php?p=yedek');
    }
    @set_time_limit(600);
    $gd = gecici_dizin();
    $tmpDb = tempnam($gd, 'db');
    db_kopyala($tmpDb);
    $tmpX = tempnam($gd, 'xls');
    excel_tam_yedek_olustur($tmpX);
    $tmpZ = tempnam($gd, 'zip');
    $z = new ZipArchive();
    $z->open($tmpZ, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $girdiler = [];
    $z->addFile($tmpDb, 'veritabani.sqlite');
    $girdiler[] = 'veritabani.sqlite';
    $z->addFile($tmpX, 'tum-veriler.xlsx');
    $girdiler[] = 'tum-veriler.xlsx';
    $z->addFromString('OKU-BENI.txt', "Sefer Takip tam yedeği - " . date('d.m.Y H:i') . "\r\n\r\n"
        . "veritabani.sqlite : Sistemin tüm verisi.\r\n"
        . "tum-veriler.xlsx  : Tüm veriler Excel formatında (okumak için).\r\n"
        . "belgeler/         : Yüklenen irsaliye ve kantar fişleri.\r\n\r\n"
        . "GERİ YÜKLEME: Sistemde Yedek / Excel > 'Yedekten geri yükle' bölümünden bu ZIP dosyasını seçin.\r\n"
        . "ZIP çok büyükse FTP ile veri klasöründeki 'yedekler' klasörüne yükleyin; sayfada listelenir.\r\n");
    $girdiler[] = 'OKU-BENI.txt';
    $bk = data_dir() . '/belgeler';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bk, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getFilename() !== 'index.html') {
            $ad = 'belgeler/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($bk))), '/');
            $z->addFile($f->getPathname(), $ad);
            $girdiler[] = $ad;
        }
    }
    if ($sifre !== '') {
        foreach ($girdiler as $ad) {
            $z->setEncryptionName($ad, ZipArchive::EM_AES_256, $sifre);
        }
    }
    if (!$z->close()) {
        @unlink($tmpDb);
        @unlink($tmpX);
        @unlink($tmpZ);
        throw new RuntimeException('ZIP yedek oluşturulamadı (disk dolu olabilir).');
    }
    ayar_yaz('son_zip_yedek', simdi());
    gecmis_yaz('sistem', 0, 'zip_yedek_indirildi', null, ['sifreli' => $sifre !== '' ? 'evet' : 'hayır']);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="sefer-takip-yedek-' . date('Y-m-d-His') . ($sifre !== '' ? '-sifreli' : '') . '.zip"');
    header('Content-Length: ' . filesize($tmpZ));
    header('Cache-Control: no-store');
    readfile($tmpZ);
    @unlink($tmpDb);
    @unlink($tmpX);
    @unlink($tmpZ);
    exit;
}

if ($islem === 'indir') {
    $yol = yedek_yolu(get('f'));
    if ($yol) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($yol) . '"');
        header('Content-Length: ' . filesize($yol));
        header('Cache-Control: no-store');
        readfile($yol);
        exit;
    }
    mesaj('Dosya bulunamadı.', 'hata');
    yonlendir('index.php?p=yedek');
}

/* ---------------- İşlemler ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($islem === 'simdi') {
        $f = yedek_dosya_adi('elle');
        db_kopyala($f);
        mesaj('Sunucuda yedek alındı: ' . basename($f));
    } elseif ($islem === 'yukle') {
        // .sqlite veya .zip yükle → onay ekranına git
        $f = $_FILES['yedek'] ?? null;
        if (!$f || !is_string($f['tmp_name'] ?? null) || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $kod = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            mesaj($kod === UPLOAD_ERR_INI_SIZE || $kod === UPLOAD_ERR_FORM_SIZE
                ? 'Dosya sunucu sınırından büyük. Büyük yedekleri FTP ile "yedekler" klasörüne yükleyin; aşağıdaki listede görünür.'
                : 'Dosya yüklenemedi.', 'hata');
            yonlendir('index.php?p=yedek');
        }
        $bas = (string)file_get_contents($f['tmp_name'], false, null, 0, 16);
        if (strpos($bas, "PK\x03\x04") === 0) {
            $zipYol = data_dir() . '/yedekler/yuklenen-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
            move_uploaded_file($f['tmp_name'], $zipYol);
            $r = zip_yedek_ac($zipYol, (string)($_POST['zip_sifre'] ?? ''));
            if (isset($r['hata'])) {
                mesaj($r['hata'], 'hata');
                yonlendir('index.php?p=yedek');
            }
            mesaj('ZIP açıldı: ' . $r['belge'] . ' belge dosyası geri kondu' . ($r['atlanan'] ? ', ' . $r['atlanan'] . ' dosya zaten vardı' : '') . '. Şimdi veritabanını geri yüklemeyi onaylayın.');
            yonlendir('index.php?p=yedek&islem=onay&f=' . rawurlencode(basename($r['sqlite'])));
        }
        if (strpos($bas, 'SQLite format 3') === 0) {
            $hedef = yedek_dosya_adi('yuklenen');
            move_uploaded_file($f['tmp_name'], $hedef);
            yonlendir('index.php?p=yedek&islem=onay&f=' . rawurlencode(basename($hedef)));
        }
        mesaj('Dosya türü tanınmadı. Sistemden indirilen .zip veya .sqlite yedeğini seçin.', 'hata');
    } elseif ($islem === 'zip_ac') {
        // FTP ile yedekler klasörüne konmuş ZIP'i aç
        $yol = yedek_yolu(post('f'));
        if (!$yol || substr($yol, -4) !== '.zip') {
            mesaj('ZIP dosyası bulunamadı.', 'hata');
            yonlendir('index.php?p=yedek');
        }
        @set_time_limit(600);
        $r = zip_yedek_ac($yol, (string)($_POST['zip_sifre'] ?? ''));
        if (isset($r['hata'])) {
            mesaj($r['hata'], 'hata');
            yonlendir('index.php?p=yedek');
        }
        mesaj('ZIP açıldı: ' . $r['belge'] . ' belge dosyası geri kondu' . ($r['atlanan'] ? ', ' . $r['atlanan'] . ' dosya zaten vardı' : '') . '. Şimdi veritabanını geri yüklemeyi onaylayın.');
        yonlendir('index.php?p=yedek&islem=onay&f=' . rawurlencode(basename($r['sqlite'])));
    } elseif ($islem === 'geri_onayli') {
        $yol = yedek_yolu(post('f'));
        $hata = $yol && substr($yol, -7) === '.sqlite' ? yedek_dogrula($yol) : 'Yedek dosyası bulunamadı.';
        if ($hata) {
            mesaj($hata, 'hata');
        } elseif (!in_array(str_replace(['İ', 'Ü'], ['I', 'U'], mb_strtoupper(post('onay'), 'UTF-8')), ['GERI YUKLE'], true)) {
            mesaj('Onay için kutuya büyük harflerle GERI YUKLE yazın.', 'hata');
            yonlendir('index.php?p=yedek&islem=onay&f=' . rawurlencode(basename($yol)));
        } else {
            $once = yedekten_don($yol);
            mesaj('Veritabanı "' . basename($yol) . '" yedeğine döndürüldü. Önceki hal "' . $once . '" olarak saklandı (gerekirse ona geri dönebilirsiniz).');
        }
    }
    yonlendir('index.php?p=yedek');
}

/* ---------------- Onay ekranı ---------------- */

if ($islem === 'onay') {
    $yol = yedek_yolu(get('f'));
    $hata = $yol && substr($yol, -7) === '.sqlite' ? yedek_dogrula($yol) : 'Yedek dosyası bulunamadı.';
    if ($hata) {
        mesaj($hata, 'hata');
        yonlendir('index.php?p=yedek');
    }
    $simdiki = yedek_ozet(db_path());
    $yedek = yedek_ozet($yol);
    $satirlar = [
        'Aktif sefer sayısı' => ['sefer', 'sayi'], 'Silinmiş sefer' => ['silinen_sefer', 'sayi'], 'Belge' => ['belge', 'sayi'],
        'Depo' => ['depo', 'sayi'], 'Şoför' => ['sofor', 'sayi'], 'Son sefer tarihi' => ['son_sefer', 'tarih'],
        'Son değişiklik' => ['son_degisiklik', 'tarih'], 'Kullanıcılar' => ['kullanicilar', 'metin'],
    ];
    sayfa_bas('Geri yükleme onayı', 'yedek');
    ?>
    <h1>⚠️ Geri Yükleme Onayı</h1>
    <div class="uyari hata">
      Sistemdeki <b>tüm veriler</b> aşağıdaki yedekteki haliyle değiştirilecek. Yedekten <b>sonra</b> girilen kayıtlar canlı sistemden çıkar
      (şu anki hal ayrıca saklanır, gerekirse ona geri dönebilirsiniz). Bu sırada başka kullanıcılar işlem yapmasın.
      Geri yüklemeden sonra <b>yedekteki kullanıcı adları ve şifreler</b> geçerli olur.
    </div>
    <div class="kart tablo-kap">
      <table class="tablo">
        <thead><tr><th></th><th>Şu anki sistem</th><th>Geri yüklenecek yedek</th></tr></thead>
        <tbody>
        <?php foreach ($satirlar as $ad => $tanim): $a = $simdiki[$tanim[0]]; $b = $yedek[$tanim[0]]; $fark = (string)$a !== (string)$b; ?>
          <tr<?= $fark ? ' class="fark"' : '' ?>>
            <td data-e=""><b><?= h($ad) ?></b></td>
            <td data-e="Şu an"><?= h($tanim[1] === 'tarih' ? tarih_tr($a) : $a) ?></td>
            <td data-e="Yedek"><?= h($tanim[1] === 'tarih' ? tarih_tr($b) : $b) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="soluk">Yedek dosyası: <?= h(basename($yol)) ?></p>
    </div>
    <?php if ($yedek['sefer'] < $simdiki['sefer']): ?>
      <div class="uyari dikkat">Yedekte şu ankinden <b><?= $simdiki['sefer'] - $yedek['sefer'] ?></b> daha az sefer var. Tek bir kaydı düzeltmek istiyorsanız geri yükleme yerine o seferin <b>değişiklik geçmişinden "Geri al"</b> kullanın.</div>
    <?php endif; ?>
    <form method="post" class="kart form">
      <?= csrf_alan() ?><input type="hidden" name="islem" value="geri_onayli"><input type="hidden" name="f" value="<?= h(basename($yol)) ?>">
      <label>Onaylamak için <b>GERI YUKLE</b> yazın<input name="onay" autocomplete="off" autocapitalize="characters"></label>
      <div class="butonlar">
        <button class="btn tehlike">Yedeği Geri Yükle</button>
        <a class="btn" href="index.php?p=yedek">Vazgeç</a>
      </div>
    </form>
    <?php
    sayfa_son();
    exit;
}

/* ---------------- Ana yedek sayfası ---------------- */

$dosyalar = array_merge(glob("$yd/*.sqlite") ?: [], glob("$yd/*.zip") ?: []);
usort($dosyalar, function ($a, $b) {
    return filemtime($b) - filemtime($a);
});
$dbBoyut = file_exists(db_path()) ? filesize(db_path()) : 0;
$belgeSay = (int)one('SELECT COUNT(*) c FROM belgeler')['c'];
$sonZip = ayar('son_zip_yedek');
$sinir = yukleme_sinirlari();
$sifreDestek = class_exists('ZipArchive') && method_exists('ZipArchive', 'setEncryptionName');

sayfa_bas('Yedek / Excel', 'yedek');
?>
<h1>💾 Yedek ve Excel</h1>

<div class="kart bilgi">
  <b>Verileriniz nasıl korunuyor?</b>
  <ul>
    <li>Silinen hiçbir kayıt gerçekten silinmez; <a href="index.php?p=silinenler">Silinenler</a> sayfasından geri alınabilir.</li>
    <li>Her düzenleme, kim yaptı ve eski/yeni değeriyle <b>değişiklik geçmişine</b> yazılır; sefer sayfasından tek tek <b>geri alınabilir</b>.</li>
    <li>Her gün ilk değişiklikten önce veritabanının <b>otomatik yedeği</b> sunucuda alınır (<?= (int)cfg('backup_keep_days', 180) ?> gün saklanır).</li>
    <li><b>Sunucu arızasına karşı</b> haftada en az bir kez <b>Tam Yedek (ZIP)</b> indirip bilgisayarınızda / iCloud'da saklayın.
      Son ZIP: <b><?= $sonZip ? h(tarih_tr($sonZip)) : 'hiç alınmadı' ?></b></li>
  </ul>
</div>

<div class="iki-sutun">
  <div class="kart">
    <h2>🗜️ Tam Yedek (ZIP)</h2>
    <p>Veritabanı + tüm belgeler (<?= $belgeSay ?> adet) + Excel. Sistemi başka sunucuya taşımak için de kullanılır.</p>
    <form method="post" class="form">
      <?= csrf_alan() ?><input type="hidden" name="islem" value="zip">
      <?php if ($sifreDestek): ?>
        <label>İsteğe bağlı ZIP şifresi (en az 8 karakter)
          <input type="password" name="zip_sifre" minlength="8" autocomplete="new-password" placeholder="Boş bırakılırsa şifresiz">
        </label>
        <p class="soluk kucuk-yazi">Şifreli ZIP (AES-256) TC/IBAN gibi kişisel verileri korur; ancak iPhone "Dosyalar" uygulaması ve Windows Gezgini açamaz
          (bilgisayarda 7-Zip ile açılır). Bu sisteme geri yüklerken şifre sorulur. <b>Şifreyi unutursanız yedek açılamaz.</b></p>
      <?php endif; ?>
      <button class="btn ana">🗜️ Tam Yedeği İndir (ZIP)</button>
    </form>
    <p class="soluk">Veritabanı boyutu: <?= h(mb_fmt($dbBoyut)) ?></p>
  </div>
  <div class="kart">
    <h2>📊 Excel</h2>
    <p>Tüm seferler, depolar, şoförler, ürünler, kullanıcılar, belge linkleri ve değişiklik geçmişi ayrı sayfalarda; tarihler gerçek Excel tarihi.</p>
    <a class="btn ana" href="index.php?p=yedek&islem=excel_tam">📊 Tüm Verileri Excel'e İndir</a>
    <p class="soluk">Sadece belli tarih / ürün için: <a href="index.php?p=seferler">Seferler</a> sayfasında filtreleyip "Excel'e Aktar"a basın.</p>
  </div>
</div>

<div class="kart">
  <div class="baslik-satir">
    <h2>Sunucudaki Yedekler</h2>
    <form method="post" class="satir-ici"><?= csrf_alan() ?><input type="hidden" name="islem" value="simdi"><button class="btn">➕ Şimdi Yedek Al</button></form>
  </div>
  <p class="soluk kucuk-yazi">oto-… otomatik günlük, elle-… elle alınan, geri-yukleme-oncesi-… geri yüklemeden önceki hal, yuklenen-… sizin yüklediğiniz dosyalar.</p>
  <div class="tablo-kap">
  <table class="tablo">
    <thead><tr><th>Dosya</th><th>Tarih</th><th class="sag">Boyut</th><th></th></tr></thead>
    <tbody>
    <?php foreach (array_slice($dosyalar, 0, 80) as $f): $b = basename($f); $zip = substr($b, -4) === '.zip'; ?>
      <tr>
        <td data-e="Dosya"><?= h($b) ?></td>
        <td data-e="Tarih"><?= date('d.m.Y H:i', filemtime($f)) ?></td>
        <td data-e="Boyut" class="sag"><?= h(mb_fmt(filesize($f))) ?></td>
        <td class="butonlar">
          <a class="btn kucuk" href="index.php?p=yedek&islem=indir&f=<?= h(rawurlencode($b)) ?>">İndir</a>
          <?php if ($zip): ?>
            <form method="post" class="satir-ici zip-ac"><?= csrf_alan() ?><input type="hidden" name="islem" value="zip_ac"><input type="hidden" name="f" value="<?= h($b) ?>">
              <input type="password" name="zip_sifre" placeholder="ZIP şifresi (varsa)" autocomplete="off">
              <button class="btn kucuk tehlike">ZIP'ten geri yükle…</button></form>
          <?php else: ?>
            <a class="btn kucuk tehlike" href="index.php?p=yedek&islem=onay&f=<?= h(rawurlencode($b)) ?>">Bu yedeğe dön…</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$dosyalar): ?><tr><td colspan="4" class="soluk">Henüz yedek yok (ilk değişiklikte otomatik oluşur).</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="kart">
  <h2>Yedekten Geri Yükle</h2>
  <p class="soluk">Sistemden indirdiğiniz <b>.zip</b> (belgeler dahil) veya <b>.sqlite</b> yedeğini seçin. Yüklemeden sonra karşılaştırma ekranı gelir; onaylamadan hiçbir şey değişmez.
    Bu sunucunun yükleme sınırı: <b><?= h(mb_fmt(min($sinir['dosya'] ?: PHP_INT_MAX, $sinir['toplam']))) ?></b>. Daha büyük ZIP'leri FTP ile veri klasöründeki <b>yedekler</b> klasörüne koyun; yukarıdaki listede görünür.</p>
  <form method="post" enctype="multipart/form-data" class="yukle-form" data-max-toplam="<?= (int)$sinir['toplam'] ?>">
    <?= csrf_alan() ?><input type="hidden" name="islem" value="yukle">
    <input type="file" name="yedek" accept=".sqlite,.zip,application/zip,application/octet-stream" required>
    <input type="password" name="zip_sifre" placeholder="ZIP şifresi (varsa)" autocomplete="off">
    <button class="btn tehlike">Yükle ve Karşılaştır</button>
  </form>
</div>

<div class="kart">
  <h2>🖥️ Sistem Durumu</h2>
  <?= gereksinim_tablosu(gereksinimler()) ?>
  <p class="soluk kucuk-yazi">Hata kaydı: veri klasöründeki <b>hata.log</b> dosyası. Kullanıcıya gösterilen hata kodu bu dosyada aranabilir.</p>
</div>
<?php sayfa_son();
