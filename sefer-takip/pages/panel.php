<?php
$bugun = date('Y-m-d');
$ayBas = date('Y-m-01');
$ayS = all(sefer_sql() . " WHERE s.silindi = 0 AND s.durum != 'iptal' AND s.tarih >= ? AND s.tarih <= ?", [$ayBas, date('Y-m-t')]);
$t = ['adet' => 0, 'kg' => 0, 'mal' => 0, 'nak' => 0];
foreach ($ayS as $s) {
    $hs = hesap($s);
    $t['adet']++;
    $t['kg'] += $s['miktar_kg'];
    $t['mal'] += $hs['mal_toplam'];
    $t['nak'] += $hs['nak_toplam'];
}
$bugunAdet = (int)one('SELECT COUNT(*) c FROM seferler WHERE silindi = 0 AND tarih = ?', [$bugun])['c'];
$yolda = (int)one("SELECT COUNT(*) c FROM seferler WHERE silindi = 0 AND durum = 'yuklendi'")['c'];
$odenmemis = one("SELECT COUNT(*) c, COALESCE(SUM(miktar_kg/1000.0*nakliye_ton_fiyat*(1+nakliye_kdv/100.0)),0) t FROM seferler WHERE silindi = 0 AND durum != 'iptal' AND nakliye_odendi = 0");
$son = all(sefer_sql() . ' WHERE s.silindi = 0 ORDER BY s.tarih DESC, s.id DESC LIMIT 10');
$eksik = [
    'depo' => (int)one('SELECT COUNT(*) c FROM depolar WHERE silindi = 0')['c'],
    'sofor' => (int)one('SELECT COUNT(*) c FROM soforler WHERE silindi = 0')['c'],
    'urun' => (int)one('SELECT COUNT(*) c FROM urunler WHERE silindi = 0')['c'],
];

sayfa_bas('Panel', 'panel');
?>
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
  <div><small>Bu ay</small><b><?= $t['adet'] ?></b><span><?= kg_fmt($t['kg'] / 1000) ?> ton</span></div>
  <div><small>Bu ay mal bedeli (KDV dahil)</small><b class="kucuk"><?= tl($t['mal']) ?></b></div>
  <div><small>Bu ay nakliye (KDV dahil)</small><b class="kucuk"><?= tl($t['nak']) ?></b></div>
  <div><a href="index.php?p=seferler&odendi=0"><small>Ödenmemiş nakliye</small><b class="kucuk"><?= tl($odenmemis['t']) ?></b><span><?= (int)$odenmemis['c'] ?> sefer</span></a></div>
</div>

<div class="kart">
  <div class="baslik-satir"><h2>Son Seferler</h2><a href="index.php?p=seferler">Tümü →</a></div>
  <?php if (!$son): ?><p class="soluk">Henüz sefer yok.</p><?php endif; ?>
  <div class="liste">
  <?php foreach ($son as $s): ?>
    <a class="liste-oge" href="index.php?p=sefer&id=<?= $s['id'] ?>">
      <div><b>#<?= $s['id'] ?> · <?= h(tarih_tr($s['tarih'])) ?></b> <?= durum_rozet($s['durum']) ?></div>
      <div><?= h($s['ydepo_ad']) ?> → <?= h($s['bdepo_ad']) ?></div>
      <div class="soluk"><?= h($s['urun_ad']) ?> · <?= kg_fmt($s['miktar_kg']) ?> kg · <?= h(sofor_adsoyad($s)) ?> · <?= h($s['cekici_plaka']) ?></div>
    </a>
  <?php endforeach; ?>
  </div>
</div>
<?php sayfa_son();
