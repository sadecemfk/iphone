<?php
$yd = data_dir() . '/yedekler';

function yedek_dogrula($dosya)
{
    try {
        $t = new PDO('sqlite:' . $dosya);
        $t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ok = $t->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('seferler','depolar','soforler','urunler','belgeler')")->fetchColumn();
        $chk = $t->query('PRAGMA integrity_check')->fetchColumn();
        $t = null;
        return (int)$ok === 5 && $chk === 'ok';
    } catch (Exception $e) {
        return false;
    }
}

function yedekten_don($kaynak)
{
    $once = data_dir() . '/yedekler/geri-yukleme-oncesi-' . date('Y-m-d-His') . '.sqlite';
    db_kopyala($once);
    db_close();
    $tmp = db_path() . '.yeni';
    copy($kaynak, $tmp);
    rename($tmp, db_path());
    gecmis_yaz('sistem', 0, 'yedekten_geri_yukle', null, ['kaynak' => basename($kaynak), 'onceki_hal' => basename($once)]);
    return basename($once);
}

$islem = get('islem') ?: post('islem');

if ($islem === 'excel_tam') {
    $tmp = tempnam(sys_get_temp_dir(), 'xls');
    excel_tam_yedek_olustur($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="sefer-takip-tam-yedek-' . date('Y-m-d-His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

if ($islem === 'zip') {
    @set_time_limit(300);
    $tmpDb = tempnam(sys_get_temp_dir(), 'db');
    db_kopyala($tmpDb);
    $tmpX = tempnam(sys_get_temp_dir(), 'xls');
    excel_tam_yedek_olustur($tmpX);
    $tmpZ = tempnam(sys_get_temp_dir(), 'zip');
    $z = new ZipArchive();
    $z->open($tmpZ, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFile($tmpDb, 'veritabani.sqlite');
    $z->addFile($tmpX, 'tum-veriler.xlsx');
    $z->addFromString('OKU-BENI.txt', "Sefer Takip tam yedeği - " . date('d.m.Y H:i') . "\r\n\r\n"
        . "veritabani.sqlite : Sistemin tüm verisi. Geri yüklemek için Yedek sayfasındaki 'Yedek dosyası yükle' bölümünü kullanın\r\n"
        . "                    veya bu dosyayı data/veritabani.sqlite olarak kopyalayın.\r\n"
        . "tum-veriler.xlsx  : Tüm veriler Excel formatında (okumak için).\r\n"
        . "belgeler/         : Yüklenen irsaliye ve kantar fişleri. data/belgeler klasörüne kopyalayın.\r\n");
    $bk = data_dir() . '/belgeler';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bk, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $z->addFile($f->getPathname(), 'belgeler/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($bk))), '/'));
        }
    }
    $z->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="sefer-takip-yedek-' . date('Y-m-d-His') . '.zip"');
    header('Content-Length: ' . filesize($tmpZ));
    readfile($tmpZ);
    @unlink($tmpDb);
    @unlink($tmpX);
    @unlink($tmpZ);
    exit;
}

if ($islem === 'indir') {
    $f = basename(get('f'));
    $yol = "$yd/$f";
    if (preg_match('/^[a-z0-9._-]+\.sqlite$/i', $f) && is_file($yol)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $f . '"');
        header('Content-Length: ' . filesize($yol));
        readfile($yol);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($islem === 'simdi') {
        $f = "$yd/elle-" . date('Y-m-d-His') . '.sqlite';
        db_kopyala($f);
        mesaj('Yedek alındı: ' . basename($f));
    } elseif ($islem === 'geri_listeden') {
        $f = basename(post('f'));
        $yol = "$yd/$f";
        if (preg_match('/^[a-z0-9._-]+\.sqlite$/i', $f) && is_file($yol) && yedek_dogrula($yol)) {
            $once = yedekten_don($yol);
            mesaj("Veritabanı $f yedeğine döndürüldü. Önceki hal $once olarak saklandı.");
        } else {
            mesaj('Yedek dosyası geçersiz.', 'hata');
        }
    } elseif ($islem === 'geri_dosyadan') {
        $f = $_FILES['yedek'] ?? null;
        if ($f && $f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) {
            $hedef = "$yd/yuklenen-" . date('Y-m-d-His') . '.sqlite';
            move_uploaded_file($f['tmp_name'], $hedef);
            if (yedek_dogrula($hedef)) {
                $once = yedekten_don($hedef);
                mesaj("Yedek yüklendi ve geri döndürüldü. Önceki hal $once olarak saklandı.");
            } else {
                @unlink($hedef);
                mesaj('Yüklenen dosya geçerli bir Sefer Takip veritabanı değil.', 'hata');
            }
        } else {
            mesaj('Dosya yüklenemedi.', 'hata');
        }
    }
    yonlendir('index.php?p=yedek');
}

$yedekler = glob("$yd/*.sqlite") ?: [];
usort($yedekler, function ($a, $b) {
    return filemtime($b) - filemtime($a);
});
$dbBoyut = file_exists(db_path()) ? filesize(db_path()) : 0;
$belgeSay = (int)one('SELECT COUNT(*) c FROM belgeler')['c'];

sayfa_bas('Yedek / Excel', 'yedek');
?>
<h1>💾 Yedek ve Excel</h1>

<div class="kart bilgi">
  <b>Verileriniz nasıl korunuyor?</b>
  <ul>
    <li>Silinen hiçbir kayıt gerçekten silinmez; <a href="index.php?p=silinenler">Silinenler</a> sayfasından geri alınabilir.</li>
    <li>Her düzenleme, kim yaptı ve eski/yeni değeriyle <b>değişiklik geçmişine</b> yazılır.</li>
    <li>Her gün ilk değişiklikten önce veritabanının <b>otomatik yedeği</b> alınır (<?= (int)cfg('backup_keep_days', 180) ?> gün saklanır).</li>
    <li>Sunucu çökmesine karşı: haftada en az bir kez aşağıdan <b>Tam Yedek (ZIP)</b> indirip bilgisayarınızda / Google Drive / iCloud'da saklayın.</li>
  </ul>
</div>

<div class="iki-sutun">
  <div class="kart">
    <h2>📊 Excel</h2>
    <p>Tüm seferler, depolar, şoförler, ürünler, belge linkleri ve değişiklik geçmişi ayrı sayfalarda.</p>
    <a class="btn ana" href="index.php?p=yedek&islem=excel_tam">📊 Tüm Verileri Excel'e İndir</a>
    <p class="soluk">Sadece belli tarih / ürün için: <a href="index.php?p=seferler">Seferler</a> sayfasında filtreleyip "Excel'e Aktar"a basın.</p>
  </div>
  <div class="kart">
    <h2>🗜️ Tam Yedek (ZIP)</h2>
    <p>Veritabanı + tüm PDF belgeler (<?= $belgeSay ?> adet) + Excel. Sistemi başka sunucuya taşımak için de kullanılır.</p>
    <a class="btn ana" href="index.php?p=yedek&islem=zip">🗜️ Tam Yedeği İndir (ZIP)</a>
    <p class="soluk">Veritabanı boyutu: <?= round($dbBoyut / 1024) ?> KB</p>
  </div>
</div>

<div class="kart">
  <div class="baslik-satir">
    <h2>Sunucudaki Yedekler</h2>
    <form method="post" class="satir-ici"><?= csrf_alan() ?><input type="hidden" name="islem" value="simdi"><button class="btn">➕ Şimdi Yedek Al</button></form>
  </div>
  <div class="tablo-kap">
  <table class="tablo">
    <thead><tr><th>Dosya</th><th>Tarih</th><th class="sag">Boyut</th><th></th></tr></thead>
    <tbody>
    <?php foreach (array_slice($yedekler, 0, 60) as $f): $b = basename($f); ?>
      <tr>
        <td data-e="Dosya"><?= h($b) ?></td>
        <td data-e="Tarih"><?= date('d.m.Y H:i', filemtime($f)) ?></td>
        <td data-e="Boyut" class="sag"><?= round(filesize($f) / 1024) ?> KB</td>
        <td class="butonlar">
          <a class="btn kucuk" href="index.php?p=yedek&islem=indir&f=<?= h(rawurlencode($b)) ?>">İndir</a>
          <form method="post" class="satir-ici" onsubmit="return confirm('Tüm veriler <?= h($b) ?> anındaki haline döndürülecek. Şu anki hal ayrıca yedeklenecek. Devam edilsin mi?')">
            <?= csrf_alan() ?><input type="hidden" name="islem" value="geri_listeden"><input type="hidden" name="f" value="<?= h($b) ?>">
            <button class="btn kucuk tehlike">Bu yedeğe dön</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$yedekler): ?><tr><td colspan="4" class="soluk">Henüz yedek yok (ilk değişiklikte otomatik oluşur).</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="kart">
  <h2>Yedek Dosyası Yükle (Geri Yükleme)</h2>
  <p class="soluk">ZIP yedeğin içindeki <b>veritabani.sqlite</b> dosyasını veya indirdiğiniz bir .sqlite yedeğini seçin. Mevcut veriler önce yedeklenir.</p>
  <form method="post" enctype="multipart/form-data" class="yukle-form" onsubmit="return confirm('Mevcut veriler seçilen yedekle değiştirilecek (şu anki hal ayrıca yedeklenecek). Devam?')">
    <?= csrf_alan() ?><input type="hidden" name="islem" value="geri_dosyadan">
    <input type="file" name="yedek" accept=".sqlite,application/octet-stream" required>
    <button class="btn tehlike">Yedeği Geri Yükle</button>
  </form>
</div>
<?php sayfa_son();
