<?php
// Yazdırılabilir Sefer Fişi: yükleme ve boşaltım tarafı bir arada. ?fiyatsiz=1 → ücretler gizli (şoför / fabrika için)
$id = (int)get('id');
$s = one(sefer_sql() . ' WHERE s.id = ?', [$id]);
if (!$s) {
    mesaj('Sefer bulunamadı.', 'hata');
    yonlendir('index.php?p=seferler');
}
$fiyatsiz = get('fiyatsiz') === '1';
$h = hesap($s);
$fd = fire_durumu($h);
$belgeler = all('SELECT * FROM belgeler WHERE sefer_id = ? AND silindi = 0 ORDER BY id', [$id]);
$firma = (string)ayar_deger('firma_adi') ?: cfg('app_name', 'Sefer Takip');
$firmaBilgi = (string)ayar_deger('firma_bilgi');
$tel = sefer_telefon($s);

$depoAdres = function ($onEk) use ($s) {
    $satirlar = [h($s[$onEk . '_ad'])];
    $yer = implode(' / ', array_filter([$s[$onEk . '_ilce'], $s[$onEk . '_il']]));
    if ($yer) {
        $satirlar[] = h($yer);
    }
    if ($s[$onEk . '_adres']) {
        $satirlar[] = h($s[$onEk . '_adres']);
    }
    if ($s[$onEk . '_lat'] !== null && $s[$onEk . '_lat'] !== '') {
        $satirlar[] = '<span class="kucuk">Konum: ' . h(koordinat_fmt($s[$onEk . '_lat']) . ', ' . koordinat_fmt($s[$onEk . '_lng'])) . '</span>';
    }
    return implode('<br>', $satirlar);
};
$belgeAdlari = function ($taraf) use ($belgeler) {
    $l = [];
    foreach ($belgeler as $b) {
        if ((BELGE_TARAFI[$b['tur']] ?? 'diger') === $taraf) {
            $l[] = h(BELGE_TURLERI[$b['tur']] ?? 'Belge');
        }
    }
    return $l ? implode(', ', $l) : '—';
};
$bosVar = bosaltim_kg_var($s);
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sefer Fişi #<?= $id ?> · <?= h($firma) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font: 13px/1.45 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; background: #e9ecf1; margin: 0; padding: 16px; }
  .sayfa { background: #fff; max-width: 800px; margin: 0 auto; padding: 28px 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.08); }
  .ust { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #1f4e79; padding-bottom: 10px; margin-bottom: 14px; }
  .ust h1 { font-size: 20px; margin: 0; color: #1f4e79; }
  .ust .no { text-align: right; }
  .ust .no b { font-size: 18px; }
  .kucuk { font-size: 11px; color: #555; }
  table { width: 100%; border-collapse: collapse; }
  .bilgi td { padding: 4px 6px; vertical-align: top; }
  .bilgi td:first-child { color: #555; width: 120px; }
  .taraflar { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 14px 0; }
  .taraf { border: 1px solid #c9d2de; border-radius: 6px; padding: 10px 12px; }
  .taraf h2 { font-size: 14px; margin: 0 0 6px; color: #1f4e79; letter-spacing: .03em; }
  .taraf table td { padding: 3px 0; border-bottom: 1px dashed #e1e6ee; }
  .taraf table td:last-child { text-align: right; font-weight: 600; }
  .fire { border: 1px solid #c9d2de; border-radius: 6px; padding: 8px 12px; display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
  .fire.asim, .fire.fazla { border-color: #c0392b; background: #fdf1f0; }
  .ucret-kap { margin-top: 14px; overflow-x: auto; }
  .ucret td:not(:first-child) { white-space: nowrap; }
  .ucret td, .ucret th { padding: 5px 6px; border-bottom: 1px solid #e1e6ee; text-align: right; }
  .ucret th:first-child, .ucret td:first-child { text-align: left; }
  .ucret th { background: #f2f5f9; font-size: 12px; }
  .imzalar { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 28px; }
  .imza { border-top: 1px solid #333; padding-top: 4px; text-align: center; font-size: 12px; min-height: 60px; }
  .not { margin-top: 12px; padding: 8px 10px; background: #f7f9fb; border-radius: 6px; }
  .araclar { max-width: 800px; margin: 0 auto 12px; display: flex; gap: 8px; flex-wrap: wrap; }
  .araclar a, .araclar button { font: inherit; font-size: 14px; padding: 9px 14px; border-radius: 8px; border: 1px solid #c9d2de; background: #fff; color: #1f4e79; text-decoration: none; cursor: pointer; }
  .araclar .ana { background: #1f4e79; color: #fff; border-color: #1f4e79; }
  @media (max-width: 560px) {
    body { padding: 8px; } .sayfa { padding: 16px; }
    .taraflar, .imzalar { grid-template-columns: 1fr; }
  }
  @media print {
    body { background: #fff; padding: 0; }
    .sayfa { box-shadow: none; border-radius: 0; max-width: none; padding: 0; }
    .araclar { display: none; }
    .taraflar { grid-template-columns: 1fr 1fr !important; }
    .imzalar { grid-template-columns: repeat(3, 1fr) !important; }
    @page { size: A4; margin: 14mm; }
  }
</style>
</head>
<body>
<div class="araclar">
  <button class="ana" onclick="window.print()">🖨️ Yazdır / PDF olarak kaydet</button>
  <?php if ($fiyatsiz): ?>
    <a href="index.php?p=fis&id=<?= $id ?>">Fiyatlı fiş</a>
  <?php else: ?>
    <a href="index.php?p=fis&id=<?= $id ?>&fiyatsiz=1">Fiyatsız fiş (şoför / fabrika için)</a>
  <?php endif; ?>
  <a href="index.php?p=sefer&id=<?= $id ?>">← Sefere dön</a>
</div>
<div class="sayfa">
  <div class="ust">
    <div>
      <h1><?= h($firma) ?></h1>
      <?php if ($firmaBilgi): ?><div class="kucuk"><?= nl2br(h($firmaBilgi)) ?></div><?php endif; ?>
    </div>
    <div class="no">
      <div>SEFER FİŞİ</div>
      <b>No: <?= $id ?></b>
      <div class="kucuk">Durum: <?= h(DURUMLAR[$s['durum']] ?? $s['durum']) ?><?= $s['silindi'] ? ' · SİLİNMİŞ KAYIT' : '' ?></div>
      <div class="kucuk">Düzenlenme: <?= h(date('d.m.Y H:i')) ?></div>
    </div>
  </div>

  <table class="bilgi">
    <tr><td>Ürün</td><td><b><?= h($s['urun_ad'] ?: '—') ?></b></td></tr>
    <tr><td>Şoför</td><td><?= h(sofor_adsoyad($s)) ?: '—' ?><?= $s['sofor_tc'] ? ' · TC ' . h($s['sofor_tc']) : '' ?><?= $tel ? ' · Tel ' . h($tel) : '' ?></td></tr>
    <tr><td>Araç</td><td>Çekici <b><?= h($s['cekici_plaka']) ?: '—' ?></b> · Dorse <b><?= h($s['dorse_plaka']) ?: '—' ?></b></td></tr>
  </table>

  <div class="taraflar">
    <div class="taraf">
      <h2>📦 YÜKLEME</h2>
      <div><?= $depoAdres('ydepo') ?></div>
      <table>
        <tr><td>Tarih</td><td><?= h(tarih_tr($s['tarih'])) ?></td></tr>
        <tr><td>Kantar net</td><td><?= (float)$s['miktar_kg'] > 0 ? kg_fmt($s['miktar_kg']) . ' kg' : '—' ?></td></tr>
        <tr><td>İrsaliye no</td><td><?= h($s['irsaliye_no']) ?: '—' ?></td></tr>
        <tr><td>Kantar fiş no</td><td><?= h($s['kantar_fis_no']) ?: '—' ?></td></tr>
        <tr><td>Belgeler</td><td><?= $belgeAdlari('yukleme') ?></td></tr>
      </table>
    </div>
    <div class="taraf">
      <h2>🏁 BOŞALTIM</h2>
      <div><?= $depoAdres('bdepo') ?></div>
      <table>
        <tr><td>Tarih</td><td><?= h(tarih_tr($s['teslim_tarihi'])) ?: '—' ?></td></tr>
        <tr><td>Kantar net</td><td><?= $bosVar ? kg_fmt($s['bosaltim_kg']) . ' kg' : '—' ?></td></tr>
        <tr><td>İrsaliye no</td><td><?= h($s['bosaltim_irsaliye_no']) ?: '—' ?></td></tr>
        <tr><td>Kantar fiş no</td><td><?= h($s['bosaltim_kantar_fis_no']) ?: '—' ?></td></tr>
        <tr><td>Belgeler</td><td><?= $belgeAdlari('bosaltim') ?></td></tr>
      </table>
    </div>
  </div>

  <div class="fire <?= h((string)$fd) ?>">
    <?php if ($bosVar): ?>
      <span>Yükleme <b><?= kg_fmt($s['miktar_kg']) ?> kg</b> · Boşaltım <b><?= kg_fmt($s['bosaltim_kg']) ?> kg</b></span>
      <span><?= $h['fire_kg'] < 0 ? 'Fazla' : 'Fire' ?>: <b><?= h(fire_metni($h)) ?></b>
        <?= $fd === 'normal' ? '· tolerans içinde' : '· ⚠️ tolerans (%' . h(sayi_fmt(fire_toleransi(), 2)) . ') aşıldı' ?></span>
    <?php else: ?>
      <span>Boşaltım kantarı henüz girilmedi; fire hesaplanmadı.</span>
    <?php endif; ?>
  </div>

  <?php if (!$fiyatsiz): ?>
  <div class="ucret-kap"><table class="ucret">
    <tr><th>Ücret</th><th>Esas</th><th>Ton fiyatı</th><th>KDV hariç</th><th>KDV</th><th>KDV dahil</th></tr>
    <tr>
      <td>Mal bedeli</td>
      <td><?= h(ESASLAR[$s['mal_esas']] ?? $s['mal_esas']) ?><br><span class="kucuk"><?= kg_fmt($h['mal_kg']) ?> kg<?= $h['mal_gecici'] ? ' (geçici)' : '' ?></span></td>
      <td><?= tl($s['urun_ton_fiyat']) ?></td><td><?= tl($h['mal']) ?></td><td>%<?= h(sayi_fmt($s['urun_kdv'], 2)) ?> · <?= tl($h['mal_kdv']) ?></td><td><b><?= tl($h['mal_toplam']) ?></b></td>
    </tr>
    <tr>
      <td>Nakliye <span class="kucuk">(<?= $s['nakliye_odendi'] ? 'ödendi' : 'ödenmedi' ?>)</span></td>
      <td><?= h(ESASLAR[$s['nakliye_esas']] ?? $s['nakliye_esas']) ?><br><span class="kucuk"><?= kg_fmt($h['nak_kg']) ?> kg<?= $h['nak_gecici'] ? ' (geçici)' : '' ?></span></td>
      <td><?= tl($s['nakliye_ton_fiyat']) ?></td><td><?= tl($h['nak']) ?></td><td>%<?= h(sayi_fmt($s['nakliye_kdv'], 2)) ?> · <?= tl($h['nak_kdv']) ?></td><td><b><?= tl($h['nak_toplam']) ?></b></td>
    </tr>
  </table></div>
  <?php endif; ?>

  <?php if ($s['notlar']): ?><div class="not"><b>Not:</b> <?= nl2br(h($s['notlar'])) ?></div><?php endif; ?>

  <div class="imzalar">
    <div class="imza">Yükleme Yetkilisi<br><span class="kucuk">Ad Soyad / İmza</span></div>
    <div class="imza">Şoför<br><span class="kucuk"><?= h(sofor_adsoyad($s)) ?></span></div>
    <div class="imza">Teslim Alan (Boşaltım)<br><span class="kucuk">Ad Soyad / İmza</span></div>
  </div>
</div>
</body>
</html>
<?php
exit;
