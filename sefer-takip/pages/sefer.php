<?php
$id = (int)get('id');
$s = one(sefer_sql() . ' WHERE s.id = ?', [$id]);
if (!$s) {
    mesaj('Sefer bulunamadı.', 'hata');
    yonlendir('index.php?p=seferler');
}

/** Geçmişteki bir değişikliği geri alınabilir alanlar */
const GERI_ALINABILIR = ['tarih', 'teslim_tarihi', 'durum', 'yukleme_depo_id', 'bosaltim_depo_id', 'sofor_id', 'sofor_telefon',
    'cekici_plaka', 'dorse_plaka', 'urun_id', 'miktar_kg', 'urun_ton_fiyat', 'urun_kdv', 'nakliye_ton_fiyat', 'nakliye_kdv',
    'nakliye_odendi', 'irsaliye_no', 'kantar_fis_no', 'notlar', 'silindi'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = post('islem');
    if ($islem === 'sil') {
        kayit_guncelle('seferler', $id, ['silindi' => 1], 'sil');
        mesaj("Sefer #$id silindi. İstediğiniz zaman geri alabilirsiniz (Sefer sayfası veya Silinenler).");
        yonlendir('index.php?p=seferler');
    } elseif ($islem === 'geri_al_sefer') {
        kayit_guncelle('seferler', $id, ['silindi' => 0], 'geri_al');
        mesaj("Sefer #$id geri alındı.");
    } elseif ($s['silindi']) {
        mesaj('Bu sefer silinmiş. İşlem yapmak için önce geri alın.', 'hata');
    } elseif ($islem === 'durum' && isset(DURUMLAR[post('durum')])) {
        $v = ['durum' => post('durum')];
        if (post('durum') === 'teslim' && !$s['teslim_tarihi']) {
            $v['teslim_tarihi'] = date('Y-m-d');
        }
        kayit_guncelle('seferler', $id, $v);
        mesaj('Durum güncellendi.');
    } elseif ($islem === 'odendi') {
        kayit_guncelle('seferler', $id, ['nakliye_odendi' => $s['nakliye_odendi'] ? 0 : 1]);
        mesaj('Nakliye ödeme durumu güncellendi.');
    } elseif ($islem === 'belge_yukle') {
        $tur = isset(BELGE_TURLERI[post('tur')]) ? post('tur') : 'diger';
        $say = 0;
        foreach (dosya_listesi('dosya') as $f) {
            $e = belge_kaydet($id, $f, $tur);
            if ($e) {
                mesaj($e, 'hata');
            } elseif ($f['error'] === UPLOAD_ERR_OK) {
                $say++;
            }
        }
        if ($say) {
            mesaj("$say belge yüklendi.");
        }
    } elseif ($islem === 'belge_sil') {
        $b = one('SELECT * FROM belgeler WHERE id = ? AND sefer_id = ?', [(int)post('belge_id'), $id]);
        if ($b) {
            kayit_guncelle('belgeler', $b['id'], ['silindi' => 1], 'sil');
            mesaj('Belge kaldırıldı (dosya saklanıyor; değişiklik geçmişinden veya Silinenler sayfasından geri alınabilir). Şoförün elindeki link artık açılmaz.');
        }
    } elseif ($islem === 'gecmis_geri_al') {
        // Değişiklik geçmişindeki tek bir değişikliği geri al
        $g = one('SELECT * FROM islem_gecmisi WHERE id = ?', [(int)post('gecmis_id')]);
        $belgeMi = $g && $g['tablo'] === 'belgeler' && one('SELECT id FROM belgeler WHERE id = ? AND sefer_id = ?', [(int)$g['kayit_id'], $id]);
        if (!$g || $g['islem'] === 'ekle' || !(($g['tablo'] === 'seferler' && (int)$g['kayit_id'] === $id) || $belgeMi)) {
            mesaj('Bu değişiklik geri alınamaz.', 'hata');
        } else {
            $tablo = $g['tablo'];
            $kayit = one("SELECT * FROM $tablo WHERE id = ?", [(int)$g['kayit_id']]);
            $eski = json_decode((string)$g['eski'], true) ?: [];
            $yeni = json_decode((string)$g['yeni'], true) ?: [];
            $geri = [];
            $atlanan = [];
            foreach ($eski as $alan => $deger) {
                if (!in_array($alan, GERI_ALINABILIR, true) || !array_key_exists($alan, $kayit)) {
                    continue;
                }
                // Alan o değişiklikten sonra tekrar değiştiyse üzerine yazma
                if (array_key_exists($alan, $yeni) && !deger_esit($kayit[$alan], $yeni[$alan])) {
                    $atlanan[] = ALAN_ETIKETLERI[$alan] ?? $alan;
                    continue;
                }
                $geri[$alan] = $deger;
            }
            if ($geri) {
                kayit_guncelle($tablo, (int)$g['kayit_id'], $geri, 'geri_al');
                mesaj('Değişiklik geri alındı: ' . implode(', ', array_map(function ($a) {
                    return ALAN_ETIKETLERI[$a] ?? $a;
                }, array_keys($geri))) . '.');
            }
            if ($atlanan) {
                mesaj('Şu alanlar sonradan tekrar değiştirildiği için geri alınmadı: ' . implode(', ', $atlanan) . '.', 'dikkat');
            }
            if (!$geri && !$atlanan) {
                mesaj('Geri alınacak bir değişiklik bulunamadı.', 'dikkat');
            }
        }
    }
    yonlendir('index.php?p=sefer&id=' . $id);
}

