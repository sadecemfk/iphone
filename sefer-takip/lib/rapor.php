<?php
// Sefer filtreleme ve Excel dışa aktarma

/** GET parametrelerinden WHERE oluşturur */
function sefer_filtre(array $g, $silinenler = false)
{
    $w = [$silinenler ? 's.silindi = 1' : 's.silindi = 0'];
    $p = [];
    if (!empty($g['bas']) && tarih_gecerli($g['bas'])) {
        $w[] = 's.tarih >= ?';
        $p[] = $g['bas'];
    }
    if (!empty($g['bit']) && tarih_gecerli($g['bit'])) {
        $w[] = 's.tarih <= ?';
        $p[] = $g['bit'];
    }
    foreach (['urun' => 's.urun_id', 'ydepo' => 's.yukleme_depo_id', 'bdepo' => 's.bosaltim_depo_id', 'sofor' => 's.sofor_id'] as $k => $kol) {
        if (!empty($g[$k]) && ctype_digit((string)$g[$k])) {
            $w[] = "$kol = ?";
            $p[] = (int)$g[$k];
        }
    }
    if (!empty($g['durum']) && isset(DURUMLAR[$g['durum']])) {
        $w[] = 's.durum = ?';
        $p[] = $g['durum'];
    }
    if (isset($g['odendi']) && ($g['odendi'] === '0' || $g['odendi'] === '1')) {
        $w[] = 's.nakliye_odendi = ?';
        $p[] = (int)$g['odendi'];
    }
    if (!empty($g['plaka'])) {
        $pl = '%' . plaka($g['plaka']) . '%';
        $w[] = '(UPPER(s.cekici_plaka) LIKE ? OR UPPER(s.dorse_plaka) LIKE ?)';
        $p[] = $pl;
        $p[] = $pl;
    }
    if (!empty($g['ara'])) {
        $a = '%' . $g['ara'] . '%';
        $w[] = '(s.irsaliye_no LIKE ? OR s.kantar_fis_no LIKE ? OR s.notlar LIKE ? OR so.ad LIKE ? OR so.soyad LIKE ? OR so.tc LIKE ? OR yd.ad LIKE ? OR bd.ad LIKE ? OR u.ad LIKE ? OR CAST(s.id AS TEXT) = ?)';
        for ($i = 0; $i < 9; $i++) {
            $p[] = $a;
        }
        $p[] = $g['ara'];
    }
    return [' WHERE ' . implode(' AND ', $w), $p];
}

function sefer_basliklar()
{
    return [
        'Sefer No', 'Tarih', 'Teslim Tarihi', 'Durum',
        'Yükleme Deposu', 'Yükleme İl', 'Yükleme İlçe', 'Yükleme Koordinat',
        'Boşaltım Deposu', 'Boşaltım İl', 'Boşaltım İlçe', 'Boşaltım Koordinat',
        'Şoför', 'TC No', 'Telefon', 'Çekici Plaka', 'Dorse Plaka',
        'Ürün', 'Miktar (kg)', 'Miktar (ton)',
        'Ürün Ton Fiyatı (₺)', 'Ürün KDV %', 'Mal Bedeli KDV Hariç (₺)', 'Mal KDV (₺)', 'Mal Bedeli KDV Dahil (₺)',
        'Nakliye Ton Fiyatı (₺)', 'Nakliye KDV %', 'Nakliye KDV Hariç (₺)', 'Nakliye KDV (₺)', 'Nakliye KDV Dahil (₺)', 'Nakliye Ödendi',
        'İrsaliye No', 'Kantar Fiş No', 'Belge Linkleri', 'Notlar', 'Oluşturan', 'Oluşturma', 'Son Güncelleme', 'Silindi',
    ];
}

function sefer_para_sutunlari()
{
    return [20, 22, 23, 24, 25, 27, 28, 29];
}

