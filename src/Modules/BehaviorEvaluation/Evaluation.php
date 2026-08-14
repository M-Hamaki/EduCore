<?php
namespace EduCore\Modules\BehaviorEvaluation;

use AcademicYear;
use AcademicYearWriteGuard;
use PDO;
use PDOException;
use EduCore\Modules\Operations\Audit\AuditService;
/**
 * Evaluation Class
 * Handles all evaluation/reward point functionality
 */
class Evaluation {
    private $conn;
    private $table_name = "evaluations";
      // Evaluation properties
    public $id;
    public $student_id;
    public $teacher_id;
    public $evaluation_type_id;
    public $class_id;
    public $date_created;
    public $type; // 'positive' or 'negative'
    public $reason; // Optional reason for the evaluation
    public $custom_points; // Optional custom points
    public $last_error; // Holds last error message (debug)

    // Constructor with DB connection
    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Create new evaluation
     * @return boolean
     */
    public function create() {
        $this->last_error = null;

        // Normalize / sanitize incoming values
        $this->student_id = (int)$this->student_id;
        $this->teacher_id = (int)$this->teacher_id;
        $this->evaluation_type_id = (int)$this->evaluation_type_id;
        $this->class_id = (int)$this->class_id;
        $this->custom_points = isset($this->custom_points) && is_numeric($this->custom_points) ? $this->custom_points : null;
        $this->reason = htmlspecialchars(strip_tags($this->reason));

        // Always validate evaluation_type exists
        $typeRow = $this->fetchEvaluationType($this->evaluation_type_id);
        if (!$typeRow) {
            $this->last_error = 'Invalid evaluation_type_id';
            return false;
        }
        $this->type = $typeRow['type'];

        // Check for duplicate evaluation within last 20 seconds (prevent double-click)
        $duplicateCheck = "SELECT id FROM {$this->table_name}
                          WHERE student_id = :student_id
                          AND teacher_id = :teacher_id
                          AND evaluation_type_id = :evaluation_type_id
                          AND class_id = :class_id
                          AND date_created >= DATE_SUB(NOW(), INTERVAL 20 SECOND)
                          LIMIT 1 FOR UPDATE";

        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) {
            $this->conn->beginTransaction();
        }
        try {
            $checkStmt = $this->conn->prepare($duplicateCheck);
            $checkStmt->bindParam(':student_id', $this->student_id, PDO::PARAM_INT);
            $checkStmt->bindParam(':teacher_id', $this->teacher_id, PDO::PARAM_INT);
            $checkStmt->bindParam(':evaluation_type_id', $this->evaluation_type_id, PDO::PARAM_INT);
            $checkStmt->bindParam(':class_id', $this->class_id, PDO::PARAM_INT);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $this->last_error = '⚠️ تنبيه: تم إضافة هذا التقييم مسبقاً خلال آخر 20 ثانية. لا يمكن تكرار نفس التقييم لنفس الطالب خلال فترة قصيرة لمنع الأخطاء.';
                if ($ownsTransaction) {
                    $this->conn->rollBack();
                }
                return false;
            }
        } catch (PDOException $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $this->last_error = 'Duplicate check failed.';
            error_log('Duplicate check error: ' . $e->getMessage());
            return false;
        }

        // العام الدراسي الحالي (لربط التقييم بالعام)
        require_once dirname(__DIR__, 3) . '/classes/AcademicYear.php';
        require_once dirname(__DIR__, 3) . '/classes/AcademicYearWriteGuard.php';
        $currentYearId = AcademicYear::currentId($this->conn);
        if ($currentYearId > 0) {
            (new AcademicYearWriteGuard($this->conn))->assertWritable($currentYearId);
        }

        $query = "INSERT INTO {$this->table_name}
                  SET student_id=:student_id, teacher_id=:teacher_id, evaluation_type_id=:evaluation_type_id,
                      class_id=:class_id, custom_points=:custom_points, reason=:reason, date_created=NOW()"
                  . ($currentYearId > 0 ? ", academic_year_id=:academic_year_id" : "");

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $this->student_id, PDO::PARAM_INT);
        $stmt->bindParam(':teacher_id', $this->teacher_id, PDO::PARAM_INT);
        $stmt->bindParam(':evaluation_type_id', $this->evaluation_type_id, PDO::PARAM_INT);
        $stmt->bindParam(':class_id', $this->class_id, PDO::PARAM_INT);
        $stmt->bindParam(':custom_points', $this->custom_points, $this->custom_points === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(':reason', $this->reason, PDO::PARAM_STR);
        if ($currentYearId > 0) {
            $stmt->bindValue(':academic_year_id', $currentYearId, PDO::PARAM_INT);
        }

        // Execute query
        try {
            if ($stmt->execute()) {
                $this->id = (int)$this->conn->lastInsertId();
                $afterStmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE id = ?");
                $afterStmt->execute([$this->id]);
                $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
                if (!$after) {
                    throw new \RuntimeException('Created evaluation could not be reloaded.');
                }
                (new AuditService($this->conn))->recordInsert(
                    'evaluation', $this->table_name, $this->id,
                    'تقييم طالب #' . $this->student_id,
                    $after, 'إضافة تقييم سلوكي'
                );
                if ($ownsTransaction) {
                    $this->conn->commit();
                }
                return true;
            } else {
                if ($ownsTransaction && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                $this->last_error = 'Execute failed: ' . print_r($stmt->errorInfo(), true);
                return false;
            }
        } catch (PDOException $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $this->last_error = $e->getMessage();
            return false;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * Fetch evaluation type row
     * @param int $evaluation_type_id
     * @return array|null
     */
    private function fetchEvaluationType($evaluation_type_id) {
        try {
            $stmt = $this->conn->prepare("SELECT id, type, points FROM evaluation_types WHERE id = :id LIMIT 1");
            $stmt->bindParam(':id', $evaluation_type_id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            $this->last_error = 'fetchEvaluationType error: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Read all evaluations for a class
     * @param int $class_id
     * @return PDOStatement
     */
    public function readByClass($class_id) {
        $query = "SELECT e.id, e.date_created,
                  s.name as student_name,
                  t.name as teacher_name,
                  et.name as evaluation_name,
                  et.type,
                  et.points
                  FROM " . $this->table_name . " e
                  JOIN users s ON e.student_id = s.id
                  JOIN users t ON e.teacher_id = t.id
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE e.class_id = :class_id
                  ORDER BY e.date_created DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':class_id', $class_id);
        $stmt->execute();

        return $stmt;
    }    /**
     * Read all evaluations for a student
     * @param int $student_id
     * @return PDOStatement
     */
    public function readByStudent($student_id) {
        require_once dirname(__DIR__, 3) . '/classes/AcademicYear.php';
        $yearId = AcademicYear::currentId($this->conn);
        $query = "SELECT e.id, e.date_created, e.custom_points, e.reason,
                  t.name as teacher_name,
                  et.name as evaluation_name,
                  et.type as display_type,
                  et.points,
                  c.name as class_name,
                  CASE
                    WHEN e.custom_points IS NOT NULL THEN e.custom_points
                    ELSE CASE
                        WHEN et.type = 'negative' THEN -et.points
                        ELSE et.points
                    END
                  END as display_points
                  FROM " . $this->table_name . " e
                  JOIN users t ON e.teacher_id = t.id
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  LEFT JOIN classes c ON e.class_id = c.id
                  WHERE e.student_id = :student_id"
                  . ($yearId > 0 ? " AND (e.academic_year_id = :academic_year_id OR e.academic_year_id IS NULL)" : "") . "
                  ORDER BY e.date_created DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        if ($yearId > 0) {
            $stmt->bindValue(':academic_year_id', $yearId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * Read single evaluation
     * @return boolean
     */
    public function readOne() {
        $query = "SELECT e.id, e.student_id, e.teacher_id, e.evaluation_type_id, e.class_id, e.date_created,
                  s.name as student_name,
                  t.name as teacher_name,
                  et.name as evaluation_name,
                  et.type,
                  et.points
                  FROM " . $this->table_name . " e
                  JOIN users s ON e.student_id = s.id
                  JOIN users t ON e.teacher_id = t.id
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE e.id = ?
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->student_id = $row['student_id'];
            $this->teacher_id = $row['teacher_id'];
            $this->evaluation_type_id = $row['evaluation_type_id'];
            $this->class_id = $row['class_id'];
            $this->date_created = $row['date_created'];
            $this->type = $row['type'];
            return true;
        }

        return false;
    }

    /**
     * Update evaluation
     * @return boolean
     */
    public function update() {
        $ownsTransaction = !$this->conn->inTransaction();
        try {
            if ($ownsTransaction) $this->conn->beginTransaction();
            $beforeStmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE id = ? FOR UPDATE");
            $beforeStmt->execute([(int) $this->id]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new \RuntimeException('Evaluation not found.');
            require_once dirname(__DIR__, 3) . '/classes/AcademicYearWriteGuard.php';
            if ((int) ($before['academic_year_id'] ?? 0) > 0) {
                (new AcademicYearWriteGuard($this->conn))->assertWritable((int) $before['academic_year_id']);
            }

            $query = "UPDATE {$this->table_name}
                    SET student_id = :student_id, teacher_id = :teacher_id,
                        evaluation_type_id = :evaluation_type_id, class_id = :class_id
                    WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':student_id' => (int) $this->student_id,
                ':teacher_id' => (int) $this->teacher_id,
                ':evaluation_type_id' => (int) $this->evaluation_type_id,
                ':class_id' => (int) $this->class_id,
                ':id' => (int) $this->id,
            ]);
            $afterStmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE id = ?");
            $afterStmt->execute([(int) $this->id]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) throw new \RuntimeException('Updated evaluation could not be reloaded.');
            (new AuditService($this->conn))->recordUpdate(
                'evaluation', $this->table_name, (int) $this->id,
                'تقييم طالب #' . (int) $after['student_id'],
                $before, $after, 'تعديل تقييم سلوكي'
            );
            if ($ownsTransaction) $this->conn->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            $this->last_error = 'Evaluation update failed.';
            error_log('Evaluation::update error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete evaluation
     * @return boolean
     */
    public function delete() {
        $ownsTransaction = !$this->conn->inTransaction();
        try {
            if ($ownsTransaction) $this->conn->beginTransaction();
            $beforeStmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE id = ? FOR UPDATE");
            $beforeStmt->execute([(int) $this->id]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new \RuntimeException('Evaluation not found.');
            require_once dirname(__DIR__, 3) . '/classes/AcademicYearWriteGuard.php';
            if ((int) ($before['academic_year_id'] ?? 0) > 0) {
                (new AcademicYearWriteGuard($this->conn))->assertWritable((int) $before['academic_year_id']);
            }
            $this->student_id = (int) $before['student_id'];

            $stmt = $this->conn->prepare("DELETE FROM {$this->table_name} WHERE id = ?");
            $stmt->execute([(int) $this->id]);
            if ($stmt->rowCount() !== 1) throw new \RuntimeException('Evaluation delete did not affect one row.');
            (new AuditService($this->conn))->recordDelete(
                'evaluation', $this->table_name, (int) $this->id,
                'تقييم طالب #' . $this->student_id,
                $before, 'حذف تقييم سلوكي'
            );
            if ($ownsTransaction) $this->conn->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            $this->last_error = 'Evaluation delete failed.';
            error_log('Evaluation::delete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get student's total points
     * @param int $student_id
     * @return int
     */
    public function getStudentTotalPoints($student_id) {
        require_once dirname(__DIR__, 3) . '/classes/AcademicYear.php';
        $yearId = AcademicYear::currentId($this->conn);
        $query = "SELECT
                  COALESCE(SUM(
                    CASE
                      WHEN e.custom_points IS NOT NULL THEN e.custom_points
                      WHEN et.type = 'positive' THEN et.points
                      ELSE -et.points
                    END
                  ), 0) AS total_points
                  FROM " . $this->table_name . " e
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE e.student_id = :student_id"
                  . ($yearId > 0 ? " AND (e.academic_year_id = :year_id OR e.academic_year_id IS NULL)" : "");

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            if ($yearId > 0) {
                $stmt->bindValue(':year_id', $yearId, PDO::PARAM_INT);
            }
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return (int)$row['total_points'];
        } catch (PDOException $e) {
            error_log("Database error in getStudentTotalPoints: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Export evaluations to array for Excel
     * @param int $class_id
     * @return array
     */
    public function exportToArray($class_id) {
        $stmt = $this->readByClass($class_id);
        $evaluations = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $evaluation_item = [
                'ID' => $row['id'],
                'Student' => $row['student_name'],
                'Teacher' => $row['teacher_name'],
                'Evaluation' => $row['evaluation_name'],
                'Type' => $row['type'],
                'Points' => $row['points'],
                'Date' => $row['date_created']
            ];

            $evaluations[] = $evaluation_item;
        }

        return $evaluations;
    }

    /**
     * Get evaluations by filter
     * @param string $where_clause
     * @param array $params
     * @return array
     */
    public function getEvaluationsByFilter($where_clause, $params) {
        $query = "SELECT e.id, e.date_created,
                    CASE WHEN et.type = 'positive' THEN et.points ELSE -et.points END as points,
                    s.name as student_name,
                    t.name as teacher_name,
                    et.name as evaluation_type_name
                  FROM " . $this->table_name . " e
                  JOIN users s ON e.student_id = s.id
                  JOIN users t ON e.teacher_id = t.id
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE " . $where_clause . "
                  ORDER BY e.date_created DESC";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getEvaluationsByFilter: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get class statistics
     * @param int $class_id
     * @return array
     */
    public function getClassStatistics($class_id) {
        $query = "SELECT
                    COUNT(CASE WHEN et.type = 'positive' THEN 1 END) as positive_count,
                    COUNT(CASE WHEN et.type = 'negative' THEN 1 END) as negative_count
                  FROM " . $this->table_name . " e
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE e.class_id = ?";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$class_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getClassStatistics: " . $e->getMessage());
            return ['positive_count' => 0, 'negative_count' => 0];
        }
    }

    /**
     * Get student statistics
     * @param int $student_id
     * @return array
     */
    public function getStudentStatistics($student_id) {
        $query = "SELECT
                    COUNT(CASE WHEN et.type = 'positive' THEN 1 END) as positive_count,
                    COUNT(CASE WHEN et.type = 'negative' THEN 1 END) as negative_count
                  FROM " . $this->table_name . " e
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE e.student_id = ?";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$student_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getStudentStatistics: " . $e->getMessage());
            return ['positive_count' => 0, 'negative_count' => 0];
        }
    }

    /**
     * Get last evaluation for student
     * @param int $student_id
     * @return array|null
     */
    public function getLastEvaluationForStudent($student_id) {
        $query = "SELECT e.created_at, et.name as evaluation_name
                  FROM " . $this->table_name . " e
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE e.student_id = ?
                  ORDER BY e.created_at DESC
                  LIMIT 1";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$student_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getLastEvaluationForStudent: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete evaluation and recalculate student's total points
     * @param int $evaluation_id
     * @return array|false Returns array with student_id and new_total_points on success, false on failure.
     */
    public function deleteAndRecalculatePoints($evaluation_id) {
        $this->id = $evaluation_id;
        if (!$this->delete()) return false;
        $studentId = (int) $this->student_id;
        return ['student_id' => $studentId, 'new_total_points' => $this->getStudentTotalPoints($studentId)];
    }

    /**
     * Delete all evaluations for a specific student (used for resetting points)
     * @return boolean
     */
    public function deleteAllForStudent() {
        if (!$this->student_id) {
            $this->last_error = "Student ID is required.";
            return false;
        }

        $ownsTransaction = !$this->conn->inTransaction();
        try {
            if ($ownsTransaction) $this->conn->beginTransaction();
            $select = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE student_id = ? ORDER BY id FOR UPDATE");
            $select->execute([(int) $this->student_id]);
            $beforeRows = $select->fetchAll(PDO::FETCH_ASSOC);
            require_once dirname(__DIR__, 3) . '/classes/AcademicYearWriteGuard.php';
            $guard = new AcademicYearWriteGuard($this->conn);
            $guardedYears = [];
            foreach ($beforeRows as $beforeRow) {
                $yearId = (int) ($beforeRow['academic_year_id'] ?? 0);
                if ($yearId > 0 && !isset($guardedYears[$yearId])) {
                    $guard->assertWritable($yearId);
                    $guardedYears[$yearId] = true;
                }
            }
            $query = "DELETE FROM " . $this->table_name . " WHERE student_id = :student_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $this->student_id, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() !== count($beforeRows)) throw new \RuntimeException('Evaluation bulk delete count mismatch.');

            $batchId = bin2hex(random_bytes(16));
            $audit = new AuditService($this->conn);
            foreach ($beforeRows as $before) {
                $audit->recordDelete(
                    'evaluation', $this->table_name, (int) $before['id'],
                    'تقييم طالب #' . (int) $this->student_id,
                    $before, 'حذف جميع تقييمات الطالب', $batchId
                );
            }
            if ($beforeRows === []) {
                $audit->recordEvent('evaluation_bulk_delete_noop', 'student', (int) $this->student_id, 'طالب', ['deleted_count' => 0]);
            }
            if ($ownsTransaction) $this->conn->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            $this->last_error = 'Evaluation bulk delete failed.';
            error_log('Evaluation::deleteAllForStudent error: ' . $e->getMessage());
            return false;
        }
    }
}
