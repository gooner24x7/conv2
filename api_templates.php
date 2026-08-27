<?php
header('Content-Type: application/json');
$dir = __DIR__ . '/templates';
$files = [];
if (is_dir($dir)) {
    $scanned = scandir($dir);
    foreach ($scanned as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'csv' && strpos($file, '~$') !== 0) {
            $files[] = $file;
        }
    }
}
echo json_encode(['templates' => $files]);
