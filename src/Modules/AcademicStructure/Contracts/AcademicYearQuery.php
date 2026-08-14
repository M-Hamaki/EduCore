<?php

declare(strict_types=1);

namespace EduCore\Modules\AcademicStructure\Contracts;

/**
 * Academic year + stage/grade/class query contracts — owned by AcademicStructure.
 * Consumed read-only by Finance for: period scoping, write-guard checks, plan scoping.
 */
interface AcademicYearQuery
{
    public function currentId(): int;
    public function idByName(string $name): ?int;
    public function yearOf(int $yearId): ?array;
    public function isLocked(int $yearId): bool;
}

interface StageGradeClassQuery
{
    public function gradeOf(int $gradeId): ?array;
    public function classOf(int $classId): ?array;
    public function stageOf(int $stageId): ?array;
}
