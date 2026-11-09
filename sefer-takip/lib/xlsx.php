<?php
// Bağımlılıksız, basit .xlsx (Excel) dosya oluşturucu

class Xlsx
{
    private $sayfalar = [];

    /**
     * @param string $ad      Sayfa adı
     * @param array  $basliklar Başlık satırı
     * @param array  $satirlar  Veri satırları (dizi dizisi). Sayılar sayı olarak yazılır.
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

    private function sayfaXml($sf)
    {
        $gen = [];
        $tum = array_merge([$sf['basliklar']], $sf['satirlar']);
        foreach ($tum as $r) {
            foreach (array_values($r) as $i => $v) {
                $l = min(60, max(8, mb_strlen((string)$v) + 2));
                $gen[$i] = max($gen[$i] ?? 8, $l);
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
                    $o .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . self::x($v) . '</t></is></c>';
                } elseif ($v === null || $v === '') {
                    continue;
                } elseif (is_int($v) || is_float($v)) {
                    $st = in_array($ci, $sf['paraSutun'], true) ? ' s="2"' : '';
                    $o .= '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>';
                } else {
                    $o .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::x($v) . '</t></is></c>';
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
        $z->open($yol, ZipArchive::CREATE | ZipArchive::OVERWRITE);
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
        // Başlık satırındaki filtre için tanımlı ad
        $wb .= '<definedNames>';
        foreach ($this->sayfalar as $i => $sf) {
            $sat = count($sf['satirlar']) + 1;
            if ($sat > 1 && $sf['basliklar']) {
                $wb .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . self::x(str_replace("'", "''", $sf['ad'])) . '\'!$A$1:$' . self::sutun(count($sf['basliklar']) - 1) . '$' . $sat . '</definedName>';
            }
        }
        $wb .= '</definedNames></workbook>';
        $wb = str_replace('<definedNames></definedNames>', '', $wb);
        $ct .= '</Types>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
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
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->kaydet($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $dosyaAdi . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
