<?php

final class StaffAccountSchemaGuard
{
    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function assertReady(): void
    {
        foreach (['staff_roles', 'staff_role_pages', 'user_role_assignments'] as $table) {
            $stmt = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Staff account schema is not ready; run the database migrations.');
            }
        }
        $stmt = $this->db->prepare("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'");
        $stmt->execute();
        if (strtolower((string) $stmt->fetchColumn()) === 'enum') {
            throw new RuntimeException('Staff account schema is not ready; run the database migrations.');
        }
        $stmt = $this->db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_roles' AND COLUMN_NAME = 'base_role_key'");
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Staff role inheritance schema is not ready; run the database migrations.');
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('staff_grade_assignments', 'staff_class_assignments')
               AND COLUMN_NAME = 'role_key'"
        );
        $stmt->execute();
        if ((int)$stmt->fetchColumn() !== 2) {
            throw new RuntimeException('Role-aware staff scope schema is not ready; run the database migrations.');
        }
    }
}
