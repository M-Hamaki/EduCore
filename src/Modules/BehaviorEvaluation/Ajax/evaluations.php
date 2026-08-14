<?php

// Loaded only after the shared AJAX authentication, CSRF and permission gates.
$resolveCurrentStudentClass = static function (int $studentId) use ($db, $currentAcademicYearId): ?array {
    if ($studentId <= 0) {
        return null;
    }
    if ($currentAcademicYearId > 0) {
        $stmt = $db->prepare("SELECT c.id, c.name
            FROM student_enrollments se
            JOIN classes c ON c.id = se.class_id
            WHERE se.student_id = ? AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
            LIMIT 1");
        $stmt->execute([$studentId, $currentAcademicYearId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    $stmt = $db->prepare('SELECT c.id, c.name FROM users u JOIN classes c ON c.id = u.class_id WHERE u.id = ? LIMIT 1');
    $stmt->execute([$studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

switch ($action) {
        // Add evaluation
        case 'add_evaluation':
            error_log("=== ADD_EVALUATION START ===");
            error_log("Processing add_evaluation action");
            error_log("POST data for add_evaluation: " . print_r($requestPost, true));
            error_log("Current user ID: " . $currentUserId);
            error_log("Current user role: " . $role);

            // التحقق من أن التقييمات مسموحة (إلا للأدمن)
            if ($role !== 'admin') {
                $evaluation_check = Utilities::areEvaluationsAllowed($db);
                if (!$evaluation_check['allowed']) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => $evaluation_check['message']
                    ]);
                    break;
                }
            }

            // Check if this is custom points (admin only)
            $is_custom_points = isset($requestPost['custom_points_enabled']) && $requestPost['custom_points_enabled'] === '1';

            if ($is_custom_points) {
                // Admin and Specialist custom points handling
                if ($role !== 'admin' && $role !== 'specialist') {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'فقط الأدمن والأخصائي يمكنهما تعديل النقاط يدوياً'
                    ]);
                    break;
                }

                $required_fields = ['student_id', 'custom_points', 'points_action', 'reason'];
                $missing_fields = [];

                foreach ($required_fields as $field) {
                    if (!isset($requestPost[$field]) || empty($requestPost[$field])) {
                        $missing_fields[] = $field;
                    }
                }

                if (!empty($missing_fields)) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'بيانات غير مكتملة للنقاط المخصصة: ' . implode(', ', $missing_fields)
                    ]);
                    break;
                }

                try {
                    // Get student's class automatically
                    $user = new User($db);
                    $student_class = $resolveCurrentStudentClass((int)$requestPost['student_id']);

                    if (!$student_class || !isset($student_class['id'])) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => 'لم يتم العثور على فصل الطالب'
                        ]);
                        break;
                    }

                    // Check if specialist or teacher can access this student's class
                    if ($role === 'specialist') {
                        $staffPortalContext->assertStudentAllowed((int)$requestPost['student_id']);
                    } elseif ($role === 'teacher') {
                        $assigned_classes = $user->getAssignedClasses($currentUserId);
                        $allowed_class_ids = array_column($assigned_classes, 'id');

                        if (!in_array($student_class['id'], $allowed_class_ids)) {
                            header('Content-Type: application/json');
                            echo json_encode([
                                'success' => false,
                                'message' => 'ليس لديك صلاحية للوصول لهذا الطالب'
                            ]);
                            break;
                        }
                    }

                    $student_id = (int)$requestPost['student_id'];
                    $custom_points = (int)$requestPost['custom_points'];
                    $points_action = $requestPost['points_action']; // 'add' or 'subtract'
                    $reason = $requestPost['reason'];

                    // Select the appropriate evaluation type based on action
                    if ($points_action === 'add') {
                        // Use "إضافة نقاط" type
                        $eval_type_query = "SELECT id FROM evaluation_types WHERE name = 'إضافة نقاط'";
                    } else {
                        // Use "خصم نقاط" type
                        $eval_type_query = "SELECT id FROM evaluation_types WHERE name = 'خصم نقاط'";
                    }

                    $eval_type_stmt = $db->query($eval_type_query);
                    $eval_type = $eval_type_stmt->fetch();

                    if (!$eval_type) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => 'نوع التقييم المخصص غير متوفر'
                        ]);
                        break;
                    }

                    // Make points negative if action is subtract
                    if ($points_action === 'subtract') {
                        $custom_points = -$custom_points;
                    }

                    // Insert as custom evaluation with proper evaluation_type_id
                    $query = "INSERT INTO evaluations
                              SET student_id=:student_id, teacher_id=:teacher_id, evaluation_type_id=:evaluation_type_id,
                                  class_id=:class_id, custom_points=:custom_points, reason=:reason,
                                  academic_year_id=:academic_year_id, date_created=NOW()";

                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
                    $stmt->bindParam(':teacher_id', $currentUserId, PDO::PARAM_INT);
                    $stmt->bindParam(':evaluation_type_id', $eval_type['id'], PDO::PARAM_INT);
                    $stmt->bindParam(':class_id', $student_class['id'], PDO::PARAM_INT);
                    $stmt->bindParam(':custom_points', $custom_points, PDO::PARAM_INT);
                    $stmt->bindParam(':reason', $reason, PDO::PARAM_STR);
                    $stmt->bindValue(':academic_year_id', $currentAcademicYearId, PDO::PARAM_INT);

                    if ($stmt->execute()) {
                        // Calculate new total points
                        $evaluation = new Evaluation($db);
                        $total_points = $evaluation->getStudentTotalPoints($student_id);

                        error_log("Custom points added successfully, new total: $total_points");

                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'total_points' => $total_points,
                            'message' => 'تم تعديل النقاط بنجاح'
                        ]);
                    } else {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => 'فشل في تعديل النقاط'
                        ]);
                    }
                } catch (Exception $e) {
                    error_log("Exception in custom points: " . $e->getMessage());
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'حدث خطأ أثناء تعديل النقاط. يرجى المحاولة مرة أخرى.'
                    ]);
                }
            } else {
                // Normal evaluation handling
                $required_fields = ['student_id', 'evaluation_type_id'];
                $missing_fields = [];

                foreach ($required_fields as $field) {
                    if (!isset($requestPost[$field]) || empty($requestPost[$field])) {
                        $missing_fields[] = $field;
                    }
                }

                if (!empty($missing_fields)) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'بيانات غير مكتملة: ' . implode(', ', $missing_fields)
                    ]);
                    break;
                }

                try {
                    // Get student's class automatically
                    $user = new User($db);
                    $student_class = $resolveCurrentStudentClass((int)$requestPost['student_id']);

                    if (!$student_class || !isset($student_class['id'])) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => 'لم يتم العثور على فصل الطالب'
                        ]);
                        break;
                    }

                    // Check if specialist or teacher can access this student's class
                    if ($role === 'specialist') {
                        $staffPortalContext->assertStudentAllowed((int)$requestPost['student_id']);
                    } elseif ($role === 'teacher') {
                        $assigned_classes = $user->getAssignedClasses($currentUserId);
                        $allowed_class_ids = array_column($assigned_classes, 'id');

                        if (!in_array($student_class['id'], $allowed_class_ids)) {
                            header('Content-Type: application/json');
                            echo json_encode([
                                'success' => false,
                                'message' => 'ليس لديك صلاحية للوصول لهذا الطالب'
                            ]);
                            break;
                        }
                    }

                    $evaluation = new Evaluation($db);
                    $evaluation->student_id = (int)$requestPost['student_id'];
                    $evaluation->teacher_id = $currentUserId;
                    $evaluation->evaluation_type_id = (int)$requestPost['evaluation_type_id'];
                    $evaluation->class_id = (int)$student_class['id'];

                    error_log("Creating normal evaluation with: student_id={$evaluation->student_id}, teacher_id={$evaluation->teacher_id}, evaluation_type_id={$evaluation->evaluation_type_id}, class_id={$evaluation->class_id}");

                    if ($evaluation->create()) {
                        $total_points = $evaluation->getStudentTotalPoints($evaluation->student_id);

                        error_log("Evaluation created successfully, total points: $total_points");

                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'total_points' => $total_points,
                            'message' => 'تم إضافة التقييم بنجاح'
                        ]);
                    } else {
                        $error_msg = isset($evaluation->last_error) ? $evaluation->last_error : 'Unknown error';
                        error_log("Failed to create evaluation: $error_msg");

                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => 'فشل في إضافة التقييم'
                        ]);
                    }
                } catch (Exception $e) {
                    error_log("Exception in add_evaluation: " . $e->getMessage());

                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'حدث خطأ أثناء إضافة التقييم. يرجى المحاولة مرة أخرى.'
                    ]);
                }
            }
            break;        // Get student evaluations
        case 'get_student_evaluations':
            error_log("=== GET_STUDENT_EVALUATIONS START ===");
            error_log("REQUEST URI: " . ($_SERVER['REQUEST_URI'] ?? 'not set'));
            error_log("Query string: " . ($_SERVER['QUERY_STRING'] ?? 'not set'));
            error_log("All GET params: " . print_r($requestGet, true));
            error_log("student_id from GET: " . (isset($requestGet['student_id']) ? $requestGet['student_id'] : 'missing'));

            // Check database connection first
            if ($db === null) {
                error_log("Database connection is null!");
                sendJsonResponse([
                    'success' => false,
                    'message' => 'فشل في الاتصال بقاعدة البيانات',
                    'error_type' => 'database_connection',
                    'error_details' => defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE ? 'Database connection is null' : null
                ]);
            } else {
                error_log("Database connection OK");
            }

            if (isset($requestGet['student_id']) && !empty($requestGet['student_id'])) {
                $student_id = intval($requestGet['student_id']);
                error_log("Processing student_id: " . $student_id);

                if ($student_id <= 0) {
                    sendJsonResponse(['success' => false, 'message' => 'معرف الطالب غير صحيح']);
                }

                try {
                    $evaluation = new Evaluation($db);
                    $stmt = $evaluation->readByStudent($student_id);
                    $evaluations = [];

                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $evaluations[] = $row;
                        error_log("Found evaluation: " . json_encode($row));
                    }

                    error_log("Total evaluations found: " . count($evaluations));

                    // Get total points
                    $total_points = $evaluation->getStudentTotalPoints($student_id);
                    error_log("Total points calculated: " . $total_points);

                    sendJsonResponse([
                        'success' => true,
                        'evaluations' => $evaluations,
                        'total_points' => $total_points,
                        'student_id' => $student_id, // For debugging
                        'debug_info' => defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE ? [
                            'evaluations_count' => count($evaluations),
                            'database_connected' => $db !== null
                        ] : null
                    ]);
                } catch (Exception $e) {
                    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
                        error_log("Error in get_student_evaluations: " . $e->getMessage());
                        error_log("Student ID: " . $student_id);
                        error_log("Stack trace: " . $e->getTraceAsString());
                    }
                    sendJsonResponse([
                        'success' => false,
                        'message' => 'حدث خطأ أثناء جلب التقييمات',
                        'error_type' => 'evaluation_fetch_error',
                        'error_details' => defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE ? $e->getMessage() : null,
                        'student_id' => $student_id
                    ]);
                }
            } else {
                sendJsonResponse(['success' => false, 'message' => 'معرف الطالب مفقود أو فارغ']);
            }
            break;

        // Delete evaluation
        case 'delete_evaluation':
            if (isset($requestPost['evaluation_id'])) {
                $evaluation_id = (int)$requestPost['evaluation_id'];
                $evaluation = new Evaluation($db);
                $evaluation->id = $evaluation_id;

                // Get student_id before deletion for total points calculation
                $evaluation->readOne();
                $student_id = $evaluation->student_id;
                // التحقق من الملكية/النطاق:
                // - admin: يملك صلاحية مطلقة.
                // - teacher: فقط التقييمات التي أنشأها بنفسه.
                // - specialist: فقط التقييمات الخاصة بطلاب في فصوله المسندة.
                if ($role === 'teacher' && (int)$evaluation->teacher_id !== $currentUserId) {
                    header('Content-Type: application/json');
                    echo json_encode(['success'=>false,'message'=>'غير مصرح بحذف هذا التقييم']);
                    break;
                }
                if ($role === 'specialist') {
                    try {
                        $staffPortalContext->assertStudentAllowed((int)$student_id);
                    } catch (RuntimeException $e) {
                        header('Content-Type: application/json');
                        echo json_encode(['success'=>false,'message'=>'غير مصرح بحذف هذا التقييم (خارج فصولك المسندة)']);
                        break;
                    }
                }

                if ($evaluation->delete()) {
                    Utilities::logAction('delete_evaluation', 'حذف تقييم '.$evaluation_id, $currentUserId);
                    // Get updated total points
                    $total_points = $evaluation->getStudentTotalPoints($student_id);

                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'total_points' => $total_points,
                        'message' => 'تم حذف التقييم بنجاح'
                    ]);
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'فشل في حذف التقييم']);
                }
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'معرف التقييم مفقود']);
            }
            break;

        // ===== معالجات صفحة تقييمات المعلمين (admin) =====
        // جلب كل تقييمات معلم محدد لعرضها في المودال
        case 'get_teacher_evaluations_for_admin':
            if ($role !== 'admin' && $role !== 'specialist') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'غير مصرح']);
                break;
            }
            $teacher_id = isset($requestPost['teacher_id']) ? (int)$requestPost['teacher_id'] : 0;
            if ($teacher_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'معرف المعلم غير صالح']);
                break;
            }
            try {
                $teacherEvaluationWhere = ['e.teacher_id = ?'];
                $teacherEvaluationParams = [$teacher_id];
                if ($role === 'specialist') {
                    $teacherAllowedClassIds = $staffPortalContext->allowedClassIds() ?? [];
                    if ($teacherAllowedClassIds === []) {
                        echo json_encode(['success' => true, 'data' => []]);
                        break;
                    }
                    $teacherClassMarks = implode(',', array_fill(0, count($teacherAllowedClassIds), '?'));
                    $teacherEvaluationWhere[] = "e.class_id IN ({$teacherClassMarks})";
                    $teacherEvaluationParams = array_merge($teacherEvaluationParams, $teacherAllowedClassIds);
                }
                if ($currentAcademicYearId > 0) {
                    $teacherEvaluationWhere[] = '(e.academic_year_id = ? OR e.academic_year_id IS NULL)';
                    $teacherEvaluationParams[] = $currentAcademicYearId;
                }
                $teacherEvaluationWhereSql = implode(' AND ', $teacherEvaluationWhere);
                $query = "SELECT e.id, e.date_created, e.custom_points, e.reason,
                                 e.student_id, e.evaluation_type_id,
                                 s.name AS student_name,
                                 c.name AS class_name,
                                 et.name AS type_name, et.type AS et_type, et.points AS type_points
                          FROM evaluations e
                          JOIN users s ON e.student_id = s.id
                          LEFT JOIN classes c ON e.class_id = c.id
                          JOIN evaluation_types et ON e.evaluation_type_id = et.id
                          WHERE {$teacherEvaluationWhereSql}
                          ORDER BY e.date_created DESC";
                $stmt = $db->prepare($query);
                $stmt->execute($teacherEvaluationParams);
                $rows = [];
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    // حساب النوع والنقاط المعروضة (مراعاة custom_points)
                    if ($r['custom_points'] !== null) {
                        $display_type = ($r['custom_points'] >= 0) ? 'positive' : 'negative';
                        $display_points = abs((int)$r['custom_points']);
                    } else {
                        $display_type = $r['et_type'];
                        $display_points = (int)$r['type_points'];
                    }
                    $rows[] = [
                        'id'             => (int)$r['id'],
                        'student_name'   => $r['student_name'],
                        'class_name'     => $r['class_name'] ?? '—',
                        'type_name'      => $r['type_name'],
                        'display_type'   => $display_type,
                        'display_points' => $display_points,
                        'reason'         => $r['reason'],
                        'date_created'   => date('Y-m-d H:i', strtotime($r['date_created'])),
                        'evaluation_type_id' => (int)$r['evaluation_type_id'],
                        'custom_points'  => $r['custom_points'],
                    ];
                }
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'data' => $rows]);
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'فشل تحميل التقييمات']);
            }
            break;

        // تعديل تقييم معلم (admin فقط) — يدعم نوع التقييم + النقاط المخصصة + السبب
        case 'update_teacher_evaluation':
            if ($role !== 'admin' && $role !== 'specialist') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'غير مصرح']);
                break;
            }
            $evaluation_id = isset($requestPost['evaluation_id']) ? (int)$requestPost['evaluation_id'] : 0;
            $evaluation_type_id = isset($requestPost['evaluation_type_id']) ? (int)$requestPost['evaluation_type_id'] : 0;
            $custom_points_raw = $requestPost['custom_points'] ?? '';
            $reason = isset($requestPost['reason']) ? trim($requestPost['reason']) : null;

            if ($evaluation_id <= 0 || $evaluation_type_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
                break;
            }
            try {
                // التحقق من وجود التقييم وجلب student_id
                $evaluation = new Evaluation($db);
                $evaluation->id = $evaluation_id;
                $evaluation->readOne();
                if (!$evaluation->student_id) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'التقييم غير موجود']);
                    break;
                }
                $student_id = $evaluation->student_id;

                // تحضير القيم: custom_points فارغ = NULL (استخدام نقاط النوع)
                if ($custom_points_raw === '' || $custom_points_raw === null) {
                    $custom_points = null;
                } else {
                    $custom_points = (int)$custom_points_raw;
                }
                $reason_val = ($reason === '') ? null : $reason;

                $upd = $db->prepare("UPDATE evaluations
                                     SET evaluation_type_id = ?, custom_points = ?, reason = ?
                                     WHERE id = ?");
                $upd->execute([$evaluation_type_id, $custom_points, $reason_val, $evaluation_id]);

                Utilities::logAction('update_teacher_evaluation',
                    'تعديل تقييم رقم ' . $evaluation_id,
                    $currentUserId);

                $total_points = $evaluation->getStudentTotalPoints($student_id);

                header('Content-Type: application/json');
                echo json_encode([
                    'success'      => true,
                    'message'      => 'تم تعديل التقييم بنجاح',
                    'total_points' => $total_points
                ]);
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'فشل في تعديل التقييم']);
            }
            break;

        // Bulk delete all evaluations for a student (admin only)
        case 'delete_all_evaluations':
            if ($role !== 'admin') {
                header('Content-Type: application/json');
                echo json_encode(['success'=>false,'message'=>'غير مصرح']);
                break;
            }
            if (!isset($requestPost['student_id'])) {
                header('Content-Type: application/json');
                echo json_encode(['success'=>false,'message'=>'معرف الطالب مفقود']);
                break;
            }
            $student_id = (int)$requestPost['student_id'];
            if ($student_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success'=>false,'message'=>'معرف الطالب غير صالح']);
                break;
            }
            try {
                // Use transaction for safety
                $db->beginTransaction();
                // Count evaluations first
                $countStmt = $db->prepare('SELECT COUNT(*) as cnt FROM evaluations WHERE student_id = ?');
                $countStmt->execute([$student_id]);
                $count = (int)$countStmt->fetchColumn();
                // Delete evaluations
                $delStmt = $db->prepare('DELETE FROM evaluations WHERE student_id = ?');
                $delStmt->execute([$student_id]);
                $db->commit();
                // Log action
                Utilities::logAction('delete_all_evaluations', 'حذف جميع التقييمات للطالب '.$student_id.' (عدد: '.$count.')', $currentUserId);
                header('Content-Type: application/json');
                echo json_encode(['success'=>true,'message'=>'تم حذف جميع التقييمات ('.$count.')','removed_count'=>$count,'total_points'=>0]);
            } catch (Exception $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                header('Content-Type: application/json');
                echo json_encode(['success'=>false,'message'=>'فشل في حذف جميع التقييمات','error'=> (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) ? $e->getMessage() : null]);
            }
            break;

        // Delete evaluation from reports page
        case 'delete_evaluation_from_report':
            if (!isset($requestPost['evaluation_id'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'معرف التقييم مفقود']);
                break;
            }
            $evaluation_id = (int)$requestPost['evaluation_id'];
            $evaluation = new Evaluation($db);
            $result = $evaluation->deleteAndRecalculatePoints($evaluation_id);

            if ($result) {
                Utilities::logAction('delete_evaluation_from_report', 'حذف تقييم '.$evaluation_id.' من صفحة التقارير', $currentUserId);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'تم حذف التقييم بنجاح.',
                    'student_id' => $result['student_id'],
                    'new_total_points' => $result['new_total_points']
                ]);
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'فشل في حذف التقييم: ' . ($evaluation->last_error ?? 'خطأ غير معروف')]);
            }
            break;

        // Bulk delete evaluations for specialist
        case 'bulk_delete_evaluations_specialist':
            header('Content-Type: application/json');

            if (!isset($requestPost['selected_evaluations']) || !is_array($requestPost['selected_evaluations'])) {
                echo json_encode(['success' => false, 'message' => 'لم يتم تحديد تقييمات للحذف']);
                break;
            }

            $selected_ids = array_filter($requestPost['selected_evaluations'], 'is_numeric');

            if (empty($selected_ids)) {
                echo json_encode(['success' => false, 'message' => 'لم يتم تحديد تقييمات صالحة للحذف']);
                break;
            }

            // Get specialist's assigned classes for security
            $specialistId = (int)$requestSession['user_id'];
            $userModel = new User($db);
            $assigned = $userModel->getAssignedClasses($specialistId);
            $allowedClassIds = array_map('intval', array_column($assigned, 'id'));

            if (empty($allowedClassIds)) {
                echo json_encode(['success' => false, 'message' => 'لا توجد فصول مسندة إليك']);
                break;
            }

            try {
                // First, verify all evaluations belong to assigned classes
                $placeholders = str_repeat('?,', count($selected_ids) - 1) . '?';
                $class_placeholders = str_repeat('?,', count($allowedClassIds) - 1) . '?';

                $verify_query = "SELECT e.id FROM evaluations e
                                JOIN users s ON e.student_id = s.id
                                WHERE e.id IN ($placeholders)
                                AND s.class_id IN ($class_placeholders)";
                $verify_stmt = $db->prepare($verify_query);
                $verify_params = array_merge($selected_ids, $allowedClassIds);
                $verify_stmt->execute($verify_params);
                $verified_ids = $verify_stmt->fetchAll(PDO::FETCH_COLUMN);

                if (empty($verified_ids)) {
                    echo json_encode(['success' => false, 'message' => 'لا توجد تقييمات صالحة للحذف']);
                    break;
                }

                // Delete each evaluation and recalculate points
                $evaluation = new Evaluation($db);
                $deleted_count = 0;
                $failed_count = 0;

                foreach ($verified_ids as $eval_id) {
                    $result = $evaluation->deleteAndRecalculatePoints((int)$eval_id);
                    if ($result) {
                        $deleted_count++;
                    } else {
                        $failed_count++;
                    }
                }

                if ($deleted_count > 0) {
                    Utilities::logAction('bulk_delete_evaluations_specialist', 'حذف جماعي لـ '.$deleted_count.' تقييم من صفحة التقارير', $currentUserId);

                    $message = "تم حذف " . $deleted_count . " تقييم بنجاح.";
                    if ($failed_count > 0) {
                        $message .= " فشل حذف " . $failed_count . " تقييم.";
                    }

                    echo json_encode([
                        'success' => true,
                        'message' => $message,
                        'deleted' => $deleted_count,
                        'failed' => $failed_count
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'فشل حذف جميع التقييمات المحددة']);
                }
            } catch (Exception $e) {
                error_log("bulk_delete_evaluations_admin error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء الحذف الجماعي. يرجى المحاولة مرة أخرى.']);
            }
            break;

        // Bulk delete evaluations - Admin version
        case 'bulk_delete_evaluations_admin':
            header('Content-Type: application/json; charset=utf-8');

            try {
                // Verify admin role
                if (!isset($requestSession['role']) || $requestSession['role'] !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بتنفيذ هذا الإجراء'], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                // Get evaluation IDs from POST data
                $selected_evaluations_raw = $requestPost['selected_evaluations'] ?? null;

                $selected_evaluations = [];

                // If it's a JSON string, decode it
                if (is_string($selected_evaluations_raw) && !empty($selected_evaluations_raw)) {
                    $decoded = json_decode($selected_evaluations_raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $selected_evaluations = $decoded;
                    }
                } elseif (is_array($selected_evaluations_raw)) {
                    $selected_evaluations = $selected_evaluations_raw;
                }

                // Validate that we have IDs
                if (empty($selected_evaluations)) {
                    echo json_encode(['success' => false, 'message' => 'لم يتم تحديد أي تقييمات للحذف'], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                // Filter to ensure only numeric IDs
                $evaluation_ids = array_filter($selected_evaluations, function($id) {
                    return is_numeric($id);
                });

                if (empty($evaluation_ids)) {
                    echo json_encode(['success' => false, 'message' => 'معرفات التقييمات غير صالحة'], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                // Initialize counters
                $deleted_count = 0;
                $failed_count = 0;

                // Create Evaluation instance
                $evaluation = new Evaluation($db);

                // Delete each evaluation and recalculate points
                foreach ($evaluation_ids as $eval_id) {
                    $result = $evaluation->deleteAndRecalculatePoints($eval_id);
                    if ($result) {
                        $deleted_count++;
                    } else {
                        $failed_count++;
                    }
                }

                // Prepare response message
                if ($deleted_count > 0 && $failed_count === 0) {
                    echo json_encode([
                        'success' => true,
                        'message' => "تم حذف {$deleted_count} تقييم بنجاح",
                        'deleted' => $deleted_count,
                        'failed' => $failed_count
                    ], JSON_UNESCAPED_UNICODE);
                } elseif ($deleted_count > 0 && $failed_count > 0) {
                    echo json_encode([
                        'success' => true,
                        'message' => "تم حذف {$deleted_count} تقييم، فشل حذف {$failed_count}",
                        'deleted' => $deleted_count,
                        'failed' => $failed_count
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(['success' => false, 'message' => 'فشل حذف جميع التقييمات المحددة'], JSON_UNESCAPED_UNICODE);
                }
            } catch (Exception $e) {
                error_log("bulk_delete_evaluations_specialist error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء الحذف الجماعي. يرجى المحاولة مرة أخرى.'], JSON_UNESCAPED_UNICODE);
            }
            exit;

        // Adjust total points (admin only)
        case 'adjust_total_points':
            // Ensure the user is admin
            if (!isset($requestSession['role']) || $requestSession['role'] !== 'admin') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'غير مصرح لك بتنفيذ هذه العملية']);
                break;
            }

            // Check for required parameters
            if (isset($requestPost['student_id']) && isset($requestPost['new_total_points']) && isset($requestPost['reason'])) {
                $student_id = (int)$requestPost['student_id'];
                $new_total_points = (int)$requestPost['new_total_points'];
                $reason = trim($requestPost['reason']);
                $admin_id = $currentUserId; // enforce session id

                // Create a special evaluation type for admin adjustment
                $evaluation = new Evaluation($db);
                $evaluation->student_id = $student_id;
                $evaluation->teacher_id = $admin_id;

                // Get current total points
                $user = new User($db);
                $student_data = $user->getTotalPoints($student_id);
                $current_total_points = isset($student_data['total_points']) ? intval($student_data['total_points']) : 0;

                // Calculate the difference to apply
                $points_difference = $new_total_points - $current_total_points;

                if ($points_difference !== 0) {
                    // Create an adjustment entry
                    $evaluation_type = new EvaluationType($db);
                    $adjustment_type = $evaluation_type->getOrCreateAdjustmentType();

                    $evaluation->evaluation_type_id = $adjustment_type['id'];
                    $evaluation->custom_points = abs($points_difference);
                    $evaluation->reason = $reason . ' (تعديل إداري)';
                    $evaluation->date_created = date('Y-m-d H:i:s');

                    // Set the type based on whether we're adding or subtracting points
                    $evaluation->type = ($points_difference > 0) ? 'positive' : 'negative';

                    // Get student's current class
                    $student_class = $user->getStudentClass($student_id);
                    $evaluation->class_id = $student_class ? $student_class['id'] : null;

                    if ($evaluation->create()) {
                        Utilities::logAction('adjust_total_points', 'تعديل إجمالي نقاط الطالب '.$student_id.' إلى '.$new_total_points, $currentUserId);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'message' => 'تم تعديل إجمالي النقاط بنجاح',
                            'new_total_points' => $new_total_points
                        ]);
                    } else {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء تعديل النقاط']);
                    }
                } else {
                    // No change in points
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'لم يتم تغيير النقاط (نفس القيمة الحالية)']);
                }
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'معلومات غير مكتملة لتعديل النقاط']);
            }
            break;

        // Delete all evaluations for a specific student
        case 'delete_all_student_evaluations':
            if (isset($requestPost['student_id']) && !empty($requestPost['student_id'])) {
                $student_id = (int)$requestPost['student_id'];

                try {
                    $evaluation = new Evaluation($db);

                    // Delete all evaluations for this student
                    $query = "DELETE FROM evaluations
                        WHERE student_id = :student_id"
                        . ($currentAcademicYearId > 0
                            ? " AND (academic_year_id = :academic_year_id OR academic_year_id IS NULL)"
                            : "");
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':student_id', $student_id);
                    if ($currentAcademicYearId > 0) {
                        $stmt->bindValue(':academic_year_id', $currentAcademicYearId, PDO::PARAM_INT);
                    }

                    if ($stmt->execute()) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'message' => 'تم حذف جميع التقييمات بنجاح'
                        ]);
                    } else {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => 'فشل في حذف التقييمات'
                        ]);
                    }
                } catch (Exception $e) {
                    error_log("Error deleting all student evaluations: " . $e->getMessage());
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => 'خطأ في حذف التقييمات'
                    ]);
                }
            } else {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'معرف الطالب مطلوب'
                ]);
            }
            break;

        // Export student evaluations to Excel
        case 'export_student_evaluations':
            if (isset($requestGet['student_id']) && !empty($requestGet['student_id'])) {
                $student_id = (int)$requestGet['student_id'];

                try {
                    // Fix path for excel_handler
                    $excel_handler_path = dirname(__DIR__) . '/classes/excel_handler.php';
                    if (!file_exists($excel_handler_path)) {
                        throw new Exception('Excel handler class not found at: ' . $excel_handler_path);
                    }
                    require_once $excel_handler_path;

                    $evaluation = new Evaluation($db);
                    $user = new User($db);

                    // Get student info
                    $user->id = $student_id;
                    if (!$user->readOne()) {
                        throw new Exception('Student not found');
                    }
                    $student_name = $user->name;

                    // Get evaluations using corrected method
                    $stmt = $evaluation->readByStudent($student_id);
                    $evaluations = [];

                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $evaluations[] = [
                            'id' => $row['id'],
                            'evaluation_name' => $row['evaluation_name'],
                            'points' => $row['display_points'], // Use display_points which includes correct sign
                            'custom_points' => $row['custom_points'],
                            'type' => $row['display_type'],
                            'teacher_name' => $row['teacher_name'],
                            'class_name' => $row['class_name'] ?? 'غير محدد',
                            'date_created' => $row['date_created'],
                            'reason' => $row['reason'] ?? ''
                        ];
                    }

                    // Check if ExcelHandler class exists
                    if (!class_exists('ExcelHandler')) {
                        throw new Exception('ExcelHandler class not found');
                    }

                    // Export to Excel
                    $excel = new ExcelHandler();
                    $filename = "تقييمات_الطالب_" . $student_name . "_" . date('Y-m-d');

                    // Check if method exists
                    if (!method_exists($excel, 'exportStudentEvaluations')) {
                        throw new Exception('exportStudentEvaluations method not found in ExcelHandler');
                    }

                    $excel->exportStudentEvaluations($evaluations, $student_name, $filename);

                } catch (Exception $e) {
                    error_log("Error exporting student evaluations: " . $e->getMessage());
                    header('Content-Type: text/plain; charset=utf-8');
                    echo "خطأ في تصدير البيانات: " . $e->getMessage();
                    exit;
                }
            } else {
                header('Content-Type: text/plain');
                echo "معرف الطالب مطلوب";
            }
            break;
}
