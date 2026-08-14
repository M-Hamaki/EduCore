<?php

final class HrSchemaGuard
{
    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function assertTable(string $table): void
    {
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) $this->notReady();
    }

    public function assertColumn(string $table, string $column): void
    {
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        if (!$stmt->fetchColumn()) $this->notReady();
    }

    public function assertIndex(string $table, string $index): void
    {
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        if (!$stmt->fetchColumn()) $this->notReady();
    }

    private function notReady(): void
    {
        throw new RuntimeException('HR schema is not ready; run the database migrations.');
    }
}
