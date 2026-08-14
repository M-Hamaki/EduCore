<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Presentation;

use InvalidArgumentException;
use PDOException;
use RuntimeException;
use SafeErrorPolicy;
use Throwable;

final class StaffProfileErrorPresenter
{
    public static function saveMessage(Throwable $exception, string $operation): string
    {
        if ($exception instanceof PDOException) {
            $reference = SafeErrorPolicy::report($exception, 'staff.profile.' . $operation);
            if ((int)($exception->errorInfo[1] ?? 0) === 1062) {
                $technicalMessage = $exception->getMessage();
                if (str_contains($technicalMessage, 'uk_biometric_id')
                    || str_contains($technicalMessage, 'uq_employee_code')) {
                    return 'رقم البصمة مستخدم بالفعل لعامل آخر.';
                }
                if (str_contains($technicalMessage, 'employee_code')) {
                    return 'كود الموظف مستخدم بالفعل لعامل آخر.';
                }
                return 'توجد قيمة مكررة في بيانات العامل. راجع كود الموظف ورقم البصمة.';
            }
            return 'تعذر حفظ بيانات العامل. رقم مرجع الخطأ: ' . $reference;
        }

        if ($exception instanceof InvalidArgumentException) {
            return $exception->getMessage();
        }

        if ($exception instanceof RuntimeException
            && self::isSafeBusinessMessage($exception->getMessage())) {
            return $exception->getMessage();
        }

        $reference = SafeErrorPolicy::report($exception, 'staff.profile.' . $operation);
        return 'تعذر حفظ بيانات العامل. رقم مرجع الخطأ: ' . $reference;
    }

    private static function isSafeBusinessMessage(string $message): bool
    {
        if ($message === '' || preg_match('/SQLSTATE|Stack trace|[A-Z]:\\\\|\\.php:\\d+/i', $message)) {
            return false;
        }
        return (bool)preg_match('/[\x{0600}-\x{06FF}]/u', $message);
    }
}
