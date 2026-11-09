<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = post('islem');
    if ($islem === 'ekle') {
        $ka = mb_strtolower(post('kullanici_adi'));
        $s = (string)($_POST['sifre'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $ka)) {
            mesaj('Kullanıcı adı 3-30 karakter olmalı (harf, rakam, . _ -).', 'hata');
        } elseif (mb_strlen($s) < 6) {
            mesaj('Şifre en az 6 karakter olmalı.', 'hata');
        } elseif (one('SELECT id FROM kullanicilar WHERE kullanici_adi = ?', [$ka])) {
            mesaj('Bu kullanıcı adı zaten var.', 'hata');
        } else {
            kayit_ekle('kullanicilar', ['kullanici_adi' => $ka, 'ad' => post('ad'), 'sifre_hash' => password_hash($s, PASSWORD_DEFAULT)]);
            mesaj('Kullanıcı eklendi.');
        }
    } elseif ($islem === 'sifre') {
        $u = one('SELECT * FROM kullanicilar WHERE id = ?', [aktif_kullanici()['id']]);
        $yeni = (string)($_POST['yeni'] ?? '');
        if (!$u || !password_verify((string)($_POST['eski'] ?? ''), $u['sifre_hash'])) {
            mesaj('Mevcut şifre hatalı.', 'hata');
        } elseif (mb_strlen($yeni) < 6) {
            mesaj('Yeni şifre en az 6 karakter olmalı.', 'hata');
        } else {
            q('UPDATE kullanicilar SET sifre_hash = ? WHERE id = ?', [password_hash($yeni, PASSWORD_DEFAULT), $u['id']]);
            gecmis_yaz('kullanicilar', $u['id'], 'sifre_degisti', null, null);
            mesaj('Şifreniz değiştirildi.');
        }
    }
    yonlendir('index.php?p=kullanicilar');
}
$liste = all('SELECT id, kullanici_adi, ad, olusturma FROM kullanicilar ORDER BY id');
sayfa_bas('Kullanıcılar');
?>
<h1>👥 Kullanıcılar</h1>
<div class="iki-sutun">
  <div class="kart">
    <h2>Kayıtlı Kullanıcılar</h2>
    <?php foreach ($liste as $u): ?>
      <div class="silinen"><span><b><?= h($u['kullanici_adi']) ?></b> <?= h($u['ad']) ?></span><span class="soluk"><?= h(tarih_tr($u['olusturma'])) ?></span></div>
    <?php endforeach; ?>
    <h2>Yeni Kullanıcı</h2>
    <form method="post" class="form">
      <?= csrf_alan() ?><input type="hidden" name="islem" value="ekle">
      <label>Ad Soyad<input name="ad"></label>
      <label>Kullanıcı adı<input name="kullanici_adi" required autocapitalize="none"></label>
      <label>Şifre<input type="password" name="sifre" required minlength="6"></label>
      <button class="btn ana">Ekle</button>
    </form>
  </div>
  <div class="kart">
    <h2>Şifremi Değiştir</h2>
    <form method="post" class="form">
      <?= csrf_alan() ?><input type="hidden" name="islem" value="sifre">
      <label>Mevcut şifre<input type="password" name="eski" required></label>
      <label>Yeni şifre<input type="password" name="yeni" required minlength="6"></label>
      <button class="btn ana">Değiştir</button>
    </form>
    <p><a href="index.php?p=silinenler">🗑️ Silinen kayıtlar</a></p>
  </div>
</div>
<?php sayfa_son();
