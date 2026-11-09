<?php
$bugun = date('Y-m-d');
$bugunAdet = (int)one('SELECT COUNT(*) c FROM seferler WHERE silindi = 0 AND tarih = ?', [$bugun])['c'];
$ayToplam = sefer_toplamlar(' WHERE s.silindi = 0 AND s.tarih >= ? AND s.tarih <= ?', [date('Y-m-01'), date('Y-m-t')]);
$bosEksik = (int)one("SELECT COUNT(*) c FROM seferler WHERE silindi = 0 AND bosaltim_kg IS NULL AND durum IN ('yuklendi', 'teslim')")['c'];
$yolda = (int)one("SELECT COUNT(*) c FROM seferler WHERE silindi = 0 AND durum = 'yuklendi'")['c'];
$odenmemis = one("SELECT COUNT(*) c, COALESCE(SUM(" . sql_esas_kg('nakliye_esas') . " / 1000.0 * s.nakliye_ton_fiyat * (1 + s.nakliye_kdv / 100.0)), 0) t FROM seferler s WHERE s.silindi = 0 AND s.durum != 'iptal' AND s.nakliye_odendi = 0");
$son = all(sefer_sql() . ' WHERE s.silindi = 0 ORDER BY s.tarih DESC, s.id DESC LIMIT 10');
$eksik = [
    'depo' => (int)one('SELECT COUNT(*) c FROM depolar WHERE silindi = 0')['c'],
    'sofor' => (int)one('SELECT COUNT(*) c FROM soforler WHERE silindi = 0')['c'],
    'urun' => (int)one('SELECT COUNT(*) c FROM urunler WHERE silindi = 0')['c'],
];

// Yönetici uyarıları
$uyarilar = [];
$erisimTesti = null;
if (yonetici_mi()) {
    $seferVar = (int)one('SELECT COUNT(*) c FROM seferler')['c'] > 0;
    $sonZip = ayar('son_zip_yedek');
    $gun = (int)cfg('yedek_hatirlatma_gun', 7);
    if ($seferVar && (!$sonZip || strtotime($sonZip) < time() - $gun * 86400)) {
        $uyarilar[] = ['hata', '💾 ' . ($sonZip ? 'Son tam yedek (ZIP) ' . tarih_tr($sonZip) . ' tarihinde alındı.' : 'Henüz hiç tam yedek (ZIP) indirilmedi.')
            . ' Sunucu arızasına karşı yedeği indirip bilgisayarınızda / iCloud\'da saklayın.', 'index.php?p=yedek', 'Yedek al →'];
    }
    if (cfg('acil_sifre_sifirlama')) {
        $uyarilar[] = ['hata', '🔑 config.local.php içinde "acil_sifre_sifirlama" ayarı duruyor. Şifre sıfırlandıysa bu ayarı hemen silin.', '', ''];
    }
    if (!is_https()) {
        $uyarilar[] = ['dikkat', '🔓 Site HTTPS (SSL) olmadan açılmış; şifreler şifrelenmeden gider. Hostingde SSL kurup config.local.php içinde https_zorunlu => true yapın.', '', ''];
    }
    $web = data_dir_web_icinde();
    if ($web !== null) {
        $test = @file_get_contents(data_dir() . '/erisim-testi.txt');
        if ($test) {
            $erisimTesti = ['url' => $web . '/erisim-testi.txt', 'deger' => $test];
        }
    }
}

sayfa_bas('Panel', 'panel');
?>
<?php if ($erisimTesti): ?>
<div class="uyari hata" id="erisim-uyari" hidden data-url="<?= h($erisimTesti['url']) ?>" data-deger="<?= h($erisimTesti['deger']) ?>">
  🚨 <b>GÜVENLİK UYARISI:</b> Veri klasörü internetten erişilebilir durumda! Veritabanı ve belgeler indirilebilir.
  Hemen config.local.php içinde <b>data_dir</b> ayarını web klasörü (public_html) dışındaki bir klasöre taşıyın.
</div>
<?php endif; ?>
<?php foreach ($uyarilar as $u): ?>
  <div class="uyari <?= h($u[0]) ?>"><?= h($u[1]) ?> <?php if ($u[2]): ?><a href="<?= h($u[2]) ?>"><b><?= h($u[3]) ?></b></a><?php endif; ?></div>
