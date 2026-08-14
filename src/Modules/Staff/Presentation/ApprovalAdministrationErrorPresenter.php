<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Presentation;

use Throwable;

/** Maps workflow administration domain failures to safe, actionable Arabic copy. */
final class ApprovalAdministrationErrorPresenter
{
    public static function message(Throwable $exception): string
    {
        return match ($exception->getMessage()) {
            'APPROVAL_ACTOR_INVALID' => 'انتهت صلاحية الجلسة. أعد تسجيل الدخول ثم حاول مرة أخرى.',
            'APPROVAL_WORKFLOW_CODE_INVALID' => 'اكتب كودًا فريدًا للمسار بحروف إنجليزية كبيرة وأرقام وشرطة سفلية فقط.',
            'APPROVAL_WORKFLOW_NAME_INVALID' => 'اسم مسار الاعتماد مطلوب ولا يجوز أن يتجاوز 200 حرف.',
            'APPROVAL_WORKFLOW_RESOURCE_TYPE_INVALID' => 'اختر نوع الطلب الذي سيستخدم هذا المسار.',
            'APPROVAL_WORKFLOW_STATUS_INVALID' => 'حالة مسار الاعتماد المختارة غير صالحة.',
            'APPROVAL_WORKFLOW_VALIDITY_INVALID' => 'تحقق من تاريخ بداية النسخة ونهايتها؛ يجب أن تكون النهاية بعد البداية.',
            'APPROVAL_WORKFLOW_NOT_FOUND' => 'مسار الاعتماد المطلوب لم يعد متاحًا.',
            'APPROVAL_WORKFLOW_RETIRED' => 'لا يمكن إنشاء نسخة جديدة لمسار متقاعد.',
            'APPROVAL_WORKFLOW_INACTIVE' => 'فعّل مسار الاعتماد أولًا قبل نشر نسخة منه.',
            'APPROVAL_WORKFLOW_PUBLISH_CONFLICT' => 'تتداخل هذه النسخة مع نسخة منشورة. أنشئ نسخة مفتوحة تبدأ بعد النسخة الحالية أو راجع تواريخ السريان.',
            'APPROVAL_WORKFLOW_VERSION_NOT_FOUND' => 'نسخة المسار المطلوبة لم تعد متاحة.',
            'APPROVAL_WORKFLOW_VERSION_NOT_DRAFT' => 'يمكن نشر نسخة المسودة فقط مرة واحدة.',
            'APPROVER_NOT_CONFIGURED', 'APPROVAL_WORKFLOW_STAGES_INVALID' => 'أضف مرحلة اعتماد واحدة على الأقل مع إعداد صحيح للمعتمدين.',
            'APPROVAL_STAGE_NAME_INVALID' => 'اسم كل مرحلة مطلوب.',
            'APPROVAL_STAGE_RESOLVER_INVALID' => 'طريقة تحديد معتمد المرحلة غير صالحة.',
            'APPROVAL_STAGE_ASSIGNEE_INVALID', 'APPROVAL_STAGE_ASSIGNEE_INACTIVE' => 'اختر عاملًا نشطًا لكل معتمد أو بديل محدد.',
            'APPROVAL_NAMED_USERS_EMPTY' => 'اختر معتمدًا واحدًا على الأقل عند استخدام «معتمدون محددون».',
            'APPROVAL_ROLE_SCOPE_EMPTY', 'APPROVAL_ROLE_SCOPE_INVALID' => 'اختر دورًا نشطًا واحدًا على الأقل عند استخدام نطاق الأدوار.',
            'APPROVAL_STAGE_QUORUM_INVALID' => 'أدخل عدد نصاب صالح عندما تكون طريقة القرار بالنصاب.',
            'APPROVAL_DELEGATION_DELEGATOR_INVALID', 'APPROVAL_DELEGATION_DELEGATE_INVALID' => 'اختر المدير الأصلي والنائب.',
            'APPROVAL_DELEGATION_SELF_FORBIDDEN' => 'لا يمكن تفويض المدير إلى نفسه.',
            'APPROVAL_DELEGATION_SCOPE_INVALID' => 'حدد نطاق التفويض ومعرّفه بشكل صحيح.',
            'APPROVAL_DELEGATION_VALIDITY_INVALID' => 'يجب أن ينتهي التفويض بعد وقت بدايته.',
            'APPROVAL_DELEGATION_REASON_REQUIRED' => 'اكتب سبب التفويض قبل الحفظ.',
            'APPROVAL_DELEGATION_ACCOUNT_INACTIVE' => 'لا يمكن إنشاء تفويض لحساب غير نشط.',
            'APPROVAL_DELEGATION_SCOPE_CONFLICT' => 'يوجد تفويض نشط متداخل في النطاق نفسه؛ راجع التفويض الحالي أولًا.',
            'APPROVAL_DELEGATION_NOT_FOUND' => 'التفويض المطلوب لم يعد متاحًا.',
            'APPROVAL_DELEGATION_TERMINAL' => 'لا يمكن تعديل تفويض تم إلغاؤه أو انتهت صلاحيته.',
            'APPROVAL_DELEGATION_EXPIRED' => 'انتهت فترة التفويض؛ أنشئ تفويضًا جديدًا بدل تفعيله.',
            'APPROVAL_DELEGATION_STATUS_INVALID' => 'إجراء حالة التفويض غير صالح.',
            default => 'تعذر إتمام العملية الآن. لم تُحفظ أي تغييرات غير مكتملة.',
        };
    }
}
