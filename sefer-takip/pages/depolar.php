<?php
$tip = get('tip');
$w = 'silindi = 0';
$prm = [];
if ($tip === 'yukleme' || $tip === 'bosaltim') {
    $w .= ' AND tip = ?';
    $prm[] = $tip;
}
$depolar = all("SELECT d.*, (SELECT COUNT(*) FROM seferler s WHERE s.silindi = 0 AND (s.yukleme_depo_id = d.id OR s.bosaltim_depo_id = d.id)) AS sefer_sayisi FROM depolar d WHERE $w ORDER BY tip DESC, ad", $prm);

sayfa_bas('Depolar', 'depolar');
?>
<div class="baslik-satir">
  <h1>Depolar</h1>
  <div class="butonlar">
    <a class="btn ana" href="index.php?p=depo_form&tip=yukleme">➕ Yükleme (Sarım) Deposu</a>
    <a class="btn ana" href="index.php?p=depo_form&tip=bosaltim">➕ Boşaltım Deposu</a>
  </div>
</div>
<div class="sekmeler">
  <a class="<?= $tip === '' ? 'aktif' : '' ?>" href="index.php?p=depolar">Tümü</a>
  <a class="<?= $tip === 'yukleme' ? 'aktif' : '' ?>" href="index.php?p=depolar&tip=yukleme">Yükleme (Sarım)</a>
  <a class="<?= $tip === 'bosaltim' ? 'aktif' : '' ?>" href="index.php?p=depolar&tip=bosaltim">Boşaltım</a>
</div>

<?php if (!$depolar): ?><div class="kart"><p class="soluk">Kayıtlı depo yok.</p></div><?php endif; ?>

<div class="izgara">
<?php foreach ($depolar as $d):
    $harita = harita_link($d['lat'], $d['lng']);
    $mesaj = ($d['tip'] === 'yukleme' ? '📍 YÜKLEME YERİ: ' : '🏁 BOŞALTIM YERİ: ') . depo_etiket($d) . ($d['adres'] ? "\n" . $d['adres'] : '') . ($harita ? "\n" . $harita : '');
    ?>
  <div class="kart depo">
    <div class="baslik-satir">
      <div>
        <span class="rozet <?= $d['tip'] === 'yukleme' ? 'yukleme' : 'bosaltim' ?>"><?= $d['tip'] === 'yukleme' ? 'Yükleme (Sarım)' : 'Boşaltım' ?></span>
        <h3><?= h($d['ad']) ?></h3>
      </div>
      <a class="btn kucuk" href="index.php?p=depo_form&id=<?= $d['id'] ?>">✏️ Düzenle</a>
    </div>
    <?php if ($d['firma']): ?><div><?= h($d['firma']) ?></div><?php endif; ?>
    <div class="soluk"><?= h(implode(' / ', array_filter([$d['ilce'], $d['il']]))) ?></div>
    <?php if ($d['adres']): ?><div class="soluk"><?= h($d['adres']) ?></div><?php endif; ?>
    <?php if ($d['yetkili'] || $d['telefon']): ?><div>👤 <?= h($d['yetkili']) ?> <?= $d['telefon'] ? '· <a href="tel:' . h($d['telefon']) . '">' . h($d['telefon']) . '</a>' : '' ?></div><?php endif; ?>
    <div class="soluk">
      <?php if ($harita): ?>🧭 <?= h(koordinat_fmt($d['lat']) . ', ' . koordinat_fmt($d['lng'])) ?><?php else: ?><span class="kirmizi">⚠️ Koordinat girilmemiş</span><?php endif; ?>
      · <?= (int)$d['sefer_sayisi'] ?> sefer
    </div>
    <?php if ($harita): ?>
    <div class="butonlar">
      <a class="btn kucuk" target="_blank" rel="noopener" href="<?= h($harita) ?>">🗺️ Haritada Aç</a>
      <button type="button" class="btn kucuk wa" data-wa-sor data-metin="<?= h($mesaj) ?>">WhatsApp ile Gönder</button>
      <button type="button" class="btn kucuk" data-kopyala="<?= h($mesaj) ?>">📋 Kopyala</button>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php sayfa_son();
