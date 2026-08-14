<?php
/**
 * Fee Payments — سداد الرسوم والمصاريف
 * Tab 1: Student list with fee status & payment actions
 * Tab 2: Operations log from activity_logs
 */
$page_title = "سداد الرسوم والمصاريف";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/user.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/AcademicYearWriteGuard.php';
require_once '../classes/FeePaymentListQuery.php';
require_once '../classes/StudentOperationalGuard.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');
require_once __DIR__ . '/../classes/FinanceLegacyAdapter.php';
FinanceLegacyAdapter::delegateRequestIfEnabled(__FILE__);

$database = new Database();
$db = $database->getConnection();
$studentOperationalGuard = new StudentOperationalGuard($db);
$currentAcademicYearId = AcademicYear::currentId($db);

// PRG: session messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Academic year
$current_year = date('Y');
$current_month = date('n');
if ($current_month >= 9) {
    $academic_year = $current_year . '-' . ($current_year + 1);
} else {
    $academic_year = ($current_year - 1) . '-' . $current_year;
}
$selected_year = $_GET['year'] ?? $academic_year;

function feePaymentsAssertYearWritable(PDO $db, string $yearName): int
{
    $stmt = $db->prepare('SELECT id FROM academic_years WHERE name = ? LIMIT 1');
    $stmt->execute([$yearName]);
    $yearId = (int) $stmt->fetchColumn();
    if ($yearId <= 0) {
        throw new RuntimeException('العام الدراسي المالي غير معروف.');
    }
    (new AcademicYearWriteGuard($db))->assertWritable($yearId);
    return $yearId;
}

