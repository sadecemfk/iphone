<?php
$g = $_GET;
list($where, $prm) = sefer_filtre($g);
$tum = all(sefer_sql() . $where . ' ORDER BY s.tarih DESC, s.id DESC', $prm);

$t = ['kg' => 0, 'mal' => 0, 'mal_toplam' => 0, 'nak' => 0, 'nak_toplam' => 0];
foreach ($tum as $s) {
    if ($s['durum'] === 'iptal') {
        continue;
    }
    $hs = hesap($s);
    $t['kg'] += $s['miktar_kg'];
    foreach (['mal', 'mal_toplam', 'nak', 'nak_toplam'] as $k) {
        $t[$k] += $hs[$k];
    }
}
$ozet = urun_ozet_satirlari(array_filter($tum, function ($s) {
    return $s['durum'] !== 'iptal';
}));

$limit = 100;
$sayfa = max(1, (int)get('s', 1));
$liste = array_slice($tum, ($sayfa - 1) * $limit, $limit);

$urunler = all('SELECT id, ad FROM urunler ORDER BY silindi, ad');
$ydepolar = all("SELECT id, ad, il, ilce FROM depolar WHERE tip = 'yukleme' ORDER BY silindi, ad");
$bdepolar = all("SELECT id, ad, il, ilce FROM depolar WHERE tip = 'bosaltim' ORDER BY silindi, ad");
$soforler = all("SELECT id, ad || ' ' || COALESCE(soyad,'') AS ad FROM soforler ORDER BY silindi, ad");

$hizli = [
    'Bugün' => [date('Y-m-d'), date('Y-m-d')],
    'Dün' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'Bu hafta' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
    'Bu ay' => [date('Y-m-01'), date('Y-m-t')],
    'Geçen ay' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'Bu yıl' => [date('Y-01-01'), date('Y-12-31')],
];
$filtreVar = count(array_filter(array_diff_key($g, ['p' => 1, 's' => 1]), 'strlen')) > 0;
$exportQ = http_build_query(array_merge(array_diff_key($g, ['p' => 1, 's' => 1]), ['p' => 'excel']));

sayfa_bas('Seferler', 'seferler');
?>
<div class="baslik-satir">
  <h1>Seferler</h1>
  <div class="butonlar">
    <a class="btn" href="index.php?<?= h($exportQ) ?>">📊 Excel'e Aktar</a>
    <a class="btn ana" href="index.php?p=sefer_form">➕ Yeni Sefer</a>
  </div>
</div>