$h = hesap($s);
$belgeler = all('SELECT * FROM belgeler WHERE sefer_id = ? AND silindi = 0 ORDER BY id', [$id]);
$gecmis = all("SELECT * FROM islem_gecmisi WHERE (tablo = 'seferler' AND kayit_id = ?) OR (tablo = 'belgeler' AND kayit_id IN (SELECT id FROM belgeler WHERE sefer_id = ?)) ORDER BY id DESC LIMIT 100", [$id, $id]);
$tel = sefer_telefon($s);
$telKarttan = trim((string)$s['sofor_telefon']) === '' && $tel !== '';
$waTel = telefon_wa($tel);
$mKonum = wa_mesaj($s, true, false);
$mBelge = wa_mesaj($s, false, true);
$mHepsi = wa_mesaj($s, true, true);
$yHarita = harita_link($s['ydepo_lat'], $s['ydepo_lng']);
$bHarita = harita_link($s['bdepo_lat'], $s['bdepo_lng']);
$silinmis = (bool)$s['silindi'];

sayfa_bas("Sefer #$id", 'seferler');
?>
<?php if ($silinmis): ?>
<div class="uyari hata silinmis-bant">
  🗑️ <b>Bu sefer silinmiş.</b> Listelerde ve toplamlarda görünmez. Geri almak için:
  <form method="post" class="satir-ici"><?= csrf_alan() ?><input type="hidden" name="islem" value="geri_al_sefer"><button class="btn kucuk ana">↩️ Seferi Geri Al</button></form>
</div>
<?php endif; ?>

<div class="baslik-satir">
  <h1>Sefer #<?= $id ?> <?= durum_rozet($s['durum']) ?></h1>
  <?php if (!$silinmis): ?>
  <div class="butonlar">
    <a class="btn" href="index.php?p=sefer_form&id=<?= $id ?>">✏️ Düzenle</a>
    <a class="btn" href="index.php?p=sefer_form&ydepo=<?= (int)$s['yukleme_depo_id'] ?>&bdepo=<?= (int)$s['bosaltim_depo_id'] ?>">➕ Aynı güzergahta yeni sefer</a>
  </div>
  <?php endif; ?>
</div>

