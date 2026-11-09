<?php
$ara = get('ara');
$w = 'so.silindi = 0';
$prm = [];
if ($ara !== '') {
    $w .= ' AND (so.ad LIKE ? OR so.soyad LIKE ? OR so.tc LIKE ? OR so.telefon LIKE ? OR so.cekici_plaka LIKE ? OR so.dorse_plaka LIKE ?)';
    $prm = array_fill(0, 6, '%' . $ara . '%');
}
$liste = all("SELECT so.*, (SELECT COUNT(*) FROM seferler s WHERE s.silindi = 0 AND s.sofor_id = so.id) AS sefer_sayisi,
    (SELECT MAX(tarih) FROM seferler s WHERE s.silindi = 0 AND s.sofor_id = so.id) AS son_sefer
    FROM soforler so WHERE $w ORDER BY so.ad, so.soyad", $prm);
sayfa_bas('Şoförler', 'soforler');
?>
<div class="baslik-satir">
  <h1>Şoförler</h1>
  <a class="btn ana" href="index.php?p=sofor_form">➕ Yeni Şoför</a>
</div>
<form class="kart filtre-satir">
  <input type="hidden" name="p" value="soforler">
  <input name="ara" value="<?= h($ara) ?>" placeholder="Ad, TC, telefon veya plaka ara">
  <button class="btn">Ara</button>
</form>
<div class="tablo-kap kart">
<table class="tablo">
  <thead><tr><th>Ad Soyad</th><th>TC</th><th>Telefon</th><th>Çekici</th><th>Dorse</th><th>Sefer</th><th>Son Sefer</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($liste as $s): ?>
    <tr>
      <td data-e="Ad Soyad"><b><?= h($s['ad'] . ' ' . $s['soyad']) ?></b></td>
      <td data-e="TC"><?= h($s['tc']) ?></td>
      <td data-e="Telefon"><?php if ($s['telefon']): ?><a href="tel:<?= h($s['telefon']) ?>"><?= h($s['telefon']) ?></a> · <a class="wa-yazi" target="_blank" rel="noopener" href="https://wa.me/<?= h(telefon_wa($s['telefon'])) ?>">WhatsApp</a><?php endif; ?></td>
      <td data-e="Çekici"><?= h($s['cekici_plaka']) ?></td>
      <td data-e="Dorse"><?= h($s['dorse_plaka']) ?></td>
      <td data-e="Sefer"><a href="index.php?p=seferler&sofor=<?= $s['id'] ?>"><?= (int)$s['sefer_sayisi'] ?></a></td>
      <td data-e="Son Sefer"><?= h(tarih_tr($s['son_sefer'])) ?></td>
      <td><a class="btn kucuk" href="index.php?p=sofor_form&id=<?= $s['id'] ?>">✏️</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$liste): ?><tr><td colspan="8" class="soluk">Kayıt yok.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php sayfa_son();