<form class="kart filtre" method="get">
  <input type="hidden" name="p" value="seferler">
  <div class="hizli">
    <?php foreach ($hizli as $ad => $ar): $aktif = ($g['bas'] ?? '') === $ar[0] && ($g['bit'] ?? '') === $ar[1]; ?>
      <a class="cip <?= $aktif ? 'aktif' : '' ?>" href="index.php?<?= h(http_build_query(array_merge(array_diff_key($g, ['s' => 1]), ['p' => 'seferler', 'bas' => $ar[0], 'bit' => $ar[1]]))) ?>"><?= $ad ?></a>
    <?php endforeach; ?>
  </div>
  <div class="filtre-izgara">
    <label>Başlangıç<input type="date" name="bas" value="<?= h($g['bas'] ?? '') ?>"></label>
    <label>Bitiş<input type="date" name="bit" value="<?= h($g['bit'] ?? '') ?>"></label>
    <label>Ürün<select name="urun"><?= secenekler($urunler, $g['urun'] ?? '', 'Tümü') ?></select></label>
    <label>Yükleme deposu<select name="ydepo"><?= secenekler($ydepolar, $g['ydepo'] ?? '', 'Tümü', 'depo_etiket') ?></select></label>
    <label>Boşaltım deposu<select name="bdepo"><?= secenekler($bdepolar, $g['bdepo'] ?? '', 'Tümü', 'depo_etiket') ?></select></label>
    <label>Şoför<select name="sofor"><?= secenekler($soforler, $g['sofor'] ?? '', 'Tümü') ?></select></label>
    <label>Durum<select name="durum"><option value="">Tümü</option><?php foreach (DURUMLAR as $k => $v): ?><option value="<?= $k ?>" <?= ($g['durum'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
    <label>Nakliye ödemesi<select name="odendi"><option value="">Tümü</option><option value="1" <?= ($g['odendi'] ?? '') === '1' ? 'selected' : '' ?>>Ödendi</option><option value="0" <?= ($g['odendi'] ?? '') === '0' ? 'selected' : '' ?>>Ödenmedi</option></select></label>
    <label>Plaka<input name="plaka" class="buyuk-harf" value="<?= h($g['plaka'] ?? '') ?>" placeholder="45AAR75"></label>
    <label>Ara<input name="ara" value="<?= h($g['ara'] ?? '') ?>" placeholder="İrsaliye no, TC, isim…"></label>
  </div>
  <div class="butonlar">
    <button class="btn ana">🔎 Filtrele</button>
    <?php if ($filtreVar): ?><a class="btn" href="index.php?p=seferler">Temizle</a><?php endif; ?>
  </div>
</form>

<div class="istatistik">
  <div><small>Sefer</small><b><?= count($tum) ?></b></div>
  <div><small>Toplam</small><b><?= kg_fmt(round($t['kg'] / 1000, 3)) ?></b><span>ton</span></div>
  <div><small>Mal bedeli KDV hariç</small><b class="kucuk"><?= tl($t['mal']) ?></b><span>dahil: <?= tl($t['mal_toplam']) ?></span></div>
  <div><small>Nakliye KDV hariç</small><b class="kucuk"><?= tl($t['nak']) ?></b><span>dahil: <?= tl($t['nak_toplam']) ?></span></div>
</div>

<?php if (count($ozet) > 1 || ($ozet && empty($g['urun']))): ?>
<div class="kart">
  <h2>Ürün Bazında Özet</h2>
  <div class="tablo-kap">
  <table class="tablo">
    <thead><tr><th>Ürün</th><th class="sag">Sefer</th><th class="sag">Ton</th><th class="sag">Mal Bedeli (KDV hariç)</th><th class="sag">Nakliye (KDV hariç)</th></tr></thead>
    <tbody>
    <?php foreach ($ozet as $o): ?>
      <tr><td data-e="Ürün"><b><?= h($o[0]) ?></b></td><td data-e="Sefer" class="sag"><?= $o[1] ?></td><td data-e="Ton" class="sag"><?= kg_fmt($o[3]) ?></td><td data-e="Mal Bedeli" class="sag"><?= tl($o[4]) ?></td><td data-e="Nakliye" class="sag"><?= tl($o[6]) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="kart tablo-kap">
<table class="tablo seferler">
  <thead><tr>
    <th>No</th><th>Tarih</th><th>Durum</th><th>Yükleme → Boşaltım</th><th>Şoför / Araç</th><th>Ürün</th><th class="sag">kg</th><th class="sag">Mal Bedeli</th><th class="sag">Nakliye</th><th>Belge</th>
  </tr></thead>
  <tbody>
  <?php foreach ($liste as $s): $hs = hesap($s); ?>
    <tr onclick="if(!event.target.closest('a'))location='index.php?p=sefer&id=<?= $s['id'] ?>'" class="tikla">
      <td data-e="No"><a href="index.php?p=sefer&id=<?= $s['id'] ?>"><b>#<?= $s['id'] ?></b></a></td>
      <td data-e="Tarih"><?= h(tarih_tr($s['tarih'])) ?></td>
      <td data-e="Durum"><?= durum_rozet($s['durum']) ?></td>
      <td data-e="Güzergah"><?= h($s['ydepo_ad']) ?> → <?= h($s['bdepo_ad']) ?><?= $s['bdepo_il'] ? ' <span class="soluk">(' . h($s['bdepo_il']) . ')</span>' : '' ?></td>
      <td data-e="Şoför"><?= h(sofor_adsoyad($s)) ?><div class="soluk"><?= h($s['cekici_plaka']) ?><?= $s['dorse_plaka'] ? ' / ' . h($s['dorse_plaka']) : '' ?></div></td>
      <td data-e="Ürün"><?= h($s['urun_ad']) ?></td>
      <td data-e="kg" class="sag"><?= kg_fmt($s['miktar_kg']) ?></td>
      <td data-e="Mal Bedeli" class="sag"><?= tl($hs['mal']) ?></td>
      <td data-e="Nakliye" class="sag"><?= tl($hs['nak']) ?> <?= $s['nakliye_odendi'] ? '✅' : '⏳' ?></td>
      <td data-e="Belge"><?= $s['belge_sayisi'] ? '📄 ' . (int)$s['belge_sayisi'] : '<span class="kirmizi">yok</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$liste): ?><tr><td colspan="10" class="soluk">Bu kriterlere uygun sefer yok.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= sayfalama(count($tum), $sayfa, $limit) ?>
</div>
<?php sayfa_son();
