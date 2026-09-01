<?php

namespace BoqAllocator\Services;

use DOMDocument;
use Exception;
use ZipArchive;

class BoqParserService
{
    /**
     * Parse any Excel XLSX file using native ZipArchive & DOMDocument (Zero external dependencies).
     */
    public function parseXlsx(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("Spreadsheet file not found: $filePath");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("Unable to open XLSX archive: $filePath");
        }

        // 1. Shared Strings
        $strings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $doc = new DOMDocument();
            @$doc->loadXML($ssXml);
            foreach ($doc->getElementsByTagName('si') as $si) {
                $text = '';
                foreach ($si->getElementsByTagName('t') as $t) {
                    $text .= $t->textContent;
                }
                $strings[] = $text;
            }
        }

        // 2. Workbook sheet mapping
        $wbXml = $zip->getFromName('xl/workbook.xml');
        $sheetsByRid = [];
        if ($wbXml) {
            $doc = new DOMDocument();
            @$doc->loadXML($wbXml);
            foreach ($doc->getElementsByTagName('sheet') as $s) {
                $name = $s->getAttribute('name');
                $rid = $s->getAttribute('r:id') ?: $s->getAttribute('id');
                $sheetsByRid[$rid] = $name;
            }
        }

        // 3. Relationships
        $relXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $sheetFiles = [];
        if ($relXml) {
            $doc = new DOMDocument();
            @$doc->loadXML($relXml);
            foreach ($doc->getElementsByTagName('Relationship') as $r) {
                $rid = $r->getAttribute('Id');
                if (isset($sheetsByRid[$rid])) {
                    $sheetFiles[$sheetsByRid[$rid]] = 'xl/' . $r->getAttribute('Target');
                }
            }
        }

        // 4. Extract rows per sheet
        $allSheets = [];
        foreach ($sheetFiles as $name => $path) {
            $xml = $zip->getFromName($path);
            if (!$xml) continue;

            $doc = new DOMDocument();
            @$doc->loadXML($xml);
            $rows = [];
            foreach ($doc->getElementsByTagName('row') as $row) {
                $rd = [];
                foreach ($row->getElementsByTagName('c') as $cell) {
                    preg_match('/^([A-Z]+)/', $cell->getAttribute('r'), $m);
                    $col = $m[1] ?? '';
                    $v = $cell->getElementsByTagName('v')->item(0);
                    if ($v) {
                        $rd[$col] = ($cell->getAttribute('t') === 's') ? ($strings[(int)$v->textContent] ?? '') : $v->textContent;
                    }
                }
                $rows[] = $rd;
            }
            $allSheets[$name] = $rows;
        }

        $zip->close();
        return $allSheets;
    }

    /**
     * Parse CSV files into structured rows mapped to column keys A, B, C, D...
     */
    public function parseCsv(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("CSV file not found: $filePath");
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception("Cannot open CSV file: $filePath");
        }

        $colLetters = range('A', 'Z');
        $rows = [];

        // Check and strip UTF-8 BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        while (($data = fgetcsv($handle, 10000, ",", '"', "\\")) !== false) {
            $rd = [];
            foreach ($data as $idx => $val) {
                $col = $colLetters[$idx] ?? ('C' . $idx);
                $rd[$col] = trim($val);
            }
            $rows[] = $rd;
        }
        fclose($handle);

        return ['Sheet1' => $rows];
    }

    /**
     * Load Works Package Template (Auto-detects 2-column or 4-column NRM2 hierarchy).
     */
    public function parseTemplate(string $templatePath): array
    {
        $ext = strtolower(pathinfo($templatePath, PATHINFO_EXTENSION));
        $data = ($ext === 'csv') ? $this->parseCsv($templatePath) : $this->parseXlsx($templatePath);
        $sheet = $data['Sheet1'] ?? reset($data);

        $wdPackages = [];
        $wdList = [];
        $tier2Map = [];
        $isTiered = false;

        $is4ColumnNrm = false;
        $isNrm1 = false;

        if (!empty($sheet)) {
            $firstRow = $sheet[0] ?? [];
            if (isset($firstRow['C']) || (isset($firstRow['A']) && stripos($firstRow['A'], 'Work Section Number') !== false)) {
                $is4ColumnNrm = true;
            } elseif (isset($sheet[1]['B']) && stripos($sheet[1]['B'], 'Context: Belongs to Group Element:') !== false) {
                $isNrm1 = true;
            }
        }

        if ($is4ColumnNrm) {
            $isTiered = true;
            $sectionGroups = [];
            foreach ($sheet as $idx => $row) {
                if ($idx === 0) continue;
                $secNum = trim($row['A'] ?? '');
                $secName = trim($row['B'] ?? '');
                $itemNum = trim($row['C'] ?? '');
                $itemName = trim($row['D'] ?? '');

                if ($secNum === '' || $secName === '') continue;

                if (!isset($sectionGroups[$secNum])) {
                    $sectionGroups[$secNum] = [
                        'name' => "Section $secNum: $secName",
                        'items' => []
                    ];
                }
                if ($itemName !== '') {
                    $sectionGroups[$secNum]['items'][] = [
                        'id' => 't2_' . md5($secNum . $itemName . $itemNum),
                        'name' => $itemNum !== '' ? "$itemNum $itemName" : $itemName,
                        'description' => "Detailed item: $itemName"
                    ];
                }
            }

            foreach ($sectionGroups as $secNum => $g) {
                $id = 'wd_' . $secNum;
                $name = $g['name'];
                
                $itemNames = array_column($g['items'], 'name');
                $desc = "Includes: " . implode(', ', array_slice($itemNames, 0, 12)) . (count($itemNames) > 12 ? '...' : '.');
                
                $wdPackages[$name] = $id;
                $wdList[] = ['id' => $id, 'name' => $name, 'description' => $desc];
                $tier2Map[$id] = $g['items'];
            }
        } elseif ($isNrm1) {
            $isTiered = true;
            $groupElements = [];
            foreach ($sheet as $idx => $row) {
                if ($idx === 0) continue;
                $name = trim($row['A'] ?? '');
                $desc = trim($row['B'] ?? '');

                if (preg_match("/Group Element:\s*'([^']+)'/", $desc, $m)) {
                    $groupName = $m[1];
                    if (!isset($groupElements[$groupName])) {
                        $groupElements[$groupName] = [];
                    }
                    $groupElements[$groupName][] = [
                        'id' => 't2_' . md5($groupName . $name),
                        'name' => $name,
                        'description' => $desc
                    ];
                }
            }

            $groupIdx = 1;
            foreach ($groupElements as $gName => $items) {
                $id = 'wd_g' . $groupIdx++;
                
                $itemNames = array_column($items, 'name');
                $desc = "Includes: " . implode(', ', array_slice($itemNames, 0, 12)) . (count($itemNames) > 12 ? '...' : '.');
                
                $wdPackages[$gName] = $id;
                $wdList[] = ['id' => $id, 'name' => "Group: $gName", 'description' => $desc];
                $tier2Map[$id] = $items;
            }
        } else {
            // Flat WD Template
            foreach ($sheet as $row) {
                $name = trim($row['A'] ?? '');
                $desc = trim($row['B'] ?? '');
                if ($name === '' || stripos($name, 'Works Package') !== false || stripos($name, 'wd_') !== false) continue;

                $id = 'wd_' . count($wdPackages);
                $wdPackages[$name] = $id;
                $wdList[] = ['id' => $id, 'name' => $name, 'description' => $desc];
            }
        }

        return [
            'is_tiered' => $isTiered,
            'packages' => $wdPackages,
            'list' => $wdList,
            'tier2_map' => $tier2Map
        ];
    }

    /**
     * Extract General Summary Bills and detailed representative bill items from BoQ spreadsheet.
     */
    public function parseBoq(string $boqPath, int $maxContextItemsPerBill = 20): array
    {
        $boqData = $this->parseXlsx($boqPath);
        $genSummary = $boqData['General Summary'] ?? [];
        $bills = [];

        foreach ($genSummary as $row) {
            $colA = trim($row['A'] ?? '');
            $colB = trim($row['B'] ?? '');
            if (preg_match('/^Bill\s+(\d+)$/i', $colA, $m) && $colB !== '') {
                $bills[(int)$m[1]] = $this->cleanText($colB);
            }
        }
        ksort($bills);

        $billContext = [];
        $billItems = $boqData['Bill Items'] ?? [];
        $lastBillNum = null;

        foreach ($billItems as $row) {
            $colA = trim($row['A'] ?? '');
            $colE = trim($row['E'] ?? '');

            if ($colA !== '' && ctype_digit($colA)) {
                $lastBillNum = (int)$colA;
            }

            if ($lastBillNum !== null && isset($bills[$lastBillNum]) && $colE !== '') {
                $desc = $this->cleanText(mb_substr($colE, 0, 400));
                // Filter out standard page headers, totals, and boilerplate
                if (!preg_match('/(to collection|brought forward|carried forward|page total|summary)/i', $desc) && strlen($desc) > 3) {
                    if (!isset($billContext[$lastBillNum])) {
                        $billContext[$lastBillNum] = [];
                    }
                    if (count($billContext[$lastBillNum]) < $maxContextItemsPerBill) {
                        if (!in_array($desc, $billContext[$lastBillNum])) {
                            $billContext[$lastBillNum][] = $desc;
                        }
                    }
                }
            }
        }

        return [
            'bills' => $bills,
            'billContext' => $billContext
        ];
    }

    public function cleanText(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace("\n", " ", $s)));
    }
}
