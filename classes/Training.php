<?php
/**
 * فئة نظام التدريب والتطوير المهني
 * Training & Professional Development System Class
 */
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';

class Training {
    
    private $db;

    /**
     * الحد الأقصى لعدد محاولات الاختبار لكل وحدة.
     * يمنع إعادة المحاولة بعد تجاوز هذا العدد أو بعد النجاح (passed = 1).
     */
    public const MAX_ATTEMPTS_PER_UNIT = 3;

    public function __construct($db) {
        $this->db = $db;
    }
    
    // =====================================================
    // مساعدات ثنائية اللغة - Bilingual Helpers
    // =====================================================
    
    /**
     * Get localized value based on display language
     */
    public static function getLocalizedValue($item, $field, $lang = 'ar') {
        if ($lang === 'en') {
            $enField = $field . '_en';
            if (!empty($item[$enField])) {
                return $item[$enField];
            }
        }
        return $item[$field] ?? '';
    }
    
    /**
     * Get display direction for a language
     */
    public static function getDirection($lang) {
        return $lang === 'en' ? 'ltr' : 'rtl';
    }
    
    /**
     * Get text alignment for a language
     */
    public static function getTextAlign($lang) {
        return $lang === 'en' ? 'left' : 'right';
    }
    
    /**
     * Get language label
     */
    public static function getLanguageLabel($lang) {
        return $lang === 'en' ? 'English' : 'العربية';
    }
    
    /**
     * Get language badge HTML
     */
    public static function getLanguageBadge($lang) {
        if ($lang === 'en') {
            return '<span class="badge bg-info"><i class="fas fa-globe me-1"></i>EN</span>';
        }
        return '<span class="badge bg-success"><i class="fas fa-globe me-1"></i>عربي</span>';
    }
    
    // =====================================================
    // البرامج التدريبية - Programs
    // =====================================================
    
    public function getPrograms($activeOnly = false) {
        $sql = "SELECT p.*, 
                (SELECT COUNT(*) FROM training_courses WHERE program_id = p.id" . ($activeOnly ? " AND is_active = 1" : "") . ") as course_count
                FROM training_programs p";
        if ($activeOnly) $sql .= " WHERE p.is_active = 1";
        $sql .= " ORDER BY p.sort_order, p.name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getProgram($id) {
        $stmt = $this->db->prepare("SELECT * FROM training_programs WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function createProgram($data) {
        $stmt = $this->db->prepare("INSERT INTO training_programs (name, name_en, description, description_en, icon, color, is_active, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $data['name'], $data['name_en'] ?? null,
            $data['description'] ?? null, $data['description_en'] ?? null,
            $data['icon'] ?? 'fa-graduation-cap',
            $data['color'] ?? '#198754', $data['is_active'] ?? 1, $data['sort_order'] ?? 0,
            $data['created_by'] ?? null
        ]);
    }
    
    public function updateProgram($id, $data) {
        $stmt = $this->db->prepare("UPDATE training_programs SET name = ?, name_en = ?, description = ?, description_en = ?, icon = ?, color = ?, is_active = ?, sort_order = ? WHERE id = ?");
        return $stmt->execute([
            $data['name'], $data['name_en'] ?? null,
            $data['description'] ?? null, $data['description_en'] ?? null,
            $data['icon'] ?? 'fa-graduation-cap',
            $data['color'] ?? '#198754', $data['is_active'] ?? 1, $data['sort_order'] ?? 0, $id
        ]);
    }
    
