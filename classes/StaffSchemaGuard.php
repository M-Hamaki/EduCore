<?php

final class StaffSchemaGuard
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function assertReady(): void
    {
        foreach (['staff_profiles', 'staff_status_history', 'staff_job_movements'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Staff schema is not ready; run the database migrations.');
            }
        }
        foreach (['military_status', 'public_service_status', 'promotions', 'status_history'] as $column) {
            if (!$this->columnExists('staff_profiles', $column)) {
                throw new RuntimeException('Staff schema is not ready; run the database migrations.');
            }
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    }
}