function sefer_satir(array $s, array $belgeler)
{
    $h = hesap($s);
    $koor = function ($a, $b) {
        return ($a !== null && $a !== '') ? koordinat_fmt($a) . ',' . koordinat_fmt($b) : '';
    };
    $linkler = [];
    foreach ($belgeler as $b) {
        $linkler[] = (BELGE_TURLERI[$b['tur']] ?? 'Belge') . ': ' . belge_link($b['token']);
    }
    return [
        (int)$s['id'], tarih_tr($s['tarih']), tarih_tr($s['teslim_tarihi']), DURUMLAR[$s['durum']] ?? $s['durum'],
        (string)$s['ydepo_ad'], (string)$s['ydepo_il'], (string)$s['ydepo_ilce'], $koor($s['ydepo_lat'], $s['ydepo_lng']),
        (string)$s['bdepo_ad'], (string)$s['bdepo_il'], (string)$s['bdepo_ilce'], $koor($s['bdepo_lat'], $s['bdepo_lng']),
        sofor_adsoyad($s), (string)$s['sofor_tc'], (string)$s['sofor_telefon'], (string)$s['cekici_plaka'], (string)$s['dorse_plaka'],
        (string)$s['urun_ad'], (float)$s['miktar_kg'], round($h['ton'], 3),
        (float)$s['urun_ton_fiyat'], (float)$s['urun_kdv'], round($h['mal'], 2), round($h['mal_kdv'], 2), round($h['mal_toplam'], 2),
        (float)$s['nakliye_ton_fiyat'], (float)$s['nakliye_kdv'], round($h['nak'], 2), round($h['nak_kdv'], 2), round($h['nak_toplam'], 2),
        $s['nakliye_odendi'] ? 'Evet' : 'Hayır',
        (string)$s['irsaliye_no'], (string)$s['kantar_fis_no'], implode("\n", $linkler), (string)$s['notlar'],
        (string)$s['olusturan'], tarih_tr($s['olusturma']), tarih_tr($s['guncelleme']), $s['silindi'] ? 'Evet' : '',
    ];
}

function belgeler_grup(array $seferIdler)
{
    $sonuc = [];
    if (!$seferIdler) {
        return $sonuc;
    }
    foreach (array_chunk($seferIdler, 500) as $parca) {
        $rows = all('SELECT * FROM belgeler WHERE silindi = 0 AND sefer_id IN (' . implode(',', array_map('intval', $parca)) . ') ORDER BY id');
        foreach ($rows as $b) {
            $sonuc[$b['sefer_id']][] = $b;
        }
    }
    return $sonuc;
}