    public function deleteProgram($id) {
        $stmt = $this->db->prepare("DELETE FROM training_programs WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    public function toggleProgramStatus($id, $status) {
        $stmt = $this->db->prepare("UPDATE training_programs SET is_active = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }
    
    // =====================================================
    // الدورات التدريبية - Courses
    // =====================================================
    
    public function getCourses($programId = null, $activeOnly = false, $limit = null, $offset = 0) {
        $sql = "SELECT c.*, p.name as program_name, p.name_en as program_name_en, p.icon as program_icon, p.color as program_color,
                (SELECT COUNT(*) FROM training_units WHERE course_id = c.id" . ($activeOnly ? " AND is_active = 1" : "") . ") as unit_count,
                (SELECT COUNT(*) FROM training_enrollments WHERE course_id = c.id) as enrollment_count
                FROM training_courses c
                JOIN training_programs p ON c.program_id = p.id";
        $where = [];
        $params = [];
        if ($programId) { $where[] = "c.program_id = ?"; $params[] = $programId; }
        if ($activeOnly) { $where[] = "c.is_active = 1 AND p.is_active = 1"; }
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY c.sort_order, c.title";
        if ($limit !== null) {
            $sql .= " LIMIT " . max(1, (int)$limit) . " OFFSET " . max(0, (int)$offset);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countCourses($programId = null, $activeOnly = false) {
        $sql = "SELECT COUNT(*) FROM training_courses c JOIN training_programs p ON c.program_id = p.id";
        $where = [];
        $params = [];
        if ($programId) { $where[] = "c.program_id = ?"; $params[] = $programId; }
        if ($activeOnly) { $where[] = "c.is_active = 1 AND p.is_active = 1"; }
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
    
    public function getCourse($id) {
        $stmt = $this->db->prepare("SELECT c.*, p.name as program_name, p.name_en as program_name_en, p.icon as program_icon, p.color as program_color
            FROM training_courses c JOIN training_programs p ON c.program_id = p.id WHERE c.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function createCourse($data) {
        $stmt = $this->db->prepare("INSERT INTO training_courses (program_id, title, title_en, description, description_en, difficulty, estimated_hours, passing_score, is_mandatory, is_active, sort_order, display_language, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $data['program_id'], $data['title'], $data['title_en'] ?? null,
            $data['description'] ?? null, $data['description_en'] ?? null,
            $data['difficulty'] ?? 'beginner', $data['estimated_hours'] ?? 1.0,
            $data['passing_score'] ?? 70, $data['is_mandatory'] ?? 0,
            $data['is_active'] ?? 1, $data['sort_order'] ?? 0,
            $data['display_language'] ?? 'ar',
            $data['created_by'] ?? null
        ]);
    }
    
    public function updateCourse($id, $data) {
        $stmt = $this->db->prepare("UPDATE training_courses SET program_id = ?, title = ?, title_en = ?, description = ?, description_en = ?, difficulty = ?, estimated_hours = ?, passing_score = ?, is_mandatory = ?, is_active = ?, sort_order = ?, display_language = ? WHERE id = ?");
        return $stmt->execute([
            $data['program_id'], $data['title'], $data['title_en'] ?? null,
            $data['description'] ?? null, $data['description_en'] ?? null,
            $data['difficulty'] ?? 'beginner', $data['estimated_hours'] ?? 1.0,
            $data['passing_score'] ?? 70, $data['is_mandatory'] ?? 0,
            $data['is_active'] ?? 1, $data['sort_order'] ?? 0,
            $data['display_language'] ?? 'ar', $id
        ]);
    }
    
    public function deleteCourse($id) {
        $stmt = $this->db->prepare("DELETE FROM training_courses WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    public function toggleCourseStatus($id, $status) {
        $stmt = $this->db->prepare("UPDATE training_courses SET is_active = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }
    
    // =====================================================
    // الوحدات التدريبية - Units
    // =====================================================
    
    public function getUnits($courseId, $activeOnly = false) {
        $sql = "SELECT u.*, 
                (SELECT COUNT(*) FROM training_questions WHERE unit_id = u.id) as question_count
                FROM training_units u WHERE u.course_id = ?";
        if ($activeOnly) $sql .= " AND u.is_active = 1";
        $sql .= " ORDER BY u.sort_order, u.id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$courseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getUnit($id) {
        // ملاحظة أمنية: محتوى الوحدة (content) يُعرض كـ HTML خام في training_course.php.
        // هذا مقبول بالتصميم لأن المحتوى يُدخل من إداري موثوق فقط. التوصية المستقبلية:
        // تطبيق HTML Purifier عند createUnit/updateUnit لتنظيف الإدخال قبل التخزين.
        $stmt = $this->db->prepare("SELECT u.*, c.title as course_title, c.title_en as course_title_en, c.passing_score, c.display_language,
            p.name as program_name, p.name_en as program_name_en FROM training_units u
            JOIN training_courses c ON u.course_id = c.id
            JOIN training_programs p ON c.program_id = p.id WHERE u.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function createUnit($data) {
        $stmt = $this->db->prepare("INSERT INTO training_units (course_id, title, title_en, description, description_en, unit_type, content, content_en, video_url, file_path, external_link, duration_minutes, has_assessment, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $data['course_id'], $data['title'], $data['title_en'] ?? null,
            $data['description'] ?? null, $data['description_en'] ?? null,
            $data['unit_type'] ?? 'text', $data['content'] ?? null, $data['content_en'] ?? null,
            $data['video_url'] ?? null, $data['file_path'] ?? null,
            $data['external_link'] ?? null, $data['duration_minutes'] ?? 30,
            $data['has_assessment'] ?? 0, $data['sort_order'] ?? 0
        ]);
    }
    
    public function updateUnit($id, $data) {
        $stmt = $this->db->prepare("UPDATE training_units SET title = ?, title_en = ?, description = ?, description_en = ?, unit_type = ?, content = ?, content_en = ?, video_url = ?, file_path = ?, external_link = ?, duration_minutes = ?, has_assessment = ?, sort_order = ?, is_active = ? WHERE id = ?");
        return $stmt->execute([
            $data['title'], $data['title_en'] ?? null,
            $data['description'] ?? null, $data['description_en'] ?? null,
            $data['unit_type'] ?? 'text', $data['content'] ?? null, $data['content_en'] ?? null,
            $data['video_url'] ?? null, $data['file_path'] ?? null,
            $data['external_link'] ?? null, $data['duration_minutes'] ?? 30,
            $data['has_assessment'] ?? 0, $data['sort_order'] ?? 0,
            $data['is_active'] ?? 1, $id
        ]);
    }
    
    public function deleteUnit($id) {
        $stmt = $this->db->prepare("DELETE FROM training_units WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    // =====================================================
    // الأسئلة - Questions
    // =====================================================
    
    public function getQuestions($unitId) {
        $stmt = $this->db->prepare("SELECT * FROM training_questions WHERE unit_id = ? ORDER BY sort_order, id");
        $stmt->execute([$unitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getQuestion($id) {
        $stmt = $this->db->prepare("SELECT * FROM training_questions WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function createQuestion($data) {
        $stmt = $this->db->prepare("INSERT INTO training_questions (unit_id, question_text, question_text_en, question_type, option_a, option_a_en, option_b, option_b_en, option_c, option_c_en, option_d, option_d_en, correct_answer, explanation, explanation_en, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $data['unit_id'], $data['question_text'], $data['question_text_en'] ?? null,
            $data['question_type'] ?? 'multiple_choice',
            $data['option_a'], $data['option_a_en'] ?? null,
            $data['option_b'], $data['option_b_en'] ?? null,
            $data['option_c'] ?? null, $data['option_c_en'] ?? null,
            $data['option_d'] ?? null, $data['option_d_en'] ?? null,
            $data['correct_answer'], $data['explanation'] ?? null, $data['explanation_en'] ?? null,
            $data['sort_order'] ?? 0
        ]);
    }
    
    public function updateQuestion($id, $data) {
        $stmt = $this->db->prepare("UPDATE training_questions SET question_text = ?, question_text_en = ?, question_type = ?, option_a = ?, option_a_en = ?, option_b = ?, option_b_en = ?, option_c = ?, option_c_en = ?, option_d = ?, option_d_en = ?, correct_answer = ?, explanation = ?, explanation_en = ?, sort_order = ? WHERE id = ?");
        return $stmt->execute([
            $data['question_text'], $data['question_text_en'] ?? null,
            $data['question_type'] ?? 'multiple_choice',
            $data['option_a'], $data['option_a_en'] ?? null,
            $data['option_b'], $data['option_b_en'] ?? null,
            $data['option_c'] ?? null, $data['option_c_en'] ?? null,
            $data['option_d'] ?? null, $data['option_d_en'] ?? null,
            $data['correct_answer'], $data['explanation'] ?? null, $data['explanation_en'] ?? null,
            $data['sort_order'] ?? 0, $id
        ]);
    }
    
    public function deleteQuestion($id) {
        $stmt = $this->db->prepare("DELETE FROM training_questions WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    // =====================================================
    // التسجيل والتقدم - Enrollments & Progress
    // =====================================================
    
    public function enrollTeacher($teacherId, $courseId) {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $stmt = $this->db->prepare("INSERT IGNORE INTO training_enrollments (teacher_id, course_id) VALUES (?, ?)");
            $stmt->execute([(int) $teacherId, (int) $courseId]);
            if ($stmt->rowCount() === 1) {
                $enrollmentId = (int) $this->db->lastInsertId();
                (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
                    'insert',
                    'training_enrollment',
                    $enrollmentId ?: null,
                    'تسجيل معلم في دورة تدريبية',
                    [
                        'teacher_id' => (int) $teacherId,
                        'course_id' => (int) $courseId,
                        'status' => 'enrolled',
                        'direct_undo' => false,
                        'reason' => 'training_progress_lifecycle',
                    ]
                );
            }
            if ($ownsTransaction) $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            error_log('Training::enrollTeacher error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function getEnrollment($teacherId, $courseId) {
        $stmt = $this->db->prepare("SELECT * FROM training_enrollments WHERE teacher_id = ? AND course_id = ?");
        $stmt->execute([$teacherId, $courseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getTeacherEnrollments($teacherId) {
        $stmt = $this->db->prepare("SELECT e.*, c.title as course_title, c.title_en as course_title_en,
            c.description as course_description, c.description_en as course_description_en,
            c.difficulty, c.estimated_hours, c.passing_score, c.thumbnail, c.display_language,
            p.name as program_name, p.name_en as program_name_en, p.icon as program_icon, p.color as program_color,
            (SELECT COUNT(*) FROM training_units WHERE course_id = c.id AND is_active = 1) as total_units,
            (SELECT COUNT(*) FROM training_progress tp WHERE tp.teacher_id = e.teacher_id AND tp.unit_id IN (SELECT id FROM training_units WHERE course_id = c.id) AND tp.status = 'completed') as completed_units
            FROM training_enrollments e 
            JOIN training_courses c ON e.course_id = c.id
            JOIN training_programs p ON c.program_id = p.id
            WHERE e.teacher_id = ? ORDER BY e.enrolled_at DESC");
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function updateEnrollmentProgress($teacherId, $courseId) {
        // Calculate progress based on completed units
        $stmt = $this->db->prepare("SELECT 
            (SELECT COUNT(*) FROM training_units WHERE course_id = ? AND is_active = 1) as total,
            (SELECT COUNT(*) FROM training_progress WHERE teacher_id = ? AND unit_id IN (SELECT id FROM training_units WHERE course_id = ?) AND status = 'completed') as done");
        $stmt->execute([$courseId, $teacherId, $courseId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $progress = ($result['total'] > 0) ? round(($result['done'] / $result['total']) * 100, 2) : 0;
        $status = 'in_progress';
        $completedAt = null;

        if ($progress >= 100) {
            $status = 'completed';
            $completedAt = date('Y-m-d H:i:s');
        }

        // احسب الدرجة النهائية كمتوسط أفضل محاولة لكل وحدة تحتوي أسئلة.
        // يُحفظ دائماً (NULL للدورات بدون اختبارات) ليُستخدم في إحصائيات متوسط الدرجات.
        $score = $this->calculateEnrollmentScore($teacherId, $courseId);

        $update = $this->db->prepare("UPDATE training_enrollments SET progress_percent = ?, status = ?, score = ?, started_at = COALESCE(started_at, NOW())" . ($completedAt ? ", completed_at = ?" : "") . " WHERE teacher_id = ? AND course_id = ?");
        $params = [$progress, $status, $score];
        if ($completedAt) $params[] = $completedAt;
        $params[] = $teacherId;
        $params[] = $courseId;
        $update->execute($params);

        return ['progress' => $progress, 'status' => $status, 'score' => $score];
    }

    /**
     * حساب درجة التسجيل النهائية: متوسط أفضل محاولة لكل وحدة نشطة في الدورة.
     * يُعيد null إذا لم تكن الدورة تحتوي على وحدات ذات أسئلة (دورات نظرية فقط).
     */
    private function calculateEnrollmentScore($teacherId, $courseId) {
        $stmt = $this->db->prepare("SELECT AVG(best) FROM (
            SELECT MAX(a.score) AS best
            FROM training_attempts a
            JOIN training_units u ON u.id = a.unit_id
            WHERE a.teacher_id = ? AND u.course_id = ? AND u.is_active = 1
            GROUP BY a.unit_id
        ) t");
        $stmt->execute([$teacherId, $courseId]);
        $avg = $stmt->fetchColumn();
        return ($avg === null || $avg === false) ? null : round((float)$avg, 2);
    }
    
    public function getUnitProgress($teacherId, $unitId) {
        $stmt = $this->db->prepare("SELECT * FROM training_progress WHERE teacher_id = ? AND unit_id = ?");
        $stmt->execute([$teacherId, $unitId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function markUnitStarted($teacherId, $unitId) {
        $stmt = $this->db->prepare("INSERT INTO training_progress (teacher_id, unit_id, status) VALUES (?, ?, 'in_progress') ON DUPLICATE KEY UPDATE status = IF(status = 'not_started', 'in_progress', status)");
        return $stmt->execute([$teacherId, $unitId]);
    }
    
    public function markUnitCompleted($teacherId, $unitId) {
        $stmt = $this->db->prepare("INSERT INTO training_progress (teacher_id, unit_id, status, completed_at) VALUES (?, ?, 'completed', NOW()) ON DUPLICATE KEY UPDATE status = 'completed', completed_at = COALESCE(completed_at, NOW())");
        return $stmt->execute([$teacherId, $unitId]);
    }
    
    public function addTimeSpent($teacherId, $unitId, $minutes) {
        $stmt = $this->db->prepare("UPDATE training_progress SET time_spent_minutes = time_spent_minutes + ? WHERE teacher_id = ? AND unit_id = ?");
        return $stmt->execute([$minutes, $teacherId, $unitId]);
    }
    
    // =====================================================
    // الاختبارات - Assessments
    // =====================================================
    
    public function createAttempt($teacherId, $unitId) {
        // منع المحاولة بعد تجاوز الحد الأقصى أو بعد النجاح المسبق في الوحدة.
        $bestStmt = $this->db->prepare("SELECT COUNT(*) AS attempt_count, MAX(passed) AS any_passed
            FROM training_attempts WHERE teacher_id = ? AND unit_id = ?");
        $bestStmt->execute([$teacherId, $unitId]);
        $meta = $bestStmt->fetch(PDO::FETCH_ASSOC);

        if ($meta && (int)$meta['any_passed'] === 1) {
            throw new RuntimeException('لقد اجتزت اختبار هذه الوحدة مسبقاً — لا يمكن إعادة المحاولة بعد النجاح.');
        }
        if ($meta && (int)$meta['attempt_count'] >= self::MAX_ATTEMPTS_PER_UNIT) {
            throw new RuntimeException('تم بلوغ الحد الأقصى لعدد محاولات هذه الوحدة (' . self::MAX_ATTEMPTS_PER_UNIT . ' محاولات).');
        }

        $questions = $this->getQuestions($unitId);
        $stmt = $this->db->prepare("INSERT INTO training_attempts (teacher_id, unit_id, total_questions) VALUES (?, ?, ?)");
        $stmt->execute([$teacherId, $unitId, count($questions)]);
        return $this->db->lastInsertId();
    }

    public function submitAttempt($attemptId, $answers, $unitId) {
        // التحقق من ملكية المحاولة وأن الوحدة تنتمي لتسجيل فعّال للمعلم.
        $ownStmt = $this->db->prepare("SELECT teacher_id, unit_id FROM training_attempts WHERE id = ?");
        $ownStmt->execute([$attemptId]);
        $attempt = $ownStmt->fetch(PDO::FETCH_ASSOC);

        if (!$attempt) {
            throw new RuntimeException('محاولة الاختبار غير موجودة.');
        }
        // اتساق: unit_id المُمرَّر يجب أن يطابق محاولة الاختبار المسجّلة.
        if ((int)$attempt['unit_id'] !== (int)$unitId) {
            throw new RuntimeException('عدم تطابق: الوحدة المُمرَّرة لا تطابق محاولة الاختبار.');
        }

        $unit = $this->getUnit($unitId);
        if (!$unit) {
            throw new RuntimeException('الوحدة غير موجودة.');
        }

        $enrollment = $this->getEnrollment((int)$attempt['teacher_id'], (int)$unit['course_id']);
        if (!$enrollment) {
            throw new RuntimeException('غير مصرح: الوحدة لا تنتمي لتسجيلك في دورة فعّالة.');
        }

        $questions = $this->getQuestions($unitId);
        $correct = 0;
        $total = count($questions);
        $answerDetails = [];

        foreach ($questions as $q) {
            $given = $answers[$q['id']] ?? '';
            $isCorrect = (strtolower($given) === strtolower($q['correct_answer']));
            if ($isCorrect) $correct++;
            $answerDetails[] = [
                'question_id' => $q['id'],
                'given' => $given,
                'correct' => $q['correct_answer'],
                'is_correct' => $isCorrect
            ];
        }

        $score = ($total > 0) ? round(($correct / $total) * 100, 2) : 0;

        // Get passing score from course
        // ملاحظة: لا يوجد تحقق من تاريخ انتهاء الدورة لأن مخطط training_courses لا يحتوي
        // على حقل end_date بالتصميم — الدورات مفتوحة حتى يُعطّلها الإداري (is_active = 0).
        $passed = ($score >= ($unit['passing_score'] ?? 70)) ? 1 : 0;

        $stmt = $this->db->prepare("UPDATE training_attempts SET score = ?, correct_answers = ?, passed = ?, answers_json = ?, completed_at = NOW() WHERE id = ?");
        $stmt->execute([$score, $correct, $passed, json_encode($answerDetails, JSON_UNESCAPED_UNICODE), $attemptId]);

        return ['score' => $score, 'correct' => $correct, 'total' => $total, 'passed' => $passed, 'details' => $answerDetails];
    }
    
    public function getAttempts($teacherId, $unitId) {
        $stmt = $this->db->prepare("SELECT * FROM training_attempts WHERE teacher_id = ? AND unit_id = ? ORDER BY started_at DESC");
        $stmt->execute([$teacherId, $unitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getBestAttempt($teacherId, $unitId) {
        $stmt = $this->db->prepare("SELECT * FROM training_attempts WHERE teacher_id = ? AND unit_id = ? ORDER BY score DESC LIMIT 1");
        $stmt->execute([$teacherId, $unitId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // =====================================================
    // الشهادات - Certificates
    // =====================================================
    
    /**
     * إصدار شهادة لمعلم في دورة.
     * - إذا لم تُمرَّر درجة ($score === null)، تُقرأ من training_enrollments.score
     *   لتجنّب طباعة نسبة نجاح خادعة (مثل 100% ثابت) على الشهادات.
     * - الجزء العشوائي من رقم الشهادة طويل (12 خانة) لتقليل قابلية التخمين.
     */
    public function issueCertificate($teacherId, $courseId, $score = null) {
        if ($score === null) {
            $stmt = $this->db->prepare("SELECT score FROM training_enrollments WHERE teacher_id = ? AND course_id = ?");
            $stmt->execute([$teacherId, $courseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $score = $row['score'] ?? null;
        }

        $certNumber = 'CERT-' . date('Y') . '-'
            . str_pad((string)$courseId, 3, '0', STR_PAD_LEFT) . '-'
            . str_pad((string)$teacherId, 4, '0', STR_PAD_LEFT) . '-'
            . bin2hex(random_bytes(6));
        $stmt = $this->db->prepare("INSERT IGNORE INTO training_certificates (teacher_id, course_id, certificate_number, score) VALUES (?, ?, ?, ?)");
        $stmt->execute([$teacherId, $courseId, $certNumber, $score]);
        return $certNumber;
    }
    
    public function getCertificate($teacherId, $courseId) {
        $stmt = $this->db->prepare("SELECT * FROM training_certificates WHERE teacher_id = ? AND course_id = ?");
        $stmt->execute([$teacherId, $courseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getTeacherCertificates($teacherId) {
        $stmt = $this->db->prepare("SELECT cert.*, c.title as course_title, c.title_en as course_title_en, c.display_language,
            p.name as program_name, p.name_en as program_name_en, p.icon as program_icon, p.color as program_color
            FROM training_certificates cert
            JOIN training_courses c ON cert.course_id = c.id
            JOIN training_programs p ON c.program_id = p.id
            WHERE cert.teacher_id = ? ORDER BY cert.issued_at DESC");
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * التحقق من شهادة برقم الشهادة - Verify certificate by number
     * مطابقة غير حساسة لحالة الأحرف (UPPER على الطرفين) لقبول إدخال المستخدم بأي حالة،
     * خاصة أن أرقام الشهادات القديمة تحتوي على أحرف hex صغيرة من uniqid().
     */
    public function verifyCertificateByNumber($certNumber) {
        $normalized = strtoupper(trim((string)$certNumber));
        $stmt = $this->db->prepare("SELECT cert.*, c.title as course_title, c.title_en as course_title_en, c.display_language,
            c.estimated_hours,
            p.name as program_name, p.name_en as program_name_en, p.icon as program_icon, p.color as program_color,
            u.name as teacher_name
            FROM training_certificates cert
            JOIN training_courses c ON cert.course_id = c.id
            JOIN training_programs p ON c.program_id = p.id
            JOIN users u ON cert.teacher_id = u.id
            WHERE UPPER(cert.certificate_number) = ?");
        $stmt->execute([$normalized]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // =====================================================
    // التقارير والإحصائيات - Reports & Statistics
    // =====================================================
    
    public function getAdminStats() {
        $stats = [];
        
        // Total programs
        $stmt = $this->db->query("SELECT COUNT(*) FROM training_programs WHERE is_active = 1");
        $stats['total_programs'] = $stmt->fetchColumn();
        
        // Total courses
        $stmt = $this->db->query("SELECT COUNT(*) FROM training_courses WHERE is_active = 1");
        $stats['total_courses'] = $stmt->fetchColumn();
        
        // Total enrollments
        $stmt = $this->db->query("SELECT COUNT(*) FROM training_enrollments");
        $stats['total_enrollments'] = $stmt->fetchColumn();
        
        // Completed enrollments
        $stmt = $this->db->query("SELECT COUNT(*) FROM training_enrollments WHERE status = 'completed'");
        $stats['completed_enrollments'] = $stmt->fetchColumn();
        
        // In-progress
        $stmt = $this->db->query("SELECT COUNT(*) FROM training_enrollments WHERE status = 'in_progress'");
        $stats['in_progress'] = $stmt->fetchColumn();
        
        // Certificates issued
        $stmt = $this->db->query("SELECT COUNT(*) FROM training_certificates");
        $stats['certificates_issued'] = $stmt->fetchColumn();
        
        // Average score
        $stmt = $this->db->query("SELECT COALESCE(AVG(score), 0) FROM training_enrollments WHERE status = 'completed' AND score IS NOT NULL");
        $stats['average_score'] = round($stmt->fetchColumn(), 1);
        
        // Active teachers (unique enrollments)
        $stmt = $this->db->query("SELECT COUNT(DISTINCT teacher_id) FROM training_enrollments");
        $stats['active_teachers'] = $stmt->fetchColumn();
        
        return $stats;
    }
    
    public function getTeacherStats($teacherId) {
        $stats = [];
        
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM training_enrollments WHERE teacher_id = ?");
        $stmt->execute([$teacherId]);
        $stats['enrolled_courses'] = $stmt->fetchColumn();
        
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM training_enrollments WHERE teacher_id = ? AND status = 'completed'");
        $stmt->execute([$teacherId]);
        $stats['completed_courses'] = $stmt->fetchColumn();
        
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM training_enrollments WHERE teacher_id = ? AND status = 'in_progress'");
        $stmt->execute([$teacherId]);
        $stats['in_progress'] = $stmt->fetchColumn();
        
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM training_certificates WHERE teacher_id = ?");
        $stmt->execute([$teacherId]);
        $stats['certificates'] = $stmt->fetchColumn();
        
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(tp.time_spent_minutes), 0) FROM training_progress tp WHERE tp.teacher_id = ?");
        $stmt->execute([$teacherId]);
        $stats['total_hours'] = round($stmt->fetchColumn() / 60, 1);
        
        $stmt = $this->db->prepare("SELECT COALESCE(AVG(score), 0) FROM training_enrollments WHERE teacher_id = ? AND status = 'completed' AND score IS NOT NULL");
        $stmt->execute([$teacherId]);
        $stats['average_score'] = round($stmt->fetchColumn(), 1);
        
        return $stats;
    }
    
    public function getEnrollmentsByTeacher() {
        $stmt = $this->db->prepare("SELECT u.id, u.name as teacher_name,
            COUNT(e.id) as total_courses,
            SUM(CASE WHEN e.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN e.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            COALESCE(AVG(e.progress_percent), 0) as avg_progress,
            (SELECT COUNT(*) FROM training_certificates WHERE teacher_id = u.id) as certificates
            FROM users u
            JOIN training_enrollments e ON u.id = e.teacher_id
            WHERE EXISTS (
                SELECT 1 FROM user_role_assignments ura
                WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active'
            )
            GROUP BY u.id, u.name
            ORDER BY completed DESC, avg_progress DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getCourseEnrollments($courseId) {
        $stmt = $this->db->prepare("SELECT e.*, u.name as teacher_name
            FROM training_enrollments e
            JOIN users u ON e.teacher_id = u.id
            WHERE e.course_id = ?
            ORDER BY e.progress_percent DESC");
        $stmt->execute([$courseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get difficulty label (bilingual)
     */
    public static function getDifficultyLabel($difficulty, $lang = 'ar') {
        $labels = [
            'ar' => ['beginner' => 'مبتدئ', 'intermediate' => 'متوسط', 'advanced' => 'متقدم'],
            'en' => ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced']
        ];
        return $labels[$lang][$difficulty] ?? $labels['ar'][$difficulty] ?? $difficulty;
    }
    
    /**
     * Get difficulty badge class
     */
    public static function getDifficultyBadge($difficulty) {
        $classes = [
            'beginner' => 'bg-success',
            'intermediate' => 'bg-warning text-dark',
            'advanced' => 'bg-danger'
        ];
        return $classes[$difficulty] ?? 'bg-secondary';
    }
    
    /**
     * Get unit type label (bilingual)
     */
    public static function getUnitTypeLabel($type, $lang = 'ar') {
        $labels = [
            'ar' => ['video' => 'فيديو', 'text' => 'محتوى نصي', 'task' => 'مهمة عملية', 'file' => 'ملف مرفق', 'link' => 'رابط خارجي'],
            'en' => ['video' => 'Video', 'text' => 'Text Content', 'task' => 'Practical Task', 'file' => 'File Attachment', 'link' => 'External Link']
        ];
        return $labels[$lang][$type] ?? $labels['ar'][$type] ?? $type;
    }
    
    /**
     * Get unit type icon
     */
    public static function getUnitTypeIcon($type) {
        $icons = [
            'video' => 'fa-play-circle',
            'text' => 'fa-file-alt',
            'task' => 'fa-tasks',
            'file' => 'fa-file-download',
            'link' => 'fa-external-link-alt'
        ];
        return $icons[$type] ?? 'fa-file';
    }
    
    /**
     * Get status label (bilingual)
     */
    public static function getStatusLabel($status, $lang = 'ar') {
        $labels = [
            'ar' => ['enrolled' => 'مسجل', 'in_progress' => 'قيد التنفيذ', 'completed' => 'مكتمل', 'dropped' => 'منسحب', 'not_started' => 'لم يبدأ'],
            'en' => ['enrolled' => 'Enrolled', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'dropped' => 'Dropped', 'not_started' => 'Not Started']
        ];
        return $labels[$lang][$status] ?? $labels['ar'][$status] ?? $status;
    }
    
    /**
     * Get status badge class
     */
    public static function getStatusBadge($status) {
        $classes = [
            'enrolled' => 'bg-info',
            'in_progress' => 'bg-warning text-dark',
            'completed' => 'bg-success',
            'dropped' => 'bg-secondary',
            'not_started' => 'bg-light text-dark'
        ];
        return $classes[$status] ?? 'bg-secondary';
    }
}
