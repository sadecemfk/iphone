<?php
// Sefer filtreleme, toplamlar ve Excel dışa aktarma

/** GET parametrelerinden WHERE oluşturur (sorgu sefer_from() ile birlikte kullanılır) */
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
        if (!empty($g[$k]) && is_string($g[$k]) && ctype_digit($g[$k])) {
            $w[] = "$kol = ?";
            $p[] = (int)$g[$k];
        }
    }
    if (!empty($g['durum']) && is_string($g['durum']) && isset(DURUMLAR[$g['durum']])) {
        $w[] = 's.durum = ?';
        $p[] = $g['durum'];
    }
    if (isset($g['odendi']) && ($g['odendi'] === '0' || $g['odendi'] === '1')) {
        $w[] = 's.nakliye_odendi = ?';
        $p[] = (int)$g['odendi'];
    }
    if (!empty($g['bosaltim']) && is_string($g['bosaltim'])) {
        if ($g['bosaltim'] === 'eksik') {
            // Yüklenmiş / teslim edilmiş ama boşaltım kantarı girilmemiş
            $w[] = "s.bosaltim_kg IS NULL AND s.durum IN ('yuklendi', 'teslim')";
        } elseif ($g['bosaltim'] === 'girildi') {
            $w[] = 's.bosaltim_kg IS NOT NULL';
        } elseif ($g['bosaltim'] === 'asim') {
            $w[] = 's.bosaltim_kg IS NOT NULL AND s.miktar_kg > 0 AND ABS(s.miktar_kg - s.bosaltim_kg) * 100.0 / s.miktar_kg > CAST(? AS REAL)';
            $p[] = fire_toleransi();
        }
    }
    if (!empty($g['plaka']) && is_string($g['plaka'])) {
        $pl = '%' . plaka($g['plaka']) . '%';
        $w[] = '(s.cekici_plaka LIKE ? OR s.dorse_plaka LIKE ?)';
        $p[] = $pl;
        $p[] = $pl;
    }
    if (!empty($g['ara']) && is_string($g['ara'])) {
        $a = '%' . tr_kucuk($g['ara']) . '%';
        $kolonlar = ['s.irsaliye_no', 's.kantar_fis_no', 's.bosaltim_irsaliye_no', 's.bosaltim_kantar_fis_no', 's.notlar', 'so.ad', 'so.soyad', "so.ad || ' ' || COALESCE(so.soyad, '')", 'so.tc', 'yd.ad', 'bd.ad', 'u.ad', 's.cekici_plaka', 's.dorse_plaka'];
        $kosul = array_map(function ($k) {
            return tr_kucuk_sql($k) . ' LIKE ?';
        }, $kolonlar);
        $kosul[] = 'CAST(s.id AS TEXT) = ?';
        $w[] = '(' . implode(' OR ', $kosul) . ')';
        foreach ($kolonlar as $_) {
            $p[] = $a;
        }
        $p[] = ltrim($g['ara'], '#');
    }
    return [' WHERE ' . implode(' AND ', $w), $p];
}

/** Toplam SQL ifadeleri (iptaller hariç); mal ve nakliye seferin hesap esasına göre */
function toplam_sql_parcalari()
{
    $mal = sql_esas_kg('mal_esas') . ' / 1000.0 * s.urun_ton_fiyat';
    $nak = sql_esas_kg('nakliye_esas') . ' / 1000.0 * s.nakliye_ton_fiyat';
    return "COUNT(*) adet,
        COALESCE(SUM(CASE WHEN s.durum != 'iptal' THEN s.miktar_kg END), 0) kg,
        COALESCE(SUM(CASE WHEN s.durum != 'iptal' THEN s.bosaltim_kg END), 0) bos_kg,
        COALESCE(SUM(CASE WHEN s.durum != 'iptal' AND s.bosaltim_kg IS NOT NULL THEN s.miktar_kg - s.bosaltim_kg END), 0) fire_kg,
        COALESCE(SUM(CASE WHEN s.durum != 'iptal' AND s.bosaltim_kg IS NOT NULL THEN s.miktar_kg END), 0) fire_taban_kg,
        COALESCE(SUM(CASE WHEN s.durum != 'iptal' THEN $mal END), 0) mal,
        COALESCE(SUM(CASE WHEN s.durum != 'iptal' THEN $mal * (1 + s.urun_kdv / 100.0) END), 0) mal_toplam,
        COALESCE(SUM(CASE WHEN s.durum != 'iptal' THEN $nak END), 0) nak,
        COALESCE(SUM(CASE WHEN s.durum != 'iptal' THEN $nak * (1 + s.nakliye_kdv / 100.0) END), 0) nak_toplam";
}

