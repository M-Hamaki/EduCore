<?php

declare(strict_types=1);

/**
 * Central contract for the predefined admin-like staff roles.
 *
 * Visible pages are the pages shown in the admin sidebar. Supporting endpoints
 * are derived at runtime so they are never exposed as standalone permissions in
 * the role editor and cannot be accidentally removed while editing a role.
 */
final class AdminRolePageCatalog
{
    public const SPECIALIST = 'specialist';
    public const DOCTOR = 'doctor';
    public const LIBRARIAN = 'librarian';
    public const STUDENT_AFFAIRS_MANAGER = 'student_affairs_manager';
    public const TRANSPORT_MANAGER = 'transport_manager';
    public const ROLES_PERMISSIONS_MANAGER = 'roles_permissions_manager';

    private const ROLE_DEFINITIONS = [
        self::SPECIALIST => [
            'name' => 'أخصائي',
            'landing_page' => 'specialist_dashboard.php',
            'pages' => [
                'specialist_dashboard.php',
                'specialist_requests.php',
                'students.php',
                'class_lists.php',
                'attendance.php',
                'student_file.php',
                'student_id_cards.php',
                'export_students.php',
                'student_statistics.php',
                'calculation_tools.php',
                'student_evaluations.php',
                'teacher_evaluations.php',
                'evaluation_analytics.php',
                'evaluation_reports.php',
                'student_clinic.php',
            ],
        ],
        self::DOCTOR => [
            'name' => 'طبيب',
            'landing_page' => 'role_dashboard.php',
            'pages' => ['student_clinic.php'],
        ],
        self::LIBRARIAN => [
            'name' => 'مسؤول مكتبة',
            'landing_page' => 'role_dashboard.php',
            'pages' => ['library.php'],
        ],
        self::STUDENT_AFFAIRS_MANAGER => [
            'name' => 'مسؤول شؤون الطلاب',
            'landing_page' => 'role_dashboard.php',
            'pages' => [
                'students.php',
                'student_operations.php',
                'pending_operations.php',
                'new_students.php',
                'transferred_students.php',
                'graduate_students.php',
                'student_archive.php',
                'student_data_completeness.php',
                'class_lists.php',
                'siblings.php',
                'attendance.php',
                'statements.php',
                'student_file.php',
                'student_numbers_reports.php',
                'student_id_cards.php',
                'export_students.php',
                'student_statistics.php',
                'calculation_tools.php',
            ],
        ],
        self::TRANSPORT_MANAGER => [
            'name' => 'مسؤول الحركة والتنقلات',
            'landing_page' => 'role_dashboard.php',
            'pages' => [
                'locations.php',
                'bus_staff.php',
                'buses.php',
                'student_buses.php',
                'bus_lists.php',
                'bus_report.php',
                'transport_statistics.php',
            ],
        ],
        self::ROLES_PERMISSIONS_MANAGER => [
            'name' => 'مسؤول الأدوار والصلاحيات',
            'landing_page' => 'role_dashboard.php',
            'pages' => [
                'school_settings.php',
                'student_accounts.php',
                'staff_accounts.php',
            ],
        ],
    ];

    private const PAGE_DEPENDENCIES = [
        'students.php' => ['ajax_students_datatable.php'],
        'new_students.php' => ['ajax_derived_students_datatable.php'],
        'transferred_students.php' => ['ajax_derived_students_datatable.php'],
        'graduate_students.php' => ['ajax_derived_students_datatable.php'],
        'student_archive.php' => ['ajax_student_archive_datatable.php'],
        'student_data_completeness.php' => ['ajax_student_completeness.php'],
        'siblings.php' => ['relationship_discovery.php'],
        'student_accounts.php' => [
            'ajax_student_accounts_datatable.php',
            'ajax_bulk_student_accounts.php',
            'download_bulk_credentials.php',
            'ajax_handlers.php',
            'get_password.php',
        ],
        'staff_accounts.php' => [
            'ajax_staff_accounts_datatable.php',
            'ajax_bulk_staff_accounts.php',
            'ajax_bulk_role_pages.php',
            'download_bulk_credentials.php',
            'ajax_staff_scope.php',
            'get_password.php',
        ],
        'student_clinic.php' => ['ajax_clinic_datatable.php'],
        'library.php' => ['ajax_library_datatable.php', 'ajax_library_lookup.php'],
    ];

