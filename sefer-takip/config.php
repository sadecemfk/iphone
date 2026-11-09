<?php
// Sefer Takip - VARSAYILAN AYARLAR
//
// ⚠️ BU DOSYAYI DÜZENLEMEYİN. Güncelleme yaptığınızda bu dosya yenisiyle değişir.
// Kendi ayarlarınızı "config.local.php" dosyasına yazın:
//   config.local.ornek.php dosyasını kopyalayın, adını config.local.php yapın ve düzenleyin.
// config.local.php güncellemelerde ezilmez ve git'e gönderilmez.

$ayar = [
    // Uygulama adı (üst menüde görünür)
    'app_name' => 'Sefer Takip',

    // Veritabanı, belgeler (PDF), yedekler ve oturumların tutulduğu klasör.
    // CANLIDA mutlaka web klasörünün (public_html) DIŞINDA bir yol verin.
    'data_dir' => __DIR__ . '/data',

    // WhatsApp'a gönderilen belge linklerinin başı. Boşsa otomatik algılanır.
    'base_url' => '',

    // İlk kurulumda sorulan gizli anahtar (en az 12 karakter). Boşsa kurulum yapılamaz.
    'kurulum_anahtari' => '',

    // true: HTTP istekleri HTTPS'e yönlendirilir (SSL kurulu olmalı)
    'https_zorunlu' => false,

    // Cloudflare vb. arkasındaysanız gerçek IP'nin geldiği başlık, ör. 'HTTP_CF_CONNECTING_IP'
    'ip_basligi' => '',

    'timezone' => 'Europe/Istanbul',

    // Yüklenebilecek en büyük belge boyutu (MB)
    'max_upload_mb' => 20,

    // Otomatik günlük yedekler kaç gün saklansın
    'backup_keep_days' => 180,

    // Kaç gün ZIP yedek indirilmezse yöneticiye uyarı gösterilsin
    'yedek_hatirlatma_gun' => 7,

    // Giriş güvenliği: bu kadar hatalı denemede ... dakika kilit
    'giris_deneme_limiti' => 5,
    'giris_kilit_dakika' => 15,

    // Aşağıdaki 5 ayar yönetici panelindeki "Ayarlar" sayfasından da değiştirilebilir (oradaki değer geçerlidir)
    'firma_adi' => '',
    'firma_bilgi' => '',
    // Ücret hesabında esas ağırlık: 'yukleme' | 'bosaltim' | 'dusuk' (hangisi düşükse)
    'varsayilan_mal_esas' => 'yukleme',
    'varsayilan_nakliye_esas' => 'yukleme',
    // Yükleme ile boşaltım kantarı arasındaki fark bu yüzdeyi aşarsa uyarı verilir
    'fire_tolerans_yuzde' => 0.5,

    // Yeni sefer / ürün için varsayılan KDV oranları (muhasebecinizle teyit edin)
    'varsayilan_nakliye_kdv' => 20,
    'varsayilan_urun_kdv' => 20,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $yerel = require __DIR__ . '/config.local.php';
    if (is_array($yerel)) {
        $ayar = array_merge($ayar, $yerel);
    }
}

return $ayar;
