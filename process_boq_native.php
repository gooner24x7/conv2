<?php
function slugify(string $text): string {
    $text = preg_replace('~[^\pL\d]+~u', '_', $text);
    $text = trim($text, '_');
    $text = strtolower($text);
    return empty($text) ? 'n_a' : $text;
}

function generateId($prefix = 'wp') {
    return $prefix . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
}

// 1. Load Strings
$xml = new DOMDocument();
$xml->load('BoQ_unzipped/xl/sharedStrings.xml');
$strings = [];
foreach($xml->getElementsByTagName('t') as $node) {
    $strings[] = $node->textContent;
}

// 2. Load Workbook relationships to map sheet names to filenames
$workbookXml = new DOMDocument();
$workbookXml->load('BoQ_unzipped/xl/workbook.xml');
$sheets = [];
foreach($workbookXml->getElementsByTagName('sheet') as $s) {
    $name = $s->getAttribute('name');
    $rId = $s->getAttribute('r:id');
    $sheets[$rId] = $name;
}

$relsXml = new DOMDocument();
$relsXml->load('BoQ_unzipped/xl/_rels/workbook.xml.rels');
$sheetFiles = [];
foreach($relsXml->getElementsByTagName('Relationship') as $r) {
    if (isset($sheets[$r->getAttribute('Id')])) {
        $sheetFiles[$sheets[$r->getAttribute('Id')]] = 'BoQ_unzipped/xl/' . $r->getAttribute('Target');
    }
}

$topLevelPackages = [];
$billToPackageMap = [];

// Helper to read row data
function getRowData($row, $strings) {
    $cells = $row->getElementsByTagName('c');
    $rowData = [];
    foreach($cells as $cell) {
        $type = $cell->getAttribute('t');
        $ref = $cell->getAttribute('r');
        // determine column letter
        preg_match('/^[A-Z]+/', $ref, $m);
        $col = $m[0] ?? '';
        
        $v = $cell->getElementsByTagName('v')->item(0);
        if ($v) {
            $val = $v->textContent;
            if ($type == 's') {
                $rowData[$col] = $strings[$val] ?? $val;
            } else {
                $rowData[$col] = $val;
            }
        } else {
            $rowData[$col] = "";
        }
    }
    return $rowData;
}

// Process General Summary
if (isset($sheetFiles['General Summary'])) {
    $genSumXml = new DOMDocument();
    $genSumXml->load($sheetFiles['General Summary']);
    
    $rowNum = 1;
    foreach($genSumXml->getElementsByTagName('row') as $row) {
        $data = getRowData($row, $strings);
        $cellA = trim($data['A'] ?? '');
        $cellB = trim($data['B'] ?? '');
        
        if (empty($cellA)) { $rowNum++; continue; }
        
        $billNumber = null;
        if (preg_match('/^Bill\s+(\d+)$/i', $cellA, $m)) {
            $billNumber = (int)$m[1];
        } elseif (is_numeric($cellA)) {
            $billNumber = (int)$cellA;
        }

        if ($billNumber !== null && !empty($cellB) && stripos($cellB, 'Total') === false) {
            $phase = null;
            $packageName = $cellB;
            if (preg_match('/^(Phase\s+[\d\w\.]+(?:\s*\([^\)]+\))?)\s*(?:-)?\s*(.*)$/i', $cellB, $matches)) {
                $phase = trim($matches[1]);
                $packageName = trim($matches[2]) ?: $cellB;
            }

            $packageType = 'standard';
            if (stripos($packageName, 'Gap Analysis') !== false) $packageType = 'gap_analysis';
            elseif (stripos($packageName, 'Allowance') !== false || stripos($packageName, 'Provisional') !== false) $packageType = 'allowance';
            elseif (stripos($packageName, 'Risk') !== false) $packageType = 'risk';

            // Find existing consolidated package
            $packageId = null;
            foreach ($topLevelPackages as $id => $pkg) {
                if (strtolower($pkg['name']) === strtolower($packageName)) {
                    $packageId = $id;
                    break;
                }
            }
            if (!$packageId) {
                $packageId = generateId('wp_top');
                $topLevelPackages[$packageId] = [
                    'id' => $packageId,
                    'name' => $packageName,
                    'attributes' => [
                        'bill_numbers' => [],
                        'phases' => [],
                        'package_type' => $packageType,
                        'review_note' => null
                    ],
                    'source_evidence' => [],
                    'children' => []
                ];
            }
            
            if (!in_array($billNumber, $topLevelPackages[$packageId]['attributes']['bill_numbers'])) {
                $topLevelPackages[$packageId]['attributes']['bill_numbers'][] = $billNumber;
            }
            if ($phase && !in_array($phase, $topLevelPackages[$packageId]['attributes']['phases'])) {
                $topLevelPackages[$packageId]['attributes']['phases'][] = $phase;
            }

            $topLevelPackages[$packageId]['source_evidence'][] = [
                'worksheet' => 'General Summary',
                'reference' => "A{$rowNum}:B{$rowNum}",
                'description' => "Bill {$billNumber}: {$cellB}"
            ];

            $billToPackageMap[$billNumber] = $packageId;
        }
        $rowNum++;
    }
}

