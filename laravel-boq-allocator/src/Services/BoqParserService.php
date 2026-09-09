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
                $rd = ['_row' => (int)$row->getAttribute('r')];
                foreach ($row->getElementsByTagName('c') as $cell) {
                    preg_match('/^([A-Z]+)/', $cell->getAttribute('r'), $m);
                    $col = $m[1] ?? '';
                    $v = $cell->getElementsByTagName('v')->item(0);
                    if ($v) {
                        $rd[$col] = ($cell->getAttribute('t') === 's') ? ($strings[(int)$v->textContent] ?? '') : $v->textContent;
                    } elseif ($cell->getAttribute('t') === 'inlineStr') {
                        $text = '';
                        foreach ($cell->getElementsByTagName('t') as $node) $text .= $node->textContent;
                        $rd[$col] = $text;
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
        $nrm2StartIndex = 1;

        if (!empty($sheet)) {
            $firstRow = $sheet[0] ?? [];
            if (isset($firstRow['A']) && stripos($firstRow['A'], 'Group Element') !== false) {
                $isNrm1 = true;
            } elseif (isset($firstRow['A']) && stripos($firstRow['A'], 'Work Section Number') !== false) {
                $is4ColumnNrm = true;
            } elseif (ctype_digit(trim((string)($firstRow['A'] ?? ''))) && trim((string)($firstRow['C'] ?? '')) !== '' && trim((string)($firstRow['D'] ?? '')) !== '') {
                // The bundled AITOOLV3 NRM2 workbook starts directly with data.
                $is4ColumnNrm = true;
                $nrm2StartIndex = 0;
            }
        }

        if ($is4ColumnNrm) {
            $isTiered = true;
            $sectionGroups = [];
            foreach ($sheet as $idx => $row) {
                if ($idx < $nrm2StartIndex) continue;
                $secNum = trim($row['A'] ?? '');
                $secName = trim($row['B'] ?? '');
                $itemNum = trim($row['C'] ?? '');
                $itemName = trim($row['D'] ?? '');

                if ($secNum === '' || $secName === '') continue;

                if (!isset($sectionGroups[$secNum])) {
                    $sectionGroups[$secNum] = [
                        'name' => "Section $secNum: $secName",
                        'code' => $secNum,
                        'section_name' => $secName,
                        'items' => []
                    ];
                }
                if ($itemName !== '') {
                    $sectionGroups[$secNum]['items'][] = [
                        'id' => 't2_' . md5($secNum . $itemName . $itemNum),
                        'code' => $secNum . '.' . $itemNum,
                        'name' => $itemNum !== '' ? "$itemNum $itemName" : $itemName,
                        'description' => "Detailed item: $itemName",
                        'section_code' => $secNum,
                        'section_name' => $secName,
                        'item_code' => $itemNum,
                        'item_name' => $itemName,
                        'template_row' => $idx + 1,
                    ];
                }
            }

            foreach ($sectionGroups as $secNum => $g) {
                $id = 'wd_' . $secNum;
                $name = $g['name'];

                $itemNames = array_column($g['items'], 'name');
                $desc = "Includes: " . implode(', ', array_slice($itemNames, 0, 12)) . (count($itemNames) > 12 ? '...' : '.');

                $wdPackages[$name] = $id;
                $wdList[] = ['id' => $id, 'name' => $name, 'description' => $desc, 'code' => (string)$secNum, 'section_name' => $g['section_name']];
                $tier2Map[$id] = $g['items'];
            }
        } elseif ($isNrm1) {
            $isTiered = true;
            $groupElements = [];
            foreach ($sheet as $idx => $row) {
                if ($idx === 0) continue;
                $groupName = trim($row['A'] ?? '');
                $elementName = trim($row['B'] ?? '');
                $code = trim($row['C'] ?? '');
                $name = trim($row['D'] ?? '');
                $scope = trim($row['E'] ?? '');

                if ($groupName === '' || $name === '') continue;

                if (!isset($groupElements[$groupName])) {
                    $groupElements[$groupName] = [];
                }

                $desc = "Element: $elementName.";
                if ($scope !== '') {
                    $desc .= " Scope: $scope";
                }

                $groupElements[$groupName][] = [
                    'id' => 't2_' . md5($groupName . $code . $name),
                    'code' => $code,
                    'name' => $code !== '' ? "$code $name" : $name,
                    'description' => $desc,
                    'package_name' => $name,
                    'group_name' => $groupName,
                    'element_name' => $elementName,
                    'scope' => "Context: Belongs to Group Element: '{$groupName}' -> Element: '{$elementName}'. Scope: {$scope}",
                ];
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
                $wdList[] = [
                    'id' => $id,
                    'code' => 'WD-' . str_pad((string)(count($wdList) + 1), 2, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'description' => $desc
                ];
            }
        }

        return [
            'is_tiered' => $isTiered,
            'packages' => $wdPackages,
            'list' => $wdList,
            'tier2_map' => $tier2Map,
            'profile' => $isNrm1 ? 'nrm1-v1' : ($is4ColumnNrm ? 'nrm2-v1' : 'wd-work-packages-v4')
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
        $records = [];
        $billItems = $boqData['Bill Items'] ?? [];
        $stateByBillSection = [];

        foreach ($billItems as $row) {
            if ($this->isBillItemsHeaderRow($row)) {
                continue;
            }

            $colA = trim($row['A'] ?? '');
            $description = (string)($row['E'] ?? '');
            if ($colA === '' || !ctype_digit($colA) || trim($description) === '') continue;

            $bill = (int)$colA;
            if ($bill < 1 || $bill > 71) continue;

            $section = $row['B'] ?? '';
            $contextKey = $bill . '|' . trim((string)$section);
            $state = $stateByBillSection[$contextKey] ?? ['prior' => '', 'system' => ''];
            $system = $this->identifySystem($description, $bill);
            if ($system !== '') $state['system'] = $system;

            $contextParts = ["Bill {$bill}: " . ($bills[$bill] ?? '')];
            if (trim((string)$section) !== '') $contextParts[] = 'Section: ' . trim((string)$section);
            if ($state['system'] !== '') $contextParts[] = 'System: ' . $state['system'];
            if ($state['prior'] !== '') $contextParts[] = 'Prior: ' . $state['prior'];

            $records[] = [
                'item_id' => (string)(count($records) + 1),
                'record_id' => 'Bill Items!' . (string)($row['_row'] ?? (count($records) + 5)),
                'excel_row' => (int)($row['_row'] ?? (count($records) + 5)),
                'bill' => $bill,
                'source_bill' => $row['A'] ?? '',
                'bill_name' => $bills[$bill] ?? '',
                'section' => $section,
                'page' => $row['C'] ?? '',
                'ref' => $row['D'] ?? '',
                'description' => $description,
                'context' => implode(' | ', $contextParts),
                'quantity' => $row['F'] ?? '',
                'unit' => $row['G'] ?? '',
                'rate' => $row['H'] ?? '',
                'extension' => $row['I'] ?? '',
                'activity' => $row['J'] ?? '',
            ];

            $state['prior'] = $description;
            $stateByBillSection[$contextKey] = $state;
            if (!isset($billContext[$bill])) $billContext[$bill] = [];
            if (count($billContext[$bill]) < $maxContextItemsPerBill && !in_array($description, $billContext[$bill], true)) {
                $billContext[$bill][] = $description;
            }
        }

        return [
            'bills' => $bills,
            'billContext' => $billContext,
            'records' => $records
        ];
    }

    public function cleanText(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace("\n", " ", $s)));
    }

    private function identifySystem(string $description, int $bill): string
    {
        if ($bill !== 16) return '';
        $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', $description)));
        if (preg_match('/lift|transport system|hoist/i', $text)) return 'Lifts and transport';
        if (preg_match('/electrical installations|electrical quotation|\belectrical\b|\bpv\b|harmonic|power/i', $text)) return 'Electrical installations';
        if (preg_match('/mechanical installations|mechanical quotation|\bmechanical\b|lossnay|ventilation|heating|cooling/i', $text)) return 'Mechanical installations';
        return '';
    }

    /**
     * Conquest exports repeat the complete Bill Items heading on each page.
     * Match the column labels as a row signature so a legitimate item whose
     * description happens to be "Description" is not discarded by itself.
     */
    private function isBillItemsHeaderRow(array $row): bool
    {
        $expected = [
            'A' => 'bill',
            'B' => 'section',
            'C' => 'page',
            'D' => 'ref',
            'E' => 'description',
            'F' => 'quantity',
            'G' => 'unit',
            'H' => 'rate',
            'I' => 'extension',
            'J' => 'activity',
        ];

        foreach ($expected as $column => $label) {
            if (mb_strtolower(trim((string)($row[$column] ?? ''))) !== $label) {
                return false;
            }
        }

        return true;
    }
}