<?php if (!$silinmis): ?>
<div class="kart wa-kart">
  <h2>📲 Şoföre Gönder</h2>
  <?php if (!$waTel): ?>
    <div class="uyari hata">Bu seferde ve şoför kartında telefon yok. <a href="index.php?p=sefer_form&id=<?= $id ?>">Düzenle</a> sayfasından ekleyin. (Butonlar WhatsApp'ta kişi seçtirerek açılır.)</div>
  <?php else: ?>
    <p class="soluk">Gönderilecek numara: <b>wa.me/<?= h($waTel) ?></b><?= $telKarttan ? ' (şoför kartından)' : '' ?>
      <?= cep_telefonu_mu($tel) ? '' : ' <span class="kirmizi">⚠️ cep telefonu biçiminde değil</span>' ?></p>
  <?php endif; ?>
  <div class="butonlar">
    <a class="btn wa" target="_blank" rel="noopener" href="<?= h(wa_link($tel, $mKonum)) ?>">📍 Konumları Gönder</a>
    <a class="btn wa" target="_blank" rel="noopener" href="<?= h(wa_link($tel, $mBelge)) ?>">📄 Belge Linklerini Gönder</a>
    <a class="btn wa" target="_blank" rel="noopener" href="<?= h(wa_link($tel, $mHepsi)) ?>">📤 Hepsini Gönder</a>
  </div>
  <div class="butonlar">
    <?php if ($belgeler): ?>
      <button type="button" class="btn" id="dosya-paylas" data-metin="<?= h(wa_mesaj($s, false, false)) ?>"
        data-dosyalar="<?= h(json_encode(array_map(function ($b) {
            return ['url' => 'belge.php?t=' . $b['token'], 'ad' => $b['orijinal_ad'] ?: ('belge.' . pathinfo($b['dosya'], PATHINFO_EXTENSION)), 'mime' => $b['mime']];
        }, $belgeler))) ?>">📎 PDF Dosyalarını Paylaş (WhatsApp vb.)</button>
    <?php endif; ?>
    <button type="button" class="btn" data-kopyala="<?= h($mHepsi) ?>">📋 Mesajı Kopyala</button>
    <button type="button" class="btn" data-wa-sor data-metin="<?= h($mHepsi) ?>">Başka numaraya gönder</button>
  </div>
  <details><summary>Mesaj önizleme</summary><pre class="onizleme"><?= h($mHepsi) ?></pre></details>
</div>
<?php endif; ?>

