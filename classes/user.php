<?php
require_once __DIR__ . '/UserProfileStore.php';
require_once __DIR__ . '/PasswordAuthenticator.php';
require_once __DIR__ . '/UserProfileFacadeTrait.php';
require_once __DIR__ . '/UserAuditSupport.php';
require_once __DIR__ . '/../src/Modules/Students/StudentListReadRepository.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
require_once __DIR__ . '/../src/Modules/Staff/SpecialistAcademicScopeService.php';
require_once __DIR__ . '/../src/Modules/Accounts/StudentLoginAccessPolicy.php';
/**
 * User Class
 * Handles all user-related functionality
 */
class User
{
    use UserProfileFacadeTrait;

    private $conn;
    private $table_name = "users";
    private UserProfileStore $profileStore;
    private UserAuditSupport $auditSupport;
    private string $storedPassword = '';
    private ?string $storedPasswordHash = null;
    private ?string $loginDenialMessage = null;
    // User properties
    public $id;
    public $name;
    public $username;
    public $password;
    public $role; // 'admin', 'teacher', 'supervisor', 'student'
    public $class_id;
    public $status; // 'active', 'inactive'
    public $is_supervisor = 0;
    // Constructor with DB connection
    public function __construct($db)
    {
        $this->conn = $db;
        $this->profileStore = new UserProfileStore($db);
        $this->auditSupport = new UserAuditSupport($db);
        // Load encryption functions
        require_once __DIR__ . '/../config/encryption.php';
    }

    /**
         * Create new user
         * @return boolean
         */
    public function create()
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        // بيانات الدخول (username/password/role) اختيارية الآن — قد تُترك NULL
        // عند إنشاء المستخدم، وتُهيّأ لاحقاً من صفحات إدارة الحسابات.
        $hasUsername = !empty($this->username);
        $hasPassword = !empty($this->password);

        // التحقق من تفرّد اسم المستخدم فقط عند توفّره
        if ($hasUsername && $this->usernameExistsForOther()) {
            throw new Exception("اسم المستخدم مستخدم بالفعل");
        }

        // Query to insert a new user
        $query = "INSERT INTO " . $this->table_name . "
                  SET name = :name,
                      username = :username,
                      password = :password,
                      password_hash = :credential_hash,
                      role = :role,
                      class_id = :class_id";

        // Prepare the query
        $stmt = $this->conn->prepare($query);

        // Sanitize inputs
        $this->name = htmlspecialchars(strip_tags($this->name));
        if ($hasUsername) {
            $this->username = htmlspecialchars(strip_tags($this->username));
        }

        // Bind values
        $stmt->bindParam(":name", $this->name);

        // اسم المستخدم: NULL إن لم يُحدَّد بعد
        if ($hasUsername) {
            $stmt->bindParam(":username", $this->username);
        } else {
            $stmt->bindValue(":username", null, PDO::PARAM_NULL);
        }

        // كلمة المرور: NULL إن لم تُحدَّد بعد، وإلا تُشفَّر
        if ($hasPassword) {
            $this->password = htmlspecialchars(strip_tags($this->password));
            $encrypted_password = encryptPassword($this->password);
            $credentialHash = password_hash($this->password, PASSWORD_DEFAULT);
            $stmt->bindParam(":password", $encrypted_password);
            $stmt->bindParam(":credential_hash", $credentialHash);
        } else {
            $stmt->bindValue(":password", null, PDO::PARAM_NULL);
            $stmt->bindValue(":credential_hash", null, PDO::PARAM_NULL);
        }

        // الدور: NULL إن لم يُحدَّد بعد
        if (!empty($this->role)) {
            $role = htmlspecialchars(strip_tags($this->role));
            $stmt->bindParam(":role", $role);
        } else {
            $stmt->bindValue(":role", null, PDO::PARAM_NULL);
        }

        $stmt->bindParam(":class_id", $this->class_id);

