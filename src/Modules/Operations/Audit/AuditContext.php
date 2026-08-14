<?php

declare(strict_types=1);

namespace EduCore\Modules\Operations\Audit;

final class AuditContext
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(16));
        }

        return self::$requestId;
    }

    public static function actor(): array
    {
        return [
            'id' => (int) ($_SESSION['user_id'] ?? 0),
            'name' => (string) ($_SESSION['name'] ?? 'غير معروف'),
            'role' => (string) ($_SESSION['role'] ?? 'system'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT'])
                ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500)
                : null,
            'route' => isset($_SERVER['REQUEST_URI'])
                ? substr((string) $_SERVER['REQUEST_URI'], 0, 500)
                : null,
        ];
    }

    public static function resetForTests(): void
    {
        self::$requestId = null;
    }
}