<div class="iki-sutun">
  <div class="kart">
    <h2>Güzergah</h2>
    <div class="guzergah">
      <div>
        <small>📦 YÜKLEME (SARIM)</small>
        <b><?= h($s['ydepo_ad']) ?></b>
        <div class="soluk"><?= h(implode(' / ', array_filter([$s['ydepo_ilce'], $s['ydepo_il']]))) ?></div>
        <?php if ($yHarita): ?><a target="_blank" rel="noopener" href="<?= h($yHarita) ?>">🗺️ Harita</a> · <a target="_blank" rel="noopener" href="<?= h(yol_tarifi_link($s['ydepo_lat'], $s['ydepo_lng'])) ?>">Yol tarifi</a><?php else: ?><span class="kirmizi">⚠️ Koordinat yok</span> <a href="index.php?p=depo_form&id=<?= (int)$s['yukleme_depo_id'] ?>">ekle</a><?php endif; ?>
      </div>
      <div class="ok">→</div>
      <div>
        <small>🏁 BOŞALTIM</small>
        <b><?= h($s['bdepo_ad']) ?></b>
        <div class="soluk"><?= h(implode(' / ', array_filter([$s['bdepo_ilce'], $s['bdepo_il']]))) ?></div>
        <?php if ($bHarita): ?><a target="_blank" rel="noopener" href="<?= h($bHarita) ?>">🗺️ Harita</a> · <a target="_blank" rel="noopener" href="<?= h(yol_tarifi_link($s['bdepo_lat'], $s['bdepo_lng'])) ?>">Yol tarifi</a><?php else: ?><span class="kirmizi">⚠️ Koordinat yok</span> <a href="index.php?p=depo_form&id=<?= (int)$s['bosaltim_depo_id'] ?>">ekle</a><?php endif; ?>
      </div>
    </div>
    <dl class="bilgiler">
      <dt>Yükleme tarihi</dt><dd><?= h(tarih_tr($s['tarih'])) ?></dd>
      <dt>Teslim tarihi</dt><dd><?= h(tarih_tr($s['teslim_tarihi'])) ?: '—' ?></dd>
      <dt>Şoför</dt><dd><?= h(sofor_adsoyad($s)) ?: '—' ?> <?= $s['sofor_tc'] ? '<span class="soluk">TC ' . h($s['sofor_tc']) . '</span>' : '' ?> <?= $s['sofor_id'] ? '<a class="kucuk-yazi" href="index.php?p=sofor_form&id=' . (int)$s['sofor_id'] . '">şoför kartı →</a>' : '' ?></dd>
      <dt>Telefon</dt><dd><?= $tel ? '<a href="tel:' . h($tel) . '">' . h($tel) . '</a>' . ($telKarttan ? ' <span class="soluk">(kart)</span>' : '') : '—' ?></dd>
      <dt>Çekici / Dorse</dt><dd><?= h($s['cekici_plaka']) ?> / <?= h($s['dorse_plaka']) ?></dd>
      <dt>İrsaliye no</dt><dd><?= h($s['irsaliye_no']) ?: '—' ?></dd>
      <dt>Kantar fiş no</dt><dd><?= h($s['kantar_fis_no']) ?: '—' ?></dd>
      <?php if ($s['notlar']): ?><dt>Notlar</dt><dd><?= nl2br(h($s['notlar'])) ?></dd><?php endif; ?>
      <dt>Kaydeden</dt><dd><?= h($s['olusturan']) ?> · <?= h(tarih_tr($s['olusturma'])) ?></dd>
    </dl>
    <?php if (!$silinmis): ?>
    <form method="post" class="butonlar">
      <?= csrf_alan() ?><input type="hidden" name="islem" value="durum">
      <?php foreach (DURUMLAR as $k => $v): if ($k === $s['durum']) continue; ?>
        <button class="btn kucuk" name="durum" value="<?= $k ?>"><?= h($v) ?> yap</button>
      <?php endforeach; ?>
    </form>
    <?php endif; ?>
  </div>

  <div class="kart">
    <h2>Yük ve Ücret</h2>
    <table class="hesap-tablo">
      <tr><th colspan="2"><?= h($s['urun_ad'] ?: 'Ürün') ?> · <?= kg_fmt($s['miktar_kg']) ?> kg (<?= kg_fmt($h['ton']) ?> ton)</th></tr>
      <tr><td>Ton fiyatı</td><td><?= tl($s['urun_ton_fiyat']) ?> + %<?= h(sayi_fmt($s['urun_kdv'], 2)) ?> KDV</td></tr>
      <tr><td>Mal bedeli (KDV hariç)</td><td><?= tl($h['mal']) ?></td></tr>
      <tr><td>KDV</td><td><?= tl($h['mal_kdv']) ?></td></tr>
      <tr class="toplam"><td>Mal bedeli (KDV dahil)</td><td><?= tl($h['mal_toplam']) ?></td></tr>
      <tr><th colspan="2">🚚 Nakliye (taşıyıcıya)</th></tr>
      <tr><td>Ton başı nakliye</td><td><?= tl($s['nakliye_ton_fiyat']) ?> + %<?= h(sayi_fmt($s['nakliye_kdv'], 2)) ?> KDV</td></tr>
      <tr><td>Nakliye (KDV hariç)</td><td><?= tl($h['nak']) ?></td></tr>
      <tr><td>KDV</td><td><?= tl($h['nak_kdv']) ?></td></tr>
      <tr class="toplam"><td>Nakliye (KDV dahil)</td><td><?= tl($h['nak_toplam']) ?></td></tr>
    </table>
    <form method="post" class="butonlar">
      <?= csrf_alan() ?><input type="hidden" name="islem" value="odendi">
      <?= $s['nakliye_odendi'] ? '<span class="rozet d-teslim">✅ Nakliye ödendi</span>' : '<span class="rozet d-planlandi">⏳ Nakliye ödenmedi</span>' ?>
      <?php if (!$silinmis): ?><button class="btn kucuk"><?= $s['nakliye_odendi'] ? 'Ödenmedi olarak işaretle' : 'Ödendi olarak işaretle' ?></button><?php endif; ?>
    </form>
  </div>
</div>