        // Execute query
        if ($stmt->execute()) {
            // Get last inserted ID
            $this->id = $this->conn->lastInsertId();

            // للطلاب: أنشئ تسجيلاً سنوياً في العام الحالي (طبقة الأعوام الدراسية)
            if ($this->role === 'student' && !empty($this->class_id)) {
                $this->ensureEnrollmentForCurrentYear();
            }
            if ($ownsTransaction) {
                $after = $this->auditSupport->fetchUserRow((int)$this->id, false);
                (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordInsert(
                    'user', 'users', (int)$this->id, (string)$this->name, $after, 'إنشاء مستخدم'
                );
                $this->conn->commit();
            }
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
     * ضمان وجود تسجيل سنوي للطالب في العام الحالي (upsert).
     * يستخرج stage_id/grade_id من الفصل تلقائياً.
     */
    public function ensureEnrollmentForCurrentYear(): void
    {
        if ($this->role !== 'student' || empty($this->id)) {
            return;
        }
        require_once __DIR__ . '/AcademicYear.php';
        require_once __DIR__ . '/StudentEnrollment.php';
        $yearId = AcademicYear::currentId($this->conn);
        if ($yearId <= 0) {
            return;
        }
        // استخراج stage_id/grade_id من الفصل المرتبط
        $stageId = null;
        $gradeId = null;
        if (!empty($this->class_id)) {
            $cstmt = $this->conn->prepare("SELECT g.stage_id, c.grade_id FROM classes c LEFT JOIN grades g ON g.id = c.grade_id WHERE c.id = ? LIMIT 1");
            $cstmt->execute([$this->class_id]);
            if ($c = $cstmt->fetch(PDO::FETCH_ASSOC)) {
                $stageId = $c['stage_id'] ?? null;
                $gradeId = $c['grade_id'] ?? null;
            }
        }
        $status = ($this->status === 'graduated') ? 'graduated' : 'enrolled';
        StudentEnrollment::upsert($this->conn, (int)$this->id, $yearId, $stageId ? (int)$stageId : null, $gradeId ? (int)$gradeId : null, $this->class_id ? (int)$this->class_id : null, $status);
    }

    /**
     * Check if username exists
     * @return boolean
     */
    public function usernameExists()
    {
        // Resolve the account first; access is evaluated only after a valid password.
        $query = "SELECT id, name, username, password, password_hash, role, is_supervisor, class_id, status
                FROM " . $this->table_name . " 
                WHERE username = ? AND deleted_at IS NULL
                LIMIT 0,1";

        // Prepare the query
        $stmt = $this->conn->prepare($query);

        // Bind username
        $stmt->bindParam(1, $this->username);

        // Execute query
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // If username exists, assign values; access is evaluated after credential verification.
        if ($row) {

            // Assign values to object properties
            $this->id = $row['id'];
            $this->name = $row['name'];
            $this->storedPassword = (string) ($row['password'] ?? '');
            $this->storedPasswordHash = isset($row['password_hash']) ? (string) $row['password_hash'] : null;
            $this->role = $row['role'];
            $this->is_supervisor = (int) ($row['is_supervisor'] ?? 0);
            $this->class_id = $row['class_id'];
            $this->status = $row['status'];

            // Return true because username exists and is active
            return true;
        }

        // Return false if username does not exist or is inactive
        return false;
    }    /**
         * Login user
         * @return boolean
         */
    public function login()
    {
        $this->loginDenialMessage = null;
        // Store the entered password before checking username
        $entered_password = $this->password;

        // Check if username exists
        if ($this->usernameExists()) {
            $legacyEnabled = filter_var(env('PASSWORD_LEGACY_LOGIN_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
            $result = (new PasswordAuthenticator($legacyEnabled))->verify(
                (string) $entered_password,
                $this->storedPassword,
                $this->storedPasswordHash,
                (int) $this->id
            );
            if ($result['verified']) {
                if (is_string($result['replacement_hash']) && $result['replacement_hash'] !== '') {
                    $this->auditSupport->upgradePasswordHash((int) $this->id, $result['replacement_hash'], 'login');
                    $this->storedPasswordHash = $result['replacement_hash'];
                }
                if ($this->role === 'student') {
                    $decision = (new \EduCore\Modules\Accounts\StudentLoginAccessPolicy($this->conn))
                        ->decisionForUserId((int) $this->id);
                    if (!$decision['allowed']) {
                        $this->loginDenialMessage = $decision['message'];
                        return false;
                    }
                } elseif ($this->status !== 'active') {
                    $this->loginDenialMessage = 'حسابك غير نشط. يرجى التواصل مع الإدارة.';
                    return false;
                }
                return true;
            }
        }

        return false;
    }

    public function getLoginDenialMessage(): ?string
    {
        return $this->loginDenialMessage;
    }

    /**
     * Verify the password for an already selected user (used by protected admin actions).
     */
    public function verifyPassword(string $plaintext): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT password, password_hash FROM {$this->table_name} WHERE id = ? LIMIT 1"
        );
        $stmt->execute([(int) $this->id]);
        $stored = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$stored) {
            return false;
        }

        $legacyEnabled = filter_var(env('PASSWORD_LEGACY_LOGIN_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
        $result = (new PasswordAuthenticator($legacyEnabled))->verify(
            $plaintext,
            (string) ($stored['password'] ?? ''),
            isset($stored['password_hash']) ? (string) $stored['password_hash'] : null,
            (int) $this->id
        );
        if (!$result['verified']) {
            return false;
        }
        if (is_string($result['replacement_hash']) && $result['replacement_hash'] !== '') {
            $this->auditSupport->upgradePasswordHash((int) $this->id, $result['replacement_hash'], 'protected_action_verification');
        }
        return true;
    }

    /**
     * Read all users by role
     * @param string $role
     * @return PDOStatement
     */    /**
          * Read users by role
          * @param string $role User role (admin, teacher, supervisor, student)
          * @return array Array of users
          */
    public function readByRole($role)
    {
        $query = "SELECT u.id, u.name, u.username, u.role, u.status, c.name as class_name 
                  FROM " . $this->table_name . " u
                  LEFT JOIN classes c ON u.class_id = c.id
                  WHERE EXISTS (
                      SELECT 1 FROM user_role_assignments ura
                      WHERE ura.user_id = u.id AND ura.role_key = :role AND ura.status = 'active'
                  )
                  ORDER BY u.name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':role', $role);
        $stmt->execute();

        $users = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $users[] = $row;
        }

        return $users;
    }

    /**
     * Get users by role (only active users)
     * @param string $role User role
     * @return array Array of active users
     */
    public function readActiveByRole($role)
    {
        $query = "SELECT u.id, u.name, u.username, u.role, u.status, c.name as class_name 
                  FROM " . $this->table_name . " u
                  LEFT JOIN classes c ON u.class_id = c.id
                  WHERE u.status = 'active'
                    AND EXISTS (
                        SELECT 1 FROM user_role_assignments ura
                        WHERE ura.user_id = u.id AND ura.role_key = :role AND ura.status = 'active'
                    )
                  ORDER BY u.name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':role', $role);
        $stmt->execute();

        $users = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $users[] = $row;
        }

