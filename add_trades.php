<?php
$jsonStr = file_get_contents('output_wd.json');
$data = json_decode($jsonStr, true);

$tradeRules = [
    'Demolition' => 'Demolition Contractor',
    'Earthwork' => 'Groundworker',
    'Groundwork' => 'Groundworker',
    'Excavation' => 'Groundworker',
    'Drainage' => 'Groundworker',
    'Kerb' => 'Groundworker',
    'Paving' => 'Groundworker',
    'Substructure' => 'Groundworker',
    'Frame' => 'Steel Erector / Concrete Frame Contractor',
    'Steel' => 'Steel Erector',
    'Roof' => 'Roofer',
    'Stair' => 'Metalworker / Concrete Contractor',
    'Brick' => 'Bricklayer',
    'Block' => 'Bricklayer',
    'Wall' => 'Dryliner / Plasterer',
    'Partition' => 'Dryliner',
    'Ceiling' => 'Ceiling Fixer',
    'Door' => 'Carpenter',
    'Window' => 'Glazier / Facade Contractor',
    'Finish' => 'Painter / Decorator',
    'Paint' => 'Painter / Decorator',
    'Floor' => 'Floor Layer',
    'Sanitary' => 'Plumber',
    'Mechanical' => 'Mechanical Contractor',
    'Electrical' => 'Electrician',
    'M&E' => 'M&E Contractor',
    'Lift' => 'Lift Engineer',
    'Balustrade' => 'Metalworker',
    'Cladding' => 'Facade Contractor',
    'Joinery' => 'Carpenter',
    'Scaffold' => 'Scaffolder',
    'Hoist' => 'Scaffolder',
    'Road' => 'Highways Contractor',
    'Signage' => 'Signage Contractor',
    'Furniture' => 'FF&E Contractor'
];

function guessTrade($name, $evidence, $wdName, $tradeRules) {
    $searchString = strtolower($name . ' ' . implode(' ', $evidence) . ' ' . $wdName);
    
    foreach ($tradeRules as $keyword => $trade) {
        if (strpos($searchString, strtolower($keyword)) !== false) {
            return $trade;
        }
    }
    return "General Contractor";
}

foreach ($data['work_packages'] as &$wp) {
    if (!isset($wp['children'])) continue;
    foreach ($wp['children'] as &$bill) {
        $name = $bill['name'] ?? '';
        $evidence = $bill['source_evidence'] ?? [];
        
        $trade = guessTrade($name, $evidence, $wp['name'], $tradeRules);
        
        if (!isset($bill['attributes'])) $bill['attributes'] = [];
        $bill['attributes']['suggested_trade'] = $trade;
    }
}

file_put_contents('output_wd.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Trades added to output_wd.json\n";
