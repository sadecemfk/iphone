<?php
$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ka = mb_strtolower(post('kullanici_adi'));
    $s1 = (string)($_POST['sifre'] ?? '');
    $s2 = (string)($_POST['sifre2'] ?? '');
    if (!preg_match('/^[a-z0-9._-]{3,30}$/', $ka)) {
        $hata = 'Kullanıcı adı 3-30 karakter olmalı (harf, rakam, . _ -).';
    } elseif (mb_strlen($s1) < 6) {
        $hata = 'Şifre en az 6 karakter olmalı.';
    } elseif ($s1 !== $s2) {
        $hata = 'Şifreler eşleşmiyor.';
    } else {
        $id = kayit_ekle('kullanicilar', ['kullanici_adi' => $ka, 'ad' => post('ad'), 'sifre_hash' => password_hash($s1, PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        $_SESSION['kullanici'] = one('SELECT id, kullanici_adi, ad FROM kullanicilar WHERE id = ?', [$id]);
        mesaj('Kurulum tamamlandı. Önce depolarınızı, şoförlerinizi ve ürünlerinizi ekleyin.');
        yonlendir('index.php');
    }
}
sayfa_bas('Kurulum');
?>
<div class="kart dar">
  <h1>İlk Kurulum</h1>
  <p class="soluk">Sisteme giriş yapacak yönetici hesabını oluşturun.</p>
  <?php if ($hata): ?><div class="uyari hata"><?= h($hata) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_alan() ?>
    <label>Adınız<input name="ad" value="<?= h(post('ad')) ?>"></label>
    <label>Kullanıcı adı<input name="kullanici_adi" required autocapitalize="none" value="<?= h(post('kullanici_adi')) ?>"></label>
    <label>Şifre<input type="password" name="sifre" required minlength="6"></label>
    <label>Şifre (tekrar)<input type="password" name="sifre2" required minlength="6"></label>
    <button class="btn ana genis">Kurulumu Tamamla</button>
  </form>
</div>
<?php sayfa_son();
