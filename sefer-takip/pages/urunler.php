<?php
$liste = all('SELECT u.*, (SELECT COUNT(*) FROM seferler s WHERE s.silindi = 0 AND s.urun_id = u.id) AS sefer_sayisi,
    (SELECT COALESCE(SUM(miktar_kg),0) FROM seferler s WHERE s.silindi = 0 AND s.durum != \'iptal\' AND s.urun_id = u.id) AS toplam_kg
    FROM urunler u WHERE u.silindi = 0 ORDER BY u.ad');
sayfa_bas('Ürünler', 'urunler');
?>
<div class="baslik-satir">
  <h1>Ürünler</h1>
  <a class="btn ana" href="index.php?p=urun_form">➕ Yeni Ürün</a>
</div>
<div class="tablo-kap kart">
<table class="tablo">
  <thead><tr><th>Ürün</th><th class="sag">Ton Fiyatı</th><th class="sag">KDV</th><th class="sag">Sefer</th><th class="sag">Toplam Taşınan</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($liste as $u): ?>
    <tr>
      <td data-e="Ürün"><b><?= h($u['ad']) ?></b></td>
      <td data-e="Ton Fiyatı" class="sag"><?= tl($u['ton_fiyat']) ?> + KDV</td>
      <td data-e="KDV" class="sag">%<?= h(sayi_input($u['kdv_orani'])) ?></td>
      <td data-e="Sefer" class="sag"><a href="index.php?p=seferler&urun=<?= $u['id'] ?>"><?= (int)$u['sefer_sayisi'] ?></a></td>
      <td data-e="Toplam" class="sag"><?= kg_fmt($u['toplam_kg'] / 1000) ?> ton</td>
      <td><a class="btn kucuk" href="index.php?p=urun_form&id=<?= $u['id'] ?>">✏️</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$liste): ?><tr><td colspan="6" class="soluk">Kayıt yok.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="soluk">Ürün fiyatı değiştirildiğinde eski seferlerin fiyatı <b>değişmez</b>; her sefer kendi fiyatını saklar.</p>
<?php sayfa_son();