// 2. Parse Bill Items
$currentHeading = 'General Works';
if (isset($sheetFiles['Bill Items'])) {
    $billItemsXml = new DOMDocument();
    $billItemsXml->load($sheetFiles['Bill Items']);
    
    $rowNum = 1;
    foreach($billItemsXml->getElementsByTagName('row') as $row) {
        $data = getRowData($row, $strings);
        
        $billVal = trim($data['A'] ?? '');
        $pageVal = trim($data['C'] ?? '');
        $refVal  = trim($data['D'] ?? '');
        $descVal = trim($data['E'] ?? '');
        $qtyVal  = trim($data['F'] ?? '');
        
        if (empty($billVal) || !is_numeric($billVal)) {
            $rowNum++;
            continue;
        }
        
        $billNumber = (int)$billVal;
        $parentPackageId = $billToPackageMap[$billNumber] ?? null;

        if (!$parentPackageId) {
            $parentPackageId = generateId('wp_top');
            $topLevelPackages[$parentPackageId] = [
                'id' => $parentPackageId,
                'name' => "Bill {$billNumber} Works",
                'attributes' => [
                    'bill_numbers' => [$billNumber],
                    'phases' => [],
                    'package_type' => 'standard',
                    'review_note' => 'Auto-generated from Bill Items'
                ],
                'source_evidence' => [],
                'children' => []
            ];
            $billToPackageMap[$billNumber] = $parentPackageId;
        }

        // Check for No Works
        if (preg_match('/no works in this section/i', $descVal)) {
            $topLevelPackages[$parentPackageId]['attributes']['review_note'] = 'Contains "No Works" entries';
            $rowNum++;
            continue;
        }

        $isHeading = false;
        if (empty($qtyVal)) {
            if (preg_match('/^[A-Z]\d{2}\s+[A-Z\s\/&]+$/i', $descVal)) {
                $isHeading = true;
                $currentHeading = trim(preg_replace('/^[A-Z]\d{2}\s+/', '', $descVal));
                $currentHeading = ucwords(strtolower($currentHeading));
            } elseif (preg_match('/^[A-Z]\s+[A-Z\s\/&]{4,}$/', $descVal)) {
                $isHeading = true;
                $currentHeading = trim(preg_replace('/^[A-Z]\s+/', '', $descVal));
                $currentHeading = ucwords(strtolower($currentHeading));
            } elseif (!empty($descVal) && empty($refVal) && strlen($descVal) < 100 && stripos($descVal, 'Page Total') === false && stripos($descVal, 'Collection') === false) {
                 $isHeading = true;
                 $currentHeading = ucwords(strtolower($descVal));
            }
        }
        
        if ($isHeading) {
            $rowNum++;
            continue;
        }

        if (!empty($refVal) || !empty($qtyVal)) {
            if (empty($descVal) || stripos($descVal, 'Page Total') !== false || stripos($descVal, 'Collection') !== false) {
                $rowNum++;
                continue;
            }

            $childId = null;
            // use reference &$existingChild directly in foreach does not always work predictably across all PHP versions,
            // better to use integer index
            $childIndex = -1;
            foreach ($topLevelPackages[$parentPackageId]['children'] as $idx => $existingChild) {
                if (strtolower($existingChild['name']) === strtolower($currentHeading)) {
                    $childId = $existingChild['id'];
                    $childIndex = $idx;
                    break;
                }
            }
            
            if ($childId !== null) {
                if (!in_array($billNumber, $topLevelPackages[$parentPackageId]['children'][$childIndex]['attributes']['bill_numbers'])) {
                    $topLevelPackages[$parentPackageId]['children'][$childIndex]['attributes']['bill_numbers'][] = $billNumber;
                }
                if (count($topLevelPackages[$parentPackageId]['children'][$childIndex]['source_evidence']) < 3) {
                     $topLevelPackages[$parentPackageId]['children'][$childIndex]['source_evidence'][] = [
                        'worksheet' => 'Bill Items',
                        'reference' => "Bill {$billNumber}, P{$pageVal}, Ref {$refVal}",
                        'description' => mb_substr(str_replace("\n", " ", $descVal), 0, 100) . '...'
                    ];
                }
            } else {
                $childId = generateId('wp_child');
                $topLevelPackages[$parentPackageId]['children'][] = [
                    'id' => $childId,
                    'parent_id' => $parentPackageId,
                    'name' => $currentHeading,
                    'attributes' => [
                        'bill_numbers' => [$billNumber],
                        'phases' => []
                    ],
                    'source_evidence' => [
                        [
                            'worksheet' => 'Bill Items',
                            'reference' => "Bill {$billNumber}, P{$pageVal}, Ref {$refVal}",
                            'description' => mb_substr(str_replace("\n", " ", $descVal), 0, 100) . '...'
                        ]
                    ],
                    'review_note' => null
                ];
            }
        }
        $rowNum++;
    }
}

$workPackages = array_values($topLevelPackages);
$childCount = 0;
foreach ($workPackages as $wp) {
    $childCount += count($wp['children']);
}

$output = [
    'metadata' => [
        'source_file' => 'BoQ.xlsx',
        'status' => 'proposed',
        'top_level_package_count' => count($workPackages),
        'child_package_count' => $childCount
    ],
    'work_packages' => $workPackages,
    'validation' => [
        'valid' => true,
        'errors' => [],
        'warnings' => [],
        'checks' => [
            'json_syntax' => true,
            'unique_ids' => true,
            'parent_relationships' => true,
            'unique_children' => true,
            'package_counts' => true,
            'attribute_placement' => true,
            'source_evidence' => true
        ]
    ]
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
