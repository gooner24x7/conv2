<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    throw new RuntimeException('Could not resolve the package root.');
}

$scanTargets = [
    $root . '/src',
    $root . '/bin',
    $root . '/config',
    $root . '/composer.json',
];
$files = [];

foreach ($scanTargets as $target) {
    if (is_file($target)) {
        $files[] = $target;
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getPathname();
        }
    }
}

$externalDirectoryName = 'AITOOL' . 'V3';
$externalPathPattern = '~(?:^|[\'"\\s=(])(?:\.\.[\\\\/]|[A-Za-z]:[\\\\/][^\r\n\'";]*[\\\\/])'
    . preg_quote($externalDirectoryName, '~') . '(?:[\\\\/]|$)~i';
$violations = [];

foreach ($files as $file) {
    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException("Could not read {$file}.");
    }

    if (preg_match($externalPathPattern, $contents) === 1) {
        $violations[] = str_replace('\\', '/', substr($file, strlen($root) + 1))
            . ': references the external compatibility-source directory';
    }

    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
        continue;
    }

    preg_match_all(
        '~\b(?:require|require_once|include|include_once)\s*(?:\(\s*)?__DIR__\s*\.\s*([\'\"])([^\'\"]+)\1~i',
        $contents,
        $matches,
        PREG_SET_ORDER
    );
    foreach ($matches as $match) {
        $dependency = realpath(dirname($file) . '/' . $match[2]);
        if ($dependency === false) {
            $violations[] = str_replace('\\', '/', substr($file, strlen($root) + 1))
                . ": missing dependency {$match[2]}";
            continue;
        }

        $relativeCheck = strtolower(str_replace('\\', '/', $dependency));
        $rootCheck = strtolower(str_replace('\\', '/', $root)) . '/';
        if (!str_starts_with($relativeCheck, $rootCheck)) {
            $violations[] = str_replace('\\', '/', substr($file, strlen($root) + 1))
                . ": dependency escapes package root ({$match[2]})";
        }
    }
}

if ($violations !== []) {
    throw new RuntimeException("Package is not self-contained:\n- " . implode("\n- ", $violations));
}

echo 'Deployment self-containment check passed for ' . count($files) . " package files.\n";
