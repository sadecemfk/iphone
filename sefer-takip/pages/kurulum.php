<?php
// Kullanıcı varsa kurulum kapalıdır
if ((int)one('SELECT COUNT(*) c FROM kullanicilar')['c'] > 0) {
    yonlendir('index.php');
}

$anahtar = (string)cfg('kurulum_anahtari', '');
$anahtarHazir = strlen($anahtar) >= 12;
$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $anahtarHazir) {
    $ka = mb_strtolower(post('kullanici_adi'));
    $s1 = (string)($_POST['sifre'] ?? '');
    $s2 = (string)($_POST['sifre2'] ?? '');
    if (giris_kilitli('__kurulum__')) {
        $hata = 'Çok fazla hatalı deneme. Lütfen ' . (int)cfg('giris_kilit_dakika', 15) . ' dakika sonra tekrar deneyin.';
    } elseif (!hash_equals($anahtar, post('anahtar'))) {
        giris_denemesi_yaz('__kurulum__', false);
        sleep(1);
        $hata = 'Kurulum anahtarı hatalı.';
    } elseif (!preg_match('/^[a-z0-9._-]{3,30}$/', $ka)) {
        $hata = 'Kullanıcı adı 3-30 karakter olmalı (küçük harf, rakam, . _ -).';
    } elseif (mb_strlen($s1) < SIFRE_MIN) {
        $hata = 'Şifre en az ' . SIFRE_MIN . ' karakter olmalı.';
    } elseif ($s1 !== $s2) {
        $hata = 'Şifreler eşleşmiyor.';
    } else {
        $id = kayit_ekle('kullanicilar', [
            'kullanici_adi' => $ka,
            'ad' => post('ad'),
            'sifre_hash' => password_hash($s1, PASSWORD_DEFAULT),
            'yonetici' => 1,
            'aktif' => 1,
            'oturum_surumu' => 1,
        ]);
        session_regenerate_id(true);
        oturum_ac(one('SELECT * FROM kullanicilar WHERE id = ?', [$id]));
        mesaj('Kurulum tamamlandı. Önce depolarınızı, şoförlerinizi ve ürünlerinizi ekleyin.');
        yonlendir('index.php');
    }
}

$gerek = gereksinimler();
sayfa_bas('Kurulum');
?>
<div class="kart dar">
  <h1>İlk Kurulum</h1>
  <?php if (!$anahtarHazir): ?>
    <div class="uyari hata">
      <b>Kurulum anahtarı tanımlı değil.</b><br>
      Güvenlik için kurulum, sadece sunucuya erişimi olan kişinin bildiği bir anahtarla yapılabilir.
      <ol>
        <li>Sunucudaki <b>config.local.ornek.php</b> dosyasını kopyalayın, adını <b>config.local.php</b> yapın.</li>
        <li>İçindeki <b>kurulum_anahtari</b> değerine en az 12 karakterlik, tahmin edilemez bir metin yazın.</li>
        <li>Bu sayfayı yenileyin.</li>
      </ol>
    </div>
  <?php else: ?>
    <p class="soluk">Sisteme giriş yapacak <b>yönetici</b> hesabını oluşturun.</p>
    <p class="soluk kucuk-yazi">Sistemi daha önce kurduysanız ve bu ekranı görüyorsanız verileriniz silinmemiştir; config.local.php içindeki <b>data_dir</b> yolunu kontrol edin.</p>
    <?php if ($hata): ?><div class="uyari hata"><?= h($hata) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_alan() ?>
      <label>Kurulum anahtarı (config.local.php içindeki)<input type="password" name="anahtar" required autocomplete="off"></label>
      <label>Adınız<input name="ad" value="<?= h(post('ad')) ?>"></label>
      <label>Kullanıcı adı<input name="kullanici_adi" required autocapitalize="none" autocomplete="username" value="<?= h(post('kullanici_adi')) ?>"></label>
      <label>Şifre (en az <?= SIFRE_MIN ?> karakter)<input type="password" name="sifre" required minlength="<?= SIFRE_MIN ?>" autocomplete="new-password"></label>
      <label>Şifre (tekrar)<input type="password" name="sifre2" required minlength="<?= SIFRE_MIN ?>" autocomplete="new-password"></label>
      <button class="btn ana genis">Kurulumu Tamamla</button>
    </form>
  <?php endif; ?>
  <details class="gereksinim">
    <summary><b>Sunucu kontrolü</b></summary>
    <?= gereksinim_tablosu($gerek) ?>
  </details>
</div>
<?php sayfa_son();
