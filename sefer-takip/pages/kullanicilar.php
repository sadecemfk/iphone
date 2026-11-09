<?php
$ben = aktif_kullanici();
$yonetici = yonetici_mi();

/** En az bir aktif yönetici kalmalı */
function son_aktif_yonetici_mi($id)
{
    $r = one('SELECT COUNT(*) c FROM kullanicilar WHERE yonetici = 1 AND aktif = 1 AND id != ?', [(int)$id]);
    return (int)$r['c'] === 0;
}

function oturumlari_kapat($id)
{
    q('UPDATE kullanicilar SET oturum_surumu = oturum_surumu + 1 WHERE id = ?', [(int)$id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = post('islem');

    // --- Herkes: kendi şifresi ve oturumları ---
    if ($islem === 'sifre') {
        $u = one('SELECT * FROM kullanicilar WHERE id = ?', [$ben['id']]);
        $yeni = (string)($_POST['yeni'] ?? '');
        if (!$u || !password_verify((string)($_POST['eski'] ?? ''), $u['sifre_hash'])) {
            mesaj('Mevcut şifre hatalı.', 'hata');
        } elseif (mb_strlen($yeni) < SIFRE_MIN) {
            mesaj('Yeni şifre en az ' . SIFRE_MIN . ' karakter olmalı.', 'hata');
        } elseif ($yeni !== (string)($_POST['yeni2'] ?? '')) {
            mesaj('Yeni şifreler eşleşmiyor.', 'hata');
        } else {
            q('UPDATE kullanicilar SET sifre_hash = ?, oturum_surumu = oturum_surumu + 1 WHERE id = ?', [password_hash($yeni, PASSWORD_DEFAULT), $u['id']]);
            gecmis_yaz('kullanicilar', $u['id'], 'sifre_degisti', null, null);
            session_regenerate_id(true);
            oturum_ac(one('SELECT * FROM kullanicilar WHERE id = ?', [$u['id']]));
            mesaj('Şifreniz değiştirildi. Diğer cihazlardaki oturumlarınız kapatıldı.');
        }
        yonlendir('index.php?p=kullanicilar');
    }
    if ($islem === 'oturumlarim') {
        oturumlari_kapat($ben['id']);
        oturum_ac(one('SELECT * FROM kullanicilar WHERE id = ?', [$ben['id']]));
        gecmis_yaz('kullanicilar', $ben['id'], 'oturumlar_kapatildi', null, null);
        mesaj('Diğer tüm cihazlardaki oturumlarınız kapatıldı.');
        yonlendir('index.php?p=kullanicilar');
    }

    // --- Sadece yönetici ---
    if (!$yonetici) {
        yetkisiz();
    }
    $hedefId = (int)post('id');
    $hedef = $hedefId ? one('SELECT * FROM kullanicilar WHERE id = ?', [$hedefId]) : null;

    if ($islem === 'ekle') {
        $ka = mb_strtolower(post('kullanici_adi'));
        $s = (string)($_POST['sifre'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $ka)) {
            mesaj('Kullanıcı adı 3-30 karakter olmalı (küçük harf, rakam, . _ -).', 'hata');
        } elseif (mb_strlen($s) < SIFRE_MIN) {
            mesaj('Şifre en az ' . SIFRE_MIN . ' karakter olmalı.', 'hata');
        } elseif (one('SELECT id FROM kullanicilar WHERE kullanici_adi = ?', [$ka])) {
            mesaj('Bu kullanıcı adı zaten var.', 'hata');
        } else {
            kayit_ekle('kullanicilar', [
                'kullanici_adi' => $ka,
                'ad' => post('ad'),
                'sifre_hash' => password_hash($s, PASSWORD_DEFAULT),
                'yonetici' => post('yonetici') ? 1 : 0,
                'aktif' => 1,
                'oturum_surumu' => 1,
            ]);
            mesaj('Kullanıcı eklendi: ' . $ka);
        }
    } elseif (!$hedef) {
        mesaj('Kullanıcı bulunamadı.', 'hata');
    } elseif ($islem === 'sifre_sifirla') {
        $s = (string)($_POST['yeni_sifre'] ?? '');
        if (mb_strlen($s) < SIFRE_MIN) {
            mesaj('Şifre en az ' . SIFRE_MIN . ' karakter olmalı.', 'hata');
        } else {
            q('UPDATE kullanicilar SET sifre_hash = ?, oturum_surumu = oturum_surumu + 1 WHERE id = ?', [password_hash($s, PASSWORD_DEFAULT), $hedef['id']]);
            gecmis_yaz('kullanicilar', $hedef['id'], 'sifre_sifirlandi', null, null);
            if ((int)$hedef['id'] === (int)$ben['id']) {
                oturum_ac(one('SELECT * FROM kullanicilar WHERE id = ?', [$ben['id']]));
            }
            mesaj($hedef['kullanici_adi'] . ' kullanıcısının şifresi değiştirildi; açık oturumları kapatıldı.');
        }
    } elseif ($islem === 'aktiflik') {
        $yeni = (int)$hedef['aktif'] ? 0 : 1;
        if (!$yeni && (int)$hedef['id'] === (int)$ben['id']) {
            mesaj('Kendi hesabınızı pasifleştiremezsiniz.', 'hata');
        } elseif (!$yeni && (int)$hedef['yonetici'] && son_aktif_yonetici_mi($hedef['id'])) {
            mesaj('Son aktif yönetici pasifleştirilemez.', 'hata');
        } else {
            kayit_guncelle('kullanicilar', $hedef['id'], ['aktif' => $yeni], $yeni ? 'aktiflestir' : 'pasiflestir');
            oturumlari_kapat($hedef['id']);
            mesaj($hedef['kullanici_adi'] . ($yeni ? ' tekrar aktif edildi.' : ' pasifleştirildi; sisteme giremez.'));
        }
    } elseif ($islem === 'yetki') {
        $yeni = (int)$hedef['yonetici'] ? 0 : 1;
        if (!$yeni && son_aktif_yonetici_mi($hedef['id'])) {
            mesaj('Son aktif yöneticinin yetkisi kaldırılamaz.', 'hata');
        } else {
            kayit_guncelle('kullanicilar', $hedef['id'], ['yonetici' => $yeni], 'yetki');
            oturumlari_kapat($hedef['id']);
            if ((int)$hedef['id'] === (int)$ben['id']) {
                oturum_ac(one('SELECT * FROM kullanicilar WHERE id = ?', [$ben['id']]));
            }
            mesaj($hedef['kullanici_adi'] . ($yeni ? ' artık yönetici.' : ' artık normal kullanıcı.'));
        }
    } elseif ($islem === 'ad') {
        kayit_guncelle('kullanicilar', $hedef['id'], ['ad' => post('ad')]);
        mesaj('Ad güncellendi.');
    } elseif ($islem === 'oturum_kapat') {
        oturumlari_kapat($hedef['id']);
        if ((int)$hedef['id'] === (int)$ben['id']) {
            oturum_ac(one('SELECT * FROM kullanicilar WHERE id = ?', [$ben['id']]));
        }
        gecmis_yaz('kullanicilar', $hedef['id'], 'oturumlar_kapatildi', null, null);
        mesaj($hedef['kullanici_adi'] . ' kullanıcısının tüm oturumları kapatıldı.');
    }
    yonlendir('index.php?p=kullanicilar');
}

$liste = $yonetici ? all('SELECT id, kullanici_adi, ad, yonetici, aktif, son_giris, olusturma FROM kullanicilar ORDER BY aktif DESC, id') : [];
sayfa_bas('Kullanıcılar', 'kullanicilar');
?>
<h1>👥 Kullanıcılar</h1>
<div class="iki-sutun">
  <div class="kart">
    <h2>Şifremi Değiştir</h2>
    <form method="post" class="form">
      <?= csrf_alan() ?><input type="hidden" name="islem" value="sifre">
      <label>Mevcut şifre<input type="password" name="eski" required autocomplete="current-password"></label>
      <label>Yeni şifre (en az <?= SIFRE_MIN ?> karakter)<input type="password" name="yeni" required minlength="<?= SIFRE_MIN ?>" autocomplete="new-password"></label>
      <label>Yeni şifre (tekrar)<input type="password" name="yeni2" required minlength="<?= SIFRE_MIN ?>" autocomplete="new-password"></label>
      <button class="btn ana">Değiştir</button>
    </form>
    <form method="post" class="butonlar" onsubmit="return confirm('Bu cihaz hariç tüm cihazlardaki oturumlarınız kapatılsın mı?')">
      <?= csrf_alan() ?><input type="hidden" name="islem" value="oturumlarim">
      <button class="btn kucuk">📵 Diğer cihazlardaki oturumlarımı kapat</button>
    </form>
    <p class="soluk kucuk-yazi">Telefonunuz kaybolursa başka bir cihazdan girip bu butona basın veya şifrenizi değiştirin.</p>
    <p><a href="index.php?p=silinenler">🗑️ Silinen kayıtlar</a></p>
  </div>

  <?php if ($yonetici): ?>
  <div class="kart">
    <h2>Yeni Kullanıcı</h2>
    <form method="post" class="form">
      <?= csrf_alan() ?><input type="hidden" name="islem" value="ekle">
      <label>Ad Soyad<input name="ad"></label>
      <label>Kullanıcı adı<input name="kullanici_adi" required autocapitalize="none"></label>
      <label>Şifre (en az <?= SIFRE_MIN ?> karakter)<input type="password" name="sifre" required minlength="<?= SIFRE_MIN ?>" autocomplete="new-password"></label>
      <label class="onay"><input type="checkbox" name="yonetici" value="1"> Yönetici (kullanıcı yönetimi, yedek ve geri yükleme yetkisi)</label>
      <button class="btn ana">Ekle</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if ($yonetici): ?>
<div class="kart">
  <h2>Kayıtlı Kullanıcılar</h2>
  <?php foreach ($liste as $u): $kendim = (int)$u['id'] === (int)$ben['id']; ?>
    <details class="kullanici-satir">
      <summary>
        <b><?= h($u['kullanici_adi']) ?></b> <?= h($u['ad']) ?>
        <?= (int)$u['yonetici'] ? '<span class="rozet d-planlandi">Yönetici</span>' : '' ?>
        <?= (int)$u['aktif'] ? '' : '<span class="rozet d-iptal">Pasif</span>' ?>
        <?= $kendim ? '<span class="rozet">Siz</span>' : '' ?>
        <span class="soluk kucuk-yazi">· son giriş: <?= h(tarih_tr($u['son_giris'])) ?: '—' ?></span>
      </summary>
      <div class="kullanici-islemler">
        <form method="post" class="yukle-form">
          <?= csrf_alan() ?><input type="hidden" name="islem" value="ad"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input name="ad" value="<?= h($u['ad']) ?>" placeholder="Ad Soyad"><button class="btn kucuk">Adı kaydet</button>
        </form>
        <form method="post" class="yukle-form">
          <?= csrf_alan() ?><input type="hidden" name="islem" value="sifre_sifirla"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input type="password" name="yeni_sifre" minlength="<?= SIFRE_MIN ?>" required placeholder="Yeni şifre" autocomplete="new-password"><button class="btn kucuk">Şifreyi değiştir</button>
        </form>
        <div class="butonlar">
          <form method="post" class="satir-ici"><?= csrf_alan() ?><input type="hidden" name="islem" value="oturum_kapat"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn kucuk">📵 Oturumlarını kapat</button></form>
          <form method="post" class="satir-ici" onsubmit="return confirm('Yetki değiştirilsin mi?')"><?= csrf_alan() ?><input type="hidden" name="islem" value="yetki"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn kucuk"><?= (int)$u['yonetici'] ? 'Yöneticiliği kaldır' : 'Yönetici yap' ?></button></form>
          <?php if (!$kendim): ?>
          <form method="post" class="satir-ici" onsubmit="return confirm('<?= (int)$u['aktif'] ? 'Kullanıcı pasifleştirilsin mi? Sisteme giremez (kayıtları silinmez).' : 'Kullanıcı tekrar aktif edilsin mi?' ?>')"><?= csrf_alan() ?><input type="hidden" name="islem" value="aktiflik"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn kucuk <?= (int)$u['aktif'] ? 'tehlike' : 'ana' ?>"><?= (int)$u['aktif'] ? '⛔ Pasifleştir' : '✅ Aktifleştir' ?></button></form>
          <?php endif; ?>
        </div>
      </div>
    </details>
  <?php endforeach; ?>
  <p class="soluk kucuk-yazi">İşten ayrılan kullanıcıyı <b>pasifleştirin</b>: giriş yapamaz, girdiği kayıtlar ve geçmiş korunur.</p>
</div>
<?php endif; ?>
<?php sayfa_son();