/** Filtreye uyan seferlerin sayısı ve (iptaller hariç) toplamları — SQL ile */
function sefer_toplamlar($where, array $p)
{
    $r = one('SELECT ' . toplam_sql_parcalari() . sefer_from() . $where, $p);
    $r = array_map('floatval', $r);
    $r['fire_yuzde'] = $r['fire_taban_kg'] > 0 ? $r['fire_kg'] / $r['fire_taban_kg'] * 100 : null;
    $tol = fire_toleransi();
    $r['fire_asim'] = (int)one("SELECT COUNT(*) c" . sefer_from() . $where . " AND s.durum != 'iptal' AND s.bosaltim_kg IS NOT NULL AND s.miktar_kg > 0 AND ABS(s.miktar_kg - s.bosaltim_kg) * 100.0 / s.miktar_kg > CAST(? AS REAL)", array_merge($p, [$tol]))['c'];
    $r['bos_eksik'] = (int)one("SELECT COUNT(*) c" . sefer_from() . $where . " AND s.bosaltim_kg IS NULL AND s.durum IN ('yuklendi', 'teslim')", $p)['c'];
    return $r;
}

/** Ürün bazında özet (iptaller hariç) — SQL ile */
function urun_ozet($where, array $p)
{
    $rows = all("SELECT COALESCE(u.ad, '(Ürün yok)') urun, " . toplam_sql_parcalari()
        . sefer_from() . $where . " AND s.durum != 'iptal' GROUP BY COALESCE(u.ad, '(Ürün yok)') ORDER BY urun", $p);
    return array_map(function ($r) {
        $o = ['urun' => $r['urun']];
        foreach ($r as $k => $v) {
            if ($k !== 'urun') {
                $o[$k] = (float)$v;
            }
        }
        $o['fire_yuzde'] = $o['fire_taban_kg'] > 0 ? $o['fire_kg'] / $o['fire_taban_kg'] * 100 : null;
        return $o;
    }, $rows);
}

function urun_ozet_basliklar()
{
    return ['Ürün', 'Sefer Sayısı', 'Yükleme (kg)', 'Yükleme (ton)', 'Boşaltım (kg)', 'Fire (kg)', 'Fire %',
        'Mal Bedeli KDV Hariç (₺)', 'Mal Bedeli KDV Dahil (₺)', 'Nakliye KDV Hariç (₺)', 'Nakliye KDV Dahil (₺)'];
}

function urun_ozet_satirlari(array $ozet)
{
    return array_map(function ($o) {
        return [$o['urun'], (int)$o['adet'], round($o['kg'], 2), round($o['kg'] / 1000, 3), round($o['bos_kg'], 2),
            round($o['fire_kg'], 2), $o['fire_yuzde'] === null ? '' : round($o['fire_yuzde'], 2),
            round($o['mal'], 2), round($o['mal_toplam'], 2), round($o['nak'], 2), round($o['nak_toplam'], 2)];
    }, $ozet);
}

/** Başlığında (₺) geçen sütunlar para biçiminde yazılır */
function para_sutunlari(array $basliklar)
{
    return array_keys(array_filter($basliklar, function ($b) {
        return strpos($b, '(₺)') !== false;
    }));
}

