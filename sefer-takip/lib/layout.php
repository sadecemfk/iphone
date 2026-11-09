<?php

function sayfa_bas($baslik, $aktif = '')
{
    $app = cfg('app_name', 'Sefer Takip');
    $menu = [
        'panel' => ['Panel', '🏠'],
        'seferler' => ['Seferler', '🚚'],
        'sefer_form' => ['Yeni Sefer', '➕'],
        'depolar' => ['Depolar', '📍'],
        'soforler' => ['Şoförler', '👤'],
        'urunler' => ['Ürünler', '📦'],
        'yedek' => ['Yedek / Excel', '💾'],
    ];
    ?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h($app) ?>">
<meta name="theme-color" content="#1f4e79">
<title><?= h($baslik) ?> · <?= h($app) ?></title>
<link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body>
<?php if (aktif_kullanici()): ?>
<header class="ust">
  <a class="logo" href="index.php">🚛 <?= h($app) ?></a>
  <nav class="menu">
    <?php foreach ($menu as $k => $m): ?>
      <a href="index.php?p=<?= $k ?>" class="<?= $aktif === $k ? 'aktif' : '' ?>"><span><?= $m[1] ?></span><?= h($m[0]) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="kullanici">
    <a href="index.php?p=kullanicilar"><?= h(aktif_kullanici()['ad'] ?: aktif_kullanici_adi()) ?></a>
    <form method="post" action="index.php?p=cikis" class="satir-ici"><?= csrf_alan() ?><button class="link">Çıkış</button></form>
  </div>
</header>
<?php endif; ?>
<main class="icerik">
<?php
    foreach ($_SESSION['mesajlar'] ?? [] as $m) {
        echo '<div class="uyari ' . h($m[0]) . '">' . h($m[1]) . '</div>';
    }
    unset($_SESSION['mesajlar']);
}

function sayfa_son()
{
    ?>
</main>
<script src="assets/app.js?v=1"></script>
</body>
</html>
<?php
}

function secenekler(array $liste, $secili, $bos = '— Seçin —', $etiket = null)
{
    $o = $bos !== null ? '<option value="">' . h($bos) . '</option>' : '';
    foreach ($liste as $r) {
        $e = $etiket ? $etiket($r) : $r['ad'];
        $o .= '<option value="' . h($r['id']) . '"' . ((string)$secili === (string)$r['id'] ? ' selected' : '') . '>' . h($e) . '</option>';
    }
    return $o;
}

function durum_rozet($d)
{
    return '<span class="rozet d-' . h($d) . '">' . h(DURUMLAR[$d] ?? $d) . '</span>';
}

function sayfalama($toplam, $sayfa, $limit)
{
    $ss = (int)ceil($toplam / $limit);
    if ($ss <= 1) {
        return '';
    }
    $g = $_GET;
    $o = '<div class="sayfalama">';
    for ($i = 1; $i <= $ss; $i++) {
        if ($ss > 12 && abs($i - $sayfa) > 3 && $i !== 1 && $i !== $ss) {
            if (abs($i - $sayfa) === 4) {
                $o .= '<span>…</span>';
            }
            continue;
        }
        $g['s'] = $i;
        $o .= '<a class="' . ($i === $sayfa ? 'aktif' : '') . '" href="index.php?' . h(http_build_query($g)) . '">' . $i . '</a>';
    }
    return $o . '</div>';
}
