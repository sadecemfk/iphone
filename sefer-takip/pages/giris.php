<?php
if (aktif_kullanici()) {
    yonlendir('index.php');
}
$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = one('SELECT * FROM kullanicilar WHERE kullanici_adi = ?', [mb_strtolower(post('kullanici_adi'))]);
    if ($u && password_verify((string)($_POST['sifre'] ?? ''), $u['sifre_hash'])) {
        session_regenerate_id(true);
        $_SESSION['kullanici'] = ['id' => $u['id'], 'kullanici_adi' => $u['kullanici_adi'], 'ad' => $u['ad']];
        yonlendir('index.php');
    }
    sleep(1);
    $hata = 'Kullanıcı adı veya şifre hatalı.';
}
sayfa_bas('Giriş');
?>
<div class="kart dar">
  <h1>🚛 <?= h(cfg('app_name')) ?></h1>
  <?php if ($hata): ?><div class="uyari hata"><?= h($hata) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_alan() ?>
    <label>Kullanıcı adı<input name="kullanici_adi" required autocapitalize="none" autocomplete="username" value="<?= h(post('kullanici_adi')) ?>"></label>
    <label>Şifre<input type="password" name="sifre" required autocomplete="current-password"></label>
    <button class="btn ana genis">Giriş Yap</button>
  </form>
</div>
<?php sayfa_son();
