<?php

declare(strict_types=1);

namespace EduCore\Modules\Search\Application;

/**
 * Converts the active admin-page grant into the result groups the header may expose.
 */
final class GlobalSearchAccessPolicy
{
    private const GROUPS = ['students', 'staff', 'classes', 'subjects', 'buses'];

    /**
     * @param array<int,string>|null $allowedPages
     */
    public function canUse(string $assignedRole, ?array $allowedPages): bool
    {
        $assignedRole = trim($assignedRole);
        if (in_array($assignedRole, ['admin', 'super_admin'], true)) {
            return true;
        }

        return is_array($allowedPages) && $allowedPages !== [];
    }

    /**
     * @param array<int,string>|null $allowedPages
     * @return array{students:bool,staff:bool,classes:bool,subjects:bool,buses:bool}
     */
    public function capabilities(string $assignedRole, ?array $allowedPages): array
    {
        $assignedRole = trim($assignedRole);
        if (in_array($assignedRole, ['admin', 'super_admin'], true)) {
            return array_fill_keys(self::GROUPS, true);
        }

        $pageSet = [];
        foreach ($allowedPages ?? [] as $page) {
            $page = basename(trim((string)$page));
            if ($page !== '') {
                $pageSet[$page] = true;
            }
        }

        return [
            'students' => isset($pageSet['students.php']),
            'staff' => isset($pageSet['staff.php']),
            'classes' => isset($pageSet['class_lists.php']),
            'subjects' => isset($pageSet['subjects.php']),
            'buses' => isset($pageSet['buses.php']) || isset($pageSet['transport_statistics.php']),
        ];
    }
}
