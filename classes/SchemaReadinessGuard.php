<?php

declare(strict_types=1);

final class SchemaReadinessGuard
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function assertTable(string $table): void
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Database schema is not ready. Run pending migrations.');
        }
    }

    public function assertColumns(string $table, array $columns): void
    {
        $this->assertTable($table);
        $stmt = $this->db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $column) {
            if (!in_array($column, $existing, true)) {
                throw new RuntimeException('Database schema is not ready. Run pending migrations.');
            }
        }
    }
}
