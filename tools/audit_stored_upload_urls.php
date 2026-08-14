<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$targets = [
    'materials' => ['file_name', 'original_file_name'],
    'classes' => ['timetable_image'],
    'settings' => ['setting_value'],
    'lesson_ppt_templates' => ['file_path', 'thumbnail_path'],
    'student_attachments' => ['file_name'],
    'staff_attachments' => ['file_name'],
];
$patterns = ['%localhost%', '%127.0.0.1%', '%file://%', '%:\\uploads\\%'];
$findings = [];

foreach ($targets as $table => $columns) {
    $tableCheck = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $tableCheck->execute([$table]);
    if ((int)$tableCheck->fetchColumn() === 0) {
        continue;
    }

    foreach ($columns as $column) {
        $columnCheck = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $columnCheck->execute([$table, $column]);
        if ((int)$columnCheck->fetchColumn() === 0) {
            continue;
        }

        $conditions = implode(' OR ', array_fill(0, count($patterns), "`{$column}` LIKE ?"));
        $statement = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$conditions}");
        $statement->execute($patterns);
        $count = (int)$statement->fetchColumn();
        if ($count > 0) {
            $findings[] = ['table' => $table, 'column' => $column, 'count' => $count];
        }
    }
}

if ($findings) {
    foreach ($findings as $finding) {
        echo $finding['table'] . '.' . $finding['column'] . ': ' . $finding['count'] . PHP_EOL;
    }
    fwrite(STDERR, "Stored environment-specific upload paths require a reviewed data migration.\n");
    exit(2);
}

echo "PASS: no localhost URLs or local upload filesystem paths found in known upload columns.\n";
