<?php
$tablolar = [
    'seferler' => 'Seferler',
    'depolar' => 'Depolar',
    'soforler' => 'Şoförler',
    'urunler' => 'Ürünler',
    'belgeler' => 'Belgeler',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t = post('tablo');
    if (isset($tablolar[$t])) {
        kayit_guncelle($t, (int)post('id'), ['silindi' => 0], 'geri_al');
        mesaj('Kayıt geri alındı.');
    }
    yonlendir('index.php?p=silinenler');
}

$veri = [
    'seferler' => all(sefer_sql() . ' WHERE s.silindi = 1 ORDER BY s.guncelleme DESC'),
    'depolar' => all('SELECT * FROM depolar WHERE silindi = 1 ORDER BY guncelleme DESC'),
    'soforler' => all('SELECT * FROM soforler WHERE silindi = 1 ORDER BY guncelleme DESC'),
    'urunler' => all('SELECT * FROM urunler WHERE silindi = 1 ORDER BY guncelleme DESC'),
    'belgeler' => all('SELECT * FROM belgeler WHERE silindi = 1 ORDER BY id DESC'),
];
$etiket = [
    'seferler' => function ($r) {
        return '#' . $r['id'] . ' · ' . tarih_tr($r['tarih']) . ' · ' . $r['ydepo_ad'] . ' → ' . $r['bdepo_ad'] . ' · ' . $r['urun_ad'] . ' ' . kg_fmt($r['miktar_kg']) . ' kg';
    },
    'depolar' => function ($r) {
        return depo_etiket($r) . ' (' . ($r['tip'] === 'yukleme' ? 'Yükleme' : 'Boşaltım') . ')';
    },
    'soforler' => function ($r) {
        return $r['ad'] . ' ' . $r['soyad'] . ' · ' . $r['cekici_plaka'];
    },
    'urunler' => function ($r) {
        return $r['ad'];
    },
    'belgeler' => function ($r) {
        return 'Sefer #' . $r['sefer_id'] . ' · ' . (BELGE_TURLERI[$r['tur']] ?? '') . ' · ' . $r['orijinal_ad'];
    },
];

sayfa_bas('Silinenler', 'yedek');
?>
<h1>🗑️ Silinen Kayıtlar</h1>
<p class="soluk">Sistemde hiçbir kayıt kalıcı olarak silinmez. Buradan geri alabilirsiniz.</p>
<?php foreach ($tablolar as $t => $ad): ?>
  <div class="kart">
    <h2><?= h($ad) ?> (<?= count($veri[$t]) ?>)</h2>
    <?php if (!$veri[$t]): ?><p class="soluk">Silinmiş kayıt yok.</p><?php endif; ?>
    <?php foreach ($veri[$t] as $r): ?>
      <div class="silinen">
        <span><?= h($etiket[$t]($r)) ?></span>
        <span class="butonlar">
          <?php if ($t === 'seferler'): ?><a class="btn kucuk" href="index.php?p=sefer&id=<?= $r['id'] ?>">Gör</a><?php endif; ?>
          <?php if ($t === 'belgeler'): ?><a class="btn kucuk" target="_blank" href="belge.php?t=<?= h($r['token']) ?>">Gör</a><?php endif; ?>
          <form method="post" class="satir-ici"><?= csrf_alan() ?><input type="hidden" name="tablo" value="<?= $t ?>"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn kucuk ana">↩️ Geri Al</button></form>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php sayfa_son();