        return $users;
    }

    /**
     * Fast count users by role (avoids loading all rows)
     */
    public function countByRole(string $role): int
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM " . $this->table_name . " WHERE role = :role");
        $stmt->bindParam(':role', $role);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Read single user
     * @return boolean
     */
    public function readOne()
    {
        $query = "SELECT u.id, u.name, u.username, u.password, u.role, u.is_supervisor, u.class_id, u.status, c.name as class_name 
                  FROM " . $this->table_name . " u
                  LEFT JOIN classes c ON u.class_id = c.id
                  WHERE u.id = ?
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->name = $row['name'];
            $this->username = $row['username'];
            $storedPassword = (string) ($row['password'] ?? '');
            $this->password = $storedPassword !== '' ? decryptPasswordForUser($storedPassword, (int)$this->id) : '';
            $this->role = $row['role'];
            $this->is_supervisor = (int) ($row['is_supervisor'] ?? 0);
            $this->class_id = $row['class_id'];
            $this->status = $row['status'];
            return true;
        }

        return false;
    }/**
     * Read identity fields without loading account credentials.
     * Use this for student/staff profile pages that do not manage logins.
     *
     * @return boolean
     */
    public function readOneWithoutCredentials()
    {
        $query = "SELECT u.id, u.name, u.role, u.is_supervisor, u.class_id, u.status, c.name AS class_name
                  FROM " . $this->table_name . " u
                  LEFT JOIN classes c ON u.class_id = c.id
                  WHERE u.id = ?
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $this->name = $row['name'];
        $this->role = $row['role'];
        $this->is_supervisor = (int) ($row['is_supervisor'] ?? 0);
        $this->class_id = $row['class_id'];
        $this->status = $row['status'];
        return true;
    }

    /**
     * Check if username exists for another user (excluding current user)
     * @param int $exclude_id User ID to exclude from check
     * @return boolean
     */
    public function usernameExistsForOther($exclude_id = null)
    {
        $query = "SELECT id FROM " . $this->table_name . " WHERE username = :username";

        if ($exclude_id !== null) {
            $query .= " AND id != :exclude_id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $this->username);

        if ($exclude_id !== null) {
            $stmt->bindParam(':exclude_id', $exclude_id);
        }

        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Update user
     * @return boolean
     */
    public function update()
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $before = $ownsTransaction ? $this->auditSupport->fetchUserRow((int)$this->id, true) : [];
        // Check if username is being changed and if new username already exists for another user
        if ($this->usernameExistsForOther($this->id)) {
            throw new Exception("اسم المستخدم مستخدم بالفعل من قبل مستخدم آخر");
        }

        // Check if password should be updated
        $update_password = !empty($this->password);

        $query = "UPDATE " . $this->table_name . "
                SET name = :name,
                    username = :username";

        // Include password in update only if it's provided
        if ($update_password) {
            $query .= ", password = :password";
            $query .= ", password_hash = :credential_hash";
        }

        $query .= ", role = :role,
                   class_id = :class_id
                WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize inputs
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->username = htmlspecialchars(strip_tags($this->username));
        $this->role = htmlspecialchars(strip_tags($this->role));
        $this->id = htmlspecialchars(strip_tags($this->id));

        // Bind parameters
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':username', $this->username);

        if ($update_password) {
            $this->password = htmlspecialchars(strip_tags($this->password));
            $encrypted_password = encryptPassword($this->password);
            $credentialHash = password_hash($this->password, PASSWORD_DEFAULT);
            $stmt->bindParam(':password', $encrypted_password);
            $stmt->bindParam(':credential_hash', $credentialHash);
        }

        $stmt->bindParam(':role', $this->role);
        $stmt->bindParam(':class_id', $this->class_id);
        $stmt->bindParam(':id', $this->id);

        // Execute query
        if ($stmt->execute()) {
            // للطلاب: حدّث التسجيل السنوي عند تغيير الفصل
            if ($this->role === 'student') {
                $this->ensureEnrollmentForCurrentYear();
            }
            if ($ownsTransaction) {
                $after = $this->auditSupport->fetchUserRow((int)$this->id, false);
                if ($before != $after) {
                    (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordUpdate(
                        'user', 'users', (int)$this->id, (string)$this->name, $before, $after, 'تعديل مستخدم'
                    );
                }
                $this->conn->commit();
            }
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
     * Update student identity fields only; credentials remain owned by student_accounts.php.
     *
     * @return boolean
     */
    public function updateStudentIdentity()
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $before = $ownsTransaction ? $this->auditSupport->fetchUserRow((int)$this->id, true) : [];
        $query = "UPDATE " . $this->table_name . "
                  SET name = :name, class_id = :class_id
                  WHERE id = :id AND role = 'student'";
        $stmt = $this->conn->prepare($query);

        $this->name = htmlspecialchars(strip_tags((string) $this->name));
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':class_id', $this->class_id);
        $stmt->bindParam(':id', $this->id);

        if (!$stmt->execute()) {
            if ($ownsTransaction) $this->conn->rollBack();
            return false;
        }

        $this->role = 'student';
        $this->ensureEnrollmentForCurrentYear();
        if ($ownsTransaction) {
            $after = $this->auditSupport->fetchUserRow((int)$this->id, false);
            if ($before != $after) {
                (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordUpdate(
                    'student', 'users', (int)$this->id, (string)$this->name, $before, $after, 'تعديل هوية طالب'
                );
            }
            $this->conn->commit();
        }
        return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }
    public function delete()
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $before = $ownsTransaction ? $this->auditSupport->fetchUserRow((int)$this->id, true) : [];
        $query = "DELETE FROM " . $this->table_name . " WHERE id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);

        if ($stmt->execute()) {
            if ($ownsTransaction) {
                if ($before) {
                    (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordDelete(
                        'user', 'users', (int)$this->id, (string)($before['name'] ?? ('User #' . $this->id)), $before, 'حذف مستخدم'
                    );
                }
                $this->conn->commit();
            }
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
     * Batch import users from Excel
     * @param array $users Array of user data
     * @return boolean
     */
    public function batchImport($users)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $inserted = [];
        foreach ($users as $user) {
            $this->name = $user['name'];
            $this->username = $user['username'];
            $this->password = $user['password'];
            $this->role = $user['role'];
            $this->class_id = $user['class_id'];

            if (!$this->create()) {
                throw new RuntimeException('تعذر إنشاء أحد المستخدمين أثناء الاستيراد الدفعي.');
            }
            $row = $this->auditSupport->fetchUserRow((int)$this->id, false);
            $inserted[] = ['table' => 'users', 'record_id' => $row['id'], 'snapshot' => $row, 'description' => 'استيراد مستخدم'];
        }
        if ($inserted) {
            (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordReplacement(
                'user_import', null, 'استيراد مستخدمين', [], $inserted,
                ['summary' => 'استيراد مستخدمين دفعة واحدة', 'imported_count' => count($inserted)]
            );
        }
        if ($ownsTransaction) $this->conn->commit();
        return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            if (!$ownsTransaction) throw $e;
            return false;
        }
    }

    /**
     * Get users assigned to specific class
     * @param int $class_id
     * @return PDOStatement
     */
    public function getUsersByClass($class_id)
    {
        // طبقة الأعوام الدراسية: جلب طلاب الفصل من التسجيلات السنوية
        require_once __DIR__ . '/AcademicYear.php';
        require_once __DIR__ . '/StudentEnrollment.php';
        $yearId = AcademicYear::currentId($this->conn);
        if ($yearId > 0) {
            $query = "SELECT u.id, u.name, u.username
                      FROM " . $this->table_name . " u
                      JOIN student_enrollments se ON se.student_id = u.id
                          AND se.academic_year_id = :year_id
                          AND se.enrollment_status = 'enrolled'
                      WHERE se.class_id = :class_id
                      AND u.role = 'student'
                      ORDER BY u.name";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':year_id', $yearId, PDO::PARAM_INT);
            $stmt->bindParam(':class_id', $class_id);
            $stmt->execute();
        } else {
            // طبقة توافق قبل تطبيق الميزة
            $query = "SELECT id, name, username FROM " . $this->table_name . "
                      WHERE class_id = :class_id AND role = 'student' ORDER BY name";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':class_id', $class_id);
            $stmt->execute();
        }

        $students = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $students[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'username' => $row['username']
            ];
        }

        return $students;
    }

    /**
     * Get teachers assigned to specific class
     * @param int $class_id
     * @return PDOStatement
     */
    public function getStaffByClass($class_id)
    {
        $query = "SELECT u.id, u.name, u.username, u.role, u.is_supervisor
                  FROM " . $this->table_name . " u
                  WHERE u.class_id = :class_id
                    AND EXISTS (
                        SELECT 1 FROM user_role_assignments ura
                        WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active'
                    )
                  ORDER BY u.name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':class_id', $class_id);
        $stmt->execute();

        $staff = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $staff[] = $row;
        }

        return $staff;
    }

    /**
     * Read all staff (teachers + specialists + supervisors) with classes and subjects via GROUP_CONCAT
     * @return array
     */
    public function readAllStaff()
    {
        $query = "SELECT u.id, u.name, u.username, u.password, u.role, u.is_supervisor, u.status,
                         GROUP_CONCAT(DISTINCT
                              CASE WHEN u.role = 'specialist' THEN sc_c.name
                                   ELSE uca_c.name END
                              ORDER BY CASE WHEN u.role = 'specialist' THEN sc_c.name ELSE uca_c.name END
                              SEPARATOR ', ') as class_names,
                         GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as subject_names
                  FROM users u
                  LEFT JOIN user_class_access uca ON u.id = uca.user_id AND u.role = 'teacher'
                  LEFT JOIN classes uca_c ON uca.class_id = uca_c.id
                  LEFT JOIN specialist_active_classes sc ON u.id = sc.specialist_id AND u.role = 'specialist'
                  LEFT JOIN classes sc_c ON sc.class_id = sc_c.id
                  LEFT JOIN teacher_subjects ts ON u.id = ts.teacher_id AND u.role = 'teacher'
                  LEFT JOIN subjects s ON ts.subject_id = s.id
                  WHERE u.role IN ('teacher', 'specialist')
                  GROUP BY u.id
                  ORDER BY u.name";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        $staff = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['password'] = decryptPasswordForUser((string)($row['password'] ?? ''), (int)$row['id']);
            $staff[] = $row;
        }

        return $staff;
    }

    /**
     * Migrate user role, handling class assignment table changes
     * @param int $userId
     * @param string $oldRole
     * @param string $newRole
     * @return bool
     */
    public function migrateRole($userId, $oldRole, $newRole)
    {
        if ($oldRole === $newRole)
            return true;

        $ownsTransaction = !$this->conn->inTransaction();
        try {
            if ($ownsTransaction) $this->conn->beginTransaction();
            $beforeUser = $this->auditSupport->fetchUserRow((int)$userId, true);
            $beforeSets = [
                'user_class_access' => $this->auditSupport->fetchRowsForUser('user_class_access', 'user_id', (int)$userId, true),
                'specialist_grade_assignments' => $this->auditSupport->fetchRowsForUser('specialist_grade_assignments', 'specialist_id', (int)$userId, true),
                'specialist_class_assignments' => $this->auditSupport->fetchRowsForUser('specialist_class_assignments', 'specialist_id', (int)$userId, true),
                'teacher_subjects' => $this->auditSupport->fetchRowsForUser('teacher_subjects', 'teacher_id', (int)$userId, true),
            ];

            // Get current class assignments
            $currentClasses = [];
            if ($oldRole === 'specialist') {
                $stmt = $this->conn->prepare("SELECT class_id FROM specialist_active_classes WHERE specialist_id = ?");
                $stmt->execute([$userId]);
                $currentClasses = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $stmt = $this->conn->prepare("SELECT class_id FROM user_class_access WHERE user_id = ?");
                $stmt->execute([$userId]);
                $currentClasses = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            // Moving TO specialist from teacher/supervisor
            if ($newRole === 'specialist' && $oldRole !== 'specialist') {
                $this->removeAllClassAssignments($userId);
                // Remove teacher subjects (specialists don't have subjects)
                $stmt = $this->conn->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
                $stmt->execute([$userId]);
            }
            // Moving FROM specialist to teacher/supervisor
            elseif ($oldRole === 'specialist' && $newRole !== 'specialist') {
                $this->removeAllSpecialistClassAssignments($userId);
                foreach ($currentClasses as $classId) {
                    $clsObj = new ClassRoom($this->conn);
                    $clsObj->id = $classId;
                    $clsObj->assignStaff($userId);
                }
            }
            // Between teacher and supervisor — same tables, no migration needed

            // Update user role
            $stmt = $this->conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$newRole, $userId]);

            $this->clearAssignedClassesCache($userId);

            $afterUser = $this->auditSupport->fetchUserRow((int)$userId, false);
            $afterSets = [
                'user_class_access' => $this->auditSupport->fetchRowsForUser('user_class_access', 'user_id', (int)$userId, false),
                'specialist_grade_assignments' => $this->auditSupport->fetchRowsForUser('specialist_grade_assignments', 'specialist_id', (int)$userId, false),
                'specialist_class_assignments' => $this->auditSupport->fetchRowsForUser('specialist_class_assignments', 'specialist_id', (int)$userId, false),
                'teacher_subjects' => $this->auditSupport->fetchRowsForUser('teacher_subjects', 'teacher_id', (int)$userId, false),
            ];
            $this->auditSupport->auditRoleMigration((int)$userId, $beforeUser, $afterUser, $beforeSets, $afterSets, (string)$oldRole, (string)$newRole);
            if ($ownsTransaction) $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * Get classes assigned to a teacher, specialist or supervisor
     * @param int|null $userId Optional user ID (if not provided, uses the instance's ID)
     * @return array Array of classes
     */
    public function getAssignedClasses($userId = null)
    {
        $user_id = ($userId !== null) ? $userId : $this->id;

        // Simple session-based cache for assigned classes
        $cache_key = "assigned_classes_" . $user_id;
        if (isset($_SESSION[$cache_key]) && !empty($_SESSION[$cache_key])) {
            return $_SESSION[$cache_key];
        }

        // Get user role to determine which table to use
        $role_query = "SELECT role FROM users WHERE id = :user_id";
        $role_stmt = $this->conn->prepare($role_query);
        $role_stmt->bindParam(':user_id', $user_id);
        $role_stmt->execute();
        $user_role = $role_stmt->fetchColumn();

        if ($user_role === 'specialist') {
            // Specialist scope has one annual source of truth exposed by a read-only compatibility view.
            $query = "SELECT c.id, c.name
                      FROM classes c
                      JOIN specialist_active_classes sc ON c.id = sc.class_id
                      WHERE sc.specialist_id = :user_id
                      ORDER BY c.name";
        } else {
            // For teachers and supervisors, use user_class_access table
            $query = "SELECT c.id, c.name
                      FROM classes c
                      JOIN user_class_access uca ON c.id = uca.class_id
                      WHERE uca.user_id = :user_id
                      ORDER BY c.name";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        $classes = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $classes[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'stage' => '' // إضافة قيمة فارغة للتوافق
            ];
        }

        // Cache for current session
        $_SESSION[$cache_key] = $classes;

        return $classes;
    }

    /**
     * Clear assigned classes cache for a user
     * @param int $userId
     */
    public function clearAssignedClassesCache($userId = null)
    {
        $user_id = ($userId !== null) ? $userId : $this->id;
        $cache_key = "assigned_classes_" . $user_id;
        if (isset($_SESSION[$cache_key])) {
            unset($_SESSION[$cache_key]);
        }
    }    /**
         * Read students by class
         * @param int $class_id
         * @return array
         */
    public function readStudentsByClass($class_id)
    {
        $query = "SELECT id, name, username, role 
                  FROM " . $this->table_name . " 
                  WHERE class_id = :class_id
                  AND role = 'student'
                  ORDER BY name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':class_id', $class_id);
        $stmt->execute();

        $students = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $students[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'username' => $row['username'],
                'role' => $row['role']
            ];
        }

        return $students;
    }    /**
         * Get all users by role as array
         * @param string $role
         * @return array
         */
    public function getAllByRole($role)
    {
        return $this->readByRole($role);
    }

    /**
     * Assign a user to a class
     * @param int $user_id
     * @param int $class_id
     * @return boolean
     */
    public function assignToClass($user_id, $class_id)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $query = "INSERT INTO user_class_access (user_id, class_id) 
                  VALUES (:user_id, :class_id)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':class_id', $class_id);

        $result = $stmt->execute();
        if ($result && $ownsTransaction) {
            $id = (int)$this->conn->lastInsertId();
            $after = $this->auditSupport->fetchTableRow('user_class_access', $id, false);
            (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordInsert(
                'user_class_assignment', 'user_class_access', $id, 'إسناد مستخدم #' . (int)$user_id, $after, 'إسناد مستخدم إلى فصل'
            );
            $this->conn->commit();
        } elseif ($ownsTransaction) {
            $this->conn->rollBack();
        }
        return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * Remove all class assignments for a user
     * @param int $user_id
     * @return boolean
     */
    public function removeAllClassAssignments($user_id)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $beforeStmt = $this->conn->prepare('SELECT * FROM user_class_access WHERE user_id = ? ORDER BY id FOR UPDATE');
        $beforeStmt->execute([(int)$user_id]);
        $beforeRows = $beforeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $query = "DELETE FROM user_class_access WHERE user_id = :user_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);

        $result = $stmt->execute();
        if ($result && $ownsTransaction) {
            $this->auditSupport->auditDeletedRows('user_class_assignment', (int)$user_id, 'إزالة إسنادات الفصول', 'user_class_access', $beforeRows);
            $this->conn->commit();
        } elseif ($ownsTransaction) {
            $this->conn->rollBack();
        }
        return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * Legacy assignment entrypoint retained only to fail closed.
     * @param int $specialist_id
     * @param int $class_id
     * @return boolean
     */
    public function assignSpecialistToClass($specialist_id, $class_id)
    {
        throw new BadMethodCallException('استخدم SpecialistAcademicScopeService لتعيين نطاق الأخصائي السنوي.');
    }

    /**
     * Remove all specialist class assignments
     * @param int $specialist_id
     * @return boolean
     */
    public function removeAllSpecialistClassAssignments($specialist_id)
    {
        (new \EduCore\Modules\Staff\SpecialistAcademicScopeService($this->conn))->removeAllAssignments(
            (int)$specialist_id,
            (int)($_SESSION['user_id'] ?? 0),
            'إزالة جميع تعيينات الأخصائي'
        );
        $this->clearAssignedClassesCache((int)$specialist_id);
        return true;
    }

    /**
     * Check if user is assigned to a specific class
     * @param int $user_id
     * @param int $class_id
     * @return boolean
     */
    public function isUserAssignedToClass($user_id, $class_id)
    {
        // Get user role to determine which table to check
        $role_query = "SELECT role FROM users WHERE id = :user_id";
        $role_stmt = $this->conn->prepare($role_query);
        $role_stmt->bindParam(':user_id', $user_id);
        $role_stmt->execute();
        $user_role = $role_stmt->fetchColumn();

        if ($user_role === 'specialist') {
            // Specialists use the current annual scope compatibility view.
            $query = "SELECT COUNT(*) FROM specialist_active_classes
                      WHERE specialist_id = :user_id AND class_id = :class_id";
        } else {
            // For teachers and supervisors, check user_class_access table
            $query = "SELECT COUNT(*) FROM user_class_access 
                      WHERE user_id = :user_id AND class_id = :class_id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':class_id', $class_id);
        $stmt->execute();

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Get students by class as array
     * @param int $class_id
     * @return array
     */
    public function getStudentsByClass($class_id)
    {
        $query = "SELECT u.id, u.name, u.username, u.class_id, u.status, c.name as class_name
                  FROM " . $this->table_name . " u
                  LEFT JOIN classes c ON u.class_id = c.id
                  WHERE u.class_id = :class_id
                  AND u.role = 'student'
                  ORDER BY u.name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':class_id', $class_id);
        $stmt->execute();

        $students = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $students[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'username' => $row['username'],
                'class_id' => $row['class_id'],
                'class_name' => $row['class_name'],
                'status' => $row['status']
            ];
        }

        return $students;
    }

    /**
     * Get students by teacher
     * @param int $teacher_id
     * @return array
     */
    public function getStudentsByTeacher($teacher_id)
    {
        $query = "SELECT DISTINCT u.id, u.name, u.username 
                  FROM " . $this->table_name . " u
                  JOIN user_class_access uca1 ON u.id = uca1.user_id
                  JOIN user_class_access uca2 ON uca1.class_id = uca2.class_id
                  WHERE uca2.user_id = :teacher_id
                  AND u.role = 'student'
                  ORDER BY u.name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':teacher_id', $teacher_id);
        $stmt->execute();

        $students = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $students[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'username' => $row['username']
            ];
        }

        return $students;
    }

    /**
     * Get students by class for a specific teacher
     * @param int $class_id
     * @param int $teacher_id
     * @return array
     */
    public function getStudentsByClassForTeacher($class_id, $teacher_id)
    {
        $query = "SELECT DISTINCT u.id, u.name, u.username, u.class_id, u.status, c.name as class_name
                  FROM " . $this->table_name . " u
                  JOIN classes c ON u.class_id = c.id
                  JOIN user_class_access uca ON u.class_id = uca.class_id
                  WHERE u.class_id = :class_id
                  AND uca.user_id = :teacher_id
                  AND u.role = 'student'
                  ORDER BY u.name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':class_id', $class_id);
        $stmt->bindParam(':teacher_id', $teacher_id);
        $stmt->execute();

        $students = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $students[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'username' => $row['username'],
                'class_id' => $row['class_id'],
                'class_name' => $row['class_name'],
                'status' => $row['status']
            ];
        }

        return $students;
    }

    /**
     * Get teachers by class for specialist
     * @param int $class_id
     * @return array
     */
    public function getTeachersByClass($class_id)
    {
        // Get teachers from user_class_access table
        $query_teachers = "SELECT DISTINCT u.id, u.name, u.username, 'teacher' AS role, u.status
                          FROM " . $this->table_name . " u
                          JOIN user_class_access uca ON u.id = uca.user_id
                          WHERE uca.class_id = :class_id
                          AND EXISTS (
                              SELECT 1 FROM user_role_assignments ura
                              WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active'
                          )
                          ORDER BY u.name";

        $stmt_teachers = $this->conn->prepare($query_teachers);
        $stmt_teachers->bindParam(':class_id', $class_id);
        $stmt_teachers->execute();

        $users = [];
        $seenUserIds = [];
        while ($row = $stmt_teachers->fetch(PDO::FETCH_ASSOC)) {
            $seenUserIds[(int)$row['id']] = true;
            $users[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'username' => $row['username'],
                'role' => $row['role'],
                'status' => $row['status']
            ];
        }

        // Get specialists from the active annual scope.
        $query_specialists = "SELECT DISTINCT u.id, u.name, u.username, 'specialist' AS role, u.status
                             FROM " . $this->table_name . " u
                             JOIN specialist_active_classes sc ON u.id = sc.specialist_id
                             WHERE sc.class_id = :class_id
                             ORDER BY u.name";

        $stmt_specialists = $this->conn->prepare($query_specialists);
        $stmt_specialists->bindParam(':class_id', $class_id);
        $stmt_specialists->execute();

        while ($row = $stmt_specialists->fetch(PDO::FETCH_ASSOC)) {
            if (isset($seenUserIds[(int)$row['id']])) {
                continue;
            }
            $users[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'username' => $row['username'],
                'role' => $row['role'],
                'status' => $row['status']
            ];
        }

        return $users;
    }

    /**
     * Get student class from users table
     * @param int $student_id     * @return array|null Student class info or null if no class assigned
     */
    /**
     * Get a student's class information
     * @param int|null $student_id - Optional student ID. If not provided, uses the object's ID
     * @return mixed - Returns class_id if no student_id is provided, or an array with class details if student_id is provided
     */
    public function getStudentClass($student_id = null)
    {
        // If student_id is not provided, use the object's ID and return just the class_id
        if ($student_id === null) {
            if (!$this->id) {
                return null;
            }

            $query = "SELECT class_id 
                      FROM " . $this->table_name . "
                      WHERE id = :student_id
                      AND role = 'student'";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $this->id);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row['class_id'];
            }

            return null;
        }

        // Original implementation for when student_id is provided
        $query = "SELECT c.id, c.name 
                  FROM classes c
                  JOIN " . $this->table_name . " u ON c.id = u.class_id
                  WHERE u.id = :student_id
                  AND u.role = 'student'";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'id' => $row['id'],
                'name' => $row['name']
            ];
        }

        return null;
    }
    /**
     * Get students with their total points (including custom points)
     * @param int $class_id Optional class ID to filter by
     * @param array $allowed_class_ids Optional array of allowed class IDs for specialist
     * @return array Array of students with their total points
     */
    public function getStudentsWithPoints($class_id = null, $allowed_class_ids = null)
    {
        $query = "SELECT u.id, u.name, u.username, u.class_id, u.status, c.name as class_name,
                  sp.student_code,
                  COALESCE(SUM(
                    CASE 
                        WHEN e.custom_points IS NOT NULL THEN 
                            e.custom_points
                        ELSE 
                            CASE WHEN et.type = 'positive' THEN et.points ELSE -et.points END
                    END
                  ), 0) AS total_points
                  FROM " . $this->table_name . " u
                  LEFT JOIN classes c ON u.class_id = c.id
                  LEFT JOIN student_profiles sp ON u.id = sp.user_id
                  LEFT JOIN evaluations e ON u.id = e.student_id
                  LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE u.role = 'student'";

        $params = [];

        // Add class filter if specified
        if ($class_id) {
            $query .= " AND u.class_id = :class_id";
            $params[':class_id'] = $class_id;
        }

        // Add allowed classes filter for specialist
        if (!empty($allowed_class_ids)) {
            $placeholders = implode(',', array_map('intval', $allowed_class_ids));
            $query .= " AND u.class_id IN ($placeholders)";
        }

        $query .= " GROUP BY u.id, u.name, u.username, u.class_id, u.status, c.name, sp.student_code
                   ORDER BY u.name";

        $stmt = $this->conn->prepare($query);

        if ($class_id) {
            $stmt->bindParam(':class_id', $class_id);
        }

        try {
            $stmt->execute();

            $students = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $students[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'username' => $row['username'],
                    'class_id' => $row['class_id'] ?? null,
                    'class_name' => $row['class_name'] ?? null,
                    'student_code' => $row['student_code'] ?? null,
                    'status' => $row['status'] ?? 'active',
                    'total_points' => $row['total_points']
                ];
            }

            return $students;
        } catch (PDOException $e) {
            error_log("Database error in getStudentsWithPoints: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get students for export (alias for getStudentsWithPoints)
     * @param int $class_id Optional class ID to filter by
     * @param array $allowed_class_ids Optional array of allowed class IDs for specialist
     * @return array Array of students with their total points
     */
    public function getStudentsForExport($class_id = null, $allowed_class_ids = null)
    {
        return $this->getStudentsWithPoints($class_id, $allowed_class_ids);
    }

    /**
     * Get students with points using server-side pagination for faster list rendering.
     * @param int|null $class_id Optional class ID filter
     * @param array|null $allowed_class_ids Optional allowed class IDs
     * @param int $limit rows per page
     * @param int $offset starting row
     * @param int|null &$totalCount total rows before pagination
     * @return array
     */
    public function getStudentsWithPointsPaginated($class_id = null, $allowed_class_ids = null, $limit = 100, $offset = 0, &$totalCount = 0, $grade_id = null, $stage_id = null, $scope = 'current', $account_status = null)
    {
        require_once __DIR__ . '/AcademicYear.php';
        $yearId = AcademicYear::currentId($this->conn);
        $useEnrollments = ($yearId > 0);

        $where = "u.role = 'student' AND u.deleted_at IS NULL";
        $countParams = [];
        $dataParams = [];

        // scope يعتمد على حالة التسجيل السنوي (إن وُجدت) مع fallback للحالة العامة
        if ($useEnrollments && $scope === 'graduates') {
            $where .= " AND (se.academic_status = 'graduated' OR se.enrollment_status = 'graduated')";
        } elseif ($useEnrollments && $scope === 'transferred') {
            $where .= " AND se.enrollment_status = 'transferred'";
        } elseif ($useEnrollments && $scope === 'discontinued') {
            $where .= " AND se.enrollment_status IN ('discontinued', 'withdrawn')";
        } elseif ($useEnrollments) {
            $where .= " AND se.enrollment_status = 'enrolled' AND se.academic_status <> 'graduated'";
        } elseif ($scope === 'graduates') {
            $where .= " AND COALESCE(sp.enrollment_status, IF(u.status = 'graduated', 'graduated', 'enrolled')) = 'graduated'";
        } elseif ($scope === 'transferred') {
            $where .= " AND COALESCE(sp.enrollment_status, 'enrolled') = 'transferred'";
        } elseif ($scope === 'discontinued') {
            $where .= " AND COALESCE(sp.enrollment_status, 'enrolled') IN ('discontinued', 'withdrawn')";
        } else {
            $where .= " AND COALESCE(sp.enrollment_status, IF(u.status = 'graduated', 'graduated', 'enrolled')) = 'enrolled'";
        }

        if (in_array($account_status, ['active', 'inactive', 'graduated'], true)) {
            $where .= " AND u.status = :account_status";
            $countParams[':account_status'] = $account_status;
            $dataParams[':account_status'] = $account_status;
        }

        if ($useEnrollments) {
            // فلاتر المرحلة/الصف/الفصل تُطبَّق على التسجيل السنوي للعام الحالي
            if ($class_id) {
                if (is_array($class_id)) {
                    $classIds = array_map('intval', $class_id);
                    if (!empty($classIds)) {
                        $where .= " AND se.class_id IN (" . implode(',', $classIds) . ")";
                    }
                } else {
                    $where .= " AND se.class_id = :class_id";
                    $countParams[':class_id'] = (int) $class_id;
                    $dataParams[':class_id'] = (int) $class_id;
                }
            }
            if (!empty($allowed_class_ids)) {
                $allowedIds = array_map('intval', $allowed_class_ids);
                $where .= " AND se.class_id IN (" . implode(',', $allowedIds) . ")";
            }
            if ($grade_id) {
                if (is_array($grade_id)) {
                    $gradeIds = array_map('intval', $grade_id);
                    if (!empty($gradeIds)) {
                        $where .= " AND se.grade_id IN (" . implode(',', $gradeIds) . ")";
                    }
                } else {
                    $where .= ' AND se.grade_id = :grade_id';
                    $countParams[':grade_id'] = (int) $grade_id;
                    $dataParams[':grade_id'] = (int) $grade_id;
                }
            }
            if ($stage_id) {
                if (is_array($stage_id)) {
                    $stageIds = array_map('intval', $stage_id);
                    if (!empty($stageIds)) {
                        $where .= " AND se.stage_id IN (" . implode(',', $stageIds) . ")";
                    }
                } else {
                    $where .= ' AND se.stage_id = :stage_id';
                    $countParams[':stage_id'] = (int) $stage_id;
                    $dataParams[':stage_id'] = (int) $stage_id;
                }
            }
            // قصر النتائج على المسجّلين في العام الحالي
            $where .= " AND se.academic_year_id = :year_id";
            $countParams[':year_id'] = $yearId;
            $dataParams[':year_id'] = $yearId;

            $enrollJoin = "JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = :year_id
                           LEFT JOIN classes c ON c.id = se.class_id
                           LEFT JOIN grades g ON g.id = se.grade_id
                           LEFT JOIN stages s ON s.id = se.stage_id";
        } else {
            // طبقة توافق: استخدم users.class_id مباشرة
            if ($class_id) {
                if (is_array($class_id)) {
                    $classIds = array_map('intval', $class_id);
                    if (!empty($classIds)) {
                        $where .= " AND u.class_id IN (" . implode(',', $classIds) . ")";
                    }
                } else {
                    $where .= " AND u.class_id = :class_id";
                    $countParams[':class_id'] = (int) $class_id;
                    $dataParams[':class_id'] = (int) $class_id;
                }
            }
            if (!empty($allowed_class_ids)) {
                $allowedIds = array_map('intval', $allowed_class_ids);
                $where .= " AND u.class_id IN (" . implode(',', $allowedIds) . ")";
            }
            if ($grade_id) {
                if (is_array($grade_id)) {
                    $gradeIds = array_map('intval', $grade_id);
                    if (!empty($gradeIds)) {
                        $where .= " AND c.grade_id IN (" . implode(',', $gradeIds) . ")";
                    }
                } else {
                    $where .= ' AND c.grade_id = :grade_id';
                    $countParams[':grade_id'] = (int) $grade_id;
                    $dataParams[':grade_id'] = (int) $grade_id;
                }
            }
            if ($stage_id) {
                if (is_array($stage_id)) {
                    $stageIds = array_map('intval', $stage_id);
                    if (!empty($stageIds)) {
                        $where .= " AND g.stage_id IN (" . implode(',', $stageIds) . ")";
                    }
                } else {
                    $where .= ' AND g.stage_id = :stage_id';
                    $countParams[':stage_id'] = (int) $stage_id;
                    $dataParams[':stage_id'] = (int) $stage_id;
                }
            }
            $enrollJoin = "LEFT JOIN classes c ON c.id = u.class_id
                           LEFT JOIN grades g ON g.id = c.grade_id
                           LEFT JOIN stages s ON s.id = g.stage_id";
        }

        $countSql = "SELECT COUNT(*) FROM {$this->table_name} u
                     {$enrollJoin}
                     LEFT JOIN student_profiles sp ON sp.user_id = u.id
                     WHERE {$where}";
        $countStmt = $this->conn->prepare($countSql);
        foreach ($countParams as $k => $v) {
            $countStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();
        $totalCount = (int) $countStmt->fetchColumn();

        $dataSql = "SELECT u.id, u.name, u.username, u.class_id, u.status, c.name AS class_name,
                           (SELECT MAX(sa.id) FROM student_attachments sa WHERE sa.user_id = u.id AND sa.label = 'الصورة الشخصية') AS profile_image_id,
                           sp.student_code, sp.enrollment_status,
                           setr.destination AS transfer_destination, setr.transfer_date AS external_transfer_date,
                           COALESCE(ep.total_points, 0) AS total_points
                    FROM {$this->table_name} u
                    {$enrollJoin}
                    LEFT JOIN student_profiles sp ON u.id = sp.user_id
                    LEFT JOIN student_external_transfers setr ON setr.student_id = u.id
                    LEFT JOIN (
                        SELECT e.student_id,
                               COALESCE(SUM(
                                   CASE
                                       WHEN e.custom_points IS NOT NULL THEN e.custom_points
                                       WHEN et.type = 'positive' THEN et.points
                                       ELSE -et.points
                                   END
                               ), 0) AS total_points
                        FROM evaluations e
                        LEFT JOIN evaluation_types et ON e.evaluation_type_id = et.id
                        GROUP BY e.student_id
                    ) ep ON ep.student_id = u.id
                    WHERE {$where}
                    ORDER BY u.name
                    LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($dataSql);
        foreach ($dataParams as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Database error in getStudentsWithPointsPaginated: " . $e->getMessage());
            $totalCount = 0;
            return [];
        }
    }

    public function getStudentsPaginated($class_id = null, $allowed_class_ids = null, $limit = 100, $offset = 0, &$totalCount = 0, $grade_id = null, $stage_id = null, $scope = 'current', $account_status = null, $searchTerm = null, $orderBy = 'name', $orderDirection = 'asc')
    {
        $repository = new \EduCore\Modules\Students\StudentListReadRepository($this->conn, $this->table_name);
        return $repository->fetch(
            $class_id,
            $allowed_class_ids,
            $limit,
            $offset,
            $totalCount,
            $grade_id,
            $stage_id,
            $scope,
            $account_status,
            $searchTerm,
            $orderBy,
            $orderDirection
        );
    }

    public function getStudentsByClasses($class_ids)
    {
        $repository = new \EduCore\Modules\Students\StudentListReadRepository($this->conn, $this->table_name);
        return $repository->getStudentsByClasses($class_ids);
    }

    /**
     * Reset student points to zero by deleting all evaluations
     * @param int $student_id
     * @return bool
     */
    public function resetStudentPoints($student_id)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        try {
            if ($ownsTransaction) $this->conn->beginTransaction();
            $beforeStmt = $this->conn->prepare('SELECT * FROM evaluations WHERE student_id = ? ORDER BY id FOR UPDATE');
            $beforeStmt->execute([(int)$student_id]);
            $beforeRows = $beforeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Delete all evaluations for this student
            $query = "DELETE FROM evaluations WHERE student_id = :student_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->execute();

            if ($beforeRows) {
                $deleted = array_map(static fn(array $row): array => [
                    'table' => 'evaluations', 'record_id' => $row['id'], 'snapshot' => $row, 'description' => 'إعادة تعيين نقاط طالب',
                ], $beforeRows);
                (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordReplacement(
                    'student_points_reset', (int)$student_id, 'نقاط طالب #' . (int)$student_id,
                    $deleted, [], ['summary' => 'حذف تقييمات الطالب لإعادة النقاط', 'evaluation_count' => count($beforeRows)]
                );
            }
            if ($ownsTransaction) $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollback();
            if (!$ownsTransaction) throw $e;
            return false;
        }
    }

    /**
     * Calculate student's total points from evaluations
     * @param int $student_id
     * @return int
     */
    public function calculateStudentPoints($student_id)
    {
        $query = "SELECT SUM(
                    CASE 
                        WHEN e.custom_points IS NOT NULL AND e.custom_points != 0 
                        THEN e.custom_points
                        ELSE et.points 
                    END
                  ) as total_points
                  FROM evaluations e
                  JOIN evaluation_types et ON e.evaluation_type_id = et.id
                  WHERE e.student_id = :student_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_points'] ?? 0;
    }

    /**
     * Count active teachers
     * @return int
     */
    public function countActiveTeachers()
    {
        return $this->countByRoleAndStatus('teacher', 'active');
    }

    /**
     * Count inactive teachers
     * @return int
     */
    public function countInactiveTeachers()
    {
        return $this->countByRoleAndStatus('teacher', 'inactive');
    }

    /**
     * Count users by role and status
     * @param string $role
     * @param string $status
     * @return int
     */
    public function countByRoleAndStatus($role, $status)
    {
        $query = "SELECT COUNT(*) as total
                  FROM " . $this->table_name . " u
                  WHERE u.status = :status
                    AND EXISTS (
                        SELECT 1 FROM user_role_assignments ura
                        WHERE ura.user_id = u.id AND ura.role_key = :role AND ura.status = 'active'
                    )";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':status', $status);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'] ?? 0;
    }

    /**
     * Check if user exists but is inactive
     * @return bool
     */
    public function isUserInactive()
    {
        $query = "SELECT status FROM " . $this->table_name . " WHERE username = :username LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $this->username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['status'] === 'inactive';
        }

        return false;
    }

    /**
     * Check if user exists but is graduated
     * @return bool
     */
    public function isUserGraduated()
    {
        return $this->getStudentLoginBlockReason(null, $this->username) === 'graduated';
    }

    /**
     * Check if a student was transferred out of school and must not log in.
     * @return bool
     */
    public function isUserTransferred()
    {
        return $this->getStudentLoginBlockReason(null, $this->username) === 'transferred';
    }

    /**
     * Get the reason a student login should be blocked, if any.
     *
     * @param int|null $studentId
     * @param string|null $username
     * @return string|null graduated|transferred|inactive|null
     */
    public function getStudentLoginBlockReason($studentId = null, $username = null)
    {
        if (!$studentId && !$username) {
            $username = $this->username;
        }

        $where = $studentId ? 'id = :student_id' : 'username = :username';
        $stmt = $this->conn->prepare("SELECT id, role, status FROM " . $this->table_name . " WHERE {$where} LIMIT 1");
        if ($studentId) {
            $stmt->bindValue(':student_id', (int) $studentId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':username', $username);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || ($row['role'] ?? '') !== 'student') {
            return null;
        }

        $studentId = (int) $row['id'];
        if (($row['status'] ?? '') === 'graduated') {
            return 'graduated';
        }

        if ($this->tableExists('student_profiles')) {
            $profileStmt = $this->conn->prepare("SELECT enrollment_status FROM student_profiles WHERE user_id = ? AND enrollment_status IN ('graduated', 'transferred', 'discontinued', 'withdrawn') LIMIT 1");
            $profileStmt->execute([$studentId]);
            $profileStatus = $profileStmt->fetchColumn();
            if (in_array($profileStatus, ['graduated', 'transferred', 'discontinued', 'withdrawn'], true)) {
                return $profileStatus === 'withdrawn' ? 'discontinued' : $profileStatus;
            }
        }

        if ($this->tableExists('student_enrollments') && $this->tableExists('academic_years')) {
            $enrollmentStmt = $this->conn->prepare("SELECT CASE
                    WHEN se.academic_status = 'graduated' OR se.enrollment_status = 'graduated' THEN 'graduated'
                    WHEN se.enrollment_status = 'withdrawn' THEN 'discontinued'
                    ELSE se.enrollment_status
                END AS terminal_status
                FROM student_enrollments se
                INNER JOIN academic_years ay ON ay.id = se.academic_year_id
                WHERE se.student_id = ? AND ay.is_active = 1
                  AND (
                    se.academic_status = 'graduated'
                    OR se.enrollment_status IN ('graduated', 'transferred', 'discontinued', 'withdrawn')
                  )
                ORDER BY se.id DESC
                LIMIT 1");
            $enrollmentStmt->execute([$studentId]);
            $enrollmentStatus = $enrollmentStmt->fetchColumn();
            if (in_array($enrollmentStatus, ['graduated', 'transferred', 'discontinued'], true)) {
                return $enrollmentStatus;
            }
        }

        if (($row['status'] ?? '') === 'inactive') {
            return 'inactive';
        }

        return null;
    }

    private function tableExists(string $tableName): bool
    {
        $stmt = $this->conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$tableName]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Update user status
     * @param string $status New status ('active' or 'inactive')
     * @return bool Success status
     */
    public function updateStatus($status)
    {
        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) $this->conn->beginTransaction();
        try {
        $before = $ownsTransaction ? $this->auditSupport->fetchUserRow((int)$this->id, true) : [];
        $query = "UPDATE " . $this->table_name . " 
                  SET status = :status 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $this->id);

        $result = $stmt->execute();

        if ($result && $ownsTransaction) {
            $after = $this->auditSupport->fetchUserRow((int)$this->id, false);
            if ($before != $after) {
                (new \EduCore\Modules\Operations\Audit\AuditService($this->conn))->recordUpdate(
                    'user', 'users', (int)$this->id, (string)($after['name'] ?? ('User #' . $this->id)), $before, $after, 'تغيير حالة مستخدم'
                );
            }
            $this->conn->commit();
        } elseif (!$result && $ownsTransaction) {
            $this->conn->rollBack();
        }

        return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        }
    }

}
