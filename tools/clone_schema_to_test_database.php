<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/env_loader.php';

$environment = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: '')));
if (in_array($environment, ['production', 'prod'], true)) {
    throw new RuntimeException('Refusing test schema clone in a production environment.');
}

$source = trim((string) env('DB_NAME', 'educore'));
$target = trim((string) (getenv('EDUCORE_TEST_DB_NAME') ?: ''));
if ($target === '' || !preg_match('/^[A-Za-z0-9_]+_test$/', $target) || $target === $source) {
    throw new RuntimeException('Target must be an isolated database ending in _test and differ from the source.');
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $source)) {
    throw new RuntimeException('Source database name is invalid.');
}

$pdo = new PDO(
    'mysql:host=' . env('DB_HOST', 'localhost') . ';charset=utf8mb4',
    env('DB_USER', 'root'),
    env('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$quote = static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';
$sourceSql = $quote($source);
$targetSql = $quote($target);

$pdo->exec("CREATE DATABASE IF NOT EXISTS {$targetSql} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

$objects = $pdo->prepare(
    'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_TYPE, TABLE_NAME'
);
$objects->execute([$source]);
$rows = $objects->fetchAll(PDO::FETCH_ASSOC);

$targetObjects = $pdo->prepare(
    'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_TYPE, TABLE_NAME'
);
$targetObjects->execute([$target]);
$targetRows = $targetObjects->fetchAll(PDO::FETCH_ASSOC);
foreach (array_reverse($targetRows) as $row) {
    $pdo->exec('DROP ' . (($row['TABLE_TYPE'] ?? '') === 'VIEW' ? 'VIEW' : 'TABLE')
        . ' IF EXISTS ' . $targetSql . '.' . $quote((string) $row['TABLE_NAME']));
}

foreach ($rows as $row) {
    if (($row['TABLE_TYPE'] ?? '') !== 'BASE TABLE') {
        continue;
    }
    $table = (string) $row['TABLE_NAME'];
    $create = $pdo->query('SHOW CREATE TABLE ' . $sourceSql . '.' . $quote($table))->fetch(PDO::FETCH_ASSOC);
    $sql = (string) ($create['Create Table'] ?? '');
    if ($sql === '') {
        throw new RuntimeException('Unable to read table definition for ' . $table);
    }
    $pdo->exec('USE ' . $targetSql);
    $pdo->exec($sql);
}

$pendingViews = array_values(array_filter($rows, static fn(array $row): bool => ($row['TABLE_TYPE'] ?? '') === 'VIEW'));
while ($pendingViews !== []) {
    $remaining = [];
    $createdThisPass = 0;
    foreach ($pendingViews as $row) {
        $view = (string) $row['TABLE_NAME'];
        $create = $pdo->query('SHOW CREATE VIEW ' . $sourceSql . '.' . $quote($view))->fetch(PDO::FETCH_ASSOC);
        $sql = (string) ($create['Create View'] ?? '');
        if ($sql === '') {
            continue;
        }
        try {
            $pdo->exec('USE ' . $targetSql);
            $pdo->exec(str_replace($sourceSql . '.', $targetSql . '.', $sql));
            ++$createdThisPass;
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() !== '42S02') {
                throw $exception;
            }
            $remaining[] = $row;
        }
    }
    if ($remaining !== [] && $createdThisPass === 0) {
        throw new RuntimeException('Unable to resolve dependent test views: ' . implode(', ', array_column($remaining, 'TABLE_NAME')));
    }
    $pendingViews = $remaining;
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo 'TEST_SCHEMA_CLONED tables=' . count(array_filter($rows, static fn(array $row): bool => $row['TABLE_TYPE'] === 'BASE TABLE')) . PHP_EOL;
