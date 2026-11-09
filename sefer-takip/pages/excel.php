<?php
// Filtrelenmiş seferleri Excel (.xlsx) olarak indir
if (!class_exists('ZipArchive')) {
    mesaj('Sunucuda "zip" PHP eklentisi olmadığı için Excel oluşturulamıyor. Hosting panelinden etkinleştirin.', 'hata');
    yonlendir('index.php?p=seferler');
}
excel_seferler(get_hepsi());
