<?php
// ÖRNEK SUNUCU AYARLARI
// Bu dosyayı kopyalayıp adını "config.local.php" yapın ve kendi bilgilerinizle doldurun.
// config.local.php içine yazdıklarınız config.php'deki varsayılanların yerine geçer.
// ⚠️ config.local.php dosyasını kimseyle paylaşmayın ve GitHub'a yüklemeyin.

return [
    // Veri klasörü: web klasörünün (public_html) DIŞINDA olmalı.
    // cPanel örneği: '/home/KULLANICIADI/sefer-data'   (klasörü oluşturup izni 775 yapın)
    'data_dir' => '/home/KULLANICIADI/sefer-data',

    // Sitenin adresi (WhatsApp belge linkleri bununla başlar)
    'base_url' => 'https://takip.firmaniz.com',

    // İlk kurulumda sorulacak gizli anahtar. En az 12 karakter, tahmin edilemez bir metin yazın.
    // Kurulum bittikten sonra boş bırakabilirsiniz ('').
    'kurulum_anahtari' => 'BURAYA-UZUN-VE-RASTGELE-BIR-METIN-YAZIN',

    // SSL (https) kuruluysa true yapın
    'https_zorunlu' => true,

    // Cloudflare arkasındaysanız gerçek ziyaretçi IP'si için (giriş kilidi doğru çalışsın):
    // 'ip_basligi' => 'HTTP_CF_CONNECTING_IP',

    // ACİL ŞİFRE SIFIRLAMA (sadece tek yönetici şifresini unutursa):
    // Satırın başındaki // işaretlerini kaldırın, sayfayı bir kez açın, yeni şifreyle girin ve SATIRI HEMEN SİLİN.
    // 'acil_sifre_sifirlama' => ['kullanici' => 'admin', 'yeni_sifre' => 'Yeni-Guclu-Sifre-2026'],
];
