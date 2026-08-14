<?php

// Loaded only after the shared AJAX authentication, CSRF and permission gates.
switch ($action) {
        case 'get_user_services':
            $userId = isset($requestGet['user_id']) ? intval($requestGet['user_id']) : 0;
            $userRole = isset($requestGet['role']) ? $requestGet['role'] : '';
            if (!$userId || !in_array($userRole, ['student', 'teacher'])) {
                sendJsonResponse(['success' => false, 'message' => 'بيانات غير صالحة']);
            }
            try {
                $stmt = $db->prepare("SELECT services, override_stage FROM user_services WHERE user_id = ? AND role = ?");
                $stmt->execute([$userId, $userRole]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $userServices = null;
                $overrideStage = false;
                if ($row) {
                    $userServices = json_decode($row['services'], true);
                    $overrideStage = (bool)$row['override_stage'];
                }
                // Get stage services for comparison (فصل الطالب من تسجيل العام الحالي)
                $stageServices = [];
                if ($userRole === 'student') {
                    $ayId = AcademicYear::currentId($db);
                    if ($ayId > 0) {
                        $sq = "SELECT s.services FROM users u
                               LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                               LEFT JOIN classes c ON c.id = se.class_id
                               LEFT JOIN grades g ON c.grade_id = g.id
                               LEFT JOIN stages s ON g.stage_id = s.id
                               WHERE u.id = ? AND s.status = 'active'";
                        $ss = $db->prepare($sq);
                        $ss->execute([$ayId, $userId]);
                    } else {
                        $sq = "SELECT s.services FROM users u
                               LEFT JOIN classes c ON u.class_id = c.id
                               LEFT JOIN grades g ON c.grade_id = g.id
                               LEFT JOIN stages s ON g.stage_id = s.id
                               WHERE u.id = ? AND s.status = 'active'";
                        $ss = $db->prepare($sq);
                        $ss->execute([$userId]);
                    }
                    $sr = $ss->fetch(PDO::FETCH_ASSOC);
                    if ($sr && !empty($sr['services'])) {
                        $stageServices = json_decode($sr['services'], true) ?: [];
                    }
                } else {
                    $sq = "SELECT DISTINCT s.teacher_services FROM stages s
                           INNER JOIN grades g ON s.id = g.stage_id
                           INNER JOIN classes c ON g.id = c.grade_id
                           INNER JOIN user_class_access uca ON c.id = uca.class_id
                           WHERE uca.user_id = ? AND s.status = 'active'";
                    $ss = $db->prepare($sq);
                    $ss->execute([$userId]);
                    $rows = $ss->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        if (!empty($r['teacher_services'])) {
                            $svcs = json_decode($r['teacher_services'], true);
                            if (is_array($svcs)) $stageServices = array_merge($stageServices, $svcs);
                        }
                    }
                    $stageServices = array_values(array_unique($stageServices));
                }
                sendJsonResponse([
                    'success' => true,
                    'user_services' => $userServices,
                    'override_stage' => $overrideStage,
                    'stage_services' => $stageServices
                ]);
            } catch (PDOException $e) {
                error_log("get_user_services error: " . $e->getMessage());
                sendJsonResponse(['success' => false, 'message' => 'تعذّر تحميل الخدمات المخصصة.']);
            }
            break;

        case 'save_user_services':
            $userId = isset($requestPost['user_id']) ? intval($requestPost['user_id']) : 0;
            $userRole = isset($requestPost['role']) ? $requestPost['role'] : '';
            $services = isset($requestPost['services']) ? $requestPost['services'] : [];
            if (!$userId || !in_array($userRole, ['student', 'teacher'])) {
                sendJsonResponse(['success' => false, 'message' => 'بيانات غير صالحة']);
            }
            try {
                $ownsTransaction = !$db->inTransaction();
                if ($ownsTransaction) $db->beginTransaction();
                $servicesJson = json_encode(array_values($services));
                $beforeStmt = $db->prepare('SELECT * FROM user_services WHERE user_id = ? AND role = ? FOR UPDATE');
                $beforeStmt->execute([$userId, $userRole]);
                $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $stmt = $db->prepare("INSERT INTO user_services (user_id, role, services, override_stage, updated_by)
                                      VALUES (?, ?, ?, 1, ?)
                                      ON DUPLICATE KEY UPDATE services = VALUES(services), override_stage = 1, updated_by = VALUES(updated_by)");
                $stmt->execute([$userId, $userRole, $servicesJson, $currentUserId]);
                $afterStmt = $db->prepare('SELECT * FROM user_services WHERE user_id = ? AND role = ?');
                $afterStmt->execute([$userId, $userRole]);
                $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
                if (!$after) throw new RuntimeException('User service assignment could not be reloaded.');
                $audit = new \EduCore\Modules\Operations\Audit\AuditService($db);
                if ($before === null) {
                    $audit->recordInsert('user_service', 'user_services', (int)$after['id'], 'خدمات مستخدم #' . $userId, $after, 'إضافة تخصيص خدمات مستخدم');
                } elseif ($before != $after) {
                    $audit->recordUpdate('user_service', 'user_services', (int)$after['id'], 'خدمات مستخدم #' . $userId, $before, $after, 'تعديل تخصيص خدمات مستخدم');
                }
                if ($ownsTransaction) $db->commit();
                sendJsonResponse(['success' => true, 'message' => 'تم حفظ تخصيص الخدمات بنجاح']);
            } catch (Throwable $e) {
                if (isset($ownsTransaction) && $ownsTransaction && $db->inTransaction()) $db->rollBack();
                error_log("save_user_services error: " . $e->getMessage());
                sendJsonResponse(['success' => false, 'message' => 'تعذّر حفظ الخدمات المخصصة.']);
            }
            break;

        case 'reset_user_services':
            $userId = isset($requestPost['user_id']) ? intval($requestPost['user_id']) : 0;
            $userRole = isset($requestPost['role']) ? $requestPost['role'] : '';
            if (!$userId || !in_array($userRole, ['student', 'teacher'])) {
                sendJsonResponse(['success' => false, 'message' => 'بيانات غير صالحة']);
            }
            try {
                $ownsTransaction = !$db->inTransaction();
                if ($ownsTransaction) $db->beginTransaction();
                $beforeStmt = $db->prepare('SELECT * FROM user_services WHERE user_id = ? AND role = ? FOR UPDATE');
                $beforeStmt->execute([$userId, $userRole]);
                $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($before) {
                    $db->prepare("DELETE FROM user_services WHERE id = ?")->execute([(int)$before['id']]);
                    (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordDelete(
                        'user_service', 'user_services', (int)$before['id'], 'خدمات مستخدم #' . $userId, $before, 'إعادة تعيين خدمات مستخدم'
                    );
                }
                if ($ownsTransaction) $db->commit();
                sendJsonResponse(['success' => true, 'message' => 'تم إعادة التعيين للإعدادات الافتراضية']);
            } catch (Throwable $e) {
                if (isset($ownsTransaction) && $ownsTransaction && $db->inTransaction()) $db->rollBack();
                error_log("reset_user_services error: " . $e->getMessage());
                sendJsonResponse(['success' => false, 'message' => 'تعذّر إعادة تعيين الخدمات.']);
            }
            break;
}
