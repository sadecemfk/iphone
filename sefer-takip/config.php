<?php
// Sefer Takip - Ayarlar
// Bu dosyayı ihtiyacınıza göre düzenleyebilirsiniz.
return [
    // Uygulama adı (üst menüde görünür)
    'app_name' => 'Sefer Takip',

    // Veritabanı, belgeler (PDF) ve yedeklerin tutulduğu klasör.
    // Mümkünse web kök dizininin DIŞINDA bir klasör verin, ör: '/home/kullanici/sefer-data'
    'data_dir' => __DIR__ . '/data',

    // WhatsApp'a gönderilen belge linklerinde kullanılacak site adresi.
    // Boş bırakılırsa otomatik algılanır. Ör: 'https://takip.firmaniz.com'
    'base_url' => '',

    'timezone' => 'Europe/Istanbul',

    // Yüklenebilecek en büyük belge boyutu (MB)
    'max_upload_mb' => 20,

    // Otomatik günlük yedekler kaç gün saklansın
    'backup_keep_days' => 180,

    // Yeni seferde varsayılan değerler
    'varsayilan_nakliye_kdv' => 20,
    'varsayilan_urun_kdv' => 20,
];