    /**
     * Legacy public URLs retained while their canonical replacements are
     * rolled out.  The alias is expanded for authorization so a saved legacy
     * grant cannot lock a role out before the corresponding migration runs.
     */
    private const PAGE_ALIASES = [
        'school_budget.php' => 'student_numbers_reports.php',
    ];

    public static function predefinedRoles(): array
    {
        return self::ROLE_DEFINITIONS;
    }

    public static function predefinedRole(string $role): ?array
    {
        return self::ROLE_DEFINITIONS[$role] ?? null;
    }

    /** @return array<int,string> */
    public static function customizableRoleKeys(): array
    {
        return array_keys(self::ROLE_DEFINITIONS);
    }

    public static function isCustomizableRole(string $role): bool
    {
        return isset(self::ROLE_DEFINITIONS[$role]);
    }

    /** @return array<int,string> */
    public static function customizablePages(string $role): array
    {
        return array_values(self::ROLE_DEFINITIONS[$role]['pages'] ?? []);
    }

    /** @return array<int,string> */
    public static function mandatoryPages(string $role): array
    {
        $definition = self::ROLE_DEFINITIONS[$role] ?? null;
        if (!is_array($definition)) {
            return [];
        }
        $pages = [(string)($definition['landing_page'] ?? '')];
        if ($role === self::SPECIALIST) {
            $pages[] = 'specialist_requests.php';
        }
        return array_values(array_filter(array_unique($pages)));
    }

    public static function expandWithDependencies(array $pages): array
    {
        $expanded = [];
        foreach ($pages as $page) {
            $page = basename(trim((string) $page));
            if ($page === '') {
                continue;
            }

            $canonicalPage = self::canonicalPage($page);
            $expanded[] = $canonicalPage;

            // Keep the compatibility URL reachable after a role is saved
            // under the canonical page name.  Its entrypoint only redirects
            // and never becomes an independently editable permission.
            foreach (self::PAGE_ALIASES as $legacyPage => $targetPage) {
                if ($targetPage === $canonicalPage) {
                    $expanded[] = $legacyPage;
                }
            }

            foreach (self::PAGE_DEPENDENCIES[$canonicalPage] ?? [] as $dependency) {
                $expanded[] = $dependency;
            }
        }

        return array_values(array_unique($expanded));
    }

    public static function canonicalPage(string $page): string
    {
        $page = basename(trim($page));
        return self::PAGE_ALIASES[$page] ?? $page;
    }

    public static function isSupportingPage(string $page): bool
    {
        $page = basename($page);
        if ($page === 'role_dashboard.php') {
            return true;
        }
        if (isset(self::PAGE_ALIASES[$page])) {
            return true;
        }
        foreach (self::PAGE_DEPENDENCIES as $dependencies) {
            if (in_array($page, $dependencies, true)) {
                return true;
            }
        }

        return str_starts_with($page, 'ajax_');
    }

    public static function landingPage(string $role, array $allowedPages): ?string
    {
        $definition = self::predefinedRole($role);
        $preferred = (string) ($definition['landing_page'] ?? '');
        if ($preferred !== '' && in_array($preferred, $allowedPages, true)) {
            return $preferred;
        }
        if (in_array('index.php', $allowedPages, true)) {
            return 'index.php';
        }
        foreach ($allowedPages as $page) {
            $page = basename((string) $page);
            if ($page !== '' && !self::isSupportingPage($page)) {
                return $page;
            }
        }

        return null;
    }
}