function sefer_basliklar()
{
    return [
        'Sefer No', 'Yükleme Tarihi', 'Boşaltım (Teslim) Tarihi', 'Durum',
        'Yükleme Deposu', 'Yükleme İl', 'Yükleme İlçe', 'Yükleme Koordinat',
        'Boşaltım Deposu', 'Boşaltım İl', 'Boşaltım İlçe', 'Boşaltım Koordinat',
        'Şoför', 'TC No', 'Telefon', 'Çekici Plaka', 'Dorse Plaka', 'Ürün',
        'Yükleme Net (kg)', 'Yükleme İrsaliye No', 'Yükleme Kantar Fiş No',
        'Boşaltım Net (kg)', 'Boşaltım İrsaliye No', 'Boşaltım Kantar Fiş No',
        'Fire (kg)', 'Fire %', 'Fire Durumu',
        'Ürün Ton Fiyatı (₺)', 'Ürün KDV %', 'Mal Hesap Esası', 'Mal Hesap Miktarı (kg)',
        'Mal Bedeli KDV Hariç (₺)', 'Mal KDV (₺)', 'Mal Bedeli KDV Dahil (₺)',
        'Nakliye Ton Fiyatı (₺)', 'Nakliye KDV %', 'Nakliye Hesap Esası', 'Nakliye Hesap Miktarı (kg)',
        'Nakliye KDV Hariç (₺)', 'Nakliye KDV (₺)', 'Nakliye KDV Dahil (₺)', 'Nakliye Ödendi',
        'Belge Linkleri', 'Notlar', 'Oluşturan', 'Oluşturma', 'Son Güncelleme', 'Silindi',
    ];
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
    $fd = fire_durumu($h);
    $esasMetni = function ($esas, $gecici) {
        return (ESASLAR[$esas] ?? $esas) . ($gecici ? ' (boşaltım girilmedi, geçici: yükleme)' : '');
    };
    return [
        (int)$s['id'], excel_tarih($s['tarih']), excel_tarih($s['teslim_tarihi']), DURUMLAR[$s['durum']] ?? $s['durum'],
        (string)$s['ydepo_ad'], (string)$s['ydepo_il'], (string)$s['ydepo_ilce'], $koor($s['ydepo_lat'], $s['ydepo_lng']),
        (string)$s['bdepo_ad'], (string)$s['bdepo_il'], (string)$s['bdepo_ilce'], $koor($s['bdepo_lat'], $s['bdepo_lng']),
        sofor_adsoyad($s), (string)$s['sofor_tc'], (string)sefer_telefon($s), (string)$s['cekici_plaka'], (string)$s['dorse_plaka'], (string)$s['urun_ad'],
        (float)$s['miktar_kg'], (string)$s['irsaliye_no'], (string)$s['kantar_fis_no'],
        $h['bos_kg'] === null ? '' : $h['bos_kg'], (string)$s['bosaltim_irsaliye_no'], (string)$s['bosaltim_kantar_fis_no'],
        $h['fire_kg'] === null ? '' : round($h['fire_kg'], 2), $h['fire_yuzde'] === null ? '' : round($h['fire_yuzde'], 2),
        $fd === null ? 'Boşaltım girilmedi' : ['normal' => 'Normal', 'asim' => 'Tolerans aşıldı (eksik)', 'fazla' => 'Tolerans aşıldı (fazla)'][$fd],
        (float)$s['urun_ton_fiyat'], (float)$s['urun_kdv'], $esasMetni($s['mal_esas'] ?? 'yukleme', $h['mal_gecici']), round($h['mal_kg'], 2),
        round($h['mal'], 2), round($h['mal_kdv'], 2), round($h['mal_toplam'], 2),
        (float)$s['nakliye_ton_fiyat'], (float)$s['nakliye_kdv'], $esasMetni($s['nakliye_esas'] ?? 'yukleme', $h['nak_gecici']), round($h['nak_kg'], 2),
        round($h['nak'], 2), round($h['nak_kdv'], 2), round($h['nak_toplam'], 2), $s['nakliye_odendi'] ? 'Evet' : 'Hayır',
        implode("\n", $linkler), (string)$s['notlar'], (string)$s['olusturan'], excel_tarih($s['olusturma']), excel_tarih($s['guncelleme']), $s['silindi'] ? 'Evet' : '',
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
    $x->sayfa('Seferler', sefer_basliklar(), $rows, para_sutunlari(sefer_basliklar()));
    $x->sayfa('Ürün Özeti (iptaller hariç)', urun_ozet_basliklar(), urun_ozet_satirlari(urun_ozet($where, $p)), para_sutunlari(urun_ozet_basliklar()));
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
    $x->sayfa('Seferler', sefer_basliklar(), $rows, para_sutunlari(sefer_basliklar()));
    $x->sayfa('Ürün Özeti (iptaller hariç)', urun_ozet_basliklar(), urun_ozet_satirlari(urun_ozet(' WHERE s.silindi = 0', [])), para_sutunlari(urun_ozet_basliklar()));

    $d = all('SELECT * FROM depolar ORDER BY tip, ad');
    $x->sayfa('Depolar', ['ID', 'Tip', 'Ad', 'Firma', 'İl', 'İlçe', 'Adres', 'Enlem', 'Boylam', 'Harita', 'Yetkili', 'Telefon', 'Notlar', 'Silindi', 'Oluşturma', 'Güncelleme'], array_map(function ($r) {
        return [(int)$r['id'], $r['tip'] === 'yukleme' ? 'Yükleme (Sarım)' : 'Boşaltım', $r['ad'], (string)$r['firma'], (string)$r['il'], (string)$r['ilce'], (string)$r['adres'],
            $r['lat'] !== null ? (float)$r['lat'] : '', $r['lng'] !== null ? (float)$r['lng'] : '', harita_link($r['lat'], $r['lng']),
            (string)$r['yetkili'], (string)$r['telefon'], (string)$r['notlar'], $r['silindi'] ? 'Evet' : '', excel_tarih($r['olusturma']), excel_tarih($r['guncelleme'])];
    }, $d));

    $so = all('SELECT * FROM soforler ORDER BY ad, soyad');
    $x->sayfa('Şoförler', ['ID', 'Ad', 'Soyad', 'TC No', 'Telefon', 'Çekici Plaka', 'Dorse Plaka', 'IBAN', 'Notlar', 'Silindi', 'Oluşturma', 'Güncelleme'], array_map(function ($r) {
        return [(int)$r['id'], $r['ad'], (string)$r['soyad'], (string)$r['tc'], (string)$r['telefon'], (string)$r['cekici_plaka'], (string)$r['dorse_plaka'], (string)$r['iban'], (string)$r['notlar'], $r['silindi'] ? 'Evet' : '', excel_tarih($r['olusturma']), excel_tarih($r['guncelleme'])];
    }, $so));

    $u = all('SELECT * FROM urunler ORDER BY ad');
    $x->sayfa('Ürünler', ['ID', 'Ürün', 'Ton Fiyatı (₺)', 'KDV %', 'Notlar', 'Silindi', 'Oluşturma', 'Güncelleme'], array_map(function ($r) {
        return [(int)$r['id'], $r['ad'], (float)$r['ton_fiyat'], (float)$r['kdv_orani'], (string)$r['notlar'], $r['silindi'] ? 'Evet' : '', excel_tarih($r['olusturma']), excel_tarih($r['guncelleme'])];
    }, $u), [2]);

    $b = all('SELECT * FROM belgeler ORDER BY id');
    $x->sayfa('Belgeler', ['ID', 'Sefer No', 'Tür', 'Dosya Adı', 'Boyut (KB)', 'Link', 'Yedekteki Dosya', 'Silindi', 'Yükleyen', 'Yükleme Zamanı'], array_map(function ($r) {
        return [(int)$r['id'], (int)$r['sefer_id'], BELGE_TURLERI[$r['tur']] ?? $r['tur'], (string)$r['orijinal_ad'], round($r['boyut'] / 1024, 1), belge_link($r['token']), 'belgeler/' . $r['dosya'], $r['silindi'] ? 'Evet' : '', (string)$r['olusturan'], excel_tarih($r['olusturma'])];
    }, $b));

    $k = all('SELECT kullanici_adi, ad, yonetici, aktif, son_giris, olusturma FROM kullanicilar ORDER BY id');
    $x->sayfa('Kullanıcılar', ['Kullanıcı Adı', 'Ad', 'Yönetici', 'Aktif', 'Son Giriş', 'Oluşturma'], array_map(function ($r) {
        return [$r['kullanici_adi'], (string)$r['ad'], $r['yonetici'] ? 'Evet' : 'Hayır', $r['aktif'] ? 'Evet' : 'Hayır', excel_tarih($r['son_giris']), excel_tarih($r['olusturma'])];
    }, $k));

    $g = all('SELECT * FROM islem_gecmisi ORDER BY id DESC LIMIT 20000');
    $x->sayfa('İşlem Geçmişi', ['Zaman', 'Kullanıcı', 'Tablo', 'Kayıt ID', 'İşlem', 'Eski Değer', 'Yeni Değer'], array_map(function ($r) {
        return [excel_tarih($r['zaman']), (string)$r['kullanici'], $r['tablo'], (int)$r['kayit_id'], $r['islem'], (string)$r['eski'], (string)$r['yeni']];
    }, $g));
    $x->kaydet($yol);
}
