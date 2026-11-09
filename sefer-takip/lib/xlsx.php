<?php
// Bağımlılıksız, basit .xlsx (Excel) dosya oluşturucu
// Sayılar sayı, DateTime değerleri gerçek Excel tarihi olarak yazılır.

class Xlsx
{
    private $sayfalar = [];

    // Hücre stilleri (styles.xml içindeki sıra)
    const S_BASLIK = 1;
    const S_PARA = 2;
    const S_TARIH = 3;
    const S_TARIH_SAAT = 4;
    const S_SARMA = 5;

    /**
     * @param string $ad        Sayfa adı
     * @param array  $basliklar Başlık satırı
     * @param array  $satirlar  Veri satırları. int/float → sayı, DateTimeInterface → tarih, diğerleri → metin.
     * @param array  $paraSutun Para biçimi uygulanacak sütun indexleri (0'dan)
     */
    public function sayfa($ad, array $basliklar, array $satirlar, array $paraSutun = [])
    {
        $ad = preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', $ad);
        $ad = mb_substr($ad, 0, 31);
        $this->sayfalar[] = compact('ad', 'basliklar', 'satirlar', 'paraSutun');
    }

    private static function sutun($i)
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - $m, 26);
        }
        return $s;
    }

    private static function x($s)
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** DateTime → Excel seri sayısı (1899-12-30 tabanlı) */
    private static function seri(DateTimeInterface $d)
    {
        $utc = new DateTime($d->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
        return $utc->getTimestamp() / 86400 + 25569;
    }

    private static function genislik($v)
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('H:i:s') === '00:00:00' ? 12 : 17;
        }
        $en = 0;
        foreach (explode("\n", (string)$v) as $satir) {
            $en = max($en, mb_strlen($satir));
        }
        return $en + 2;
    }

    private function sayfaXml($sf)
    {
        $gen = [];
        $tum = array_merge([$sf['basliklar']], $sf['satirlar']);
        foreach ($tum as $r) {
            foreach (array_values($r) as $i => $v) {
                $gen[$i] = max($gen[$i] ?? 8, min(60, max(8, self::genislik($v))));
            }
        }
        $o = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>';
        foreach ($gen as $i => $w) {
            $o .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $o .= '</cols><sheetData>';
        foreach ($tum as $ri => $r) {
            $rn = $ri + 1;
            $o .= '<row r="' . $rn . '">';
            foreach (array_values($r) as $ci => $v) {
                $ref = self::sutun($ci) . $rn;
                if ($ri === 0) {
                    $o .= '<c r="' . $ref . '" t="inlineStr" s="' . self::S_BASLIK . '"><is><t>' . self::x($v) . '</t></is></c>';
                } elseif ($v === null || $v === '') {
                    continue;
                } elseif ($v instanceof DateTimeInterface) {
                    $st = $v->format('H:i:s') === '00:00:00' ? self::S_TARIH : self::S_TARIH_SAAT;
                    $o .= '<c r="' . $ref . '" s="' . $st . '"><v>' . round(self::seri($v), 10) . '</v></c>';
                } elseif (is_int($v) || is_float($v)) {
                    $st = in_array($ci, $sf['paraSutun'], true) ? ' s="' . self::S_PARA . '"' : '';
                    $o .= '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>';
                } else {
                    $st = strpos((string)$v, "\n") !== false ? ' s="' . self::S_SARMA . '"' : '';
                    $o .= '<c r="' . $ref . '" t="inlineStr"' . $st . '><is><t xml:space="preserve">' . self::x($v) . '</t></is></c>';
                }
            }
            $o .= '</row>';
        }
        $o .= '</sheetData>';
        if (count($tum) > 1 && $sf['basliklar']) {
            $o .= '<autoFilter ref="A1:' . self::sutun(count($sf['basliklar']) - 1) . count($tum) . '"/>';
        }
        $o .= '</worksheet>';
        return $o;
    }

    public function kaydet($yol)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Sunucuda ZipArchive (php-zip) eklentisi yok.');
        }
        if (!$this->sayfalar) {
            $this->sayfa('Sayfa1', [], []);
        }
        $z = new ZipArchive();
        if ($z->open($yol, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Excel dosyası oluşturulamadı.');
        }
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sayfalar as $i => $sf) {
            $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb .= '<sheet name="' . self::x($sf['ad']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $z->addFromString('xl/worksheets/sheet' . $n . '.xml', $this->sayfaXml($sf));
        }
        $sn = count($this->sayfalar) + 1;
        $rels .= '<Relationship Id="rId' . $sn . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $wb .= '</sheets>';
        $tanimli = '';
        foreach ($this->sayfalar as $i => $sf) {
            $sat = count($sf['satirlar']) + 1;
            if ($sat > 1 && $sf['basliklar']) {
                $tanimli .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . self::x(str_replace("'", "''", $sf['ad'])) . '\'!$A$1:$' . self::sutun(count($sf['basliklar']) - 1) . '$' . $sat . '</definedName>';
            }
        }
        if ($tanimli !== '') {
            $wb .= '<definedNames>' . $tanimli . '</definedNames>';
        }
        $wb .= '</workbook>';
        $ct .= '</Types>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="dd\.mm\.yyyy"/><numFmt numFmtId="166" formatCode="dd\.mm\.yyyy\ hh:mm"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
        $z->addFromString('[Content_Types].xml', $ct);
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', $wb);
        $z->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $z->addFromString('xl/styles.xml', $styles);
        $z->close();
    }

    public function indir($dosyaAdi)
    {
        $tmp = tempnam(gecici_dizin(), 'xlsx');
        $this->kaydet($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $dosyaAdi . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}

/** Geçici dosyalar için klasör (paylaşımlı hostinglerde /tmp kısıtlı olabilir) */
function gecici_dizin()
{
    $d = data_dir() . '/gecici';
    if (!is_dir($d)) {
        @mkdir($d, 0770, true);
    }
    if (is_dir($d) && is_writable($d)) {
        // 1 günden eski artıkları temizle
        foreach (glob($d . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        return $d;
    }
    return sys_get_temp_dir();
}

/** 'Y-m-d' veya 'Y-m-d H:i:s' metnini Excel tarihine çevirir; boşsa '' */
function excel_tarih($t)
{
    if (!$t) {
        return '';
    }
    try {
        return new DateTimeImmutable($t);
    } catch (Exception $e) {
        return (string)$t;
    }
}