<div class="kart">
  <h2>📄 Belgeler (İrsaliye / Kantar Fişi)</h2>
  <?php if (!$belgeler): ?><p class="soluk">Henüz belge yüklenmedi.</p><?php endif; ?>
  <div class="belgeler">
  <?php foreach ($belgeler as $b): $link = belge_link($b['token']); ?>
    <div class="belge">
      <a target="_blank" href="belge.php?t=<?= h($b['token']) ?>" class="belge-ad"><?= strpos((string)$b['mime'], 'pdf') !== false ? '📕' : '🖼️' ?> <b><?= h(BELGE_TURLERI[$b['tur']] ?? 'Belge') ?></b> <span class="soluk"><?= h($b['orijinal_ad']) ?> · <?= (int)round($b['boyut'] / 1024) ?> KB · <?= h(tarih_tr($b['olusturma'])) ?></span></a>
      <?php if (!$silinmis): ?>
      <div class="butonlar">
        <a class="btn kucuk wa" target="_blank" rel="noopener" href="<?= h(wa_link($tel, (BELGE_TURLERI[$b['tur']] ?? 'Belge') . ' - Sefer #' . $id . ' (' . $s['cekici_plaka'] . ")\n" . $link)) ?>">WhatsApp</a>
        <button type="button" class="btn kucuk" data-kopyala="<?= h($link) ?>">Linki kopyala</button>
        <form method="post" class="satir-ici" onsubmit="return confirm('Belge kaldırılsın mı? Şoförün elindeki link de çalışmaz.')"><?= csrf_alan() ?><input type="hidden" name="islem" value="belge_sil"><input type="hidden" name="belge_id" value="<?= (int)$b['id'] ?>"><button class="btn kucuk tehlike">Kaldır</button></form>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if (!$silinmis): $sinir = yukleme_sinirlari(); ?>
  <form method="post" enctype="multipart/form-data" class="yukle-form" data-max-dosya="<?= (int)$sinir['dosya'] ?>" data-max-toplam="<?= (int)$sinir['toplam'] ?>" data-max-adet="<?= (int)$sinir['adet'] ?>">
    <?= csrf_alan() ?><input type="hidden" name="islem" value="belge_yukle">
    <select name="tur"><?php foreach (BELGE_TURLERI as $k => $v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?></select>
    <input type="file" name="dosya[]" multiple accept="application/pdf,image/*" required>
    <button class="btn ana">⬆️ Yükle</button>
  </form>
  <?php endif; ?>
</div>

<div class="kart">
  <details>
    <summary><b>🕓 Değişiklik Geçmişi</b> (<?= count($gecmis) ?>)</summary>
    <table class="tablo gecmis">
      <thead><tr><th>Zaman</th><th>Kullanıcı</th><th>İşlem</th><th>Değişiklik</th><th></th></tr></thead>
      <tbody>
      <?php
      $islemAd = ['ekle' => 'Oluşturuldu', 'duzenle' => 'Düzenlendi', 'sil' => 'Silindi', 'geri_al' => 'Geri alındı'];
      foreach ($gecmis as $g):
          $eski = json_decode((string)$g['eski'], true) ?: [];
          $yeni = json_decode((string)$g['yeni'], true) ?: [];
          $belge = $g['tablo'] === 'belgeler';
          ?>
        <tr>
          <td data-e="Zaman"><?= h(tarih_tr($g['zaman'])) ?></td>
          <td data-e="Kullanıcı"><?= h($g['kullanici']) ?></td>
          <td data-e="İşlem"><?= h(($belge ? 'Belge: ' : '') . ($islemAd[$g['islem']] ?? $g['islem'])) ?></td>
          <td class="kucuk-yazi">
            <?php if ($g['islem'] === 'ekle'): ?>
              <?= $belge ? h((BELGE_TURLERI[$yeni['tur'] ?? ''] ?? 'Belge') . ' yüklendi: ' . ($yeni['orijinal_ad'] ?? '')) : 'Kayıt oluşturuldu' ?>
            <?php else: foreach ($yeni as $k => $v): if ($k === 'guncelleme') continue; ?>
              <div><b><?= h(ALAN_ETIKETLERI[$k] ?? $k) ?>:</b> <?= h(gecmis_deger($k, $eski[$k] ?? null)) ?> → <?= h(gecmis_deger($k, $v)) ?></div>
            <?php endforeach; endif; ?>
          </td>
          <td>
            <?php if ($g['islem'] !== 'ekle' && $eski && !$silinmis): ?>
              <form method="post" onsubmit="return confirm('Bu değişiklik geri alınsın mı?')"><?= csrf_alan() ?><input type="hidden" name="islem" value="gecmis_geri_al"><input type="hidden" name="gecmis_id" value="<?= (int)$g['id'] ?>"><button class="btn kucuk">↩️ Geri al</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
</div>

<?php if (!$silinmis): ?>
<form method="post" class="sil-form" onsubmit="return confirm('Sefer #<?= $id ?> silinsin mi? (Sonradan geri alınabilir)')">
  <?= csrf_alan() ?><input type="hidden" name="islem" value="sil">
  <button class="btn tehlike kucuk">🗑️ Seferi Sil</button>
</form>
<?php endif; ?>
<?php sayfa_son();