function urun_ozet_satirlari(array $seferler)
{
    $oz = [];
    foreach ($seferler as $s) {
        $k = $s['urun_ad'] ?: '(Ürün yok)';
        $h = hesap($s);
        if (!isset($oz[$k])) {
            $oz[$k] = [$k, 0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        }
        $oz[$k][1]++;
        $oz[$k][2] += (float)$s['miktar_kg'];
        $oz[$k][3] += $h['ton'];
        $oz[$k][4] += $h['mal'];
        $oz[$k][5] += $h['mal_toplam'];
        $oz[$k][6] += $h['nak'];
        $oz[$k][7] += $h['nak_toplam'];
    }
    ksort($oz);
    return array_map(function ($r) {
        return [$r[0], $r[1], round($r[2], 2), round($r[3], 3), round($r[4], 2), round($r[5], 2), round($r[6], 2), round($r[7], 2)];
    }, array_values($oz));
}

function urun_ozet_basliklar()
{
    return ['Ürün', 'Sefer Sayısı', 'Toplam kg', 'Toplam ton', 'Mal Bedeli KDV Hariç (₺)', 'Mal Bedeli KDV Dahil (₺)', 'Nakliye KDV Hariç (₺)', 'Nakliye KDV Dahil (₺)'];
}

/** Filtrelenmiş seferleri Excel'e aktarır */
function excel_seferler(array $g)
{
    list($where, $p) = sefer_filtre($g);
    $seferler = all(sefer_sql() . $where . ' ORDER BY s.tarih, s.id', $p);
    $bel = belgeler_grup(array_column($seferler, 'id'));
    $rows = [];
    foreach ($seferler as $s) {
        $rows[] = sefer_satir($s, $bel[$s['id']] ?? []);
    }
    $x = new Xlsx();
    $x->sayfa('Seferler', sefer_basliklar(), $rows, sefer_para_sutunlari());
    $x->sayfa('Ürün Özeti', urun_ozet_basliklar(), urun_ozet_satirlari($seferler), [4, 5, 6, 7]);
    $x->indir('seferler-' . date('Y-m-d-His') . '.xlsx');
}

/** Tüm veritabanının Excel yedeği (silinenler dahil) */
function excel_tam_yedek_olustur($yol)
{
    $seferler = all(sefer_sql() . ' ORDER BY s.id');
    $bel = belgeler_grup(array_column($seferler, 'id'));
    $rows = [];
    foreach ($seferler as $s) {
        $rows[] = sefer_satir($s, $bel[$s['id']] ?? []);
    }
    $x = new Xlsx();
    $x->sayfa('Seferler', sefer_basliklar(), $rows, sefer_para_sutunlari());
    $x->sayfa('Ürün Özeti', urun_ozet_basliklar(), urun_ozet_satirlari(array_filter($seferler, function ($s) {
        return !$s['silindi'];
    })), [4, 5, 6, 7]);

    $d = all('SELECT * FROM depolar ORDER BY tip, ad');
    $x->sayfa('Depolar', ['ID', 'Tip', 'Ad', 'Firma', 'İl', 'İlçe', 'Adres', 'Enlem', 'Boylam', 'Harita', 'Yetkili', 'Telefon', 'Notlar', 'Silindi', 'Oluşturma', 'Güncelleme'], array_map(function ($r) {
        return [(int)$r['id'], $r['tip'] === 'yukleme' ? 'Yükleme (Sarım)' : 'Boşaltım', $r['ad'], (string)$r['firma'], (string)$r['il'], (string)$r['ilce'], (string)$r['adres'],
            $r['lat'] !== null ? (float)$r['lat'] : '', $r['lng'] !== null ? (float)$r['lng'] : '', harita_link($r['lat'], $r['lng']),
            (string)$r['yetkili'], (string)$r['telefon'], (string)$r['notlar'], $r['silindi'] ? 'Evet' : '', tarih_tr($r['olusturma']), tarih_tr($r['guncelleme'])];
    }, $d));

    $so = all('SELECT * FROM soforler ORDER BY ad, soyad');
    $x->sayfa('Şoförler', ['ID', 'Ad', 'Soyad', 'TC No', 'Telefon', 'Çekici Plaka', 'Dorse Plaka', 'IBAN', 'Notlar', 'Silindi', 'Oluşturma', 'Güncelleme'], array_map(function ($r) {
        return [(int)$r['id'], $r['ad'], (string)$r['soyad'], (string)$r['tc'], (string)$r['telefon'], (string)$r['cekici_plaka'], (string)$r['dorse_plaka'], (string)$r['iban'], (string)$r['notlar'], $r['silindi'] ? 'Evet' : '', tarih_tr($r['olusturma']), tarih_tr($r['guncelleme'])];
    }, $so));

    $u = all('SELECT * FROM urunler ORDER BY ad');
    $x->sayfa('Ürünler', ['ID', 'Ürün', 'Ton Fiyatı (₺)', 'KDV %', 'Notlar', 'Silindi', 'Oluşturma', 'Güncelleme'], array_map(function ($r) {
        return [(int)$r['id'], $r['ad'], (float)$r['ton_fiyat'], (float)$r['kdv_orani'], (string)$r['notlar'], $r['silindi'] ? 'Evet' : '', tarih_tr($r['olusturma']), tarih_tr($r['guncelleme'])];
    }, $u), [2]);

    $b = all('SELECT * FROM belgeler ORDER BY id');
    $x->sayfa('Belgeler', ['ID', 'Sefer No', 'Tür', 'Dosya Adı', 'Boyut (KB)', 'Link', 'Yedekteki Dosya', 'Silindi', 'Yükleyen', 'Yükleme Zamanı'], array_map(function ($r) {
        return [(int)$r['id'], (int)$r['sefer_id'], BELGE_TURLERI[$r['tur']] ?? $r['tur'], (string)$r['orijinal_ad'], round($r['boyut'] / 1024, 1), belge_link($r['token']), 'belgeler/' . $r['dosya'], $r['silindi'] ? 'Evet' : '', (string)$r['olusturan'], tarih_tr($r['olusturma'])];
    }, $b));

    $g = all('SELECT * FROM islem_gecmisi ORDER BY id DESC LIMIT 20000');
    $x->sayfa('İşlem Geçmişi', ['Zaman', 'Kullanıcı', 'Tablo', 'Kayıt ID', 'İşlem', 'Eski Değer', 'Yeni Değer'], array_map(function ($r) {
        return [tarih_tr($r['zaman']), (string)$r['kullanici'], $r['tablo'], (int)$r['kayit_id'], $r['islem'], (string)$r['eski'], (string)$r['yeni']];
    }, $g));
    $x->kaydet($yol);
}
