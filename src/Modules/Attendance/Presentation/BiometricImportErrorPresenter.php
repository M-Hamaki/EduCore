<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Presentation;

use Throwable;

/** Converts typed import failures into safe, actionable Arabic messages. */
final class BiometricImportErrorPresenter
{
    public static function present(Throwable $exception): string
    {
        return match ($exception->getMessage()) {
            'BIOMETRIC_EVENTS_REQUIRED' => 'لا توجد بصمات صالحة للاستيراد داخل الملف.',
            'BIOMETRIC_DEVICE_ID_INVALID' => 'حدد رقم جهاز بصمة رقميًا وصحيحًا قبل التأكيد.',
            'BIOMETRIC_ENTRY_METHOD_ID_INVALID' => 'حدد وسيلة حضور معتمدة قبل التأكيد.',
            'BIOMETRIC_ENTRY_METHOD_NOT_ACTIVE' => 'وسيلة الحضور المحددة غير نشطة أو غير موجودة.',
            'BIOMETRIC_ENTRY_METHOD_CONFIGURATION_INVALID' => 'وسيلة الحضور المحددة لا تصلح لاستيراد بصمة CSV؛ راجع إعداداتها.',
            'BIOMETRIC_DEVICE_TIMEZONE_INVALID' => 'المنطقة الزمنية للجهاز غير صالحة.',
            'BIOMETRIC_EVENT_DEVICE_MISMATCH' => 'يوجد رقم جهاز مختلف داخل الملف. استخدم ملفًا لجهاز واحد أو صحح رقم الجهاز.',
            'BIOMETRIC_IDENTITY_INVALID' => 'يوجد رقم بصمة فارغ أو غير صالح داخل الملف.',
            'BIOMETRIC_DEVICE_EVENT_AT_INVALID' => 'يوجد وقت بصمة غير صالح داخل الملف. استخدم الصيغة YYYY-MM-DD HH:MM:SS.',
            'BIOMETRIC_EVENT_TYPE_INVALID' => 'نوع البصمة يجب أن يكون in أو out أو unknown.',
            'BIOMETRIC_RAW_EVIDENCE_REQUIRED' => 'تعذر حفظ دليل البصمة الخام لهذا الصف؛ أعد رفع الملف.',
            'BIOMETRIC_BATCH_IDEMPOTENCY_CONFLICT', 'BIOMETRIC_EVENT_IDEMPOTENCY_CONFLICT' => 'تعارضت محاولة الاستيراد مع دفعة سابقة. لا تُعد الإرسال؛ راجع سجل الاستيراد.',
            'BIOMETRIC_MAPPING_RESULT_INVALID' => 'يوجد ربط بصمة غير مكتمل ويحتاج إلى مراجعة إدارية.',
            default => 'تعذر استيراد البصمات الجديدة. لم تُحفظ أي دفعة جديدة؛ راجع إعدادات الجهاز وربط أرقام البصمة ثم أعد المحاولة.',
        };
    }
}
