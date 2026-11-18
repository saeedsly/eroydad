<?php
declare(strict_types=1);

namespace Eroydad\Excel;

/**
 * XlsxWriter — ساخت فایل واقعی XLSX (Office Open XML) بدون هیچ کتابخانهٔ بیرونی.
 * همهٔ رشته‌ها به‌صورت inlineStr نوشته می‌شوند تا هیچ فرمولی اجرا نشود.
 */
final class XlsxWriter
{
    /** @var array<int,array<string,mixed>> */
    private array $sheets = [];

    /**
     * @param array<int,array<int,mixed>> $rows سطرها؛ سطر اول سرستون است.
     * @param array<string,mixed> $options ['columns' => [عرض‌ها], 'freeze' => bool, 'tabColor' => 'RRGGBB']
     */
    public function addSheet(string $name, array $rows, array $options = []): self
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'برگ';
        }
        $name = mb_substr($name, 0, 31, 'UTF-8');
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/u', '-', $name);
        $this->sheets[] = ['name' => (string)$name, 'rows' => array_values($rows), 'options' => $options];
        return $this;
    }

    public function sheetCount(): int
    {
        return count($this->sheets);
    }

    public function save(string $path): void
    {
        $binary = $this->toBinary();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($path, $binary);
    }

    public function toBinary(): string
    {
        if ($this->sheets === []) {
            $this->addSheet('برگ', [['بدون داده']]);
        }
        $entries = [];
        $entries['[Content_Types].xml'] = $this->contentTypes();
        $entries['_rels/.rels'] = $this->rootRels();
        $entries['xl/workbook.xml'] = $this->workbook();
        $entries['xl/_rels/workbook.xml.rels'] = $this->workbookRels();
        $entries['xl/styles.xml'] = $this->styles();
        foreach ($this->sheets as $index => $sheet) {
            $entries['xl/worksheets/sheet' . ($index + 1) . '.xml'] = $this->sheetXml($sheet);
        }
        $entries['docProps/core.xml'] = $this->coreProps();
        $entries['docProps/app.xml'] = $this->appProps();
        return Zip::create($entries);
    }

    private function contentTypes(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        foreach ($this->sheets as $index => $sheet) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($index + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        $xml .= '</Types>';
        return $xml;
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<workbookPr/><bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="14000" rightToLeft="1" activeTab="0"/></bookViews>';
        $xml .= '<sheets>';
        foreach ($this->sheets as $index => $sheet) {
            $xml .= '<sheet name="' . self::escape($sheet['name']) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }
        $xml .= '</sheets><calcPr calcId="0"/></workbook>';
        return $xml;
    }

    private function workbookRels(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $index => $sheet) {
            $xml .= '<Relationship Id="rId' . ($index + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
        }
        $xml .= '<Relationship Id="rId' . (count($this->sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $xml .= '</Relationships>';
        return $xml;
    }

    private function styles(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<fonts count="3">';
        $xml .= '<font><sz val="10"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>';
        $xml .= '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>';
        $xml .= '<font><b/><sz val="10"/><color rgb="FF14532D"/><name val="Calibri"/><family val="2"/></font>';
        $xml .= '</fonts>';
        $xml .= '<fills count="4">';
        $xml .= '<fill><patternFill patternType="none"/></fill>';
        $xml .= '<fill><patternFill patternType="gray125"/></fill>';
        $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF1F6F43"/><bgColor indexed="64"/></patternFill></fill>';
        $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF3ED"/><bgColor indexed="64"/></patternFill></fill>';
        $xml .= '</fills>';
        $xml .= '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFC9D8CE"/></left><right style="thin"><color rgb="FFC9D8CE"/></right><top style="thin"><color rgb="FFC9D8CE"/></top><bottom style="thin"><color rgb="FFC9D8CE"/></bottom><diagonal/></border></borders>';
        $xml .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $xml .= '<cellXfs count="4">';
        $xml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>';
        $xml .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>';
        $xml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>';
        $xml .= '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>';
        $xml .= '</cellXfs>';
        $xml .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
        $xml .= '</styleSheet>';
        return $xml;
    }

    /** @param array<string,mixed> $sheet */
    private function sheetXml(array $sheet): string
    {
        $rows = $sheet['rows'];
        $options = is_array($sheet['options'] ?? null) ? $sheet['options'] : [];
        $maxCols = 0;
        foreach ($rows as $row) {
            $count = is_array($row) ? count($row) : 0;
            if ($count > $maxCols) {
                $maxCols = $count;
            }
        }
        if ($maxCols === 0) {
            $maxCols = 1;
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/></sheetPr>';
        $xml .= '<sheetViews><sheetView rightToLeft="1" workbookViewId="0" showGridLines="1">';
        if (!empty($options['freeze']) && count($rows) > 1) {
            $xml .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/>';
        }
        $xml .= '</sheetView></sheetViews>';
        $xml .= '<sheetFormatPr defaultRowHeight="17"/>';
        $widths = is_array($options['columns'] ?? null) ? $options['columns'] : [];
        if ($widths !== []) {
            $xml .= '<cols>';
            $index = 0;
            foreach ($widths as $width) {
                $index++;
                $width = (float)$width;
                if ($width < 6) {
                    $width = 6;
                }
                if ($width > 60) {
                    $width = 60;
                }
                $xml .= '<col min="' . $index . '" max="' . $index . '" width="' . rtrim(rtrim(number_format($width, 2, '.', ''), '0'), '.') . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $isHeader = $rowIndex === 0;
            $xml .= '<row r="' . $rowNumber . '"' . ($isHeader ? ' s="1" customFormat="1"' : '') . '>';
            $cells = is_array($row) ? array_values($row) : [];
            foreach ($cells as $colIndex => $value) {
                $ref = self::columnName($colIndex + 1) . $rowNumber;
                $style = $isHeader ? 1 : 2;
                if (is_int($value) || is_float($value)) {
                    $number = is_float($value) ? rtrim(rtrim(sprintf('%.4F', $value), '0'), '.') : (string)$value;
                    if ($number === '') {
                        $number = '0';
                    }
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . self::escape($number) . '</v></c>';
                } else {
                    $text = (string)$value;
                    if ($isHeader) {
                        $style = 3;
                    }
                    $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::escape($text) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if (count($rows) > 1) {
            $xml .= '<autoFilter ref="A1:' . self::columnName($maxCols) . max(1, count($rows)) . '"/>';
        }
        $xml .= '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>';
        $xml .= '</worksheet>';
        return $xml;
    }

    private function coreProps(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>گزارش ای‌رویداد</dc:title><dc:creator>ای‌رویداد</dc:creator><cp:lastModifiedBy>ای‌رویداد</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function appProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>erooydad-local</Application><Company>مؤسسه آموزش، تحقیقات و مشاوره شهرداری‌های خراسان رضوی</Company>'
            . '</Properties>';
    }

    public static function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $index = (int)(($index - $mod) / 26);
        }
        return $name === '' ? 'A' : $name;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
