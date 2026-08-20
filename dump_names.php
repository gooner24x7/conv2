<?php
function parseXlsx(string $xlsxPath): array {
    $zip = new ZipArchive();
    $zip->open($xlsxPath);
    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $doc = new DOMDocument(); @$doc->loadXML($ssXml);
        foreach ($doc->getElementsByTagName('si') as $si) {
            $text = ''; foreach ($si->getElementsByTagName('t') as $t) $text .= $t->textContent;
            $strings[] = $text;
        }
    }
    $doc = new DOMDocument(); @$doc->loadXML($zip->getFromName('xl/workbook.xml'));
    $sheetsByRid = [];
    foreach ($doc->getElementsByTagName('sheet') as $s) $sheetsByRid[$s->getAttribute('r:id')] = $s->getAttribute('name');
    $doc2 = new DOMDocument(); @$doc2->loadXML($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $sheetFiles = [];
    foreach ($doc2->getElementsByTagName('Relationship') as $r) {
        $rid = $r->getAttribute('Id');
        if (isset($sheetsByRid[$rid])) $sheetFiles[$sheetsByRid[$rid]] = 'xl/' . $r->getAttribute('Target');
    }
    $allSheets = [];
    foreach ($sheetFiles as $name => $path) {
        $xml = $zip->getFromName($path);
        if (!$xml) continue;
        $doc = new DOMDocument(); @$doc->loadXML($xml);
        $rows = [];
        foreach ($doc->getElementsByTagName('row') as $row) {
            $rd = [];
            foreach ($row->getElementsByTagName('c') as $cell) {
                preg_match('/^([A-Z]+)/', $cell->getAttribute('r'), $m);
                $col = $m[1] ?? '';
                $v = $cell->getElementsByTagName('v')->item(0);
                if ($v) $rd[$col] = ($cell->getAttribute('t') == 's') ? ($strings[(int)$v->textContent] ?? '') : $v->textContent;
            }
            $rows[] = $rd;
        }
        $allSheets[$name] = $rows;
    }
    return $allSheets;
}
function cleanText(string $s): string { return trim(preg_replace('/\s+/', ' ', str_replace("\n", " ", $s))); }

$wdData = parseXlsx('WD template.xlsx');
$wdSheet = $wdData['Sheet1'] ?? reset($wdData);
$wdList = [];
foreach ($wdSheet as $row) {
    $name = trim($row['A'] ?? '');
    if ($name === '' || stripos($name, 'Works Package') !== false) continue;
    $wdList[] = $name;
}

$boqData = parseXlsx('BoQ.xlsx');
$genSummary = $boqData['General Summary'] ?? [];
$bills = [];
foreach ($genSummary as $row) {
    $colA = trim($row['A'] ?? '');
    $colB = trim($row['B'] ?? '');
    if (preg_match('/^Bill\s+(\d+)$/i', $colA, $m) && $colB !== '') {
        $bills[(int)$m[1]] = cleanText($colB);
    }
}

$matched = 0;
foreach ($bills as $bn => $bName) {
    $cleanBill = strtolower(preg_replace('/[^a-z0-9]/i', '', $bName));
    foreach ($wdList as $wdName) {
        if ($cleanBill === strtolower(preg_replace('/[^a-z0-9]/i', '', $wdName))) {
            $matched++;
            break;
        }
    }
}
echo "\nMatched: $matched / " . count($bills) . "\n";
