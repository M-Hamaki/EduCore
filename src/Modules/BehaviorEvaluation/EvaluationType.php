<?php
namespace EduCore\Modules\BehaviorEvaluation;

use PDO;
use PDOException;
use EduCore\Modules\Operations\Audit\AuditService;
/**
 * EvaluationType Class
 * Handles evaluation types/categories
 */
class EvaluationType {
    private $conn;
    private $table_name = "evaluation_types";

    // Properties
    public $id;
    public $name;
    public $type; // 'positive' or 'negative'
    public $points;
    public $error_message;

    // Constructor with DB connection
    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Create new evaluation type
     * @return boolean
     */
    public function create() {
        $query = "INSERT INTO " . $this->table_name . "
                  SET name = :name,
                      type = :type,
                      points = :points";

        // Sanitize inputs
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->type = htmlspecialchars(strip_tags($this->type));

        $ownsTransaction = !$this->conn->inTransaction();
        try {
            if ($ownsTransaction) $this->conn->beginTransaction();
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':name' => $this->name, ':type' => $this->type, ':points' => $this->points]);
            $this->id = (int) $this->conn->lastInsertId();
            $afterStmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE id = ?");
            $afterStmt->execute([$this->id]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) throw new \RuntimeException('Created evaluation type could not be reloaded.');
            (new AuditService($this->conn))->recordInsert(
                'evaluation_type', $this->table_name, $this->id,
                (string) $this->name, $after, 'إضافة نوع تقييم'
            );
            if ($ownsTransaction) $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            // Check for duplicate entry error
            if ($e->getCode() == 23000 && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                // Set a more user-friendly error message
                $this->error_message = "اسم نوع التقييم موجود مسبقاً. يرجى اختيار اسم آخر.";
                return false;
            }
            // Re-throw other exceptions
            throw $e;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            error_log('EvaluationType::create error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Read all evaluation types
     * @param bool $include_admin_types Include admin-only custom types
     * @return PDOStatement
     */
    public function readAll($include_admin_types = true) {
        if ($include_admin_types) {
            $query = "SELECT id, name, type, points
                      FROM " . $this->table_name . "
                      ORDER BY type, name";
        } else {
            // Exclude admin-only custom evaluation types from teacher view
            $query = "SELECT id, name, type, points
                      FROM " . $this->table_name . "
                      WHERE name NOT IN ('إضافة نقاط', 'خصم نقاط')
                      ORDER BY type, name";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Fast count of all evaluation types
     */
    public function countAll(): int {
        $stmt = $this->conn->query("SELECT COUNT(*) AS cnt FROM " . $this->table_name);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Read evaluation types by type (positive/negative)
     * @param string $type
     * @return PDOStatement
     */
    public function readByType($type) {
        $query = "SELECT id, name, type, points
                  FROM " . $this->table_name . "
                  WHERE type = :type
                  ORDER BY name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':type', $type);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Read single evaluation type
     * @return boolean
     */
    public function readOne() {
        $query = "SELECT id, name, type, points
                  FROM " . $this->table_name . "
                  WHERE id = ?
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->name = $row['name'];
            $this->type = $row['type'];
            $this->points = $row['points'];
            return true;
        }

        return false;
    }

    /**
     * Update evaluation type
     * @return boolean
     */
    public function update() {
        $query = "UPDATE " . $this->table_name . "
                SET name = :name,
                    type = :type,
                    points = :points
                WHERE id = :id";

        // Sanitize inputs
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->type = htmlspecialchars(strip_tags($this->type));

        $ownsTransaction = !$this->conn->inTransaction();
        try {
            if ($ownsTransaction) $this->conn->beginTransaction();
            $beforeStmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE id = ? FOR UPDATE");
            $beforeStmt->execute([(int) $this->id]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new \RuntimeException('Evaluation type not found.');
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':name' => $this->name, ':type' => $this->type, ':points' => $this->points, ':id' => (int) $this->id]);
            $afterStmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE id = ?");
            $afterStmt->execute([(int) $this->id]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) throw new \RuntimeException('Updated evaluation type could not be reloaded.');
            (new AuditService($this->conn))->recordUpdate(
                'evaluation_type', $this->table_name, (int) $this->id,
                (string) $this->name, $before, $after, 'تعديل نوع تقييم'
            );
            if ($ownsTransaction) $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            // Check for duplicate entry error
            if ($e->getCode() == 23000 && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                // Set a more user-friendly error message
                $this->error_message = "اسم نوع التقييم موجود مسبقاً. يرجى اختيار اسم آخر.";
                return false;
            }
            // Re-throw other exceptions
            throw $e;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            error_log('EvaluationType::update error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete evaluation type
     * @return boolean
     */
    public function delete() {
        $ownsTransaction = !$this->conn->inTransaction();
        try {
            if ($ownsTransaction) $this->conn->beginTransaction();
            $beforeStmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE id = ? FOR UPDATE");
            $beforeStmt->execute([(int) $this->id]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new \RuntimeException('Evaluation type not found.');

            $usage = $this->conn->prepare('SELECT COUNT(*) FROM evaluations WHERE evaluation_type_id = ?');
            $usage->execute([(int) $this->id]);
            if ((int) $usage->fetchColumn() > 0) {
                if ($ownsTransaction) $this->conn->rollBack();
                return false;
            }

            $stmt = $this->conn->prepare("DELETE FROM {$this->table_name} WHERE id = ?");
            $stmt->execute([(int) $this->id]);
            if ($stmt->rowCount() !== 1) throw new \RuntimeException('Evaluation type delete did not affect one row.');
            (new AuditService($this->conn))->recordDelete(
                'evaluation_type', $this->table_name, (int) $this->id,
                (string) $before['name'], $before, 'حذف نوع تقييم'
            );
            if ($ownsTransaction) $this->conn->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            error_log('EvaluationType::delete error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get or create the adjustment evaluation type
     * @return array The evaluation type data
     */
    public function getOrCreateAdjustmentType() {
        // First, try to get an existing adjustment type
        $query = "SELECT * FROM " . $this->table_name . " WHERE name LIKE '%تعديل النقاط%' LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            // Found an existing adjustment type
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // No adjustment type found, create one
        $this->name = "تعديل النقاط";
        $this->type = "positive"; // Default to positive, but will be overridden when used
        $this->points = 0; // The actual points will be set via custom_points

        if ($this->create()) {
            // Get the newly created type
            $this->id = $this->conn->lastInsertId();

            $query = "SELECT * FROM " . $this->table_name . " WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(1, $this->id);
            $stmt->execute();

            return $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // If we failed to create, return a default array with minimal info
        return [
            'id' => 0,
            'name' => 'تعديل النقاط',
            'type' => 'positive',
            'points' => 0
        ];
    }
}
