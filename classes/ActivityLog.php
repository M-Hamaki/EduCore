<?php
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditContext.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditPolicyRegistry.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/EntityChangeTracker.php';

use EduCore\Modules\Operations\Audit\AuditContext;
use EduCore\Modules\Operations\Audit\AuditPolicyRegistry;
use EduCore\Modules\Operations\Audit\EntityChangeTracker;

/**
 * فئة سجل النشاطات - Activity Log Helper
 * تسجيل جميع العمليات الإدارية المهمة
 */
class ActivityLog {
    
    private static $db = null;
    /**
     * تعيين اتصال قاعدة البيانات
     */
    public static function setDb($db) {
        self::$db = $db;
    }
    
    /**
     * الحصول على اتصال قاعدة البيانات
     */
    private static function getDb() {
        if (self::$db === null) {
            require_once __DIR__ . '/../config/database.php';
            $database = new Database();
            self::$db = $database->getConnection();
        }
        return self::$db;
    }
    
    /**
     * تسجيل نشاط جديد
     * 
     * @param string $action نوع العملية (create, update, delete, login, reset, etc)
     * @param string|null $target_type نوع الهدف (student, teacher, class, stage, evaluation, settings, etc)
     * @param int|null $target_id معرف الهدف
     * @param string|null $target_name اسم الهدف
     * @param array|null $details تفاصيل إضافية
     */
    public static function log($action, $target_type = null, $target_id = null, $target_name = null, $details = null, array $context = []) {
        try {
            $db = self::getDb();
            $actor = AuditContext::actor();
            if (isset($context['actor_id']) && (int) $context['actor_id'] > 0) {
                $actor['id'] = (int) $context['actor_id'];
            }
            if (isset($context['actor_name'])) $actor['name'] = (string) $context['actor_name'];
            if (isset($context['actor_role'])) $actor['role'] = (string) $context['actor_role'];
            $requestId = $context['request_id'] ?? AuditContext::requestId();
            $batchId = self::normalizeIdentifier($context['batch_id'] ?? null);
            $requestedResult = (string) ($context['result'] ?? 'success');
            $result = in_array($requestedResult, ['success', 'failure', 'denied'], true)
                ? $requestedResult
                : 'success';
            $detailArray = $details !== null ? (array) $details : [];
            $academicYearId = (int) ($context['academic_year_id'] ?? ($detailArray['academic_year_id'] ?? 0));
            if ($academicYearId <= 0 && isset($detailArray['changes']['academic_year_id']['to'])) {
                $academicYearId = (int) $detailArray['changes']['academic_year_id']['to'];
            }
            if ($academicYearId <= 0 && session_status() === PHP_SESSION_ACTIVE) {
                $academicYearId = (int) ($_SESSION['academic_year_id'] ?? 0);
            }
            $academicYearId = $academicYearId > 0 ? $academicYearId : null;
            
            // تحويل التفاصيل إلى JSON
            $details_json = null;
            if ($details !== null) {
                $details_json = json_encode(
                    AuditPolicyRegistry::redact((array) $details, (string) $target_type),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
            }

            $stmt = $db->prepare("INSERT INTO activity_logs
                (user_id, user_name, user_role, action, target_type, target_id, target_name, details, ip_address, academic_year_id,
                 request_id, batch_id, result, route, user_agent, undo_log_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $actor['id'],
                $actor['name'],
                $actor['role'],
                $action,
                $target_type,
                $target_id,
                $target_name,
                $details_json,
                $actor['ip_address'],
                $academicYearId,
                $requestId,
                $batchId,
                $result,
                $actor['route'],
                $actor['user_agent'],
                isset($context['undo_log_id']) ? (int) $context['undo_log_id'] : null,
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("ActivityLog error: " . $e->getMessage());
            return false;
        }
    }

    public static function logChange(
        string $action,
        string $targetType,
        $targetId,
        string $targetName,
        array $before,
        array $after,
        array $context = [],
        array $additionalDetails = []
    ): bool {
        $details = $additionalDetails;
        $details['changes'] = EntityChangeTracker::diff($before, $after, $targetType);

        return self::log($action, $targetType, $targetId, $targetName, $details, $context);
    }

    private static function normalizeIdentifier($value): ?string
    {
        $value = strtolower(trim((string) $value));
        return preg_match('/^[a-f0-9]{32}$/', $value) ? $value : null;
    }
    
    // ============ دوال مختصرة للعمليات الشائعة ============
    
    /**
     * تسجيل عملية إنشاء
     */
    public static function logCreate($target_type, $target_id, $target_name, $details = null) {
        return self::log('create', $target_type, $target_id, $target_name, $details);
    }
    
    /**
     * تسجيل عملية تعديل
     */
    public static function logUpdate($target_type, $target_id, $target_name, $details = null) {
        return self::log('update', $target_type, $target_id, $target_name, $details);
    }
    
    /**
     * تسجيل عملية حذف
     */
    public static function logDelete($target_type, $target_id, $target_name, $details = null) {
        return self::log('delete', $target_type, $target_id, $target_name, $details);
    }
    
    /**
     * تسجيل عملية استيراد
     */
    public static function logImport($target_type, $count, $details = null) {
        return self::log('import', $target_type, null, "استيراد $count عنصر", $details);
    }
    
    /**
     * تسجيل تغيير الحالة
     */
    public static function logStatusChange($target_type, $target_id, $target_name, $new_status) {
        return self::log('status_change', $target_type, $target_id, $target_name, ['new_status' => $new_status]);
    }
    
    /**
     * تسجيل تغيير الإعدادات
     */
    public static function logSettings($setting_name, $old_value = null, $new_value = null) {
        return self::log('settings', 'settings', null, $setting_name, [
            'old_value' => $old_value,
            'new_value' => $new_value
        ]);
    }
    
    /**
     * تسجيل إعادة ضبط النقاط
     */
    public static function logReset($details = null) {
        return self::log('reset', 'points', null, 'إعادة ضبط النقاط', $details);
    }
    
    /**
     * تسجيل تسجيل دخول
     */
    public static function logLogin($user_id, $user_name, $role) {
        // تعيين الجلسة فقط إذا لم تكن معيّنة بعد
        if (!isset($_SESSION['user_id'])) {
            $_SESSION['user_id'] = $user_id;
            $_SESSION['name'] = $user_name;
            $_SESSION['role'] = $role;
        }
        return self::log('login', 'user', $user_id, $user_name);
    }
    
    // ============ دوال الاستعلام ============
    
    /**
     * جلب سجل النشاطات مع فلترة
     */
    public static function getLogs($filters = [], $limit = 50, $offset = 0) {
        $db = self::getDb();
        
        $where = [];
        $params = [];
        
        if (!empty($filters['action'])) {
            $where[] = "action = ?";
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['target_type'])) {
            $where[] = "target_type = ?";
            $params[] = $filters['target_type'];
        }
        if (!empty($filters['target_types']) && is_array($filters['target_types'])) {
            $types = array_values(array_filter($filters['target_types'], 'is_string'));
            if ($types) {
                $where[] = 'target_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
                $params = array_merge($params, $types);
            }
        }
        if (isset($filters['target_ids']) && is_array($filters['target_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['target_ids']), static fn($id) => $id > 0));
            if ($ids) {
                $where[] = 'target_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            } else {
                $where[] = '1 = 0';
            }
        }
        
        if (!empty($filters['user_id'])) {
            $where[] = "user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(user_name LIKE ? OR target_name LIKE ? OR details LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT * FROM activity_logs $whereClause ORDER BY created_at DESC LIMIT " . intval($limit) . " OFFSET " . intval($offset);
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * عدد السجلات
     */
    public static function countLogs($filters = []) {
        $db = self::getDb();
        
        $where = [];
        $params = [];
        
        if (!empty($filters['action'])) {
            $where[] = "action = ?";
            $params[] = $filters['action'];
        }
        if (!empty($filters['target_type'])) {
            $where[] = "target_type = ?";
            $params[] = $filters['target_type'];
        }
        if (!empty($filters['target_types']) && is_array($filters['target_types'])) {
            $types = array_values(array_filter($filters['target_types'], 'is_string'));
            if ($types) {
                $where[] = 'target_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
                $params = array_merge($params, $types);
            }
        }
        if (isset($filters['target_ids']) && is_array($filters['target_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['target_ids']), static fn($id) => $id > 0));
            if ($ids) {
                $where[] = 'target_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            } else {
                $where[] = '1 = 0';
            }
        }
        if (!empty($filters['user_id'])) {
            $where[] = "user_id = ?";
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[] = "(user_name LIKE ? OR target_name LIKE ? OR details LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM activity_logs $whereClause");
        $stmt->execute($params);
        
        return $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }
    
    /**
     * ترجمة نوع العملية إلى العربية
     */
    public static function getActionLabel($action) {
        $labels = [
            'create' => 'إنشاء',
            'update' => 'تعديل',
            'delete' => 'حذف',
            'import' => 'استيراد',
            'export' => 'تصدير',
            'login' => 'تسجيل دخول',
            'logout' => 'تسجيل خروج',
            'reset' => 'إعادة ضبط',
            'status_change' => 'تغيير حالة',
            'settings' => 'تغيير إعدادات',
            'link' => 'ربط',
            'unlink' => 'إلغاء ربط',
            'backup' => 'نسخ احتياطي',
            'restore' => 'استعادة',
            'undo' => 'تراجع',
            'redo' => 'إعادة عمل',
            'review' => 'مراجعة',
        ];
        return $labels[$action] ?? self::humanizeAuditCode((string) $action);
    }
    
    /**
     * ترجمة نوع الهدف إلى العربية
     */
    public static function getTargetLabel($target_type) {
        $labels = [
            'student' => 'طالب',
            'teacher' => 'معلم',
            'specialist' => 'أخصائي',
            'staff' => 'موظف',
            'admin' => 'مدير',
            'user' => 'مستخدم',
            'class' => 'فصل',
            'academic_year' => 'عام دراسي',
            'grade' => 'صف',
            'stage' => 'مرحلة',
            'subject' => 'مادة',
            'evaluation' => 'تقييم',
            'evaluation_type' => 'نوع تقييم',
            'notification' => 'تنبيه',
            'settings' => 'إعدادات',
            'points' => 'نقاط',
            'report' => 'تقرير',
            'backup' => 'نسخة احتياطية',
            'attendance' => 'حضور',
            'timetable' => 'جدول حصص',
            'academic_term' => 'ترم دراسي',
            'academic_week' => 'أسبوع دراسي',
            'subject_grade_assignment' => 'ربط مادة بصف',
            'teacher_subject_assignment' => 'تعيين معلم لمادة',
            'assessment_scheme' => 'خطة درجات',
            'assessment_component' => 'بند درجات',
            'assessment_window' => 'نافذة رصد',
            'assessment_permission' => 'صلاحية درجات',
            'assessment_student_lock' => 'قفل درجات طالب',
            'student_mark' => 'درجة طالب',
            'report_window' => 'نافذة تقرير',
            'published_report' => 'تقرير منشور',
            'fee_payment' => 'دفعة مالية',
            'fee_structure' => 'هيكل رسوم',
            'fee_generation' => 'توليد مستحقات',
            'bus_fee_zone' => 'منطقة حافلة',
            'sibling_discounts' => 'خصومات إخوة',
            'governorate' => 'محافظة',
            'city' => 'مدينة',
            'center' => 'مركز',
            'neighborhood' => 'حي',
            'street' => 'شارع',
            'bus' => 'حافلة',
            'discipline' => 'جزاء',
            'leave' => 'إجازة',
            'training' => 'تدريب',
            'training_program' => 'برنامج تدريبي',
            'training_course' => 'دورة تدريبية',
            'training_unit' => 'وحدة تدريبية',
            'training_question' => 'سؤال تدريبي',
            'clinic_visit' => 'زيارة عيادة',
            'library_book' => 'كتاب مكتبة',
            'library_loan' => 'استعارة مكتبة',
            'library_fine' => 'غرامة مكتبة',
            'student_account' => 'حساب طالب',
            'staff_account' => 'حساب عامل',
            'staff_financial' => 'مالية عامل',
            'staff_role' => 'دور عامل',
            'super_admin' => 'المدير العام',
        ];
        return $labels[$target_type] ?? self::humanizeAuditCode((string) $target_type);
    }

    /**
     * Converts new structured audit identifiers to readable Arabic without
     * requiring every module to change the unified log page for its events.
     */
    private static function humanizeAuditCode(string $value): string
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return 'غير محدد';
        }

        $terms = [
            'academic' => 'أكاديمي', 'account' => 'حساب', 'accounts' => 'حسابات', 'activity' => 'نشاط',
            'adjustment' => 'تسوية', 'ai' => 'الذكاء الاصطناعي', 'alternative' => 'بديل', 'appeal' => 'تظلم',
            'approval' => 'اعتماد', 'archive' => 'أرشفة', 'archived' => 'أرشفة', 'assignment' => 'تعيين',
            'assignments' => 'تعيينات', 'attendance' => 'حضور', 'balance' => 'رصيد', 'batch' => 'دفعة',
            'biometric' => 'بصمة', 'budget' => 'ميزانية', 'bus' => 'حافلة', 'cancelled' => 'إلغاء',
            'case' => 'حالة', 'chat' => 'محادثة', 'change' => 'تغيير', 'changed' => 'تغيير',
            'classified' => 'تصنيف', 'class' => 'فصل', 'cleanup' => 'تنظيف', 'claimed' => 'استلام المعالجة',
            'close' => 'إغلاق', 'closed' => 'إغلاق', 'clone' => 'نسخ', 'conversation' => 'محادثة',
            'coverage' => 'تغطية', 'credential' => 'بيانات الدخول', 'create' => 'إنشاء', 'created' => 'إنشاء',
            'data' => 'بيانات', 'day' => 'يوم', 'decision' => 'قرار', 'decided' => 'حسم',
            'delegation' => 'تفويض', 'delegations' => 'تفويضات', 'delete' => 'حذف', 'deleted' => 'حذف',
            'discipline' => 'انضباط', 'discount' => 'خصم', 'download' => 'تنزيل', 'draft' => 'مسودة',
            'drafted' => 'حفظ مسودة', 'effect' => 'أثر', 'employee' => 'موظف', 'enqueue' => 'إدراج في الطابور',
            'escalated' => 'تصعيد', 'event' => 'حدث', 'events' => 'أحداث', 'evaluation' => 'تقييم',
            'export' => 'تصدير', 'external' => 'خارجي', 'fee' => 'رسوم', 'finance' => 'المالية',
            'finalized' => 'اعتماد نهائي', 'folder' => 'مجلد', 'generated' => 'توليد', 'generation' => 'توليد',
            'grade' => 'درجات', 'identity' => 'هوية', 'image' => 'صورة', 'import' => 'استيراد',
            'ingested' => 'استيراد', 'insert' => 'إضافة', 'interim' => 'مؤقت', 'intent' => 'نية',
            'journal' => 'قيد يومية', 'leave' => 'إجازة', 'lesson' => 'درس', 'link' => 'ربط', 'linked' => 'ربط',
            'lock' => 'قفل', 'mapped' => 'ربط', 'mapping' => 'ربط', 'mark' => 'درجة', 'medical' => 'طبي',
            'message' => 'رسالة', 'movement' => 'حركة', 'noop' => 'دون تغيير', 'notification' => 'إشعار',
            'outcome' => 'نتيجة', 'party' => 'طرف', 'payroll' => 'رواتب', 'period' => 'فترة',
            'permission' => 'إذن', 'plan' => 'خطة', 'post' => 'ترحيل', 'posted' => 'ترحيل', 'private' => 'خاص',
            'profile' => 'ملف شخصي', 'projected' => 'إسقاط', 'projection' => 'إسقاط', 'publish' => 'نشر',
            'published' => 'نشر', 'queue' => 'طابور', 'queued' => 'إدراج في الطابور', 'quiz' => 'اختبار',
            'reassigned' => 'إعادة تعيين', 'recalculated' => 'إعادة احتساب', 'record' => 'تسجيل', 'recorded' => 'تسجيل', 'redo' => 'إعادة عمل',
            'regenerated' => 'إعادة توليد', 'rejected' => 'رفض', 'reopen' => 'إعادة فتح', 'reopened' => 'إعادة فتح',
            'report' => 'تقرير', 'request' => 'طلب', 'requests' => 'طلبات', 'results' => 'نتائج',
            'review' => 'مراجعة', 'reviewed' => 'مراجعة', 'reverse' => 'عكس', 'reversed' => 'عكس',
            'revoked' => 'إلغاء', 'run' => 'تشغيل', 'saved' => 'حفظ', 'schedule' => 'جدول', 'section' => 'قسم',
            'security' => 'أمان', 'send' => 'إرسال', 'session' => 'جلسة', 'settings' => 'إعدادات',
            'share' => 'مشاركة', 'shift' => 'وردية', 'sla' => 'اتفاقية مستوى الخدمة', 'staff' => 'شؤون العاملين',
            'stage' => 'مرحلة', 'state' => 'حالة', 'status' => 'حالة', 'student' => 'طالب',
            'submission' => 'إرسال', 'submitted' => 'إرسال', 'successor' => 'بديل', 'swap' => 'تبديل',
            'sync' => 'مزامنة', 'ticket' => 'تذكرة', 'training' => 'تدريب', 'type' => 'نوع', 'undo' => 'تراجع',
            'unlink' => 'إلغاء الربط', 'unlinked' => 'إلغاء الربط', 'updated' => 'تعديل', 'upgrade' => 'ترقية',
            'urgent' => 'عاجل', 'user' => 'مستخدم', 'version' => 'إصدار', 'voucher' => 'سند',
            'window' => 'نافذة', 'withdrawal' => 'سحب', 'withdrawn' => 'سحب', 'workflow' => 'سير العمل',
            'accepted' => 'قبول', 'activate' => 'تفعيل', 'activated' => 'تفعيل', 'added' => 'إضافة',
            'adjustments' => 'تسويات', 'all' => 'الكل', 'application' => 'تطبيق', 'applied' => 'تطبيق',
            'appeals' => 'تظلمات', 'approve' => 'اعتماد', 'approved' => 'اعتماد', 'assessment' => 'التقييمات والدرجات', 'assigned' => 'تعيين',
            'assignees' => 'المكلّفون', 'attachment' => 'مرفق', 'attachments' => 'مرفقات', 'authorized' => 'تفويض',
            'award' => 'منحة', 'backup' => 'نسخة احتياطية', 'batches' => 'دفعات', 'bulk' => 'جماعي',
            'canva' => 'كانفا', 'cashbox' => 'خزينة', 'classes' => 'فصول', 'completed' => 'اكتمل', 'component' => 'بند', 'compensation' => 'تعويض',
            'configure' => 'تهيئة', 'conflict' => 'تعارض', 'connection' => 'اتصال', 'contract' => 'عقد',
            'counts' => 'أعداد', 'database' => 'قاعدة البيانات', 'declared' => 'إقرار', 'decisions' => 'قرارات',
            'device' => 'جهاز', 'disconnect' => 'فصل الاتصال', 'dispatch' => 'إرسال', 'effects' => 'آثار', 'email' => 'البريد الإلكتروني',
            'enabled' => 'تفعيل', 'enrollment' => 'التحاق', 'entry' => 'قيد', 'ertaq' => 'ارتق',
            'exam' => 'اختبار', 'expired' => 'انتهاء', 'failed' => 'فشل', 'failure' => 'فشل',
            'from' => 'من', 'graduation' => 'تخرج', 'group' => 'مجموعة', 'groups' => 'مجموعات', 'guardian' => 'ولي أمر', 'history' => 'سجل',
            'incident' => 'واقعة', 'incidents' => 'وقائع', 'instances' => 'حالات', 'investigation' => 'تحقيق',
            'investigations' => 'تحقيقات', 'issue' => 'إصدار', 'issued' => 'إصدار', 'job' => 'وظيفة',
            'kinship' => 'قرابة', 'legacy' => 'قديم', 'line' => 'بند', 'links' => 'روابط', 'manager' => 'مدير', 'material' => 'مادة', 'materials' => 'مواد',
            'manual' => 'يدوي', 'mappings' => 'روابط', 'measures' => 'إجراءات', 'membership' => 'عضوية',
            'memberships' => 'عضويات', 'mode' => 'وضع', 'move' => 'نقل', 'no' => 'لا', 'online' => 'إلكتروني',
            'open' => 'فتح', 'operation' => 'عملية', 'org' => 'تنظيمي', 'organization' => 'تنظيم',
            'override' => 'تجاوز', 'overrides' => 'تجاوزات', 'password' => 'كلمة المرور', 'payment' => 'دفع',
            'pending' => 'قيد الانتظار', 'policy' => 'سياسة', 'powerpoint' => 'باوربوينت', 'ppt' => 'باوربوينت',
            'progress' => 'تقدم', 'proposed' => 'اقتراح', 'protected' => 'محمي', 'public' => 'عام', 'push' => 'دفع',
            'quota' => 'حصة', 'recalculation' => 'إعادة احتساب', 'receipt' => 'إيصال', 'recovery' => 'استعادة',
            'requested' => 'طلب', 'resolved' => 'حسم', 'resource' => 'مورد', 'result' => 'نتيجة',
            'retry' => 'إعادة محاولة', 'reviewer' => 'مراجع', 'revise' => 'مراجعة', 'role' => 'دور',
            'rollback' => 'إلغاء المعاملة', 'rollover' => 'ترحيل', 'route' => 'مسار', 'row' => 'صف',
            'rule' => 'قاعدة', 'rules' => 'قواعد', 'runs' => 'تشغيلات', 'scheduled' => 'مجدول',
            'scope' => 'نطاق', 'settlement' => 'تسوية', 'shadow' => 'ظل', 'sibling' => 'شقيق',
            'staffing' => 'تغطية العمل', 'started' => 'بدء', 'structure' => 'هيكل', 'subledger' => 'دفتر فرعي',
            'submit' => 'إرسال', 'superseded' => 'استبدال', 'switch' => 'تبديل', 'template' => 'قالب',
            'teacher' => 'معلم', 'temp' => 'مؤقت', 'title' => 'مسمى وظيفي', 'transaction' => 'معاملة',
            'translate' => 'ترجمة', 'triaged' => 'فرز', 'unit' => 'وحدة', 'units' => 'وحدات',
            'uploaded' => 'رفع', 'verify' => 'تحقق', 'view' => 'عرض', 'week' => 'أسبوع', 'year' => 'عام',
            'abandon' => 'إلغاء', 'acknowledged' => 'إقرار', 'accounting' => 'محاسبة', 'admin' => 'إدارة',
            'advance' => 'سلفة', 'apply' => 'تطبيق', 'cases' => 'حالات', 'dataset' => 'مجموعة بيانات',
            'escalation' => 'تصعيد', 'fired' => 'تنفيذ', 'movements' => 'حركات', 'opened' => 'فتح',
            'parties' => 'أطراف', 'periods' => 'فترات', 'promotion' => 'ترقية', 'relationship' => 'علاقة',
            'response' => 'استجابة', 'reversal' => 'عكس', 'scheduler' => 'مجدول', 'subscription' => 'اشتراك',
            'school' => 'المدرسة', 'switched' => 'تبديل', 'tickets' => 'تذاكر', 'titles' => 'مسميات وظيفية', 'transfer' => 'نقل', 'update' => 'تعديل',
            'versions' => 'إصدارات', 'workflows' => 'مسارات العمل',
            'read' => 'عرض', 'messages' => 'رسائل', 'users' => 'مستخدمون',
            'id' => 'المعرّف', 'ids' => 'المعرّفات', 'reason' => 'السبب', 'is' => 'حالة', 'was' => 'كان',
            'substitute' => 'بديل', 'inserted' => 'المضاف', 'count' => 'العدد', 'primary' => 'أساسي',
            'actor' => 'المنفذ', 'api' => 'واجهة التسجيل', 'before' => 'قبل', 'after' => 'بعد',
            'roles' => 'الأدوار', 'encryption' => 'التشفير', 'key' => 'المفتاح', 'rotated' => 'تم تغييره',
            'old' => 'السابق', 'new' => 'الجديد', 'date' => 'التاريخ', 'by' => 'بواسطة', 'included' => 'مشمول',
            'source' => 'المصدر', 'target' => 'الهدف', 'direct' => 'مباشر', 'name' => 'الاسم',
            'display' => 'العرض', 'order' => 'الترتيب', 'auto' => 'تلقائي', 'place' => 'تسكين',
            'students' => 'الطلاب', 'csrf' => 'حماية الطلب', 'token' => 'الرمز', 'in' => 'ضمن', 'total' => 'الإجمالي',
            'sha256' => 'البصمة الرقمية', 'fingerprint' => 'البصمة الرقمية', 'fingerprints' => 'البصمات الرقمية',
            'files' => 'الملفات', 'file' => 'الملف', 'table' => 'الجدول', 'weekly' => 'أسبوعي',
            'repeat' => 'التكرار', 'per' => 'لكل', 'visible' => 'ظاهر', 'to' => 'إلى', 'accepts' => 'يقبل',
            'absence' => 'الغياب', 'excused' => 'بعذر', 'sort' => 'الفرز', 'duration' => 'المدة', 'minutes' => 'دقائق',
            'language' => 'اللغة', 'content' => 'المحتوى', 'length' => 'الحجم', 'field' => 'الحقل', 'fields' => 'الحقول',
            'statuses' => 'الحالات', 'generator' => 'المولّد', 'theme' => 'القالب', 'skipped' => 'المتجاوز',
            'package' => 'الحزمة', 'remove' => 'إزالة', 'removed' => 'المزال', 'logo' => 'الشعار',
            'kind' => 'الفئة', 'members' => 'الأعضاء', 'pairs' => 'الأزواج', 'credentials' => 'بيانات الدخول',
            'room' => 'الغرفة', 'location' => 'الموقع', 'reports' => 'التقارير', 'details' => 'التفاصيل',
            'locked' => 'مقفل', 'error' => 'الخطأ', 'opens' => 'يفتح', 'closes' => 'يغلق', 'at' => 'في',
            'passwords' => 'كلمات المرور', 'educational' => 'التعليمية', 'directorate' => 'المديرية',
            'administration' => 'الإدارة', 'affairs' => 'الشؤون', 'officer' => 'المسؤول', 'transport' => 'النقل',
            'director' => 'المدير', 'financial' => 'المالي', 'general' => 'العام', 'secretary' => 'السكرتير',
            'kg' => 'رياض الأطفال', 'prep' => 'الإعدادي', 'sec' => 'الثانوي', 'ceo' => 'الرئيس التنفيذي',
            'pass' => 'النجاح', 'enable' => 'تفعيل', 'normal' => 'العادية', 'log' => 'السجل',
            'models' => 'النماذج', 'question' => 'السؤال', 'questions' => 'الأسئلة', 'essay' => 'المقالي',
            'tab' => 'التبويب', 'sensitive' => 'حساس', 'deleted' => 'المحذوف',
            'mc' => 'اختيار من متعدد', 'tf' => 'صح أو خطأ', 'custom' => 'مخصص', 'points' => 'النقاط',
            'algorithm' => 'الخوارزمية', 'evaluations' => 'التقييمات', 'unlimited' => 'غير محدد', 'time' => 'الوقت',
            'limit' => 'الحد', 'retroactive' => 'بأثر رجعي', 'value' => 'القيمة', 'bytes' => 'بايت',
            'code' => 'الرمز', 'multiple' => 'متعدد', 'choice' => 'اختيار', 'true' => 'صح', 'false' => 'خطأ',
            'original' => 'الأصلي', 'bank' => 'البنك', 'visual' => 'المرئية', 'activities' => 'الأنشطة',
            'mind' => 'ذهنية', 'maps' => 'خرائط', 'summary' => 'الملخص', 'html' => 'صفحة ويب', 'path' => 'المسار',
            'experimental' => 'تجريبي', 'item' => 'العنصر', 'manifest' => 'بيان الترحيل', 'metadata' => 'البيانات الوصفية',
            'rows' => 'السجلات', 'hash' => 'البصمة', 'prompt' => 'التوجيه', 'ms' => 'مللي ثانية',
            'copied' => 'المنسوخ', 'promoted' => 'المنقولون للصف التالي', 'retained' => 'الباقون للإعادة',
            'graduating' => 'الخريجون', 'transferred' => 'المنقولون', 'out' => 'للخارج', 'excluded' => 'المستبعدون',
            'test' => 'الاختبار', 'subject' => 'المادة', 'schemes' => 'الخطط', 'components' => 'البنود',
            'owner' => 'المسؤول', 'affected' => 'المتأثر', 'basis' => 'الأساس', 'timetable' => 'الجدول الدراسي',
            'derived' => 'المشتق', 'en' => 'بالإنجليزية', 'terms' => 'الفصول الدراسية', 'months' => 'الأشهر',
            'weeks' => 'الأسابيع',
        ];

        $parts = preg_split('/[_\-\s]+/', $value) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        if ($parts === []) {
            return $value;
        }

        return implode(' · ', array_map(static fn(string $part): string => $terms[$part] ?? $part, $parts));
    }

    /**
     * ترجمة مفاتيح التفاصيل للعربية — قابلة للتوسيع
     * أي مفتاح غير موجود في القاموس يُعرض كما هو
     */
    public static function getDetailKeyLabel($key) {
        static $labels = [
            'student_code' => 'كود',
            'employee_code' => 'كود الموظف',
            'attachment_label' => 'اسم المرفق',
            'biometric_id' => 'رقم البصمة',
            'ministry_code' => 'كود الوزارة',
            'role' => 'الدور',
            'role_key' => 'مفتاح الدور',
            'legacy_api' => 'مصدر التسجيل',
            'actor_id' => 'معرّف منفذ العملية',
            'primary_role' => 'الدور الأساسي',
            'before_roles' => 'الأدوار قبل العملية',
            'after_roles' => 'الأدوار بعد العملية',
            'pending_reason' => 'سبب الانتظار',
            'pages' => 'الصفحات',
            'is_supervisor' => 'صلاحية الإشراف',
            'phone_mobile' => 'الموبايل',
            'job_title' => 'المسمى الوظيفي',
            'department' => 'القسم',
            'hire_date' => 'تاريخ التعيين',
            'contract_type' => 'نوع التعاقد',
            'basic_salary' => 'الراتب الأساسي',
            'allowance_transport' => 'بدل انتقال',
            'allowance_housing' => 'بدل سكن',
            'other_allowances_data' => 'بدلات أخرى',
            'deduction_insurance' => 'تأمينات',
            'deduction_tax' => 'ضرائب',
            'other_deductions_data' => 'استقطاعات أخرى',
            'net_salary' => 'صافي المرتب',
            'financial_notes' => 'ملاحظات مالية',
            'advances_data' => 'السلف والقروض',
            'qualification' => 'المؤهل',
            'specialization' => 'التخصص',
            'username' => 'مستخدم',
            'class' => 'الفصل',
            'grade' => 'الصف',
            'academic_year' => 'العام الدراسي',
            'visit_at' => 'وقت الزيارة',
            'book_id' => 'الكتاب',
            'student_id' => 'الطالب',
            'id' => 'رقم السجل',
            'guardian_name' => 'اسم ولي الأمر',
            'relationship' => 'صلة القرابة',
            'relationship_other' => 'صلة القرابة الأخرى',
            'is_primary' => 'ولي الأمر الأساسي',
            'phone_primary' => 'رقم الهاتف الأساسي',
            'relative_id' => 'رقم الطالب ذي الصلة',
            'relative_name' => 'اسم الطالب ذي الصلة',
            'kinship_type_id' => 'نوع صلة القرابة',
            'first_student' => 'الطالب الأول',
            'second_student' => 'الطالب الثاني',
            'first_to_second' => 'صلة الطالب الأول بالثاني',
            'second_to_first' => 'صلة الطالب الثاني بالأول',
            'academic_year_id' => 'العام الدراسي',
            'stage_id' => 'المرحلة',
            'grade_id' => 'الصف',
            'class_id_at_entry' => 'الفصل عند القيد',
            'enrollment_status' => 'حالة القيد',
            'academic_status' => 'الحالة الدراسية',
            'enrollment_date' => 'تاريخ القيد',
            'transfer_date' => 'تاريخ النقل',
            'transfer_destination' => 'جهة النقل',
            'destination' => 'جهة النقل',
            'external_transfer_date' => 'تاريخ النقل الخارجي',
            'graduation_year' => 'عام التخرج',
            'is_repeater' => 'حالة الإعادة',
            'repeat_count' => 'عدد مرات الإعادة',
            'created_at' => 'تاريخ الإنشاء',
            'updated_at' => 'تاريخ آخر تعديل',
            'locked_at' => 'وقت القفل',
            'locked_by' => 'تم القفل بواسطة',
            'recorded_by' => 'تم الرصد بواسطة',
            'transferred_by' => 'تم النقل بواسطة',
            'is_test_account' => 'حساب تجريبي',
            'direct_undo_available' => 'إمكانية التراجع المباشر',
            'recovery_backup_id' => 'رقم نسخة الاستعادة',
            'rollover_run_id' => 'رقم عملية الترحيل',
            'source_year_id' => 'العام الدراسي المصدر',
            'target_year_id' => 'العام الدراسي المستهدف',
            'decision_count' => 'عدد القرارات',
            'deleted_count' => 'عدد السجلات المحذوفة',
            'counts' => 'الأعداد',
            'fields' => 'الحقول',
            'note' => 'ملاحظة',
            'promoted' => 'الطلاب المصعّدون',
            'retained' => 'الطلاب الباقون للإعادة',
            'transferred_out' => 'الطلاب المنقولون خارج المدرسة',
            'withdrawn' => 'الطلاب المنسحبون',
            'graduated' => 'الطلاب الخريجون',
            'pending' => 'قيد الانتظار',
            'component_id' => 'بند الدرجات',
            'scheme_id' => 'خطة الدرجات',
            'subject_id' => 'المادة',
            'term_id' => 'الفصل الدراسي',
            'specialist_id' => 'الأخصائي',
            'mark_status' => 'حالة الدرجة',
            'week_slot' => 'الأسبوع الدراسي',
            'amount' => 'القيمة',
            'old_academic_year' => 'الاسم القديم للعام',
            'new_academic_year' => 'الاسم الجديد للعام',
            'student_count' => 'عدد الطلاب المرتبطين',
            'start_date' => 'تاريخ البداية',
            'end_date' => 'تاريخ النهاية',
            'notes' => 'ملاحظات',
            'is_active' => 'نشط',
            'gender' => 'النوع',
            'national_id' => 'قومي',
            'guardians' => 'أولياء أمور',
            'changes' => 'التغييرات',
            'related_changes' => 'تغييرات مرتبطة نفذها النظام',
            'summary' => 'الملخص',
            'from' => 'من',
            'to' => 'إلى',
            'password_changed' => 'كلمة المرور',
            'password_reset' => 'إعادة تعيين كلمة المرور',
            'class_id' => 'الفصل',
            'guardian_count' => 'عدد أولياء الأمور',
            'new_status' => 'الحالة الجديدة',
            'old_status' => 'الحالة السابقة',
            'status' => 'الحالة',
            'deleted_at' => 'وقت الأرشفة',
            'archived_by' => 'أرشف بواسطة',
            'archive_reason' => 'سبب الأرشفة',
            'status_before_archive' => 'حالة الحساب قبل الأرشفة',
            'count' => 'العدد',
            'assignment_count' => 'عدد التعيينات',
            'previous_count' => 'العدد السابق',
            'active_count' => 'التعيينات النشطة',
            'pending_count' => 'التعيينات المعلقة',
            'record_enabled_count' => 'تعيينات تسمح بالرصد',
            'review_enabled_count' => 'تعيينات تسمح بالمراجعة',
            'requested_active_count' => 'تعيينات مطلوب تفعيلها',
            'assignments' => 'التعيينات',
            'subjects' => 'المواد',
            'whole_grades' => 'الصفوف بالكامل',
            'classes' => 'الفصول',
            'requested_active' => 'طلب التفعيل',
            'audit_snapshot' => 'لقطة التدقيق',
            'previous_assignments' => 'التعيينات السابقة',
            'errors' => 'الأخطاء',
            'phone' => 'الهاتف',
            'email' => 'البريد',
            'address' => 'العنوان',
            'birth_date' => 'تاريخ الميلاد',
            'religion' => 'الديانة',
            'blood_type' => 'فصيلة الدم',
            'stage' => 'المرحلة',
            'grade' => 'الصف',
            'notes' => 'ملاحظات',
            'reason' => 'السبب',
            'old_class' => 'الفصل القديم',
            'new_class' => 'الفصل الجديد',
            'old_class_id' => 'معرف الفصل القديم',
            'new_class_id' => 'معرف الفصل الجديد',
            'amount' => 'المبلغ',
            'method' => 'طريقة الدفع',
            'receipt' => 'رقم الإيصال',
            'total_paid' => 'إجمالي المدفوع',
            'balance' => 'المتبقي',
            'deleted_amount' => 'المبلغ المحذوف',
            'new_total_paid' => 'إجمالي المدفوع الجديد',
            'new_balance' => 'المتبقي الجديد',
            'generated' => 'تم توليده',
            'skipped' => 'تم تخطيه',
            'year' => 'العام الدراسي',
            'term' => 'الترم',
            'week' => 'الأسبوع',
            'subject' => 'المادة',
            'component' => 'البند',
            'scheme' => 'الخطة',
            'template' => 'القالب',
            'replace_existing' => 'استبدال الموجود',
            'source_scheme_id' => 'الخطة المصدر',
            'target_scheme_id' => 'الخطة الهدف',
            'subject_assignment_id' => 'ربط المادة والصف',
            'max_grade' => 'الدرجة الكبرى',
            'total_grade' => 'المجموع الكلي',
            'components_total' => 'مجموع البنود',
            'components_count' => 'عدد البنود',
            'calculation_mode' => 'طريقة الحساب',
            'counts_in_average' => 'يدخل في المتوسط',
            'rounding_enabled' => 'تفعيل التقريب',
            'rounding_mode' => 'طريقة التقريب',
            'rounding_scope' => 'نطاق التقريب',
            'absence_policy' => 'سياسة الغياب',
            'annual_result_enabled' => 'تفعيل نهاية العام',
            'first_term_weight' => 'وزن الترم الأول',
            'second_term_weight' => 'وزن الترم الثاني',
            'window' => 'نافذة الرصد',
            'allow_edit_after_save' => 'السماح بالتعديل بعد الحفظ',
            'requires_review' => 'تتطلب مراجعة',
            'report_type' => 'نوع التقرير',
            'include_details' => 'إظهار التفاصيل',
            'include_absence' => 'إظهار الغياب',
            'include_teacher_notes' => 'إظهار ملاحظات المعلم',
            'freeze_on_publish' => 'تجميد عند النشر',
            'published_at' => 'وقت النشر',
            'teacher_id' => 'المعلم',
            'starts_at' => 'بداية التعيين',
            'ends_at' => 'نهاية التعيين',
            'can_record' => 'يسمح بالرصد',
            'can_review' => 'يسمح بالمراجعة',
            'user_id' => 'المستخدم',
            'permission' => 'الصلاحية',
            'scope' => 'النطاق',
            'scope_id' => 'معرف النطاق',
            'lock_reason' => 'سبب القفل',
            'review_status' => 'حالة المراجعة',
            'review_note' => 'ملاحظة المراجعة',
            'reviewed_by' => 'راجع بواسطة',
            'reviewed_at' => 'وقت المراجعة',
            'pending_review' => 'بانتظار المراجعة',
            'level' => 'المستوى',
            'old_name' => 'الاسم القديم',
            'new_name' => 'الاسم الجديد',
            'payment_method' => 'طريقة الدفع',
            'name' => 'الاسم',
            'first_name_ar' => 'الاسم الأول',
            'second_name_ar' => 'اسم الأب',
            'third_name_ar' => 'اسم الجد',
            'fourth_name_ar' => 'الاسم الرابع',
            'family_name_ar' => 'اسم العائلة',
            'birth_place' => 'مكان الميلاد',
            'city_area' => 'المدينة / المنطقة',
            'address_current' => 'العنوان الحالي',
            'phone_home' => 'هاتف المنزل',
            'phone_emergency' => 'هاتف الطوارئ',
            'enrollment_date' => 'تاريخ القيد',
            'health_status' => 'الحالة الصحية',
            'chronic_diseases' => 'الأمراض المزمنة',
            'allergies' => 'الحساسية',
            'disabilities' => 'الإعاقات',
            'medications' => 'الأدوية',
            'insurance_number' => 'رقم التأمين الصحي',
            'description' => 'الوصف',
            'type' => 'النوع',
            'value' => 'القيمة',
            // ===== حقول نموذج الطالب الإضافية =====
            'ministry_code' => 'كود الوزارة',
            'nationality' => 'الجنسية',
            'passport_number' => 'رقم جواز السفر',
            'previous_school' => 'المدرسة السابقة',
            'first_name_en' => 'الاسم الأول (إنجليزي)',
            'second_name_en' => 'اسم الأب (إنجليزي)',
            'third_name_en' => 'اسم الجد (إنجليزي)',
            'fourth_name_en' => 'الاسم الرابع (إنجليزي)',
            'family_name_en' => 'اسم العائلة (إنجليزي)',
            'insurance_start_date' => 'بداية التأمين',
            'insurance_end_date' => 'نهاية التأمين',
            'psychological_notes' => 'ملاحظات نفسية',
            'emergency_medical_notes' => 'ملاحظات طبية طارئة',
            'treatment_plan' => 'خطة العلاج',
            'previous_medical_reports' => 'تقارير طبية سابقة',
            'external_transfer_reason' => 'سبب النقل الخارجي',
            'external_transfer_notes' => 'ملاحظات النقل الخارجي',
            // ===== حقول نموذج العامل الإضافية =====
            'full_name_ar' => 'الاسم الكامل (عربي)',
            'full_name_en' => 'الاسم الكامل (إنجليزي)',
            'address_detail' => 'تفاصيل العنوان',
            'emergency_contact_name' => 'اسم جهة الطوارئ',
            'email_personal' => 'البريد الشخصي',
            'marital_status' => 'الحالة الاجتماعية',
            'military_status' => 'الحالة العسكرية',
            'public_service_status' => 'حالة الخدمة العامة',
            'number_of_children' => 'عدد الأطفال',
            'job_grade' => 'الدرجة الوظيفية',
            'contract_start' => 'بداية التعاقد',
            'contract_end' => 'نهاية التعاقد',
            'admin_notes' => 'ملاحظات إدارية',
            'qualification_year' => 'سنة التأهيل',
            'qualification_university' => 'جامعة التأهيل',
            'other_qualifications' => 'مؤهلات أخرى',
            'training_courses' => 'دورات تدريبية',
            'years_of_experience' => 'سنوات الخبرة',
            'work_history' => 'الخبرات السابقة',
            'promotions' => 'الحركات الوظيفية',
            'status_history' => 'تاريخ الحالات',
            'health_issues' => 'مشاكل صحية',
            'current_work_status' => 'حالة العمل الحالية',
            'current_status_reason' => 'سبب الحالة الحالية',
            'current_status_effective_date' => 'تاريخ سريان الحالة',
            'latest_hire_date' => 'آخر تاريخ تعيين',
            'last_working_day' => 'آخر يوم عمل',
            'can_rehire' => 'إمكانية إعادة التعيين',
        ];
        return $labels[$key] ?? self::humanizeAuditCode((string) $key);
    }

    /**
     * تنسيق تفاصيل السجل للعرض — ديناميكي بالكامل
     * كل مفتاح جديد يُضاف للـ details يظهر تلقائياً
     */
    public static function formatDetailsHtml($details, $format = 'inline') {
        if (empty($details) || !is_array($details)) return '<span class="text-muted">-</span>';

        // تنسيق diff_table: جدول مصغر (الحقل/قبل/بعد) — مثالي لعرض تغييرات التعديل بوضوح.
        // إذا لم توجد تغييرات من بنية changes/related_changes، يرجع للتنسيق العادي.
        if ($format === 'diff_table') {
            return self::formatDetailsDiffTable($details);
        }

        $parts = [];
        foreach ($details as $key => $val) {
            $label = self::getDetailKeyLabel($key);
            if ($key === 'audit_snapshot' && is_array($val)) {
                $parts[] = self::formatAuditSnapshotHtml($val);
                continue;
            }
            if (($key === 'changes' || $key === 'related_changes') && is_array($val)) {
                $changeRows = [];
                if ($key === 'related_changes') {
                    $changeRows[] = '<div class="text-muted mt-1"><i class="fas fa-cogs me-1"></i>تغييرات مرتبطة نفذها النظام:</div>';
                }
                foreach ($val as $field => $change) {
                    $fieldLabel = self::getDetailKeyLabel($field);
                    if (is_array($change) && array_key_exists('from', $change) && array_key_exists('to', $change)) {
                        $from = self::renderChangedDetailValue($change['from'], true);
                        $to = self::renderChangedDetailValue($change['to'], false);
                        $changeRows[] = '<div class="activity-change-row"><span class="fw-semibold">' . htmlspecialchars($fieldLabel) . '</span><span class="text-muted mx-1">من</span>' . $from . '<i class="fas fa-arrow-left text-muted mx-2"></i>' . $to . '</div>';
                    } else {
                        $changeRows[] = '<div><span class="fw-semibold">' . htmlspecialchars($fieldLabel) . ':</span> ' . self::displayDetailValue($change) . '</div>';
                    }
                }
                $parts[] = implode('', $changeRows);
                continue;
            } elseif (is_array($val)) {
                $val = self::displayDetailValue($val, (string) $key);
            } else {
                $val = self::displayDetailValue($val, (string) $key);
            }
            if ($format === 'badge') {
                $parts[] = '<span class="badge bg-light text-dark me-1">' . htmlspecialchars($label) . ': ' . $val . '</span>';
            } else {
                $parts[] = '<span class="text-muted">' . htmlspecialchars($label) . ':</span> ' . $val;
            }
        }
        return ($format === 'badge') ? implode(' ', $parts) : implode('<br>', $parts);
    }

    /**
     * تنسيق جدول مصغّر (الحقل/قبل/بعد) لعرض فروقات التعديل بوضوح.
     * - الحقول العادية (changes): جدول رئيسي.
     * - التغييرات المرتبطة (related_changes): جدول ثانوي بعنوان توضيحي.
     * - باقي المفاتيح (summary/description/...): تُعرض كـ inline فوق الجدول.
     */
    private static function formatDetailsDiffTable(array $details): string {
        $preParts = [];
        $mainRows = [];
        $relatedRows = [];

        foreach ($details as $key => $val) {
            if ($key === 'changes' && is_array($val)) {
                foreach ($val as $field => $change) {
                    $mainRows[] = self::buildDiffRow($field, $change);
                }
            } elseif ($key === 'related_changes' && is_array($val)) {
                foreach ($val as $field => $change) {
                    $relatedRows[] = self::buildDiffRow($field, $change);
                }
            } elseif ($key === 'audit_snapshot' && is_array($val)) {
                $preParts[] = '<div class="mb-1">' . self::formatAuditSnapshotHtml($val) . '</div>';
            } else {
                $label = self::getDetailKeyLabel($key);
                $displayVal = self::displayDetailValue($val, (string) $key);
                $preParts[] = '<div class="mb-1"><span class="text-muted">' . htmlspecialchars($label) . ':</span> ' . $displayVal . '</div>';
            }
        }

        $html = '';
        if ($preParts) {
            $html .= implode('', $preParts);
        }
        if ($mainRows) {
            $html .= self::renderDiffTable($mainRows);
        }
        if ($relatedRows) {
            $html .= '<div class="text-muted mt-2 mb-1"><i class="fas fa-cogs me-1"></i>تغييرات مرتبطة نفذها النظام:</div>';
            $html .= self::renderDiffTable($relatedRows);
        }
        return $html ?: '<span class="text-muted">-</span>';
    }

    private static function buildDiffRow(string $field, $change): array {
        $fieldLabel = self::getDetailKeyLabel($field);
        if (is_array($change) && array_key_exists('from', $change) && array_key_exists('to', $change)) {
            return [
                'field' => $fieldLabel,
                'from'  => self::renderChangedDetailValue($change['from'], true, $field),
                'to'    => self::renderChangedDetailValue($change['to'], false, $field),
            ];
        }
        // قيمة بسيطة (ليست from/to) — نعرضها في عمود "بعد" فقط
        return [
            'field' => $fieldLabel,
            'from'  => '<span class="text-muted">—</span>',
            'to'    => self::renderChangedDetailValue($change, false, $field),
        ];
    }

    private static function renderDiffTable(array $rows): string {
        $tbody = '';
        foreach ($rows as $r) {
            $tbody .= '<tr>'
                . '<td class="fw-semibold text-nowrap">' . htmlspecialchars($r['field']) . '</td>'
                . '<td>' . $r['from'] . '</td>'
                . '<td>' . $r['to'] . '</td>'
                . '</tr>';
        }
        return '<table class="table table-sm table-bordered mb-1 diff-table mb-0">'
            . '<thead class="table-light"><tr>'
            . '<th style="width:30%;">الحقل</th>'
            . '<th style="width:35%;">قبل</th>'
            . '<th style="width:35%;">بعد</th>'
            . '</tr></thead><tbody>' . $tbody . '</tbody></table>';
    }

    private static function displayDetailValue($value, ?string $field = null) {
        if ($value === null || $value === '') return '<span class="text-muted">فارغ</span>';
        if (is_bool($value)) return $value ? 'نعم' : 'لا';
        if (self::isBooleanDetailField($field) && in_array($value, [0, 1, '0', '1'], true)) {
            return (int) $value === 1 ? 'نعم' : 'لا';
        }
        if (is_array($value)) return self::displayStructuredDetailValue($value);
        $labels = [
            'active' => 'نشط', 'inactive' => 'معطل', 'graduated' => 'خريج',
            'enrolled' => 'مقيد', 'transferred' => 'منقول',
            'allowed' => 'مسموح', 'denied' => 'ممنوع',
            'father' => 'الأب', 'mother' => 'الأم',
            'brother' => 'الأخ', 'sister' => 'الأخت',
            'grandfather' => 'الجد', 'grandmother' => 'الجدة',
            'uncle' => 'العم أو الخال', 'aunt' => 'العمة أو الخالة',
            'present' => 'حاضر', 'absent' => 'غائب', 'excused_absent' => 'غياب بعذر',
            'approved' => 'معتمد', 'rejected' => 'مرفوض', 'not_required' => 'غير مطلوب',
            'external_transfer' => 'نقل خارجي', 'empty' => 'فارغ',
            'extra_data' => 'بيانات إضافية', 'extra_phones' => 'أرقام هاتف إضافية',
            'first_name_ar' => 'الاسم الأول (عربي)', 'second_name_ar' => 'اسم الأب (عربي)',
            'grade_id' => 'الصف',
            'male' => 'ذكر', 'female' => 'أنثى', 'muslim' => 'مسلم',
            'christian' => 'مسيحي', 'other' => 'أخرى',
            'on_duty' => 'على رأس العمل', 'off_duty' => 'غير على رأس العمل',
            'permanent' => 'دائم', 'temporary' => 'مؤقت', 'parttime' => 'جزئي',
            'single' => 'أعزب', 'married' => 'متزوج', 'divorced' => 'مطلق', 'widowed' => 'أرمل',
            'teacher' => 'معلم', 'specialist' => 'أخصائي', 'user' => 'مستخدم', 'cohort' => 'دفعة طلاب',
            'generating' => 'جارٍ التوليد', 'completed' => 'مكتمل', 'transport_manager' => 'مسؤول النقل',
            'promote' => 'ترقية', 'supervisor' => 'مشرف', 'student_affairs_manager' => 'مسؤول شؤون الطلاب',
            'admin' => 'إدارة النظام', 'external_teacher' => 'معلم خارجي', 'ar' => 'العربية', 'local' => 'محلي',
            'employee' => 'موظف', 'average_weeks' => 'متوسط الأسابيع', 'modern' => 'حديث', 'siblings' => 'الإخوة والأشقاء',
            'doctor' => 'طبيب', 'entry_template' => 'نموذج إدخال', 'librarian' => 'أمين المكتبة', 'direct' => 'مباشر',
            'super_admin' => 'المدير العام', 'open' => 'مفتوح', 'monthly' => 'شهري', 'locked' => 'مقفل',
            'zero' => 'صفر', 'weekly' => 'أسبوعي', 'graduate' => 'خريج', 'study' => 'دراسة', 'total' => 'الإجمالي',
            'error' => 'خطأ', 'closed' => 'مغلق', 'bulk_set_supervisor' => 'تعيين مشرف جماعي',
            'basic' => 'أساسي', 'roles_permissions_manager' => 'مسؤول الأدوار والصلاحيات', 'services' => 'الخدمات',
            'exclude' => 'استبعاد', 'final' => 'نهائي', 'practical' => 'عملي', 'behavior' => 'سلوك',
            'academics' => 'شؤون أكاديمية', 'integer' => 'عدد صحيح', 'draft' => 'مسودة',
            'two_decimals' => 'رقمان عشريان', 'exam' => 'اختبار', 'blocked' => 'محظور', 'pending' => 'قيد الانتظار',
            'missing_subject_link' => 'لا يوجد ربط للمادة',
            'password_revealed' => 'تم كشف كلمة المرور',
            'security_observation_not_undoable' => 'ملاحظة أمنية لا تقبل التراجع',
            'generated_content_lifecycle' => 'دورة حياة المحتوى المولّد',
            'generated_content_restore_not_enabled' => 'استعادة المحتوى المولّد غير مفعّلة',
            'generated_content_non_restorable' => 'المحتوى المولّد غير قابل للاستعادة',
            'lesson_content_restore_not_enabled' => 'استعادة محتوى الدرس غير مفعّلة',
            'published_exam_composite_restore_not_enabled' => 'استعادة الاختبار المنشور غير مفعّلة',
            'public_bearer_link_requires_explicit_revocation' => 'الرابط العام يتطلب إلغاءً صريحًا',
            'external_device_sync_not_undoable' => 'مزامنة الجهاز الخارجي لا تقبل التراجع',
            'credential_change_not_direct_undo' => 'تغيير بيانات الدخول لا يقبل التراجع المباشر',
            'Active portal role changed to super_admin' => 'تم تغيير الدور النشط في بوابة المستخدم إلى المدير العام',
            'Utilities::logAction' => 'مسجّل العمليات القديم للنظام',
            'A+' => 'A+','A-' => 'A-','B+' => 'B+','B-' => 'B-','AB+' => 'AB+','AB-' => 'AB-','O+' => 'O+','O-' => 'O-',
        ];
        $text = (string)$value;
        return htmlspecialchars($labels[$text] ?? $text);
    }

    private static function isBooleanDetailField(?string $field): bool
    {
        if ($field === null || $field === '') {
            return false;
        }

        return strncmp($field, 'is_', 3) === 0
            || strncmp($field, 'can_', 4) === 0
            || in_array($field, ['confirmed', 'enabled', 'active', 'direct_undo_available'], true);
    }

    /**
     * القيم المركبة تُلخّص في السجل بدلاً من طباعة JSON داخل الصف.
     * تبقى البيانات الكاملة محفوظة في details لأغراض المراجعة والتدقيق.
     */
    private static function displayStructuredDetailValue(array $value): string
    {
        $summary = self::describeStructuredDetailValue($value);
        return '<span class="badge bg-light text-dark border"><i class="fas fa-layer-group me-1"></i>'
            . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }

    private static function renderChangedDetailValue($value, bool $isBefore, ?string $field = null): string
    {
        if (is_array($value)) {
            $summary = self::describeStructuredDetailValue($value);
            $stateLabel = $isBefore ? 'بيانات سابقة' : 'بيانات جديدة';
            $stateClass = $isBefore ? 'border-danger text-danger' : 'border-success text-success';
            $icon = $isBefore ? 'fa-box-archive' : 'fa-box-open';

            return '<span class="badge bg-light border ' . $stateClass . '"><i class="fas ' . $icon . ' me-1"></i>'
                . htmlspecialchars($stateLabel . ' (' . $summary . ')', ENT_QUOTES, 'UTF-8')
                . '</span>';
        }

        $display = self::displayDetailValue($value, $field);
        if ($isBefore) {
            return '<del class="text-danger">' . $display . '</del>';
        }

        return '<ins class="text-success text-decoration-none">' . $display . '</ins>';
    }

    private static function formatAuditSnapshotHtml(array $snapshot): string
    {
        $items = [];
        foreach ($snapshot as $key => $value) {
            $label = self::getDetailKeyLabel($key);
            $description = is_array($value)
                ? self::describeStructuredDetailValue($value)
                : strip_tags(self::displayDetailValue($value, (string) $key));
            $items[] = $label . ': ' . $description;
        }

        $suffix = $items ? ' · ' . implode('، ', $items) : '';
        return '<span class="badge bg-light text-dark border"><i class="fas fa-shield-halved me-1"></i>لقطة تدقيق محفوظة'
            . htmlspecialchars($suffix, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }

    private static function describeStructuredDetailValue(array $value): string
    {
        $count = count($value);
        if ($count === 0) {
            return 'لا توجد بيانات';
        }

        if (self::isSequentialArray($value)) {
            foreach ($value as $item) {
                if (is_array($item)) {
                    return $count === 1 ? 'سجل واحد' : ($count === 2 ? 'سجلان' : $count . ' سجلات');
                }
            }
            return $count === 1 ? 'عنصر واحد' : ($count === 2 ? 'عنصران' : $count . ' عناصر');
        }

        return $count === 1 ? 'حقل واحد' : ($count === 2 ? 'حقلان' : $count . ' حقول');
    }

    private static function isSequentialArray(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $_) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }

        return true;
    }

    /**
     * يبني وصفًا موجزًا ومراجع فنية موحدة لصفوف سجل النظام العام.
     * تبقى التفاصيل الكاملة محفوظة وتُعرض عند الطلب من خلال formatDetailsHtml().
     *
     * @return array{summary:string,context:string,technical_reference:string}
     */
    public static function getOperationPresentation(array $log): array {
        $action = self::getActionLabel((string) ($log['action'] ?? ''));
        $targetType = self::getTargetLabel((string) ($log['target_type'] ?? ''));
        $targetName = trim((string) ($log['target_name'] ?? ''));

        $summary = 'تم تنفيذ عملية «' . ($action !== '' ? $action : 'غير محددة') . '»';
        if ($targetType !== '') {
            $summary .= ' على ' . $targetType;
        }
        if ($targetName !== '') {
            $summary .= ' «' . $targetName . '»';
        }

        $context = 'حُفظت بيانات العملية في سجل النظام للمراجعة والتتبع.';
        $references = [];
        if (!empty($log['id'])) {
            $references[] = 'سجل النشاط #' . (int) $log['id'];
        }
        if (!empty($log['target_id'])) {
            $references[] = 'مرجع البيانات #' . (int) $log['target_id'];
        }
        if (!empty($log['undo_id'])) {
            $references[] = 'سجل التراجع #' . (int) $log['undo_id'];
        }

        return [
            'summary' => $summary,
            'context' => $context,
            'technical_reference' => implode(' · ', $references),
        ];
    }

    public static function getLegacyDetailsHtml(array $log): string {
        $action = self::getActionLabel($log['action'] ?? '');
        $target = trim((string)($log['target_name'] ?? ''));
        if ($target === '' && !empty($log['target_id'])) $target = 'طالب #' . (int)$log['target_id'];
        $message = 'تم تنفيذ عملية ' . $action;
        if ($target !== '') $message .= ' على ' . $target;
        return '<span class="text-muted"><i class="fas fa-info-circle me-1"></i>' . htmlspecialchars($message) . '<br><small>هذا سجل قديم لم تُحفظ معه فروق الحقول.</small></span>';
    }
    
    /**
     * لون Badge حسب العملية
     */
    public static function getActionBadgeClass($action) {
        $classes = [
            'create' => 'bg-success',
            'update' => 'bg-primary',
            'delete' => 'bg-danger',
            'import' => 'bg-info',
            'export' => 'bg-info',
            'login' => 'bg-secondary',
            'logout' => 'bg-secondary',
            'reset' => 'bg-danger',
            'status_change' => 'bg-warning text-dark',
            'settings' => 'bg-purple',
            'link' => 'bg-success',
            'unlink' => 'bg-warning text-dark',
            'backup' => 'bg-primary',
            'restore' => 'bg-info',
            'undo' => 'bg-warning text-dark',
            'redo' => 'bg-primary',
        ];
        if (isset($classes[$action])) {
            return $classes[$action];
        }

        $action = strtolower((string) $action);
        if (preg_match('/(?:delete|reject|fail|cancel|withdraw|expire)/', $action)) {
            return 'bg-danger';
        }
        if (preg_match('/(?:archive|reverse|revoke|cleanup|reset)/', $action)) {
            return 'bg-warning text-dark';
        }
        if (preg_match('/(?:create|insert|save|activate|approve|publish|record|open)/', $action)) {
            return 'bg-success';
        }
        if (preg_match('/(?:update|change|map|assign|link|recalculate|review|submit)/', $action)) {
            return 'bg-primary';
        }
        if (preg_match('/(?:import|export|download|report|projection|sync|read)/', $action)) {
            return 'bg-info';
        }

        return 'bg-secondary';
    }
    
    /**
     * أيقونة حسب العملية
     */
    public static function getActionIcon($action) {
        $icons = [
            'create' => 'fa-plus-circle',
            'update' => 'fa-edit',
            'delete' => 'fa-trash-alt',
            'import' => 'fa-file-import',
            'export' => 'fa-file-export',
            'login' => 'fa-sign-in-alt',
            'logout' => 'fa-sign-out-alt',
            'reset' => 'fa-redo-alt',
            'status_change' => 'fa-toggle-on',
            'settings' => 'fa-cog',
            'link' => 'fa-link',
            'unlink' => 'fa-unlink',
            'backup' => 'fa-database',
            'restore' => 'fa-undo',
            'undo' => 'fa-rotate-left',
            'redo' => 'fa-rotate-right',
        ];
        if (isset($icons[$action])) {
            return $icons[$action];
        }

        $action = strtolower((string) $action);
        if (preg_match('/(?:delete|reject|fail|cancel|withdraw|expire)/', $action)) {
            return 'fa-times-circle';
        }
        if (preg_match('/(?:archive|reverse|revoke|cleanup|reset)/', $action)) {
            return 'fa-box-archive';
        }
        if (preg_match('/(?:finance|budget|voucher|payroll|fee)/', $action)) {
            return 'fa-coins';
        }
        if (preg_match('/(?:attendance|biometric|schedule)/', $action)) {
            return 'fa-calendar-check';
        }
        if (preg_match('/(?:staff|approval|leave|permission|discipline)/', $action)) {
            return 'fa-users';
        }
        if (preg_match('/(?:create|insert|save|activate|approve|publish|record|open)/', $action)) {
            return 'fa-check-circle';
        }
        if (preg_match('/(?:update|change|map|assign|link|recalculate|review|submit)/', $action)) {
            return 'fa-edit';
        }
        if (preg_match('/(?:import|export|download|report|projection|sync|read)/', $action)) {
            return 'fa-file-alt';
        }

        return 'fa-circle';
    }
}
