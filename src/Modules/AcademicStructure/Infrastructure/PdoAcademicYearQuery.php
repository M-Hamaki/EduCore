<?php

declare(strict_types=1);

namespace EduCore\Modules\AcademicStructure\Infrastructure;

use EduCore\Modules\AcademicStructure\Contracts\AcademicYearQuery;
use EduCore\Modules\AcademicStructure\Contracts\StageGradeClassQuery;
use PDO;

/**
 * PDO implementations of AcademicYearQuery + StageGradeClassQuery — owned by AcademicStructure.
 */
final class PdoAcademicYearQuery implements AcademicYearQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function currentId(): int
    {
        $stmt = $this->db->query('SELECT id FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
        return (int) $stmt->fetchColumn();
    }

    public function idByName(string $name): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM academic_years WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function yearOf(int $yearId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, is_active, locked, status FROM academic_years WHERE id = ? LIMIT 1');
        $stmt->execute([$yearId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function isLocked(int $yearId): bool
    {
        $stmt = $this->db->prepare('SELECT locked FROM academic_years WHERE id = ? LIMIT 1');
        $stmt->execute([$yearId]);
        return (int) $stmt->fetchColumn() === 1;
    }
}

final class PdoStageGradeClassQuery implements StageGradeClassQuery
{
    public function __construct(private PDO $db)
    {
    }

    public function gradeOf(int $gradeId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, grade_name, stage_id FROM grades WHERE id = ? LIMIT 1');
        $stmt->execute([$gradeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function classOf(int $classId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, grade_id FROM classes WHERE id = ? LIMIT 1');
        $stmt->execute([$classId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function stageOf(int $stageId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, stage_name FROM stages WHERE id = ? LIMIT 1');
        $stmt->execute([$stageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