<?php endforeach; ?>

<div class="baslik-satir">
  <h1>Panel</h1>
  <a class="btn ana" href="index.php?p=sefer_form">➕ Yeni Sefer</a>
</div>

<?php if (!$eksik['depo'] || !$eksik['sofor'] || !$eksik['urun']): ?>
<div class="kart bilgi">
  <b>Başlarken:</b>
  <?php if (!$eksik['depo']): ?><a href="index.php?p=depo_form&tip=yukleme">Yükleme (sarım) deposu</a> ve <a href="index.php?p=depo_form&tip=bosaltim">boşaltım deposu</a> ekleyin. <?php endif; ?>
  <?php if (!$eksik['sofor']): ?><a href="index.php?p=sofor_form">Şoför ekleyin.</a> <?php endif; ?>
  <?php if (!$eksik['urun']): ?><a href="index.php?p=urun_form">Ürün ekleyin</a> (ör. Fındık, ton fiyatı). <?php endif; ?>
</div>
<?php endif; ?>

<div class="istatistik">
  <div><small>Bugün</small><b><?= $bugunAdet ?></b><span>sefer</span></div>
  <div><small>Yolda</small><b><?= $yolda ?></b><span>araç</span></div>
  <div><small>Bu ay</small><b><?= (int)$ayToplam['adet'] ?></b><span><?= kg_fmt($ayToplam['kg'] / 1000) ?> ton yüklendi</span></div>
  <div><small>Bu ay mal bedeli (KDV dahil)</small><b class="kucuk"><?= tl($ayToplam['mal_toplam']) ?></b></div>
  <div><small>Bu ay nakliye (KDV dahil)</small><b class="kucuk"><?= tl($ayToplam['nak_toplam']) ?></b></div>
  <div><a href="index.php?p=seferler&bas=<?= date('Y-m-01') ?>&bit=<?= date('Y-m-t') ?>&bosaltim=asim"><small>Bu ay fire</small><b class="kucuk <?= $ayToplam['fire_asim'] ? 'kirmizi' : '' ?>"><?= kg_fmt($ayToplam['fire_kg']) ?> kg</b><span><?= $ayToplam['fire_yuzde'] !== null ? '%' . h(sayi_fmt($ayToplam['fire_yuzde'], 2)) . ' · ' : '' ?><?= (int)$ayToplam['fire_asim'] ?> sefer tolerans üstü</span></a></div>
  <?php if ($bosEksik): ?><div><a href="index.php?p=seferler&bosaltim=eksik"><small>Boşaltım kantarı bekleyen</small><b class="kirmizi"><?= $bosEksik ?></b><span>sefer</span></a></div><?php endif; ?>
  <div><a href="index.php?p=seferler&odendi=0"><small>Ödenmemiş nakliye</small><b class="kucuk"><?= tl($odenmemis['t']) ?></b><span><?= (int)$odenmemis['c'] ?> sefer</span></a></div>
</div>

<div class="kart">
  <div class="baslik-satir"><h2>Son Seferler</h2><a href="index.php?p=seferler">Tümü →</a></div>
  <?php if (!$son): ?><p class="soluk">Henüz sefer yok.</p><?php endif; ?>
  <div class="liste">
  <?php foreach ($son as $s): ?>
    <a class="liste-oge" href="index.php?p=sefer&id=<?= (int)$s['id'] ?>">
      <div><b>#<?= (int)$s['id'] ?> · <?= h(tarih_tr($s['tarih'])) ?></b> <?= durum_rozet($s['durum']) ?></div>
      <div><?= h($s['ydepo_ad']) ?> → <?= h($s['bdepo_ad']) ?></div>
      <div class="soluk"><?= h($s['urun_ad']) ?> · <?= kg_fmt($s['miktar_kg']) ?> kg · <?= h(sofor_adsoyad($s)) ?> · <?= h($s['cekici_plaka']) ?></div>
    </a>
  <?php endforeach; ?>
  </div>
</div>
<?php sayfa_son();