// Tab
$activeTab = $_GET['tab'] ?? 'students';
$validTabs = ['students', 'log'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'students';

// Filters
$filter_stage_ids = isset($_GET['stage_id']) ? (is_array($_GET['stage_id']) ? array_filter(array_map('intval', $_GET['stage_id'])) : array_filter(array_map('intval', explode(',', $_GET['stage_id'])))) : [];
$filter_grade_ids = isset($_GET['grade_id']) ? (is_array($_GET['grade_id']) ? array_filter(array_map('intval', $_GET['grade_id'])) : array_filter(array_map('intval', explode(',', $_GET['grade_id'])))) : [];
$filter_class_ids = isset($_GET['class_id']) ? (is_array($_GET['class_id']) ? array_filter(array_map('intval', $_GET['class_id'])) : array_filter(array_map('intval', explode(',', $_GET['class_id'])))) : [];
$filter_statuses = isset($_GET['fee_status']) ? (is_array($_GET['fee_status']) ? $_GET['fee_status'] : explode(',', $_GET['fee_status'])) : [];

$filter_stage = implode(',', $filter_stage_ids);
$filter_grade = implode(',', $filter_grade_ids);
$filter_class = implode(',', $filter_class_ids);
$filter_status = implode(',', $filter_statuses);

// =============== AJAX ENDPOINTS ===============
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Get student fee details + payment history
    if ($_GET['ajax'] === 'get_student_fee') {
        $student_id = (int)($_GET['student_id'] ?? 0);
        $year = $_GET['year'] ?? $selected_year;

        // Student info (فصل الطالب من تسجيل العام الحالي)
        if ($currentAcademicYearId > 0) {
            $stmt = $db->prepare("SELECT u.id, u.name, u.status, c.name as class_name, g.grade_name, s.stage_name, c.grade_id,
                sp.student_code
                FROM users u
                LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                LEFT JOIN classes c ON c.id = se.class_id
                LEFT JOIN grades g ON c.grade_id = g.id
                LEFT JOIN stages s ON g.stage_id = s.id
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                WHERE u.id = ?");
            $stmt->execute([$currentAcademicYearId, $student_id]);
        } else {
            $stmt = $db->prepare("SELECT u.id, u.name, u.status, c.name as class_name, g.grade_name, s.stage_name, c.grade_id,
                sp.student_code
                FROM users u
                LEFT JOIN classes c ON u.class_id = c.id
                LEFT JOIN grades g ON c.grade_id = g.id
                LEFT JOIN stages s ON g.stage_id = s.id
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                WHERE u.id = ?");
            $stmt->execute([$student_id]);
        }
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'الطالب غير موجود']);
            exit();
        }

        // Fee record
        $stmt = $db->prepare("SELECT * FROM student_fees WHERE student_id = ? AND academic_year = ?");
        $stmt->execute([$student_id, $year]);
        $fee = $stmt->fetch(PDO::FETCH_ASSOC);

        // Payment history
        $payments = [];
        if ($fee) {
            $stmt = $db->prepare("SELECT fp.*, u.name as received_by_name
                FROM fee_payments fp
                LEFT JOIN users u ON fp.received_by = u.id
                WHERE fp.student_fee_id = ?
                ORDER BY fp.payment_date DESC, fp.created_at DESC");
            $stmt->execute([$fee['id']]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Siblings
        $stmt = $db->prepare("SELECT ss.sibling_id, u.name as sibling_name, g.grade_name
            FROM student_siblings ss
            JOIN users u ON ss.sibling_id = u.id
            LEFT JOIN classes c ON u.class_id = c.id
            LEFT JOIN grades g ON c.grade_id = g.id
            WHERE ss.student_id = ? AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL");
        $stmt->execute([$student_id]);
        $siblings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Installments for the grade (من صف الطالب في العام الحالي)
        $installments = [];
        if ($fee && !empty($student['grade_id'])) {
            $stmt = $db->prepare("SELECT fi.* FROM fee_installments fi
                JOIN fee_structure fs ON fi.fee_structure_id = fs.id
                WHERE fs.grade_id = ? AND fs.academic_year = ?
                ORDER BY fi.display_order");
            $stmt->execute([$student['grade_id'], $year]);
            $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Other discounts assigned
        $otherDiscounts = [];
        if ($fee) {
            $stmt = $db->prepare("SELECT sod.*, od.name as discount_name, od.discount_type
                FROM student_other_discounts sod
                JOIN other_discounts od ON sod.other_discount_id = od.id
                WHERE sod.student_fee_id = ?");
            $stmt->execute([$fee['id']]);
            $otherDiscounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // سجل المديونيات عبر الأعوام السابقة (لعرضها للطالب)
        $priorBalances = [];
        $priorTotal = 0;
        try {
            $pbStmt = $db->prepare("SELECT b.academic_year_id, ay.name AS year_name,
                    b.total_due, b.total_paid, b.balance, b.carried_forward
                FROM student_fee_balances_history b
                JOIN academic_years ay ON ay.id = b.academic_year_id
                WHERE b.student_id = ? AND b.balance > 0
                ORDER BY ay.name DESC");
            $pbStmt->execute([$student_id]);
            $priorBalances = $pbStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($priorBalances as $pb) {
                $priorTotal += (float)$pb['balance'];
            }
        } catch (Throwable $pbErr) {
            // الجدول قد لا يكون موجوداً — تجاهل بهدوء
        }

        echo json_encode([
            'success' => true,
            'student' => $student,
            'fee' => $fee,
            'payments' => $payments,
            'siblings' => $siblings,
            'installments' => $installments,
            'other_discounts' => $otherDiscounts,
            'prior_balances' => $priorBalances,
            'prior_total' => $priorTotal
        ]);
        exit();
    }

    // Record a payment
    if ($_GET['ajax'] === 'record_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        // CSRF
        if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الأمان']);
            exit();
        }

        $student_id = (int)($input['student_id'] ?? 0);
        $amount = (float)($input['amount'] ?? 0);
        $payment_date = $input['payment_date'] ?? date('Y-m-d');
        $payment_method = $input['payment_method'] ?? 'cash';
        $receipt_number = trim($input['receipt_number'] ?? '');
        $notes = trim($input['notes'] ?? '');
        $year = $input['year'] ?? $selected_year;

        $valid_methods = ['cash', 'bank_transfer', 'check', 'other'];
        if (!in_array($payment_method, $valid_methods)) $payment_method = 'cash';

        if ($student_id <= 0 || $amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة']);
            exit();
        }

        try {
            $db->beginTransaction();
            feePaymentsAssertYearWritable($db, (string) $year);
            $studentOperationalGuard->assertWritable($student_id);

            // Get or create student_fees record
            $stmt = $db->prepare("SELECT * FROM student_fees WHERE student_id = ? AND academic_year = ?");
            $stmt->execute([$student_id, $year]);
            $fee = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$fee) {
                // Auto-generate fee record from fee_structure
                $fee = autoGenerateStudentFee($db, $student_id, $year);
                if (!$fee) {
                    throw new Exception("لم يتم العثور على هيكل رسوم لهذا الطالب");
                }
            }

            // Insert payment
            $stmt = $db->prepare("INSERT INTO fee_payments (student_fee_id, student_id, amount, payment_date, payment_method, receipt_number, notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$fee['id'], $student_id, $amount, $payment_date, $payment_method, $receipt_number ?: null, $notes ?: null, $_SESSION['user_id']]);

            // Update totals
            $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM fee_payments WHERE student_fee_id = ?");
            $stmt->execute([$fee['id']]);
            $total_paid = (float)$stmt->fetchColumn();
            $balance = $fee['final_amount'] - $total_paid;

            $stmt = $db->prepare("UPDATE student_fees SET total_paid = ?, balance = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$total_paid, $balance, $fee['id']]);

            $db->commit();

            // Get student name for log
            $stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
            $stmt->execute([$student_id]);
            $studentName = $stmt->fetchColumn();

            ActivityLog::logCreate('fee_payment', $db->lastInsertId(), $studentName, [
                'amount' => $amount,
                'method' => $payment_method,
                'receipt' => $receipt_number,
                'total_paid' => $total_paid,
                'balance' => $balance
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'تم تسجيل الدفعة بنجاح (' . number_format($amount, 2) . ' جنيه)',
                'total_paid' => $total_paid,
                'balance' => $balance
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // Delete a payment
    if ($_GET['ajax'] === 'delete_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الأمان']);
            exit();
        }

        $payment_id = (int)($input['payment_id'] ?? 0);

        try {
            $db->beginTransaction();

            // Get payment info
            $stmt = $db->prepare("SELECT fp.*, u.name as student_name, sf.academic_year AS fee_academic_year
                FROM fee_payments fp
                JOIN users u ON fp.student_id = u.id
                JOIN student_fees sf ON sf.id = fp.student_fee_id
                WHERE fp.id = ?");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) throw new Exception("الدفعة غير موجودة");
            feePaymentsAssertYearWritable($db, (string) $payment['fee_academic_year']);

            $student_fee_id = $payment['student_fee_id'];

            // Delete payment
            $db->prepare("DELETE FROM fee_payments WHERE id = ?")->execute([$payment_id]);

            // Recalculate totals
            $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM fee_payments WHERE student_fee_id = ?");
            $stmt->execute([$student_fee_id]);
            $total_paid = (float)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT final_amount FROM student_fees WHERE id = ?");
            $stmt->execute([$student_fee_id]);
            $final_amount = (float)$stmt->fetchColumn();
            $balance = $final_amount - $total_paid;

            $stmt = $db->prepare("UPDATE student_fees SET total_paid = ?, balance = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$total_paid, $balance, $student_fee_id]);

            $db->commit();

            ActivityLog::logDelete('fee_payment', $payment_id, $payment['student_name'], [
                'deleted_amount' => $payment['amount'],
                'new_total_paid' => $total_paid,
                'new_balance' => $balance
            ]);

            echo json_encode(['success' => true, 'message' => 'تم حذف الدفعة بنجاح']);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    // Generate fees for all students in a grade/class
    if ($_GET['ajax'] === 'generate_fees' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الأمان']);
            exit();
        }

        $year = $input['year'] ?? $selected_year;
        $grade_id = (int)($input['grade_id'] ?? 0);
        $class_id = (int)($input['class_id'] ?? 0);

        try {
            $db->beginTransaction();
            feePaymentsAssertYearWritable($db, (string) $year);
            // Get students (مرتبطة بالعام الحالي)
            if ($currentAcademicYearId > 0) {
                $sql = "SELECT u.id FROM users u
                    JOIN student_enrollments se ON se.student_id = u.id
                        AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
                    JOIN classes c ON c.id = se.class_id
            WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
                $params = [$currentAcademicYearId];
                if ($class_id > 0) {
                    $sql .= " AND se.class_id = ?";
                    $params[] = $class_id;
                } elseif ($grade_id > 0) {
                    $sql .= " AND se.grade_id = ?";
                    $params[] = $grade_id;
                }
            } else {
        $sql = "SELECT u.id FROM users u JOIN classes c ON u.class_id = c.id WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
                $params = [];
                if ($class_id > 0) {
                    $sql .= " AND u.class_id = ?";
                    $params[] = $class_id;
                } elseif ($grade_id > 0) {
                    $sql .= " AND c.grade_id = ?";
                    $params[] = $grade_id;
                }
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $students = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $generated = 0;
            $skipped = 0;
            foreach ($students as $sid) {
                // Check if already exists
                $chk = $db->prepare("SELECT id FROM student_fees WHERE student_id = ? AND academic_year = ?");
                $chk->execute([$sid, $year]);
                if ($chk->fetch()) {
                    $skipped++;
                    continue;
                }
                $fee = autoGenerateStudentFee($db, $sid, $year);
                if ($fee) $generated++;
            }

            $db->commit();

            ActivityLog::logCreate('fee_generation', null, 'توليد مستحقات', [
                'generated' => $generated,
                'skipped' => $skipped,
                'year' => $year
            ]);

            echo json_encode([
                'success' => true,
                'message' => "تم توليد المستحقات: $generated طالب جديد، $skipped طالب موجود مسبقاً"
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

// =============== POST HANDLERS ===============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_message'] = "خطأ في التحقق من الأمان";
        header("Location: fee_payments.php?tab=" . urlencode($activeTab));
        exit();
    }
    $action = $_POST['action'] ?? '';

    // تعيين خصم أخرى لطالب
    if ($action === 'assign_discount') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $discount_id = (int)($_POST['other_discount_id'] ?? 0);
        $year = $selected_year;

        try {
            if ($student_id <= 0 || $discount_id <= 0) throw new Exception("بيانات غير صحيحة");
            $db->beginTransaction();
            feePaymentsAssertYearWritable($db, (string) $year);
            $studentOperationalGuard->assertWritable($student_id);

            // Get discount info
            $stmt = $db->prepare("SELECT * FROM other_discounts WHERE id = ? AND status = 'active'");
            $stmt->execute([$discount_id]);
            $disc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$disc) throw new Exception("الخصم غير موجود أو معطّل");

            // Get or create student fee
            $stmt = $db->prepare("SELECT * FROM student_fees WHERE student_id = ? AND academic_year = ?");
            $stmt->execute([$student_id, $year]);
            $fee = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fee) {
                $fee = autoGenerateStudentFee($db, $student_id, $year);
                if (!$fee) throw new Exception("لم يتم العثور على هيكل رسوم لهذا الطالب");
            }

            // Check not already assigned
            $stmt = $db->prepare("SELECT id FROM student_other_discounts WHERE student_fee_id = ? AND other_discount_id = ?");
            $stmt->execute([$fee['id'], $discount_id]);
            if ($stmt->fetch()) throw new Exception("هذا الخصم معيّن بالفعل لهذا الطالب");

            // Calculate discount amount
            $discountAmount = $disc['discount_type'] === 'percentage'
                ? ($fee['tuition_amount'] * $disc['discount_value'] / 100)
                : $disc['discount_value'];

            $stmt = $db->prepare("INSERT INTO student_other_discounts (student_fee_id, student_id, other_discount_id, discount_amount, academic_year) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$fee['id'], $student_id, $discount_id, $discountAmount, $year]);

            // Recalculate other_discount_total and final_amount
            $stmt = $db->prepare("SELECT COALESCE(SUM(discount_amount), 0) FROM student_other_discounts WHERE student_fee_id = ?");
            $stmt->execute([$fee['id']]);
            $otherDiscountTotal = (float)$stmt->fetchColumn();

            $newFinal = ($fee['tuition_amount'] - $fee['sibling_discount'] - ($fee['custom_discount'] ?? 0) - $otherDiscountTotal) + ($fee['bus_fee_amount'] ?? 0);
            if ($newFinal < 0) $newFinal = 0;
            $newBalance = $newFinal - $fee['total_paid'];

            $stmt = $db->prepare("UPDATE student_fees SET other_discount_total = ?, final_amount = ?, balance = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$otherDiscountTotal, $newFinal, $newBalance, $fee['id']]);

            $db->commit();

            $stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
            $stmt->execute([$student_id]);
            $sName = $stmt->fetchColumn();

            ActivityLog::logCreate('student_other_discount', $student_id, $sName, [
                'discount_name' => $disc['name'],
                'discount_amount' => $discountAmount,
                'new_final' => $newFinal,
                'new_balance' => $newBalance
            ]);

            $_SESSION['success_message'] = "تم تعيين خصم «" . htmlspecialchars($disc['name']) . "» بمبلغ " . number_format($discountAmount, 2) . " جنيه بنجاح";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['error_message'] = "خطأ: " . $e->getMessage();
        }
        header("Location: fee_payments.php?tab=students");
        exit();
    }
}

// =============== HELPER: Auto-generate student fee ===============
function autoGenerateStudentFee($db, $student_id, $year) {
    // Get student's grade (من تسجيل العام الحالي)
    require_once __DIR__ . '/../classes/AcademicYear.php';
    $yearId = AcademicYear::currentId($db);
    if ($yearId > 0) {
        $stmt = $db->prepare("SELECT c.grade_id FROM student_enrollments se JOIN classes c ON c.id = se.class_id WHERE se.student_id = ? AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'");
        $stmt->execute([$student_id, $yearId]);
    } else {
        $stmt = $db->prepare("SELECT c.grade_id FROM users u JOIN classes c ON u.class_id = c.id WHERE u.id = ?");
        $stmt->execute([$student_id]);
    }
    $grade_id = $stmt->fetchColumn();
    if (!$grade_id) return null;

    // Get fee structure
    $stmt = $db->prepare("SELECT total_amount FROM fee_structure WHERE grade_id = ? AND academic_year = ? AND status = 'active'");
    $stmt->execute([$grade_id, $year]);
    $tuition = (float)$stmt->fetchColumn();
    if ($tuition <= 0) return null;

    // Count siblings and determine order
    $sibling_order = 1;
    $stmt = $db->prepare("SELECT ss.sibling_id FROM student_siblings ss
        JOIN users u ON ss.sibling_id = u.id
            WHERE ss.student_id = ? AND u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL");
    $stmt->execute([$student_id]);
    $siblingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($siblingIds)) {
        // Count how many siblings already have fee records (they were registered before this student)
        $placeholders = implode(',', array_fill(0, count($siblingIds), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) FROM student_fees WHERE student_id IN ($placeholders) AND academic_year = ?");
        $stmt->execute(array_merge($siblingIds, [$year]));
        $sibling_order = (int)$stmt->fetchColumn() + 1;
    }

    // Get discount
    $stmt = $db->prepare("SELECT discount_percentage FROM sibling_discounts WHERE academic_year = ? AND sibling_order = ?");
    $stmt->execute([$year, $sibling_order]);
    $discount_pct = (float)($stmt->fetchColumn() ?: 0);
    $sibling_discount = $tuition * ($discount_pct / 100);

    // Bus fee
    $bus_fee = 0;
    $bus_zone_id = null;
    // Check if student is assigned to a bus
    $stmt = $db->prepare("SELECT b.area FROM student_bus_assignments sba JOIN buses b ON sba.bus_id = b.id WHERE sba.student_id = ?");
    $stmt->execute([$student_id]);
    $busArea = $stmt->fetchColumn();
    if ($busArea) {
        $stmt = $db->prepare("SELECT id, fee_amount FROM bus_fee_zones WHERE zone_name = ? AND academic_year = ?");
        $stmt->execute([$busArea, $year]);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($zone) {
            $bus_fee = (float)$zone['fee_amount'];
            $bus_zone_id = $zone['id'];
        }
    }

    $final = ($tuition - $sibling_discount) + $bus_fee;

    $stmt = $db->prepare("INSERT INTO student_fees (student_id, academic_year, tuition_amount, bus_fee_amount, sibling_discount, final_amount, total_paid, balance, sibling_order, bus_zone_id)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)");
    $stmt->execute([$student_id, $year, $tuition, $bus_fee, $sibling_discount, $final, $final, $sibling_order, $bus_zone_id]);

    $fee_id = $db->lastInsertId();
    $stmt = $db->prepare("SELECT * FROM student_fees WHERE id = ?");
    $stmt->execute([$fee_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// =============== FETCH DATA ===============
// Stages for filter
$stages = $db->query("SELECT id, stage_name FROM stages WHERE status = 'active' ORDER BY stage_order")->fetchAll(PDO::FETCH_ASSOC);
$gradesAll = $db->query("SELECT id, grade_name, stage_id FROM grades WHERE status = 'active' ORDER BY grade_order")->fetchAll(PDO::FETCH_ASSOC);
$classesAll = $db->query("SELECT id, name, grade_id FROM classes WHERE status = 'active' ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);

// Legacy local table query is retained temporarily for rollback but never executed.
if (false) {
if ($currentAcademicYearId > 0) {
    $sql = "SELECT u.id, u.name, u.status as user_status,
        c.name as class_name, c.id as class_id,
        g.grade_name, g.id as grade_id,
        s.stage_name, s.id as stage_id,
        sp.student_code,
        sf.id as fee_id, sf.tuition_amount, sf.bus_fee_amount, sf.sibling_discount, sf.custom_discount,
        sf.final_amount, sf.total_paid, sf.balance, sf.exemption_type, sf.sibling_order
        FROM users u
        JOIN student_enrollments se ON se.student_id = u.id
            AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
        JOIN classes c ON c.id = se.class_id
        JOIN grades g ON c.grade_id = g.id
        LEFT JOIN stages s ON g.stage_id = s.id
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN student_fees sf ON sf.student_id = u.id AND sf.academic_year = ?
        WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
    $params = [$currentAcademicYearId, $selected_year];

    if ($filter_class) {
        $sql .= " AND se.class_id = ?";
        $params[] = (int)$filter_class;
    } elseif ($filter_grade) {
        $sql .= " AND se.grade_id = ?";
        $params[] = (int)$filter_grade;
    } elseif ($filter_stage) {
        $sql .= " AND se.stage_id = ?";
        $params[] = (int)$filter_stage;
    }
} else {
    $sql = "SELECT u.id, u.name, u.status as user_status,
        c.name as class_name, c.id as class_id,
        g.grade_name, g.id as grade_id,
        s.stage_name, s.id as stage_id,
        sp.student_code,
        sf.id as fee_id, sf.tuition_amount, sf.bus_fee_amount, sf.sibling_discount, sf.custom_discount,
        sf.final_amount, sf.total_paid, sf.balance, sf.exemption_type, sf.sibling_order
        FROM users u
        JOIN classes c ON u.class_id = c.id
        JOIN grades g ON c.grade_id = g.id
        LEFT JOIN stages s ON g.stage_id = s.id
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN student_fees sf ON sf.student_id = u.id AND sf.academic_year = ?
        WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL";
    $params = [$selected_year];

    if ($filter_class) {
        $sql .= " AND u.class_id = ?";
        $params[] = (int)$filter_class;
    } elseif ($filter_grade) {
        $sql .= " AND c.grade_id = ?";
        $params[] = (int)$filter_grade;
    } elseif ($filter_stage) {
        $sql .= " AND g.stage_id = ?";
        $params[] = (int)$filter_stage;
    }
}

$sql .= " ORDER BY s.stage_order, g.grade_order, c.display_order, u.name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Apply fee_status filter
if ($filter_status) {
    $students = array_filter($students, function($s) use ($filter_status) {
        if ($filter_status === 'paid') return $s['fee_id'] && $s['balance'] <= 0;
        if ($filter_status === 'partial') return $s['fee_id'] && $s['total_paid'] > 0 && $s['balance'] > 0;
        if ($filter_status === 'unpaid') return !$s['fee_id'] || ($s['total_paid'] == 0 && $s['balance'] > 0);
        return true;
    });
    $students = array_values($students);
}

// Summary stats
$totalStudents = count($students);
$totalDue = array_sum(array_column($students, 'final_amount'));
$totalPaid = array_sum(array_column($students, 'total_paid'));
$totalBalance = array_sum(array_column($students, 'balance'));
$paidCount = count(array_filter($students, function($s) { return $s['fee_id'] && $s['balance'] <= 0; }));
$partialCount = count(array_filter($students, function($s) { return $s['fee_id'] && $s['total_paid'] > 0 && $s['balance'] > 0; }));
$unpaidCount = $totalStudents - $paidCount - $partialCount;

}

// Summary only: the paged rows are loaded by ajax_fee_payments_datatable.php.
$feeListSummary = (new FeePaymentListQuery($db))->summary($currentAcademicYearId, $selected_year, [
    'stage_id' => $filter_stage, 'grade_id' => $filter_grade, 'class_id' => $filter_class, 'fee_status' => $filter_status
]);
$totalStudents = $feeListSummary['total'];
$totalDue = $feeListSummary['due'];
$totalPaid = $feeListSummary['paid'];
$totalBalance = $feeListSummary['balance'];
$paidCount = $feeListSummary['paid_count'];
$partialCount = $feeListSummary['partial_count'];
$unpaidCount = $feeListSummary['unpaid_count'];
$students = [];

// Operations log (tab 2)
$logEntries = [];
if ($activeTab === 'log') {
    $stmt = $db->prepare("SELECT * FROM activity_logs WHERE target_type IN ('fee_payment', 'fee_structure', 'fee_generation', 'bus_fee_zone', 'sibling_discounts') ORDER BY created_at DESC LIMIT 500");
    $stmt->execute();
    $logEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include_once '../includes/admin_header.php';
echo FinanceLegacyAdapter::bridgeNotice(__FILE__);
?>

<div class="fee-payments-page admin-unified-container">
    <!-- Page Header -->
    <div class="admin-page-heading">
        <h1 class="h2"><i class="fas fa-cash-register me-2 text-primary"></i>سداد الرسوم والمصاريف</h1>
        <div class="admin-top-actions no-print">
            <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill shadow-sm me-2">
                <i class="fas fa-calendar-alt me-1"></i>العام الدراسي: <?php echo htmlspecialchars($selected_year); ?>
            </span>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" onclick="generateFees()">
                <i class="fas fa-magic me-1"></i>توليد المستحقات
            </button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                <div class="stat-card-icon"><i class="fas fa-users"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$totalStudents; ?>">0</div>
                    <div class="stat-card-label">إجمالي الطلاب</div>
                    <div class="stat-card-sub"><i class="fas fa-money-bill me-1"></i>مستحق: <?php echo number_format($totalDue, 0); ?> ج.م</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$paidCount; ?>">0</div>
                    <div class="stat-card-label">مسددين بالكامل</div>
                    <div class="stat-card-sub"><i class="fas fa-coins me-1"></i><?php echo number_format($totalPaid, 0); ?> ج.م محصّل</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$partialCount; ?>">0</div>
                    <div class="stat-card-label">مسددين جزئياً</div>
                    <div class="stat-card-sub"><i class="fas fa-hourglass-half me-1"></i> سداد جزئي</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
                <div class="stat-card-icon"><i class="fas fa-times-circle"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo (int)$unpaidCount; ?>">0</div>
                    <div class="stat-card-label">لم يسددوا</div>
                    <div class="stat-card-sub"><i class="fas fa-exclamation me-1"></i>متبقي: <?php echo number_format($totalBalance, 0); ?> ج.م</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3 border-bottom" id="paymentTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link fw-semibold <?php echo $activeTab === 'students' ? 'active' : ''; ?>" href="#pane-students" data-bs-toggle="tab">
                <i class="fas fa-users me-2"></i>قائمة الطلاب <span class="badge bg-primary ms-1"><?php echo (int)$totalStudents; ?></span>
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link fw-semibold <?php echo $activeTab === 'log' ? 'active' : ''; ?>" href="#pane-log" data-bs-toggle="tab">
                <i class="fas fa-history me-2"></i>سجل العمليات <span class="badge bg-secondary ms-1"><?php echo count($logEntries); ?></span>
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- ====== TAB 1: Students ====== -->
        <div class="tab-pane fade <?php echo $activeTab === 'students' ? 'show active' : ''; ?>" id="pane-students">
            <form method="GET" class="admin-filter-bar mb-3" novalidate id="feePaymentsFilterForm">
                <input type="hidden" name="tab" value="students">
                <div class="admin-filter-controls flex-wrap gap-2">
                    <!-- Stage Dropdown -->
                    <div class="dropdown me-1 mb-1">
                        <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="stageDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                            <span>المراحل: <span id="selectedStagesLabel" class="fw-bold">الكل</span></span>
                        </button>
                        <div class="dropdown-menu p-3 shadow-sm" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right;">
                            <?php foreach ($stages as $st): ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input stage-checkbox" type="checkbox" name="stage_id[]" value="<?php echo $st['id']; ?>" id="stage_<?php echo $st['id']; ?>" <?php echo in_array((int)$st['id'], $filter_stage_ids) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="stage_<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['stage_name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Grade Dropdown -->
                    <div class="dropdown me-1 mb-1">
                        <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="gradeDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                            <span>الصفوف: <span id="selectedGradesLabel" class="fw-bold">الكل</span></span>
                        </button>
                        <div class="dropdown-menu p-3 shadow-sm" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right;">
                            <?php foreach ($gradesAll as $g): ?>
                                <div class="form-check mb-1 grade-item" data-stage="<?php echo $g['stage_id']; ?>">
                                    <input class="form-check-input grade-checkbox" type="checkbox" name="grade_id[]" value="<?php echo $g['id']; ?>" id="grade_<?php echo $g['id']; ?>" <?php echo in_array((int)$g['id'], $filter_grade_ids) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="grade_<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['grade_name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Class Dropdown -->
                    <div class="dropdown me-1 mb-1">
                        <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="classDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                            <span>الفصول: <span id="selectedClassesLabel" class="fw-bold">الكل</span></span>
                        </button>
                        <div class="dropdown-menu p-3 shadow-sm" style="max-height: 250px; overflow-y: auto; min-width: 220px; text-align: right;">
                            <?php foreach ($classesAll as $c): ?>
                                <div class="form-check mb-1 class-item" data-grade="<?php echo $c['grade_id']; ?>">
                                    <input class="form-check-input class-checkbox" type="checkbox" name="class_id[]" value="<?php echo $c['id']; ?>" id="class_<?php echo $c['id']; ?>" <?php echo in_array((int)$c['id'], $filter_class_ids) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="class_<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Status Dropdown -->
                    <div class="dropdown me-1 mb-1">
                        <button class="btn btn-light dropdown-toggle btn-sm filter-dropdown-btn" type="button" id="statusDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" style="background: white; border-color: #dee2e6; color: #495057; height: 31px; display: inline-flex; align-items: center; justify-content: space-between; min-width: 140px;">
                            <span>حالة السداد: <span id="selectedStatusLabel" class="fw-bold">الكل</span></span>
                        </button>
                        <div class="dropdown-menu p-3 shadow-sm" style="max-height: 250px; overflow-y: auto; min-width: 200px; text-align: right;">
                            <div class="form-check mb-1">
                                <input class="form-check-input status-checkbox" type="checkbox" name="fee_status[]" value="paid" id="st_paid" <?php echo in_array('paid', $filter_statuses) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="st_paid">مسدد بالكامل</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input status-checkbox" type="checkbox" name="fee_status[]" value="partial" id="st_partial" <?php echo in_array('partial', $filter_statuses) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="st_partial">مسدد جزئياً</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input status-checkbox" type="checkbox" name="fee_status[]" value="unpaid" id="st_unpaid" <?php echo in_array('unpaid', $filter_statuses) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="st_unpaid">لم يسدد</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="admin-filter-actions">
                    <button type="button" class="btn btn-light btn-sm" id="resetFeeFiltersBtn" style="height: 31px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important;">
                        <i class="fas fa-undo me-1"></i>إعادة تعيين
                    </button>
                </div>
            </form>

            <div class="admin-list-surface">
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped admin-data-table" id="studentsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>كود الطالب</th>
                                <th>اسم الطالب</th>
                                <th>الفصل</th>
                                <th>المستحق</th>
                                <th>المدفوع</th>
                                <th>المتبقي</th>
                                <th>الحالة</th>
                                <th class="text-center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $idx => $s): ?>
                            <?php
                                $statusBadge = 'bg-secondary';
                                $statusText = 'غير محدد';
                                if ($s['fee_id']) {
                                    if ($s['balance'] <= 0) {
                                        $statusBadge = 'bg-success';
                                        $statusText = 'مسدد';
                                    } elseif ($s['total_paid'] > 0) {
                                        $statusBadge = 'bg-warning text-dark';
                                        $statusText = 'جزئي';
                                    } else {
                                        $statusBadge = 'bg-danger';
                                        $statusText = 'لم يسدد';
                                    }
                                }
                            ?>
                            <tr>
                                <td><?php echo $idx + 1; ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($s['student_code'] ?? '-'); ?></small></td>
                                <td class="fw-bold text-primary"><?php echo htmlspecialchars($s['name']); ?></td>
                                <td><?php echo htmlspecialchars($s['class_name']); ?></td>
                                <td><?php echo $s['fee_id'] ? number_format($s['final_amount'], 2) : '<span class="text-muted">-</span>'; ?></td>
                                <td class="text-success"><?php echo $s['fee_id'] ? number_format($s['total_paid'], 2) : '-'; ?></td>
                                <td class="<?php echo ($s['fee_id'] && $s['balance'] > 0) ? 'text-danger fw-bold' : ''; ?>"><?php echo $s['fee_id'] ? number_format($s['balance'], 2) : '-'; ?></td>
                                <td><span class="badge <?php echo $statusBadge; ?>"><?php echo $statusText; ?></span></td>
                                <td class="text-center actions-column admin-table-actions">
                                    <button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="عرض التفاصيل" onclick="viewStudentFee(<?php echo $s['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-action-pills btn-activate me-1" data-bs-toggle="tooltip" title="تسجيل دفعة" onclick="openPaymentModal(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-plus-circle"></i>
                                    </button>
                                    <button class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعيين خصم" onclick="openDiscountModal(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-tags"></i>
                                    </button>
                                    <?php if ($s['fee_id'] && $s['total_paid'] > 0): ?>
                                    <button class="btn btn-action-pills btn-deactivate" data-bs-toggle="tooltip" title="طباعة إيصال" onclick="printReceipt(<?php echo $s['id']; ?>)">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ====== TAB 2: Log ====== -->
        <div class="tab-pane fade <?php echo $activeTab === 'log' ? 'show active' : ''; ?>" id="pane-log">
            <div class="admin-list-surface">
                <?php if (empty($logEntries)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-history fa-3x mb-3 opacity-50"></i>
                        <p>لا توجد عمليات مسجلة بعد</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover table-striped datatable admin-data-table" id="logTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>المستخدم</th>
                                <th>العملية</th>
                                <th>الهدف</th>
                                <th>التفاصيل</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logEntries as $idx => $log):
                                $actionBadge = 'bg-secondary';
                                $actionLabel = $log['action'];
                                if ($log['action'] === 'create') { $actionBadge = 'bg-success'; $actionLabel = 'إنشاء'; }
                                elseif ($log['action'] === 'update') { $actionBadge = 'bg-primary'; $actionLabel = 'تعديل'; }
                                elseif ($log['action'] === 'delete') { $actionBadge = 'bg-danger'; $actionLabel = 'حذف'; }

                                $details = json_decode($log['details'] ?? '{}', true);
                                $detailHtml = ActivityLog::formatDetailsHtml($details, 'badge');
                            ?>
                            <tr>
                                <td><?php echo $idx + 1; ?></td>
                                <td><small><?php echo htmlspecialchars($log['created_at']); ?></small></td>
                                <td class="fw-bold text-primary"><?php echo htmlspecialchars($log['user_name']); ?></td>
                                <td><span class="badge <?php echo $actionBadge; ?>"><?php echo $actionLabel; ?></span></td>
                                <td><?php echo htmlspecialchars($log['target_name'] ?? '-'); ?></td>
                                <td><?php echo $detailHtml ?: '-'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ====== MODALS ====== -->

<!-- Student Fee Detail Modal -->
<div class="modal fade" id="studentFeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>تفاصيل مصاريف الطالب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="studentFeeBody">
                <div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>تسجيل دفعة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-center">
                    <strong id="payStudentName" class="text-primary fs-5"></strong>
                </div>
                <input type="hidden" id="payStudentId">
                <div class="mb-3">
                    <label class="form-label fw-bold">المبلغ (جنيه) <span class="text-danger">*</span></label>
                    <input type="number" id="payAmount" class="form-control" min="0.01" step="0.01" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">تاريخ الدفع</label>
                    <input type="text" id="payDate" class="form-control flatpickr-date" placeholder="اختر التاريخ..." value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">طريقة الدفع</label>
                    <select id="payMethod" class="form-select">
                        <option value="cash">نقدي</option>
                        <option value="bank_transfer">تحويل بنكي</option>
                        <option value="check">شيك</option>
                        <option value="other">أخرى</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">رقم الإيصال</label>
                    <input type="text" id="payReceipt" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">ملاحظات</label>
                    <textarea id="payNotes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
                <button type="button" class="btn btn-success" onclick="submitPayment()"><i class="fas fa-save me-1"></i>تسجيل الدفعة</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Payment Confirmation Modal -->
<div class="modal fade" id="deletePaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف دفعة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3"><i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i></div>
                <p class="text-center">هل تريد حذف هذه الدفعة بمبلغ <strong class="text-danger" id="deletePayAmount"></strong> جنيه؟</p>
                <div class="alert alert-danger"><i class="fas fa-info-circle me-2"></i>سيتم إعادة حساب المبالغ تلقائياً.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
                <button type="button" class="btn btn-danger" id="confirmDeletePayBtn" onclick="confirmDeletePayment()"><i class="fas fa-trash me-1"></i>تأكيد الحذف</button>
            </div>
        </div>
    </div>
</div>

<!-- Generate Fees Modal -->
<div class="modal fade" id="generateFeesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-magic me-2"></i>توليد المستحقات</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">سيتم توليد سجلات المستحقات المالية للطلاب بناءً على هيكل الرسوم المحدد.</p>
                <div class="mb-3">
                    <label class="form-label fw-bold">النطاق</label>
                    <select id="genGrade" class="form-select">
                        <option value="">كل الصفوف</option>
                        <?php foreach ($gradesAll as $g): ?>
                        <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['grade_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>الطلاب الذين لديهم سجلات مالية سابقة لن يتأثروا.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
                <button type="button" class="btn btn-success" id="genBtn" onclick="submitGenerateFees()"><i class="fas fa-magic me-1"></i>توليد</button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Discount Modal -->
<div class="modal fade" id="discountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="assign_discount">
                <input type="hidden" name="student_id" id="discountStudentId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-tags me-2"></i>تعيين خصم</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2 text-center">
                        <strong id="discountStudentName" class="text-primary fs-5"></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">اختر الخصم <span class="text-danger">*</span></label>
                        <select name="other_discount_id" class="form-select" required>
                            <option value="">-- اختر --</option>
                            <?php
                            $stmtOd = $db->prepare("SELECT * FROM other_discounts WHERE academic_year = ? AND status = 'active' ORDER BY name");
                            $stmtOd->execute([$selected_year]);
                            $availableDiscounts = $stmtOd->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($availableDiscounts as $od):
                            ?>
                            <option value="<?php echo $od['id']; ?>">
                                <?php echo htmlspecialchars($od['name']); ?> — <?php echo number_format($od['discount_value'], 2); ?><?php echo $od['discount_type'] === 'percentage' ? '%' : ' جنيه'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إغلاق</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-tags me-1"></i>تعيين الخصم</button>
                </div>
            </form>
        </div>
    </div>
</div>

</div>

<!-- Receipt Print (hidden) -->
<div id="receiptPrintArea" style="display:none;">
    <div id="receiptContent" style="direction:rtl; font-family: 'Arial', sans-serif; padding: 20px; max-width: 700px; margin: 0 auto;">
    </div>
</div>

<style>
</style>

<script src="../assets/js/admin-server-side-table.js"></script>
<script>
var csrfToken = <?php echo json_encode($_SESSION['csrf_token']); ?>;
var selectedYear = <?php echo json_encode($selected_year); ?>;
var deletePaymentId = null;

// ========== Cascading Filters (managed by DOMContentLoaded block below) ==========

// ========== View Student Fee ==========
function viewStudentFee(studentId) {
    var modal = new bootstrap.Modal(document.getElementById('studentFeeModal'));
    var body = document.getElementById('studentFeeBody');
    body.innerHTML = '<div class="text-center py-3"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    modal.show();

    $.ajax({
        url: 'fee_payments.php?ajax=get_student_fee&student_id=' + studentId + '&year=' + encodeURIComponent(selectedYear),
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            if (!data.success) {
                body.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
                return;
            }

            var s = data.student;
            var f = data.fee;
            var html = '<div class="text-center mb-3"><h5>' + escHtml(s.name) + '</h5><small class="text-muted">' + escHtml(s.grade_name || '') + ' - ' + escHtml(s.class_name || '') + '</small></div>';

            if (data.siblings && data.siblings.length > 0) {
                html += '<div class="alert alert-info py-2"><i class="fas fa-users me-1"></i><strong>الإخوة:</strong> ';
                data.siblings.forEach(function(sib) {
                    html += '<span class="badge bg-primary me-1">' + escHtml(sib.sibling_name) + ' (' + escHtml(sib.grade_name || '') + ')</span>';
                });
                html += '</div>';
            }

            // سجل المديونيات التاريخية من الأعوام السابقة
            if (data.prior_balances && data.prior_balances.length > 0) {
                html += '<div class="alert alert-warning border-0">';
                html += '<h6 class="alert-heading"><i class="fas fa-history me-1"></i>مديونيات من أعوام سابقة</h6>';
                html += '<table class="table table-sm table-borderless mb-2"><thead><tr><th>العام</th><th class="text-end">المستحق</th><th class="text-end">المدفوع</th><th class="text-end">المتبقي</th></tr></thead><tbody>';
                data.prior_balances.forEach(function(pb) {
                    html += '<tr><td><span class="badge bg-secondary">' + escHtml(pb.year_name) + '</span>' + (pb.carried_forward == 1 ? ' <small class="text-muted">(مرحّلة)</small>' : '') + '</td>';
                    html += '<td class="text-end">' + fmt(pb.total_due) + '</td>';
                    html += '<td class="text-end text-success">' + fmt(pb.total_paid) + '</td>';
                    html += '<td class="text-end text-danger fw-bold">' + fmt(pb.balance) + '</td></tr>';
                });
                html += '</tbody></table>';
                html += '<div class="text-end fw-bold border-top pt-2">إجمالي المديونيات السابقة: <span class="text-danger">' + fmt(data.prior_total) + ' جنيه</span></div>';
                html += '</div>';
            }

            if (f) {
                html += '<table class="table table-bordered table-sm">';
                html += '<tr><td>المصاريف الأصلية</td><td>' + fmt(f.tuition_amount) + ' جنيه</td></tr>';
                html += '<tr><td>خصم الإخوة (ترتيب: ' + (f.sibling_order || 1) + ')</td><td class="text-danger">-' + fmt(f.sibling_discount) + ' جنيه</td></tr>';
                if (parseFloat(f.custom_discount) > 0) {
                    html += '<tr><td>خصم إضافي</td><td class="text-danger">-' + fmt(f.custom_discount) + ' جنيه' + (f.custom_discount_reason ? ' <small>(' + escHtml(f.custom_discount_reason) + ')</small>' : '') + '</td></tr>';
                }
                if (parseFloat(f.other_discount_total) > 0) {
                    html += '<tr><td>خصومات أخرى</td><td class="text-danger">-' + fmt(f.other_discount_total) + ' جنيه</td></tr>';
                }
                if (data.other_discounts && data.other_discounts.length > 0) {
                    data.other_discounts.forEach(function(od) {
                        html += '<tr><td class="ps-4"><small><i class="fas fa-tag text-info me-1"></i>' + escHtml(od.discount_name) + '</small></td><td class="text-muted"><small>-' + fmt(od.discount_amount) + ' جنيه</small></td></tr>';
                    });
                }
                if (parseFloat(f.bus_fee_amount) > 0) {
                    html += '<tr><td>رسوم الحافلة</td><td>' + fmt(f.bus_fee_amount) + ' جنيه</td></tr>';
                }
                html += '<tr class="table-primary"><td><strong>الإجمالي المستحق</strong></td><td><strong>' + fmt(f.final_amount) + ' جنيه</strong></td></tr>';
                html += '<tr class="table-success"><td><strong>إجمالي المدفوع</strong></td><td><strong class="text-success">' + fmt(f.total_paid) + ' جنيه</strong></td></tr>';
                html += '<tr class="' + (parseFloat(f.balance) > 0 ? 'table-danger' : 'table-success') + '"><td><strong>المتبقي</strong></td><td><strong>' + fmt(f.balance) + ' جنيه</strong></td></tr>';
                html += '</table>';

                // Installments
                if (data.installments && data.installments.length > 0) {
                    html += '<h6 class="mt-3"><i class="fas fa-list-ol me-1"></i>الأقساط المسجلة</h6>';
                    html += '<table class="table table-sm table-bordered"><thead class="table-light"><tr><th>#</th><th>اسم القسط</th><th>المبلغ</th><th>تاريخ الاستحقاق</th></tr></thead><tbody>';
                    data.installments.forEach(function(inst, idx) {
                        html += '<tr><td>' + (idx+1) + '</td><td>' + escHtml(inst.installment_name) + '</td><td>' + fmt(inst.amount) + ' جنيه</td><td>' + (inst.due_date || '-') + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                // Payments
                if (data.payments && data.payments.length > 0) {
                    html += '<h6 class="mt-3"><i class="fas fa-receipt me-1"></i>سجل الإيصالات</h6>';
                    html += '<table class="table table-sm table-bordered"><thead class="table-light"><tr><th>التاريخ</th><th>المبلغ</th><th>الطريقة</th><th>الإيصال</th><th>المستلم</th><th></th></tr></thead><tbody>';
                    var methods = {cash: 'نقدي', bank_transfer: 'تحويل', check: 'شيك', other: 'أخرى'};
                    data.payments.forEach(function(p) {
                        html += '<tr><td>' + p.payment_date + '</td><td class="text-success fw-bold">' + fmt(p.amount) + '</td><td>' + (methods[p.payment_method] || p.payment_method) + '</td><td>' + escHtml(p.receipt_number || '-') + '</td><td>' + escHtml(p.received_by_name || '-') + '</td>';
                        html += '<td><button class="btn btn-sm btn-outline-danger" onclick="showDeletePayment(' + p.id + ', ' + p.amount + ')"><i class="fas fa-trash"></i></button></td></tr>';
                    });
                    html += '</tbody></table>';
                } else {
                    html += '<div class="text-center text-muted mt-3"><i class="fas fa-receipt me-1"></i>لا توجد مدفوعات بعد</div>';
                }
            } else {
                html += '<div class="alert alert-warning text-center"><i class="fas fa-exclamation-triangle me-2"></i>لم يتم توليد المستحقات لهذا الطالب بعد.<br><button class="btn btn-outline-success btn-sm mt-2" onclick="generateSingleFee(' + s.id + ')"><i class="fas fa-magic me-1"></i>توليد الآن</button></div>';
            }

            body.innerHTML = html;
        },
        error: function() {
            body.innerHTML = '<div class="alert alert-danger">حدث خطأ في جلب البيانات</div>';
        }
    });
}

// ========== Payment Actions ==========
function openPaymentModal(studentId, studentName) {
    document.getElementById('payStudentId').value = studentId;
    document.getElementById('payStudentName').textContent = studentName;
    document.getElementById('payAmount').value = '';
    document.getElementById('payDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('payMethod').value = 'cash';
    document.getElementById('payReceipt').value = '';
    document.getElementById('payNotes').value = '';
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}

function submitPayment() {
    var studentId = document.getElementById('payStudentId').value;
    var amount = parseFloat(document.getElementById('payAmount').value);

    if (!amount || amount <= 0) {
        alert('يرجى إدخال مبلغ صحيح');
        return;
    }

    $.ajax({
        url: 'fee_payments.php?ajax=record_payment',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            csrf_token: csrfToken,
            student_id: studentId,
            amount: amount,
            payment_date: document.getElementById('payDate').value,
            payment_method: document.getElementById('payMethod').value,
            receipt_number: document.getElementById('payReceipt').value,
            notes: document.getElementById('payNotes').value,
            year: selectedYear
        }),
        success: function(data) {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('paymentModal')).hide();
                // Reload page to refresh data
                window.location.href = 'fee_payments.php?tab=students' +
                    (document.getElementById('filterStage').value ? '&stage_id=' + document.getElementById('filterStage').value : '') +
                    (document.getElementById('filterGrade').value ? '&grade_id=' + document.getElementById('filterGrade').value : '') +
                    (document.getElementById('filterClass').value ? '&class_id=' + document.getElementById('filterClass').value : '');
            } else {
                alert(data.message);
            }
        }
    });
}

function showDeletePayment(paymentId, amount) {
    deletePaymentId = paymentId;
    document.getElementById('deletePayAmount').textContent = parseFloat(amount).toFixed(2);
    // Hide the fee detail modal first
    var feeModal = bootstrap.Modal.getInstance(document.getElementById('studentFeeModal'));
    if (feeModal) feeModal.hide();
    setTimeout(function() {
        new bootstrap.Modal(document.getElementById('deletePaymentModal')).show();
    }, 400);
}

function confirmDeletePayment() {
    if (!deletePaymentId) return;
    $.ajax({
        url: 'fee_payments.php?ajax=delete_payment',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ csrf_token: csrfToken, payment_id: deletePaymentId }),
        success: function(data) {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('deletePaymentModal')).hide();
                window.location.href = 'fee_payments.php?tab=students';
            } else {
                alert(data.message);
            }
        }
    });
}

// ========== Generate Fees ==========
function generateFees() {
    new bootstrap.Modal(document.getElementById('generateFeesModal')).show();
}

function submitGenerateFees() {
    var btn = document.getElementById('genBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>جاري التوليد...';

    $.ajax({
        url: 'fee_payments.php?ajax=generate_fees',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            csrf_token: csrfToken,
            year: selectedYear,
            grade_id: document.getElementById('genGrade').value
        }),
        success: function(data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-magic me-1"></i>توليد';
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('generateFeesModal')).hide();
                alert(data.message);
                window.location.href = 'fee_payments.php?tab=students';
            } else {
                alert(data.message);
            }
        },
        error: function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-magic me-1"></i>توليد';
        }
    });
}

function generateSingleFee(studentId) {
    $.ajax({
        url: 'fee_payments.php?ajax=generate_fees',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ csrf_token: csrfToken, year: selectedYear, student_id: studentId }),
        success: function(data) {
            if (data.success) {
                viewStudentFee(studentId);
            } else {
                alert(data.message);
            }
        }
    });
}

// ========== Discount Assignment ==========
function openDiscountModal(studentId, studentName) {
    document.getElementById('discountStudentId').value = studentId;
    document.getElementById('discountStudentName').textContent = studentName;
    new bootstrap.Modal(document.getElementById('discountModal')).show();
}

// ========== Receipt Printing ==========
function printReceipt(studentId) {
    $.ajax({
        url: 'fee_payments.php?ajax=get_student_fee&student_id=' + studentId + '&year=' + encodeURIComponent(selectedYear),
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            if (!data.success || !data.fee) {
                alert('لا توجد بيانات مالية لهذا الطالب');
                return;
            }
            var s = data.student;
            var f = data.fee;
            var lastPayment = (data.payments && data.payments.length > 0) ? data.payments[0] : null;
            var payAmount = lastPayment ? parseFloat(lastPayment.amount) : parseFloat(f.total_paid);
            var amountWords = numberToArabicWords(payAmount);

            var html = '<div style="border: 2px solid #333; padding: 25px; font-size: 14px;">';
            html += '<div style="text-align:center; margin-bottom: 15px;">';
            html += '<h3 style="margin:0;">إيصال سداد رسوم دراسية</h3>';
            html += '<p style="margin:5px 0; color:#666;">العام الدراسي: ' + escHtml(selectedYear) + '</p>';
            html += '</div>';
            html += '<hr style="border-top: 1px solid #999;">';

            html += '<table style="width:100%; border-collapse:collapse; margin: 10px 0;">';
            html += '<tr><td style="padding:5px; font-weight:bold;">اسم الطالب:</td><td style="padding:5px;">' + escHtml(s.name) + '</td></tr>';
            html += '<tr><td style="padding:5px; font-weight:bold;">المرحلة:</td><td style="padding:5px;">' + escHtml(s.stage_name || '-') + '</td></tr>';
            html += '<tr><td style="padding:5px; font-weight:bold;">الصف:</td><td style="padding:5px;">' + escHtml(s.grade_name || '-') + '</td></tr>';
            html += '<tr><td style="padding:5px; font-weight:bold;">الفصل:</td><td style="padding:5px;">' + escHtml(s.class_name || '-') + '</td></tr>';
            html += '</table>';

            html += '<hr style="border-top: 1px solid #999;">';

            // Installments section
            if (data.installments && data.installments.length > 0) {
                html += '<h5 style="margin:10px 0 5px;">الأقساط المسجلة:</h5>';
                html += '<table style="width:100%; border-collapse:collapse; border:1px solid #ccc;">';
                html += '<thead><tr style="background:#f0f0f0;"><th style="border:1px solid #ccc;padding:5px;">اسم القسط</th><th style="border:1px solid #ccc;padding:5px;">المبلغ</th><th style="border:1px solid #ccc;padding:5px;">تاريخ الاستحقاق</th></tr></thead><tbody>';
                data.installments.forEach(function(inst) {
                    html += '<tr><td style="border:1px solid #ccc;padding:5px;">' + escHtml(inst.installment_name) + '</td><td style="border:1px solid #ccc;padding:5px;">' + fmt(inst.amount) + ' جنيه</td><td style="border:1px solid #ccc;padding:5px;">' + (inst.due_date || '-') + '</td></tr>';
                });
                html += '</tbody></table>';
            }

            if (lastPayment) {
                html += '<table style="width:100%; margin: 10px 0;">';
                html += '<tr><td style="padding:5px; font-weight:bold;">القسط المسدد:</td><td style="padding:5px;">' + (lastPayment.notes || 'دفعة') + '</td></tr>';
                html += '<tr><td style="padding:5px; font-weight:bold;">المبلغ المدفوع (رقماً):</td><td style="padding:5px; font-size:16px; font-weight:bold; color:green;">' + fmt(payAmount) + ' جنيه</td></tr>';
                html += '<tr><td style="padding:5px; font-weight:bold;">المبلغ المدفوع (كتابةً):</td><td style="padding:5px;">' + amountWords + ' جنيه مصري فقط لا غير</td></tr>';
                html += '<tr><td style="padding:5px; font-weight:bold;">تاريخ السداد:</td><td style="padding:5px;">' + lastPayment.payment_date + '</td></tr>';
                html += '<tr><td style="padding:5px; font-weight:bold;">رقم الإيصال:</td><td style="padding:5px;">' + escHtml(lastPayment.receipt_number || '-') + '</td></tr>';
                html += '</table>';
            }

            html += '<table style="width:100%; margin: 10px 0; background:#f8f8f8; border:1px solid #ddd;">';
            html += '<tr><td style="padding:8px; font-weight:bold;">إجمالي المستحق:</td><td style="padding:8px;">' + fmt(f.final_amount) + ' جنيه</td></tr>';
            html += '<tr><td style="padding:8px; font-weight:bold;">إجمالي المدفوع:</td><td style="padding:8px; color:green;">' + fmt(f.total_paid) + ' جنيه</td></tr>';
            html += '<tr><td style="padding:8px; font-weight:bold;">المتبقي:</td><td style="padding:8px; color:' + (parseFloat(f.balance) > 0 ? 'red' : 'green') + '; font-weight:bold;">' + fmt(f.balance) + ' جنيه</td></tr>';
            html += '</table>';

            html += '<hr style="border-top: 1px solid #999; margin-top: 25px;">';
            html += '<table style="width:100%; margin-top:30px;">';
            html += '<tr>';
            html += '<td style="text-align:center; width:50%; padding-top:40px; border-top: 1px solid #333;"><strong>المدير المالي</strong></td>';
            html += '<td style="text-align:center; width:50%; padding-top:40px; border-top: 1px solid #333;"><strong>أمين الخزينة</strong></td>';
            html += '</tr></table>';

            html += '</div>';

            document.getElementById('receiptContent').innerHTML = html;

            // Print
            var printWin = window.open('', '_blank', 'width=750,height=900');
            printWin.document.write('<!DOCTYPE html><html dir="rtl"><head><meta charset="UTF-8"><title>إيصال سداد</title><style>body{font-family: Arial, sans-serif; direction: rtl;} @media print { body { margin: 0; } }</style></head><body>');
            printWin.document.write(html);
            printWin.document.write('</body></html>');
            printWin.document.close();
            printWin.focus();
            setTimeout(function() { printWin.print(); }, 500);
        }
    });
}

// Number to Arabic words
function numberToArabicWords(num) {
    if (num === 0) return 'صفر';
    num = Math.floor(num);
    var ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة',
                'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    var tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    var hundreds = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];

    if (num < 20) return ones[num];
    if (num < 100) {
        var t = Math.floor(num / 10);
        var o = num % 10;
        return o > 0 ? ones[o] + ' و' + tens[t] : tens[t];
    }
    if (num < 1000) {
        var h = Math.floor(num / 100);
        var rem = num % 100;
        return hundreds[h] + (rem > 0 ? ' و' + numberToArabicWords(rem) : '');
    }
    if (num < 1000000) {
        var th = Math.floor(num / 1000);
        var rem = num % 1000;
        var thWord = '';
        if (th === 1) thWord = 'ألف';
        else if (th === 2) thWord = 'ألفان';
        else if (th >= 3 && th <= 10) thWord = numberToArabicWords(th) + ' آلاف';
        else thWord = numberToArabicWords(th) + ' ألف';
        return thWord + (rem > 0 ? ' و' + numberToArabicWords(rem) : '');
    }
    return num.toString();
}

// ========== Utilities ==========
function fmt(n) {
    return parseFloat(n || 0).toLocaleString('en', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

// ========== Tab Persistence ==========
document.querySelectorAll('#paymentTabs a[data-bs-toggle="tab"]').forEach(function(tab) {
    tab.addEventListener('shown.bs.tab', function(e) {
        var tabName = e.target.getAttribute('href').replace('#pane-', '');
        var url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url);
    });
});

// ========== DataTables ==========
function getCheckedValues(selector) {
    var vals = [];
    $(selector + ':checked').each(function() {
        vals.push($(this).val());
    });
    return vals;
}

function updateFilterLabel(checkboxSelector, labelSelector) {
    var checked = $(checkboxSelector + ':checked');
    if (checked.length === 0) {
        $(labelSelector).text('الكل');
    } else if (checked.length === 1) {
        var txt = checked.first().next('label').text().trim();
        $(labelSelector).text(txt);
    } else {
        $(labelSelector).text(checked.length + ' تم تحديدهم');
    }
}

function updateCascadingFilters() {
    var selStages = getCheckedValues('.stage-checkbox');

    $('.grade-item').each(function() {
        var st = String($(this).attr('data-stage'));
        if (selStages.length === 0 || selStages.includes(st)) {
            $(this).show();
        } else {
            $(this).hide();
            $(this).find('.grade-checkbox').prop('checked', false);
        }
    });

    var selGrades = getCheckedValues('.grade-checkbox');
    $('.class-item').each(function() {
        var gr = String($(this).attr('data-grade'));
        if (selGrades.length === 0 || selGrades.includes(gr)) {
            $(this).show();
        } else {
            $(this).hide();
            $(this).find('.class-checkbox').prop('checked', false);
        }
    });

    updateFilterLabel('.stage-checkbox', '#selectedStagesLabel');
    updateFilterLabel('.grade-checkbox', '#selectedGradesLabel');
    updateFilterLabel('.class-checkbox', '#selectedClassesLabel');
    updateFilterLabel('.status-checkbox', '#selectedStatusLabel');
}

function reloadFeeTable() {
    updateCascadingFilters();
    if (window.AdminServerSideTable && window.AdminServerSideTable.reload) {
        window.AdminServerSideTable.reload('#studentsTable');
    } else if ($.fn.DataTable && $.fn.DataTable.isDataTable('#studentsTable')) {
        $('#studentsTable').DataTable().ajax.reload();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (typeof jQuery === 'undefined') return;
    var $ = jQuery;

    updateCascadingFilters();

    if ($('#studentsTable').length && window.AdminServerSideTable) {
        window.AdminServerSideTable.init({
            selector: '#studentsTable', url: 'ajax_fee_payments_datatable.php', order: [[2, 'asc']],
            language: { processing: '<i class="fas fa-spinner fa-spin me-2"></i>جاري تحميل بيانات الرسوم…', emptyTable: 'لا يوجد طلاب مطابقون للفلاتر المحددة.' },
            requestData: function () {
                return {
                    csrf_token: (typeof csrfToken !== 'undefined' ? csrfToken : ''),
                    year: selectedYear,
                    stage_id: getCheckedValues('.stage-checkbox').join(','),
                    grade_id: getCheckedValues('.grade-checkbox').join(','),
                    class_id: getCheckedValues('.class-checkbox').join(','),
                    fee_status: getCheckedValues('.status-checkbox').join(',')
                };
            }
        });
    }

    $(document).on('change', '.stage-checkbox, .grade-checkbox, .class-checkbox, .status-checkbox', function() {
        reloadFeeTable();
    });

    $(document).on('click', '#resetFeeFiltersBtn', function() {
        $('.stage-checkbox, .grade-checkbox, .class-checkbox, .status-checkbox').prop('checked', false);
        reloadFeeTable();
    });

    if ($('#logTable').length && typeof $.fn.DataTable !== 'undefined') {
        $('#logTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/ar.json' },
            order: [[1, 'desc']],
            pageLength: 50,
            responsive: true
        });
    }
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
        bootstrap.Tooltip.getOrCreateInstance(element);
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
