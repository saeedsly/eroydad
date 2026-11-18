<?php
declare(strict_types=1);

namespace Eroydad\Excel;

/**
 * XlsxReader — خواندن فایل XLSX بدون وابستگی بیرونی و بدون نیاز به افزونهٔ XML.
 * فقط ساختار سادهٔ Office Open XML پشتیبانی می‌شود (که خودِ XlsxWriter تولید می‌کند).
 */
final class XlsxReader
{
    /** @var array<int,array<string,mixed>> */
    private array $sheets = [];

    public function __construct(string $binary)
    {
        $entries = Zip::read($binary);
        $shared = $this->readSharedStrings($entries['xl/sharedStrings.xml'] ?? '');
        $sheetFiles = $this->readSheetFiles($entries);
        foreach ($sheetFiles as $index => $info) {
            $xml = $entries[$info['target']] ?? '';
            $this->sheets[] = [
                'name' => $info['name'],
                'rows' => $this->readRows($xml, $shared),
            ];
        }
        if ($this->sheets === []) {
            throw new \RuntimeException('در فایل XLSX هیچ برگی یافت نشد.');
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function sheets(): array
    {
        return $this->sheets;
    }

    /** @return array<int,mixed> */
    public function firstSheetRows(): array
    {
        return $this->sheets[0]['rows'] ?? [];
    }

    /**
     * @param array<string,string> $entries
     * @return array<int,array<string,string>>
     */
    private function readSheetFiles(array $entries): array
    {
        $rels = [];
        $relsXml = $entries['xl/_rels/workbook.xml.rels'] ?? '';
        if (preg_match_all('/<Relationship\b([^>]*)\/>/i', $relsXml, $matches)) {
            foreach ($matches[1] as $attrs) {
                $id = $this->attribute($attrs, 'Id');
                $target = $this->attribute($attrs, 'Target');
                if ($id !== '' && $target !== '') {
                    $rels[$id] = $target;
                }
            }
        }
        $result = [];
        $workbook = $entries['xl/workbook.xml'] ?? '';
        if (preg_match_all('/<sheet\b([^>]*)\/>/i', $workbook, $matches)) {
            foreach ($matches[1] as $attrs) {
                $name = $this->attribute($attrs, 'name');
                $rid = $this->attribute($attrs, 'r:id');
                if ($rid === '') {
                    $rid = $this->attribute($attrs, 'id');
                }
                $target = $rels[$rid] ?? '';
                if ($target === '') {
                    continue;
                }
                if ($target[0] !== '/') {
                    $target = 'xl/' . ltrim($target, '/');
                } else {
                    $target = ltrim($target, '/');
                }
                $result[] = ['name' => $name !== '' ? $name : ('برگ' . (count($result) + 1)), 'target' => $target];
            }
        }
        return $result;
    }

    /** @return array<int,string> */
    private function readSharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $strings = [];
        if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is', $xml, $matches)) {
            foreach ($matches[1] as $chunk) {
                $text = '';
                if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/is', $chunk, $textMatches)) {
                    foreach ($textMatches[1] as $part) {
                        $text .= html_entity_decode($part, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                }
                $strings[] = $text;
            }
        }
        return $strings;
    }

    /**
     * @param array<int,string> $shared
     * @return array<int,array<int,string>>
     */
    private function readRows(string $xml, array $shared): array
    {
        $rows = [];
        if ($xml === '') {
            return $rows;
        }
        if (!preg_match_all('/<row\b([^>]*)>(.*?)<\/row>/is', $xml, $rowMatches)) {
            return $rows;
        }
        foreach ($rowMatches[2] as $rowXml) {
            $cells = [];
            if (!preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/is', $rowXml, $cellMatches, PREG_SET_ORDER)) {
                $rows[] = $cells;
                continue;
            }
            $columnIndex = 0;
            foreach ($cellMatches as $cell) {
                $attrs = $cell[1];
                $inner = $cell[2] ?? '';
                $ref = $this->attribute($attrs, 'r');
                if ($ref !== '' && preg_match('/([A-Z]+)/', $ref, $m)) {
                    $columnIndex = self::columnIndex($m[1]);
                } else {
                    $columnIndex++;
                }
                $type = strtolower($this->attribute($attrs, 't'));
                $value = '';
                if ($type === 'inlinestr') { // $type کوچک‌شده است
                    if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/is', $inner, $textMatches)) {
                        foreach ($textMatches[1] as $part) {
                            $value .= html_entity_decode($part, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    }
                } elseif (preg_match('/<v>(.*?)<\/v>/is', $inner, $valueMatch)) {
                    $value = html_entity_decode($valueMatch[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    if ($type === 's') {
                        $index = (int)$value;
                        $value = $shared[$index] ?? '';
                    }
                }
                $cells[$columnIndex] = $value;
            }
            ksort($cells);
            if ($cells === []) {
                $rows[] = [];
                continue;
            }
            // سطرها در XLSX واقعی ممکن است سلول خالی داشته باشند؛ با پرکردن شکاف‌ها
            // ستون‌ها در جای خود می‌مانند (مقدار جابه‌جا نمی‌شود).
            $lastIndex = max(array_keys($cells));
            $row = [];
            for ($i = 0; $i <= $lastIndex; $i++) {
                $row[] = isset($cells[$i]) ? $cells[$i] : '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function attribute(string $attributes, string $name): string
    {
        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $attributes, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        if (preg_match("/\b" . preg_quote($name, '/') . "\s*=\s*'([^']*)'/i", $attributes, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return '';
    }

    /** شمارهٔ ستون (پایه صفر): A → 0، B → 1، …، AA → 26 */
    public static function columnIndex(string $letters): int
    {
        $index = 0;
        $letters = strtoupper($letters);
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($letters[$i]);
            if ($code < 65 || $code > 90) {
                continue;
            }
            $index = $index * 26 + ($code - 64);
        }
        return max(0, $index - 1);
    }
}
