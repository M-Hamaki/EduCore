<?php
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
/**
 * Class Management
 * Handles all classroom-related functionality
 */
class ClassRoom {
    private $conn;
    private $table_name = "classes";
    
    // Class properties
    public $id;
    public $name;
    public $grade_id;
    public $room_location;
    public $capacity;
    public $display_order;
    public $academic_year_id;
    public $is_experimental = 0;
    public $error_message;
    
    // Constructor with DB connection
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Create new class
     * @return boolean
     */
    public function create() {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        if (empty($this->display_order)) {
            $maxStmt = $this->conn->query("SELECT COALESCE(MAX(display_order), 0) FROM " . $this->table_name);
            $this->display_order = ((int)$maxStmt->fetchColumn()) + 1;
        }
        $query = "INSERT INTO " . $this->table_name . " SET name = :name, grade_id = :grade_id, room_location = :room_location, capacity = :capacity, display_order = :display_order, academic_year_id = :academic_year_id, is_experimental = :is_experimental";
        
        $stmt = $this->conn->prepare($query);
        
        // Sanitize input
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->grade_id = $this->grade_id ? htmlspecialchars(strip_tags($this->grade_id)) : null;
        $this->room_location = !empty($this->room_location) ? htmlspecialchars(strip_tags($this->room_location)) : null;
        $this->capacity = $this->capacity !== null && $this->capacity !== '' ? max(1, min(65535, (int)$this->capacity)) : null;
        $this->display_order = (int)$this->display_order;
        $this->academic_year_id = (int)$this->academic_year_id > 0 ? (int)$this->academic_year_id : null;
        $this->is_experimental = (int)$this->is_experimental === 1 ? 1 : 0;
        
        // Bind values
        $stmt->bindParam(":name", $this->name);
        $stmt->bindParam(":grade_id", $this->grade_id);
        $stmt->bindParam(":room_location", $this->room_location);
        $stmt->bindValue(":capacity", $this->capacity, $this->capacity === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(":display_order", $this->display_order, PDO::PARAM_INT);
        $stmt->bindValue(":academic_year_id", $this->academic_year_id, $this->academic_year_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(":is_experimental", $this->is_experimental, PDO::PARAM_INT);
        
        // Execute query with error handling
        try {
            if ($stmt->execute()) {
                // Get last inserted ID
                $this->id = $this->conn->lastInsertId();
                $after = $this->fetchRow('classes', (int)$this->id);
                (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordInsert(
                    'class', 'classes', (int)$this->id, (string)$this->name, $after, 'إنشاء فصل'
                );
                if ($ownsTransaction) $this->conn->commit();
                return true;
            }
        } catch (PDOException $e) {
            // Check for duplicate entry error
            if ($e->getCode() == 23000 && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                // Set a more user-friendly error message
                $this->error_message = "اسم الفصل موجود مسبقاً. يرجى اختيار اسم آخر.";
                if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
                return false;
            }
            // Re-throw other exceptions
            throw $e;
        }
        if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
        return false;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }
    
    /**
     * Read all classes
     * @return PDOStatement
     */
    public function readAll() {
        $query = "SELECT id, name FROM " . $this->table_name . " ORDER BY name";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt;
    }

    /**
     * Count all classes (fast COUNT(*))
     * @return int
     */
    public function countAll() {
        $sql = "SELECT COUNT(*) AS cnt FROM " . $this->table_name;
        $stmt = $this->conn->query($sql);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        return (int)($row['cnt'] ?? 0);
    }
    
    /**
     * Read single class
     * @return boolean
     */
    public function readOne() {
        $query = "SELECT id, name, grade_id, room_location, capacity, display_order, is_experimental FROM " . $this->table_name . " WHERE id = ? LIMIT 0,1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $this->name = $row['name'];
            $this->grade_id = $row['grade_id'];
            $this->room_location = $row['room_location'];
            $this->capacity = $row['capacity'];
            $this->display_order = $row['display_order'];
            $this->is_experimental = (int)($row['is_experimental'] ?? 0);
            return true;
        }
        
        return false;
    }
    
    /**
     * Update class
     * @return boolean
     */
    public function update() {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $before = $this->fetchRowForUpdate('classes', (int)$this->id);
        if (!$before) {
            if ($ownsTransaction) $this->conn->rollBack();
            return false;
        }
        $query = "UPDATE " . $this->table_name . " SET name = :name, grade_id = :grade_id, room_location = :room_location, capacity = :capacity, display_order = :display_order, is_experimental = :is_experimental WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        
        // Sanitize inputs
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->id = htmlspecialchars(strip_tags($this->id));
        $this->grade_id = $this->grade_id ? htmlspecialchars(strip_tags($this->grade_id)) : null;
        $this->room_location = !empty($this->room_location) ? htmlspecialchars(strip_tags($this->room_location)) : null;
        $this->capacity = $this->capacity !== null && $this->capacity !== '' ? max(1, min(65535, (int)$this->capacity)) : null;
        $this->display_order = (int)$this->display_order;
        $this->is_experimental = (int)$this->is_experimental === 1 ? 1 : 0;
        
        // Bind parameters
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':grade_id', $this->grade_id);
        $stmt->bindParam(':room_location', $this->room_location);
        $stmt->bindValue(':capacity', $this->capacity, $this->capacity === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(':display_order', $this->display_order, PDO::PARAM_INT);
        $stmt->bindParam(':is_experimental', $this->is_experimental, PDO::PARAM_INT);
        $stmt->bindParam(':id', $this->id);
        
        // Execute query with error handling
        try {
            if ($stmt->execute()) {
                $after = $this->fetchRow('classes', (int)$this->id);
                if ($before != $after) {
                    (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordUpdate(
                        'class', 'classes', (int)$this->id, (string)$this->name, $before, $after, 'تعديل فصل'
                    );
                }
                if ($ownsTransaction) $this->conn->commit();
                return true;
            }
        } catch (PDOException $e) {
            // Check for duplicate entry error
            if ($e->getCode() == 23000 && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                // Set a more user-friendly error message
                $this->error_message = "اسم الفصل موجود مسبقاً. يرجى اختيار اسم آخر.";
                if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
                return false;
            }
            // Re-throw other exceptions
            throw $e;
        }
        if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
        return false;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }
    
    /**
     * Delete class
     * @return boolean
     */
    public function delete() {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $classRow = $this->fetchRowForUpdate('classes', (int)$this->id);
        if (!$classRow) {
            if ($ownsTransaction) $this->conn->rollBack();
            return false;
        }
        // First check if there are any students or evaluations assigned to this class
        $query = "SELECT COUNT(*) as count FROM users WHERE class_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row['count'] > 0) {
            if ($ownsTransaction) $this->conn->rollBack();
            return false; // Can't delete class with students
        }
        
        // Also check evaluations
        $query = "SELECT COUNT(*) as count FROM evaluations WHERE class_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row['count'] > 0) {
            if ($ownsTransaction) $this->conn->rollBack();
            return false; // Can't delete class with evaluations
        }
        $accessStmt = $this->conn->prepare('SELECT * FROM user_class_access WHERE class_id = ? ORDER BY id FOR UPDATE');
        $accessStmt->execute([(int)$this->id]);
        $accessRows = $accessStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Delete class-teacher assignments
        $query = "DELETE FROM user_class_access WHERE class_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();
        
        // Now delete the class
        $query = "DELETE FROM " . $this->table_name . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        
        if ($stmt->execute()) {
            $deleted = [];
            foreach ($accessRows as $row) {
                $deleted[] = ['table' => 'user_class_access', 'record_id' => $row['id'], 'snapshot' => $row, 'description' => 'حذف إسناد مرتبط بفصل'];
            }
            $deleted[] = ['table' => 'classes', 'record_id' => $classRow['id'], 'snapshot' => $classRow, 'description' => 'حذف فصل'];
            (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordReplacement(
                'class', (int)$this->id, (string)$classRow['name'], $deleted, [],
                ['summary' => 'حذف فصل وإسنادات العاملين', 'assignment_count' => count($accessRows)]
            );
            if ($ownsTransaction) $this->conn->commit();
            return true;
        }
        
        if ($ownsTransaction) $this->conn->rollBack();
        return false;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }
    
    /**
     * Assign teacher or supervisor to class
     * @param int $user_id
     * @return boolean
     */
    public function assignStaff($user_id) {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $query = "INSERT INTO user_class_access SET user_id = :user_id, class_id = :class_id";
        
        $stmt = $this->conn->prepare($query);
        
        // Bind parameters
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':class_id', $this->id);
        
        // Execute query
        if ($stmt->execute()) {
            $id = (int)$this->conn->lastInsertId();
            $after = $this->fetchRow('user_class_access', $id);
            (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordInsert(
                'class_staff_assignment', 'user_class_access', $id, 'إسناد مستخدم #' . (int)$user_id . ' لفصل #' . (int)$this->id,
                $after, 'إسناد موظف إلى فصل'
            );
            if ($ownsTransaction) $this->conn->commit();
            return true;
        }
        
        if ($ownsTransaction) $this->conn->rollBack();
        return false;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }
    
    /**
     * Remove teacher or supervisor from class
     * @param int $user_id
     * @return boolean
     */
    public function removeStaff($user_id) {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $beforeStmt = $this->conn->prepare('SELECT * FROM user_class_access WHERE user_id = ? AND class_id = ? FOR UPDATE');
        $beforeStmt->execute([(int)$user_id, (int)$this->id]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $query = "DELETE FROM user_class_access WHERE user_id = :user_id AND class_id = :class_id";
        
        $stmt = $this->conn->prepare($query);
        
        // Bind parameters
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':class_id', $this->id);
        
        // Execute query
        if ($stmt->execute()) {
            if ($before) {
                (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordDelete(
                    'class_staff_assignment', 'user_class_access', $before['id'], 'إسناد مستخدم #' . (int)$user_id,
                    $before, 'إزالة موظف من فصل'
                );
            }
            if ($ownsTransaction) $this->conn->commit();
            return true;
        }
        
        if ($ownsTransaction) $this->conn->rollBack();
        return false;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }
    
    /**
     * Reset points for a class
     * @return boolean
     */
    public function resetPoints() {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $beforeStmt = $this->conn->prepare('SELECT * FROM evaluations WHERE class_id = ? ORDER BY id FOR UPDATE');
        $beforeStmt->execute([(int)$this->id]);
        $beforeRows = $beforeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $query = "DELETE FROM evaluations WHERE class_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        
        if ($stmt->execute()) {
            if ($beforeRows) {
                $deleted = array_map(static fn(array $row): array => [
                    'table' => 'evaluations', 'record_id' => $row['id'], 'snapshot' => $row, 'description' => 'إعادة تعيين نقاط فصل',
                ], $beforeRows);
                (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordReplacement(
                    'class_points_reset', (int)$this->id, 'نقاط الفصل #' . (int)$this->id, $deleted, [],
                    ['summary' => 'حذف تقييمات الفصل لإعادة النقاط', 'evaluation_count' => count($beforeRows)]
                );
            }
            if ($ownsTransaction) $this->conn->commit();
            return true;
        }
        
        if ($ownsTransaction) $this->conn->rollBack();
        return false;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get all students in a class with their total points
     * @return PDOStatement
     */
    public function getStudentsWithPoints() {
        require_once __DIR__ . '/AcademicYear.php';
        $yearId = AcademicYear::currentId($this->conn);
        if ($yearId > 0) {
            $query = "SELECT u.id, u.name,
                      COALESCE(SUM(CASE WHEN e.type = 'positive' THEN et.points ELSE 0 END), 0) AS positive_points,
                      COALESCE(SUM(CASE WHEN e.type = 'negative' THEN et.points ELSE 0 END), 0) AS negative_points,
                      COALESCE(SUM(CASE WHEN e.type = 'positive' THEN et.points ELSE -et.points END), 0) AS total_points
                      FROM users u
                      JOIN student_enrollments se ON se.student_id = u.id
                          AND se.academic_year_id = :year_id
                          AND se.enrollment_status = 'enrolled'
                      LEFT JOIN evaluations e ON u.id = e.student_id
                      LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
                      WHERE se.class_id = :class_id AND u.role = 'student'
                      GROUP BY u.id, u.name
                      ORDER BY total_points DESC, u.name";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':year_id', $yearId, PDO::PARAM_INT);
            $stmt->bindParam(':class_id', $this->id);
        } else {
            $query = "SELECT u.id, u.name,
                      COALESCE(SUM(CASE WHEN e.type = 'positive' THEN et.points ELSE 0 END), 0) AS positive_points,
                      COALESCE(SUM(CASE WHEN e.type = 'negative' THEN et.points ELSE 0 END), 0) AS negative_points,
                      COALESCE(SUM(CASE WHEN e.type = 'positive' THEN et.points ELSE -et.points END), 0) AS total_points
                      FROM users u
                      LEFT JOIN evaluations e ON u.id = e.student_id
                      LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
                      WHERE u.class_id = :class_id AND u.role = 'student'
                      GROUP BY u.id, u.name
                      ORDER BY total_points DESC, u.name";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':class_id', $this->id);
        }
        $stmt->execute();

        return $stmt;
    }
      /**
     * Get all classes as array
     * @return array
     */
    public function getAll() {
        try {
            $stmt = $this->readAll();
            $classes = [];
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $classes[] = [
                    'id' => $row['id'],
                    'name' => $row['name']
                ];
            }
            
            return $classes;
        } catch (Exception $e) {
            // Log the error and return empty array
            error_log("Error in ClassRoom::getAll(): " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get student count for a specific class
     * @param int $class_id
     * @return int
     */
    public function getStudentCount($class_id) {
        require_once __DIR__ . '/AcademicYear.php';
        $yearId = AcademicYear::currentId($this->conn);
        try {
            if ($yearId > 0) {
                $query = "SELECT COUNT(*) as student_count
                          FROM student_enrollments se
                          JOIN users u ON u.id = se.student_id
                          WHERE se.academic_year_id = :year_id AND se.class_id = :class_id
                          AND se.enrollment_status = 'enrolled' AND u.role = 'student'";
                $stmt = $this->conn->prepare($query);
                $stmt->bindValue(':year_id', $yearId, PDO::PARAM_INT);
                $stmt->bindParam(':class_id', $class_id);
            } else {
                $query = "SELECT COUNT(*) as student_count
                          FROM users WHERE role = 'student' AND class_id = :class_id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':class_id', $class_id);
            }
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['student_count'] ?? 0);
        } catch (Exception $e) {
            error_log("Error in ClassRoom::getStudentCount(): " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get all classes with student counts
     * @return PDOStatement
     */
    public function readAllWithStudentCounts() {
        require_once __DIR__ . '/AcademicYear.php';
        $yearId = AcademicYear::currentId($this->conn);
        if ($yearId > 0) {
            $query = "SELECT c.id, c.name, COUNT(se.student_id) as student_count
                      FROM " . $this->table_name . " c
                      LEFT JOIN student_enrollments se ON se.class_id = c.id
                          AND se.academic_year_id = :year_id
                          AND se.enrollment_status = 'enrolled'
                      GROUP BY c.id, c.name
                      ORDER BY c.name";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':year_id', $yearId, PDO::PARAM_INT);
        } else {
            $query = "SELECT c.id, c.name, COUNT(u.id) as student_count
                      FROM " . $this->table_name . " c
                      LEFT JOIN users u ON c.id = u.class_id AND u.role = 'student'
                      GROUP BY c.id, c.name
                      ORDER BY c.name";
            $stmt = $this->conn->prepare($query);
        }
        $stmt->execute();

        return $stmt;
    }

    private function fetchRow(string $table, int $id): array {
        $stmt = $this->conn->prepare("SELECT * FROM `{$table}` WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchRowForUpdate(string $table, int $id): array {
        $stmt = $this->conn->prepare("SELECT * FROM `{$table}` WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
