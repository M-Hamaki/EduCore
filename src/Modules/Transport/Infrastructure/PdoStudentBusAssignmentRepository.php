<?php

declare(strict_types=1);

namespace EduCore\Modules\Transport\Infrastructure;

use EduCore\Modules\Transport\Contracts\StudentBusAssignmentRepository;
use PDO;

final class PdoStudentBusAssignmentRepository implements StudentBusAssignmentRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function lock(int $studentId, int $academicYearId): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Bus assignment locks require an active transaction.');
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM student_bus_assignments
             WHERE student_id = ? AND academic_year_id = ? FOR UPDATE'
        );
        $stmt->execute([$studentId, $academicYearId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function replace(
        int $studentId,
        int $academicYearId,
        ?int $busId,
        ?int $backupBusId,
        ?string $notes,
        int $actorId
    ): ?array {
        if ($busId === null && $backupBusId === null) {
            $this->db->prepare(
                "UPDATE student_bus_assignments
                 SET status = 'archived', archived_at = NOW(), archived_by = ?
                 WHERE student_id = ? AND academic_year_id = ? AND status = 'active'"
            )->execute([$actorId, $studentId, $academicYearId]);
            return null;
        }
        $this->db->prepare(
            "INSERT INTO student_bus_assignments
                (student_id, bus_id, backup_bus_id, notes, academic_year_id, status, archived_at, archived_by)
             VALUES (?, ?, ?, ?, ?, 'active', NULL, NULL)
             ON DUPLICATE KEY UPDATE
                bus_id = VALUES(bus_id),
                backup_bus_id = VALUES(backup_bus_id),
                notes = VALUES(notes),
                status = 'active',
                archived_at = NULL,
                archived_by = NULL"
        )->execute([$studentId, $busId, $backupBusId, $notes, $academicYearId]);
        $stmt = $this->db->prepare(
            "SELECT * FROM student_bus_assignments
             WHERE student_id = ? AND academic_year_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$studentId, $academicYearId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function activeBusExists(int $busId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM buses WHERE id = ? AND status = 'active'"
        );
        $stmt->execute([$busId]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
