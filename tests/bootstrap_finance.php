<?php

declare(strict_types=1);

/**
 * Finance test bootstrap — registers a lightweight autoloader that loads
 * EduCore\ namespace from THIS worktree's src/ directory, plus classmap
 * for classes/ directory. This avoids depending on a broken vendor/ autoload.
 *
 * Usage: require_once __DIR__ . '/bootstrap_finance.php';
 */

$root = dirname(__DIR__);

spl_autoload_register(function (string $class) use ($root): void {
    // PSR-4: EduCore\ -> src/
    if (str_starts_with($class, 'EduCore\\')) {
        $relative = substr($class, strlen('EduCore\\'));
        $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }

    // Classmap: classes/ (lowercase class name → file)
    $bare = str_contains($class, '\\') ? substr($class, strrpos($class, '\\') + 1) : $class;
    $candidates = [
        $root . '/classes/' . $bare . '.php',
        $root . '/classes/' . $bare . '/' . $bare . '.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
