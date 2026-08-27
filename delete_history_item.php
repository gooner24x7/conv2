<?php
header('Content-Type: application/json');

$file = __DIR__ . '/benchmark_history.json';
if (!file_exists($file)) {
    echo json_encode(["status" => "error", "message" => "History file not found"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$targetTimestamp = $input['timestamp'] ?? null;
$targetModel = $input['model'] ?? null;
$targetTemplate = $input['template'] ?? null;

if (!$targetTimestamp && !$targetModel) {
    echo json_encode(["status" => "error", "message" => "Missing entry identifier"]);
    exit;
}

$history = json_decode(file_get_contents($file), true);
if (!is_array($history)) {
    $history = [];
}

$filtered = [];
$deleted = false;

foreach ($history as $row) {
    $match = true;
    if ($targetTimestamp && isset($row['timestamp']) && $row['timestamp'] !== $targetTimestamp) {
        $match = false;
    }
    if ($targetModel && isset($row['model']) && $row['model'] !== $targetModel) {
        $match = false;
    }
    if ($targetTemplate && isset($row['template']) && $row['template'] !== $targetTemplate) {
        $match = false;
    }

    if ($match && !$deleted) {
        $deleted = true; // Delete only the specific matching item
        continue;
    }
    $filtered[] = $row;
}

if ($deleted) {
    file_put_contents($file, json_encode(array_values($filtered), JSON_PRETTY_PRINT));
    echo json_encode(["status" => "success", "message" => "Entry deleted successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Entry not found in history"]);
}
