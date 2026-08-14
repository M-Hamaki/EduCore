<?php

declare(strict_types=1);

final class AuthorizationFacade
{
    public static function isSupervisor(array $session): bool
    {
        $role = (string) ($session['active_role'] ?? $session['role'] ?? '');
        return $role === 'supervisor'
            || ($role === 'teacher' && !empty($session['is_supervisor']));
    }

    public static function effectiveRole(array $session): string
    {
        if (!empty($session['role_selection_required'])) {
            return '';
        }
        if (self::isSupervisor($session)) {
            $mode = (string) ($session['active_mode'] ?? '');
            if ($mode === 'teacher') {
                return 'teacher';
            }
            if ($mode === 'supervisor') {
                return 'supervisor';
            }
        }

        return (string) ($session['active_role'] ?? $session['role'] ?? '');
    }

    public static function allowsRequiredRole(
        array $session,
        string $requiredRole,
        ?callable $customAdminPageDecision = null
    ): bool {
        if (!empty($session['role_selection_required'])) {
            return false;
        }
        $actualRole = (string) ($session['active_role'] ?? $session['role'] ?? '');
        if ($actualRole === $requiredRole || self::effectiveRole($session) === $requiredRole) {
            return true;
        }
        if ($requiredRole !== 'admin') {
            return false;
        }
        if ($actualRole === 'super_admin') {
            return true;
        }

        return $customAdminPageDecision !== null
            && (bool) $customAdminPageDecision($actualRole);
    }

    public static function allowsAdminPage(string $role, string $page, ?array $allowedPages): bool
    {
        if ($role === 'admin' || $role === 'super_admin') {
            return true;
        }
        if ($allowedPages === null || $allowedPages === []) {
            return false;
        }

        return in_array($page, $allowedPages, true);
    }
}
