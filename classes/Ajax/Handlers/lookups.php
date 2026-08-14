<?php

// Loaded only after the shared AJAX authentication, CSRF and permission gates.
switch ($action) {
        // Get students by class
        case 'get_students_by_class':
            if (isset($requestPost['class_id']) && !empty($requestPost['class_id'])) {
                $class_id = $requestPost['class_id'];
                $user = new User($db);

                try {
                    // Check if the user is a specialist or teacher and verify class assignment
                    if ($role === 'specialist') {
                        $staffPortalContext->assertClassAllowed((int)$class_id);
                        $studentsStmt = $db->prepare("SELECT u.id, u.name, se.class_id
                            FROM student_enrollments se JOIN users u ON u.id = se.student_id
                            WHERE se.academic_year_id = ? AND se.class_id = ? AND se.enrollment_status = 'enrolled'
                              AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
                            ORDER BY u.name");
                        $studentsStmt->execute([$currentAcademicYearId, (int)$class_id]);
                        $students_data = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
                    } elseif ($role === 'teacher') {
                        $user_id = $requestSession['user_id'];
                        $assigned_classes = $user->getAssignedClasses($user_id);
                        $allowed_class_ids = array_column($assigned_classes, 'id');

                        if (!in_array($class_id, $allowed_class_ids)) {
                            header('Content-Type: application/json');
                            echo json_encode([]);
                            exit;
                        }

                        // For teachers, get students filtered by teacher access
                        $students_data = $user->getStudentsByClassForTeacher($class_id, $user_id);
                    } else {
                        // For admin, use the original method
                        $students_data = $user->getStudentsByClass($class_id);
                    }

                    // Return JSON response (direct array like admin page)
                    header('Content-Type: application/json');
                    echo json_encode($students_data);
                } catch (Exception $e) {
                    error_log("get_all_students error: " . $e->getMessage());
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'تعذّر تحميل قائمة الطلاب.']);
                }
            } else {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Class ID not provided']);
            }
            break;

        // Get teachers by class or grade
        case 'get_teachers_by_class':
            $class_id = $requestPost['class_id'] ?? $requestGet['class_id'] ?? null;
            $grade_id = $requestPost['grade_id'] ?? $requestGet['grade_id'] ?? null;
            $user = new User($db);

            try {
                if (!empty($class_id)) {
                    if ($role === 'specialist') {
                        $staffPortalContext->assertClassAllowed((int)$class_id);
                    }
                    $teachers_data = $user->getTeachersByClass($class_id);
                } elseif (!empty($grade_id)) {
                    $stmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.status, u.role
                        FROM user_class_access uca
                        JOIN classes c ON c.id = uca.class_id
                        JOIN users u ON u.id = uca.user_id
                        WHERE c.grade_id = ? AND u.deleted_at IS NULL
                        ORDER BY u.name");
                    $stmt->execute([(int)$grade_id]);
                    $teachers_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $teachers_data = $user->getAllByRole('teacher');
                }

                header('Content-Type: application/json');
                echo json_encode(is_array($teachers_data) ? array_values($teachers_data) : []);
            } catch (Exception $e) {
                error_log("get_teachers_by_class error: " . $e->getMessage());
                header('Content-Type: application/json');
                echo json_encode([]);
            }
            break;
              // Get all students
        case 'get_all_students':
            if ($role === 'specialist') {
                $lookupClassIds = $staffPortalContext->allowedClassIds() ?? [];
                if ($lookupClassIds === []) {
                    $students_array = [];
                } else {
                    $lookupMarks = implode(',', array_fill(0, count($lookupClassIds), '?'));
                    $studentsStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.status, se.class_id
                        FROM student_enrollments se JOIN users u ON u.id = se.student_id
                        WHERE se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                          AND se.class_id IN ({$lookupMarks}) AND u.role = 'student' AND u.deleted_at IS NULL
                        ORDER BY u.name");
                    $studentsStmt->execute(array_merge([$currentAcademicYearId], $lookupClassIds));
                    $students_array = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } else {
                $user = new User($db);
                $students_array = $user->getAllByRole('student');
            }

            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode($students_array);
            break;

        // Get all teachers and specialists
        case 'get_all_teachers':
            if ($role === 'specialist') {
                $scopeService = new StaffAcademicScopeService($db);
                $teacherIds = $scopeService->allowedTeacherIds(
                    $staffPortalContext->userId(),
                    $currentAcademicYearId,
                    $staffPortalContext->assignedRole()
                );
                if ($teacherIds === []) {
                    $all_array = [];
                } else {
                    $teacherMarks = implode(',', array_fill(0, count($teacherIds), '?'));
                    $teacherStmt = $db->prepare("SELECT u.id, u.name, 'teacher' AS role, u.status
                        FROM users u
                        WHERE u.id IN ({$teacherMarks})
                          AND EXISTS (SELECT 1 FROM user_role_assignments ura WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active')
                        ORDER BY u.name");
                    $teacherStmt->execute($teacherIds);
                    $all_array = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } else {
                $user = new User($db);
                $teachers_array = $user->getAllByRole('teacher');
                $specialists_array = $user->getAllByRole('specialist');
                $all_array = array_merge($teachers_array, $specialists_array);
            }

            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode($all_array);
            break;

        // Get students from specialist's assigned classes
        case 'get_specialist_students':
            if (isset($requestPost['specialist_id'])) {
                $specialist_id = (int)$requestPost['specialist_id'];

                // Get students from all classes that the specialist has evaluations in
                $query = "SELECT DISTINCT u.id, u.name
                         FROM users u
                         JOIN evaluations e ON u.id = e.student_id
                         WHERE u.role = 'student'
                         AND e.class_id IN (
                             SELECT DISTINCT class_id
                             FROM evaluations
                             WHERE teacher_id = ?
                         )
                         ORDER BY u.name";

                $stmt = $db->prepare($query);
                $stmt->execute([$specialist_id]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

                header('Content-Type: application/json');
                echo json_encode($students);
            } else {
                header('Content-Type: application/json');
                echo json_encode([]);
            }
            break;

        // Get evaluation types
        case 'get_evaluation_types':
            $eval_type = new EvaluationType($db);
            $types_stmt = $eval_type->readAll();
            $types = [];

            while ($row = $types_stmt->fetch(PDO::FETCH_ASSOC)) {
                $types[] = $row;
            }

            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode($types);
            break;

        // Get classrooms
        case 'get_classrooms':
            $grade_id = $requestPost['grade_id'] ?? $requestGet['grade_id'] ?? null;
            if (!empty($grade_id)) {
                $query = "SELECT DISTINCT c.id, c.name FROM classes c WHERE c.grade_id = ? AND (c.academic_year_id = ? OR c.academic_year_id IS NULL) ORDER BY c.display_order, c.name";
                $stmt = $db->prepare($query);
                $stmt->execute([(int)$grade_id, $currentAcademicYearId]);
                $classrooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $query = "SELECT DISTINCT c.id, c.name FROM classes c WHERE (c.academic_year_id = ? OR c.academic_year_id IS NULL) ORDER BY c.display_order, c.name";
                $stmt = $db->prepare($query);
                $stmt->execute([$currentAcademicYearId]);
                $classrooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode($classrooms);
            break;

        // Get student data for editing
        case 'get_student':
            if (isset($requestGet['id'])) {
                $student_id = $requestGet['id'];
                $user = new User($db);
                $user->id = $student_id;

                if ($user->readOne()) {
                    // Return student data
                    $student_data = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'class_id' => $user->class_id
                    ];
                    // Include password only for admin
                    if (isset($requestSession['role']) && $requestSession['role'] === 'admin') {
                        $student_data['password'] = $user->password;
                    }

                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'student' => $student_data]);
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'لم يتم العثور على الطالب']);
                }
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'معرف الطالب غير موجود']);
            }
            break;

        // Get teacher data for editing
        case 'get_teacher':
            if (isset($requestGet['id'])) {
                $teacher_id = $requestGet['id'];
                $user = new User($db);
                $user->id = $teacher_id;

                if ($user->readOne()) {
                    // Return teacher data
                    $teacher_data = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'class_id' => $user->class_id
                    ];

                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'teacher' => $teacher_data]);
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'لم يتم العثور على المعلم']);
                }
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'معرف المعلم غير موجود']);
            }
            break;

        // Get student's class
        case 'get_student_class':
            if (isset($requestGet['student_id'])) {
                $student_id = $requestGet['student_id'];
                $user = new User($db);
                $student_class = $user->getStudentClass($student_id);

                if ($student_class) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'class_id' => $student_class['id'], 'class_name' => $student_class['name']]);
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'لم يتم العثور على فصل الطالب']);
                }
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'لم يتم تحديد معرف الطالب']);
            }
            break;

        // البحث عن أشقاء محتملين
        case 'find_siblings':
            header('Content-Type: application/json');
            $studentId = (int)($requestGet['student_id'] ?? 0);
            $secondNameAr = $requestGet['second_name_ar'] ?? '';
            $familyNameAr = $requestGet['family_name_ar'] ?? '';
            $thirdNameAr = $requestGet['third_name_ar'] ?? '';
            if (!$studentId) {
                echo json_encode(['success' => false, 'message' => 'معرف الطالب مطلوب']);
                break;
            }
            $user = new User($db);
            $candidates = $user->findPotentialSiblings($studentId, $secondNameAr, $thirdNameAr, $familyNameAr);
            echo json_encode(['success' => true, 'candidates' => $candidates]);
            break;

        // البحث اليدوي عن طلاب لربطهم كأشقاء
        case 'search_students_for_sibling':
            header('Content-Type: application/json');
            $studentId = (int)($requestGet['student_id'] ?? 0);
            $searchTerm = $requestGet['search'] ?? '';
            if (!$studentId || empty(trim($searchTerm))) {
                echo json_encode(['success' => false, 'message' => 'بيانات البحث غير كاملة']);
                break;
            }
            $user = new User($db);
            $results = $user->searchStudentsForSibling($studentId, $searchTerm);
            echo json_encode(['success' => true, 'students' => $results]);
            break;

        // البحث التلقائي عن صلات القرابة (أبناء عم / عمة / خال / خالة)
        case 'find_kinship':
            header('Content-Type: application/json');
            $studentId = (int)($requestGet['student_id'] ?? 0);
            $secondNameAr = $requestGet['second_name_ar'] ?? '';
            $thirdNameAr = $requestGet['third_name_ar'] ?? '';
            $familyNameAr = $requestGet['family_name_ar'] ?? '';
            if (!$studentId) {
                echo json_encode(['success' => false, 'message' => 'معرف الطالب مطلوب']);
                break;
            }
            $user = new User($db);
            $candidates = $user->findPotentialKinship($studentId, $secondNameAr, $thirdNameAr, $familyNameAr);
            echo json_encode(['success' => true, 'candidates' => $candidates]);
            break;

        // ربط صلة قرابة بين طالبين
        case 'link_kinship':
            header('Content-Type: application/json');
            $studentId = (int)($requestPost['student_id'] ?? 0);
            $relativeId = (int)($requestPost['relative_id'] ?? 0);
            $kinshipName = trim($requestPost['kinship_name'] ?? '');

            if (!$studentId || !$relativeId || empty($kinshipName)) {
                echo json_encode(['success' => false, 'message' => 'بيانات غير كاملة']);
                break;
            }

            try {
                require_once $base_path . 'classes/StudentProfileRepository.php';
                require_once $base_path . 'classes/StudentRelationshipService.php';
                $service = new StudentRelationshipService($db, new StudentProfileRepository($db));
                $message = $service->link($studentId, $relativeId, $kinshipName);
                echo json_encode(['success' => true, 'message' => $message]);
            } catch (Throwable $e) {
                error_log("link_kinship error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء إضافة القرابة. يرجى المحاولة مرة أخرى.']);
            }
            break;

        // Role-scoped global search across the approved read projection.
        case 'global_deep_search':
            $q = trim($requestGet['q'] ?? $requestPost['q'] ?? '');
            require_once $base_path . 'src/Modules/Search/Contracts/GlobalSearchReadRepository.php';
            require_once $base_path . 'src/Modules/Search/Infrastructure/PdoGlobalSearchReadRepository.php';
            require_once $base_path . 'src/Modules/Search/Application/GlobalSearchQueryService.php';

            try {
                $repository = new \EduCore\Modules\Search\Infrastructure\PdoGlobalSearchReadRepository($db);
                $searchService = new \EduCore\Modules\Search\Application\GlobalSearchQueryService($repository);
                $results = $searchService->search(
                    $q,
                    is_array($globalSearchCapabilities ?? null) ? $globalSearchCapabilities : [],
                    $currentAcademicYearId,
                    $staffPortalContext->allowedClassIds(),
                    5
                );
                sendJsonResponse(['success' => true, 'data' => $results]);
            } catch (Throwable $e) {
                error_log('global_search query failed: ' . $e->getMessage());
                sendJsonResponse(
                    ['success' => false, 'message' => 'تعذر تحميل نتائج البحث الآن. أعد المحاولة.'],
                    500
                );
            }

}
