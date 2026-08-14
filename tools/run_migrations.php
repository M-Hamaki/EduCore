<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$applied = $db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
sort($files, SORT_STRING);

$only = '';
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with((string) $argument, '--only=')) {
        $only = basename(substr((string) $argument, 7));
    }
}
if ($only !== '') {
    if (!preg_match('/^\d{8}_[A-Za-z0-9_]+\.php$/', $only)) {
        fwrite(STDERR, "Invalid migration name for --only.\n");
        exit(2);
    }
    $files = array_values(array_filter($files, static fn (string $file): bool => basename($file) === $only));
    if (!$files) {
        fwrite(STDERR, "Migration not found: {$only}\n");
        exit(2);
    }
}

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        echo "skip $name\n";
        continue;
    }

    // Isolate legacy migration variables from the runner. Some old files use
    // generic names such as $name and would otherwise overwrite $name here,
    // causing the wrong value to be recorded in schema_migrations.
    $migration = (static function (string $migrationFile) {
        return require $migrationFile;
    })($file);

    // Legacy migrations (pre-2026) execute their logic directly at include time
    // and do NOT return a callable. If so, the work is already done by the require
    // above — treat it as applied as long as the include succeeded.
    if (!is_callable($migration)) {
        if ($migration !== 1) {
            fwrite(STDERR, "WARNING: $name returned non-callable, non-1 value; skipping registration.\n");
            continue;
        }
    } else {
        try {
            $migration($db);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    try {
        $stmt = $db->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
        $stmt->execute([$name]);
        echo "applied $name\n";
    } catch (Throwable $e) {
        // If already recorded (race/duplicate), just skip.
        echo "already recorded $name\n";
    }
}
