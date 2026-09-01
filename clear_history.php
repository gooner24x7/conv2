<?php
$file = __DIR__ . '/benchmark_history.json';
if (file_exists($file)) {
    file_put_contents($file, json_encode([], JSON_PRETTY_PRINT));
    $runFiles = glob(__DIR__ . '/runs/*.json');
    foreach ($runFiles as $rf) {
        @unlink($rf);
    }
    echo json_encode(["status" => "success", "message" => "League Table cleared"]);
} else {
    echo json_encode(["status" => "error", "message" => "File not found"]);
}
