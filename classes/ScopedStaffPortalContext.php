<?php

declare(strict_types=1);

require_once __DIR__ . '/StaffAcademicScopeService.php';
require_once __DIR__ . '/StaffRoleCapabilityResolver.php';

/**
 * Request-scoped authorization context for admin pages shared with staff portals.
 * A null class list means unrestricted admin access; an empty list means that a
 * scoped staff member currently has no academic access.
 */
final class ScopedStaffPortalContext
{
    private StaffAcademicScopeService $scopeService;
    private string $role;
    private string $assignedRole;
    private int $userId;
    private int $academicYearId;
    /** @var array<int,int>|null */
    private ?array $allowedClassIds = null;

    public function __construct(PDO $db, int $academicYearId, ?array $session = null)
    {
        $session = $session ?? $_SESSION;
        $this->userId = max(0, (int)($session['user_id'] ?? 0));
        $this->academicYearId = max(0, $academicYearId);
        $this->assignedRole = trim((string)($session['active_role'] ?? $session['role'] ?? ''));
        $this->role = $this->assignedRole;
        $this->scopeService = new StaffAcademicScopeService($db);

        if ($this->userId > 0 && $this->assignedRole === '') {
            $stmt = $db->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$this->userId]);
            $databaseRole = $stmt->fetchColumn();
            if ($databaseRole !== false) {
                $this->assignedRole = trim((string)$databaseRole);
            }
        }

        $this->role = (new StaffRoleCapabilityResolver($db))->family($this->assignedRole);

        if (StaffAcademicScopeService::roleRequiresScope($this->role)) {
            if ($this->userId <= 0 || $this->academicYearId <= 0) {
                $this->allowedClassIds = [];
                return;
            }
            $this->allowedClassIds = $this->scopeService->allowedClassIdsForStaff(
                $this->userId,
                $this->academicYearId,
                $this->assignedRole
            );
        }
    }

    public function isScoped(): bool
    {
        return $this->allowedClassIds !== null;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function assignedRole(): string
    {
        return $this->assignedRole;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function academicYearId(): int
    {
        return $this->academicYearId;
    }

    /** @return array<int,int>|null */
    public function allowedClassIds(): ?array
    {
        return $this->allowedClassIds;
    }

    public function allowsClass(int $classId): bool
    {
        return !$this->isScoped()
            || ($classId > 0 && in_array($classId, $this->allowedClassIds ?? [], true));
    }

    public function assertClassAllowed(int $classId): void
    {
        if ($this->isScoped()) {
            $this->scopeService->assertClassAllowed(
                $this->userId,
                $this->academicYearId,
                $classId,
                $this->assignedRole
            );
        }
    }

    public function assertStudentAllowed(int $studentId): void
    {
        if ($this->isScoped()) {
            $this->scopeService->assertStudentAllowed(
                $this->userId,
                $this->academicYearId,
                $studentId,
                $this->assignedRole
            );
        }
    }

    /** @param array<int,mixed> $classIds @return array<int,int> */
    public function filterClassIds(array $classIds): array
    {
        $classIds = array_values(array_unique(array_filter(
            array_map('intval', $classIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$this->isScoped()) {
            return $classIds;
        }

        return array_values(array_intersect($classIds, $this->allowedClassIds ?? []));
    }
}
