<?php
if (aktif_kullanici()) {
    yonlendir('index.php');
}
$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ka = mb_strtolower(post('kullanici_adi'));
    if (giris_kilitli($ka)) {
        $hata = 'Çok fazla hatalı deneme yapıldı. Güvenlik için lütfen ' . (int)cfg('giris_kilit_dakika', 15) . ' dakika sonra tekrar deneyin.';
    } else {
        $u = one('SELECT * FROM kullanicilar WHERE kullanici_adi = ?', [$ka]);
        if ($u && (int)$u['aktif'] && password_verify((string)($_POST['sifre'] ?? ''), $u['sifre_hash'])) {
            giris_denemesi_yaz($ka, true);
            if (password_needs_rehash($u['sifre_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE kullanicilar SET sifre_hash = ? WHERE id = ?', [password_hash((string)$_POST['sifre'], PASSWORD_DEFAULT), $u['id']]);
            }
            q('UPDATE kullanicilar SET son_giris = ? WHERE id = ?', [simdi(), $u['id']]);
            session_regenerate_id(true);
            oturum_ac($u);
            yonlendir('index.php');
        }
        giris_denemesi_yaz($ka, false);
        sleep(1);
        $hata = 'Kullanıcı adı veya şifre hatalı.';
    }
}
sayfa_bas('Giriş');
?>
<div class="kart dar">
  <h1>🚛 <?= h(cfg('app_name')) ?></h1>
  <?php if ($hata): ?><div class="uyari hata"><?= h($hata) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_alan() ?>
    <label>Kullanıcı adı<input name="kullanici_adi" required autocapitalize="none" autocomplete="username" value="<?= h(post('kullanici_adi')) ?>"></label>
    <label>Şifre<input type="password" name="sifre" required autocomplete="current-password"></label>
    <button class="btn ana genis">Giriş Yap</button>
  </form>
  <?php if (!is_https()): ?><p class="soluk kucuk-yazi">⚠️ Bağlantı şifreli değil (https yok).</p><?php endif; ?>
</div>
<?php sayfa_son();
