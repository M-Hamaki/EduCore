<?php
/**
 * كلاس إرسال الإشعارات الفورية (Push Notifications)
 * يستخدم مكتبة minishlink/web-push
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/push_config.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushNotification {
    
    private $db;
    private $webPush;
    
    /**
     * Get the school logo URL for push notification icons
     */
    private function getSchoolLogoUrl(): string {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'school_logo' LIMIT 1");
            $stmt->execute();
            $logo = $stmt->fetchColumn();
            if ($logo && file_exists(__DIR__ . '/../uploads/' . $logo)) {
                return '/uploads/' . $logo;
            }
        } catch (\Exception $e) {}
        return '/assets/img/logo.png';
    }

    public function __construct($db = null) {
        if ($db) {
            $this->db = $db;
        } else {
            $database = new Database();
            $this->db = $database->getConnection();
        }
        
        $auth = [
            'VAPID' => [
                'subject' => VAPID_SUBJECT,
                'publicKey' => VAPID_PUBLIC_KEY,
                'privateKey' => VAPID_PRIVATE_KEY,
            ],
        ];
        
        $this->webPush = new WebPush($auth, [], 30, [
            'verify' => false // للتطوير المحلي
        ]);
        $this->webPush->setAutomaticPadding(false);
    }
    
    /**
     * إرسال push لمستخدمين محددين
     * @param array $userIds قائمة معرفات المستخدمين
     * @param string $title عنوان الإشعار
     * @param string $body نص الإشعار
     * @param array $extra بيانات إضافية (url, icon, tag, etc.)
     * @return array نتائج الإرسال
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $extra = []): array {
        if (empty($userIds)) {
            $results = ['sent' => 0, 'failed' => 0, 'errors' => []];
            $this->auditDelivery($userIds, $title, $extra, $results, 'no_targets');
            return $results;
        }
        
        // جلب اشتراكات المستخدمين
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare("SELECT * FROM push_subscriptions WHERE user_id IN ($placeholders)");
        $stmt->execute(array_values($userIds));
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($subscriptions)) {
            $results = ['sent' => 0, 'failed' => 0, 'errors' => [], 'no_subscriptions' => true];
            $this->auditDelivery($userIds, $title, $extra, $results, 'no_subscriptions');
            return $results;
        }
        
        $payload = json_encode(array_merge([
            'title' => $title,
            'body' => $body,
            'icon' => $this->getSchoolLogoUrl(),
            'badge' => '/assets/img/badge.png',
        ], $extra));
        
        // إضافة كل اشتراك للطابور
        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'publicKey' => $sub['p256dh_key'],
                'authToken' => $sub['auth_key'],
            ]);
            $this->webPush->queueNotification($subscription, $payload);
        }
        
        // إرسال كل الإشعارات
        $results = ['sent' => 0, 'failed' => 0, 'errors' => []];
        $expiredEndpoints = [];
        
        foreach ($this->webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $results['sent']++;
            } else {
                $results['failed']++;
                $endpoint = $report->getEndpoint();
                $reason = $report->getReason();
                $results['errors'][] = $reason;
                
                // حذف الاشتراكات المنتهية (410 Gone أو 404 Not Found)
                $statusCode = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
                if (in_array($statusCode, [404, 410])) {
                    $expiredEndpoints[] = $endpoint;
                }
            }
        }
        
        // تنظيف الاشتراكات المنتهية
        if (!empty($expiredEndpoints)) {
            $this->removeExpiredSubscriptions($expiredEndpoints);
        }

        $this->auditDelivery($userIds, $title, $extra, $results, 'completed');
        
        return $results;
    }
    
    /**
     * إرسال push بناءً على إشعار النظام وأهدافه
     * @param int $notificationId معرف الإشعار في جدول notifications
     * @return array نتائج الإرسال
     */
    public function sendForNotification(int $notificationId): array {
        // جلب بيانات الإشعار
        $stmt = $this->db->prepare("SELECT * FROM notifications WHERE id = ?");
        $stmt->execute([$notificationId]);
        $notification = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$notification) {
            return ['sent' => 0, 'failed' => 0, 'errors' => ['الإشعار غير موجود']];
        }
        
        // تحديد المستخدمين المستهدفين
        $userIds = $this->resolveTargetUsers($notificationId, $notification['type']);
        
        // تحديد رابط الإشعار بحسب النوع
        $urlMap = [
            'student' => '/student/',
            'teacher' => '/teacher/',
            'specialist' => '/specialist/',
            'public' => '/'
        ];
        
        $extra = [
            'url' => $urlMap[$notification['type']] ?? '/',
            'notification_id' => $notificationId,
            'tag' => 'notif-' . $notificationId,
        ];
        
        // إضافة حالة الأولوية
        if ($notification['priority'] === 'urgent') {
            $extra['actions'] = [
                ['action' => 'open', 'title' => 'فتح الآن']
            ];
        }
        
        return $this->sendToUsers($userIds, $notification['title'], $notification['message'], $extra);
    }
    
    /**
     * تحليل أهداف الإشعار وإرجاع قائمة user_ids
     */
    private function resolveTargetUsers(int $notificationId, string $type): array {
        // إشعار عام = كل المستخدمين
        if ($type === 'public') {
            $stmt = $this->db->query("SELECT DISTINCT user_id FROM push_subscriptions");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // جلب الأهداف
        $stmt = $this->db->prepare("SELECT target_type, target_id FROM notification_targets WHERE notification_id = ?");
        $stmt->execute([$notificationId]);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($targets)) return [];
        
        $userIds = [];
        
        foreach ($targets as $target) {
            switch ($target['target_type']) {
                case 'student':
                    $userIds[] = $target['target_id'];
                    break;
                    
                case 'class':
                    $stmt = $this->db->prepare("SELECT u.id FROM users u WHERE u.class_id = ? AND u.role = 'student' AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id=u.id AND sp.enrollment_status <> 'enrolled')");
                    $stmt->execute([$target['target_id']]);
                    $userIds = array_merge($userIds, $stmt->fetchAll(PDO::FETCH_COLUMN));
                    break;
                    
                case 'grade':
                    $stmt = $this->db->prepare("SELECT u.id FROM users u JOIN classes c ON u.class_id = c.id WHERE c.grade_id = ? AND u.role = 'student' AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id=u.id AND sp.enrollment_status <> 'enrolled')");
                    $stmt->execute([$target['target_id']]);
                    $userIds = array_merge($userIds, $stmt->fetchAll(PDO::FETCH_COLUMN));
                    break;
                    
                case 'stage':
                    if ($type === 'student') {
                        $stmt = $this->db->prepare("SELECT u.id FROM users u JOIN classes c ON u.class_id = c.id JOIN grades g ON c.grade_id = g.id WHERE g.stage_id = ? AND u.role = 'student' AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id=u.id AND sp.enrollment_status <> 'enrolled')");
                    } else {
                        // للمعلمين والأخصائيين: المرتبطين بالمرحلة
                        $role = ($type === 'teacher') ? 'teacher' : 'specialist';
                        $stmt = $this->db->prepare("SELECT DISTINCT u.id
                            FROM users u
                            WHERE u.status = 'active'
                              AND EXISTS (
                                  SELECT 1 FROM user_role_assignments ura
                                  WHERE ura.user_id = u.id AND ura.role_key = ? AND ura.status = 'active'
                              )
                              AND (
                                  EXISTS (
                                      SELECT 1
                                      FROM user_class_access uca
                                      JOIN classes c ON c.id = uca.class_id
                                      JOIN grades g ON g.id = c.grade_id
                                      WHERE uca.user_id = u.id
                                        AND g.stage_id = ?
                                  )
                                  OR EXISTS (
                                      SELECT 1
                                      FROM teacher_subject_assignments tsa
                                      LEFT JOIN grades tg ON tg.id = tsa.grade_id
                                      LEFT JOIN classes tc ON tc.id = tsa.class_id
                                      LEFT JOIN grades cg ON cg.id = tc.grade_id
                                      WHERE tsa.teacher_id = u.id
                                        AND tsa.is_active = 1
                                        AND COALESCE(tg.stage_id, cg.stage_id) = ?
                                  )
                                  OR EXISTS (
                                      SELECT 1
                                      FROM teacher_subjects ts
                                      JOIN subject_grade_assignments sga ON sga.subject_id = ts.subject_id
                                      LEFT JOIN grades sgag ON sgag.id = sga.grade_id
                                      WHERE ts.teacher_id = u.id
                                        AND sga.is_active = 1
                                        AND (sga.stage_id = ? OR sgag.stage_id = ?)
                                  )
                              )");
                    }
                    $stmt->execute($type === 'student' ? [$target['target_id']] : [$role, $target['target_id'], $target['target_id'], $target['target_id'], $target['target_id']]);
                    $userIds = array_merge($userIds, $stmt->fetchAll(PDO::FETCH_COLUMN));
                    break;
                    
                case 'teacher':
                    $userIds[] = $target['target_id'];
                    break;
                    
                case 'specialist':
                    $userIds[] = $target['target_id'];
                    break;
                    
                case 'subject':
                    $stmt = $this->db->prepare("SELECT teacher_id FROM teacher_subjects WHERE subject_id = ?");
                    $stmt->execute([$target['target_id']]);
                    $userIds = array_merge($userIds, $stmt->fetchAll(PDO::FETCH_COLUMN));
                    break;
            }
        }
        
        return array_unique(array_filter($userIds));
    }
    
    /**
     * حذف الاشتراكات المنتهية
     */
    private function removeExpiredSubscriptions(array $endpoints): void {
        $endpoints = array_values(array_unique(array_filter($endpoints, 'is_string')));
        if (!$endpoints) return;

        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($endpoints), '?'));
            $stmt = $this->db->prepare("DELETE FROM push_subscriptions WHERE endpoint IN ($placeholders)");
            $stmt->execute($endpoints);
            $deleted = $stmt->rowCount();

            $hashes = array_map(static fn(string $endpoint): string => hash('sha256', $endpoint), $endpoints);
            sort($hashes, SORT_STRING);
            (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
                'cleanup',
                'push_subscription',
                null,
                'Expired push subscriptions',
                [
                    'deleted_count' => $deleted,
                    'endpoint_count' => count($endpoints),
                    'endpoint_set_hash' => hash('sha256', implode('|', $hashes)),
                    'reason' => 'provider_404_or_410',
                ]
            );
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function auditDelivery(array $userIds, string $title, array $extra, array $results, string $outcome): void {
        $targetIds = array_values(array_unique(array_map('intval', $userIds)));
        sort($targetIds, SORT_NUMERIC);
        (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
            'send',
            'push_notification',
            isset($extra['notification_id']) ? (int) $extra['notification_id'] : null,
            'Push notification delivery',
            [
                'outcome' => $outcome,
                'target_count' => count($targetIds),
                'target_set_hash' => hash('sha256', implode(',', $targetIds)),
                'title_hash' => hash('sha256', $title),
                'sent_count' => (int) ($results['sent'] ?? 0),
                'failed_count' => (int) ($results['failed'] ?? 0),
            ],
            ['result' => ((int) ($results['failed'] ?? 0)) > 0 ? 'partial' : 'success']
        );
    }
    
    /**
     * إرسال push بناءً على تنبيه مناسبة
     * @param int $occasionId معرف المناسبة في جدول occasion_notifications
     * @return array نتائج الإرسال
     */
    public function sendForOccasion(int $occasionId): array {
        $stmt = $this->db->prepare("SELECT * FROM occasion_notifications WHERE id = ?");
        $stmt->execute([$occasionId]);
        $occasion = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$occasion) {
            return ['sent' => 0, 'failed' => 0, 'errors' => ['تنبيه المناسبة غير موجود']];
        }
        
        // تحديد المستخدمين حسب target_type
        $userIds = $this->resolveOccasionTargets($occasion['target_type']);
        
        $extra = [
            'url' => '/',
            'tag' => 'occasion-' . $occasionId,
            'icon' => $this->getSchoolLogoUrl(),
        ];
        
        $title = ($occasion['emoji'] ? $occasion['emoji'] . ' ' : '') . $occasion['title'];
        
        return $this->sendToUsers($userIds, $title, $occasion['message'], $extra);
    }
    
    /**
     * تحليل أهداف تنبيه المناسبة وإرجاع قائمة user_ids
     */
    private function resolveOccasionTargets(string $targetType): array {
        switch ($targetType) {
            case 'all':
                $stmt = $this->db->query("SELECT DISTINCT user_id FROM push_subscriptions");
                return $stmt->fetchAll(PDO::FETCH_COLUMN);
                
            case 'student':
                $stmt = $this->db->query("SELECT DISTINCT ps.user_id FROM push_subscriptions ps JOIN users u ON ps.user_id = u.id WHERE u.role = 'student' AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id=u.id AND sp.enrollment_status <> 'enrolled')");
                return $stmt->fetchAll(PDO::FETCH_COLUMN);
                
            case 'teacher':
                $stmt = $this->db->query("SELECT DISTINCT ps.user_id
                    FROM push_subscriptions ps
                    JOIN users u ON ps.user_id = u.id
                    WHERE u.status = 'active'
                      AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active')");
                return $stmt->fetchAll(PDO::FETCH_COLUMN);
                
            case 'both':
                $stmt = $this->db->query("SELECT DISTINCT ps.user_id
                    FROM push_subscriptions ps
                    JOIN users u ON ps.user_id = u.id
                    WHERE u.status = 'active'
                      AND EXISTS (
                          SELECT 1 FROM user_role_assignments ura
                          WHERE ura.user_id = u.id AND ura.role_key IN ('teacher', 'specialist') AND ura.status = 'active'
                      )");
                return $stmt->fetchAll(PDO::FETCH_COLUMN);
                
            default:
                return [];
        }
    }
    
    /**
     * عدد المشتركين في الإشعارات الفورية
     */
    public function getSubscriptionCount(): int {
        return (int) $this->db->query("SELECT COUNT(DISTINCT user_id) FROM push_subscriptions")->fetchColumn();
    }
    
    /**
     * عدد الأجهزة المسجلة
     */
    public function getDeviceCount(): int {
        return (int) $this->db->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
    }
}
