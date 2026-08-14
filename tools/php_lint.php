<?php

$root = dirname(__DIR__);
$excluded = ['vendor', 'archive', 'phpmyadmin'];
$failures = [];
$checked = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $topLevel = explode('/', $relative, 2)[0];
    if (in_array($topLevel, $excluded, true)) {
        continue;
    }

    $checked++;
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $failures[] = $relative . PHP_EOL . implode(PHP_EOL, $output);
    }
    $output = [];
}

printf("Checked %d PHP files; %d failure(s).%s", $checked, count($failures), PHP_EOL);
if ($failures) {
    fwrite(STDERR, implode(PHP_EOL . PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
