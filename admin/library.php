<?php
$page_title = "المكتبة";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/AcademicYear.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/SchemaReadinessGuard.php';
require_once '../classes/StudentOperationalGuard.php';
require_once '../classes/ScopedStaffPortalContext.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$success_message = $_SESSION['library_success'] ?? '';
$error_message = $_SESSION['library_error'] ?? '';
unset($_SESSION['library_success'], $_SESSION['library_error']);

$database = new Database();
$db = $database->getConnection();
$studentOperationalGuard = new StudentOperationalGuard($db);

$schemaGuard = new SchemaReadinessGuard($db);
$schemaGuard->assertColumns(
    'library_fines',
    ['loan_id', 'student_id', 'amount', 'reason', 'paid', 'paid_at', 'notes']
);
$schemaGuard->assertColumns(
    'library_books',
    ['author', 'category', 'isbn', 'copies_total', 'copies_available', 'location', 'status', 'notes']
);

$currentAcademicYearId = AcademicYear::currentId($db);
$portalContext = new ScopedStaffPortalContext($db, $currentAcademicYearId);
$allowedClassIds = $portalContext->allowedClassIds();
$studentScopeSql = static function (string $classColumn) use ($allowedClassIds): array {
    if ($allowedClassIds === null) {
        return ['', []];
    }
    if ($allowedClassIds === []) {
        return [' AND 1 = 0', []];
    }
    return [' AND ' . $classColumn . ' IN (' . implode(',', array_fill(0, count($allowedClassIds), '?')) . ')', $allowedClassIds];
};
$assertLoanAllowed = static function (int $loanId, bool $activeOnly = false) use ($db, $portalContext): array {
    $sql = 'SELECT book_id, student_id FROM library_loans WHERE id = ?';
    if ($activeOnly) {
        $sql .= " AND status <> 'returned'";
    }
    $sql .= ' LIMIT 1 FOR UPDATE';
    $stmt = $db->prepare($sql);
    $stmt->execute([$loanId]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loan) {
        throw new RuntimeException('عملية الاستعارة غير صالحة أو غير موجودة.');
    }
    $portalContext->assertStudentAllowed((int)$loan['student_id']);
    return $loan;
};
$assertFineAllowed = static function (int $fineId) use ($db, $portalContext): int {
    $stmt = $db->prepare('SELECT COALESCE(f.student_id, l.student_id) FROM library_fines f LEFT JOIN library_loans l ON l.id = f.loan_id WHERE f.id = ? LIMIT 1');
    $stmt->execute([$fineId]);
    $studentId = (int)($stmt->fetchColumn() ?: 0);
    if ($studentId <= 0) {
        throw new RuntimeException('الغرامة المطلوبة غير موجودة.');
    }
    $portalContext->assertStudentAllowed($studentId);
    return $studentId;
};
$activeTab = $_GET['tab'] ?? 'books';
if (!in_array($activeTab, ['books', 'loans', 'returns', 'fines'], true)) {
    $activeTab = 'books';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $_SESSION['library_error'] = 'خطأ في التحقق من الأمان.';
        header('Location: library.php?tab=' . urlencode($activeTab));
        exit();
    }

    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_book') {
            $title = trim((string)($_POST['title'] ?? ''));
            $copies = max(1, (int)($_POST['copies_total'] ?? 1));
            if ($title === '') {
                throw new InvalidArgumentException('اسم الكتاب مطلوب.');
            }
            $stmt = $db->prepare("INSERT INTO library_books (title, author, category, isbn, copies_total, copies_available, location, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $title,
                trim((string)($_POST['author'] ?? '')),
                trim((string)($_POST['category'] ?? '')),
                trim((string)($_POST['isbn'] ?? '')),
                $copies,
                $copies,
                trim((string)($_POST['location'] ?? '')),
                trim((string)($_POST['notes'] ?? '')),
            ]);
            ActivityLog::logCreate('library_book', (int)$db->lastInsertId(), $title);
            $_SESSION['library_success'] = 'تمت إضافة الكتاب بنجاح.';
            $activeTab = 'books';
        } elseif ($action === 'borrow_book') {
            $bookId = (int)($_POST['book_id'] ?? 0);
            $borrowerType = trim((string)($_POST['borrower_type'] ?? 'student'));
            $borrowerId = $borrowerType === 'staff' ? (int)($_POST['staff_id'] ?? 0) : (int)($_POST['student_id'] ?? 0);
            if ($borrowerId <= 0) {
                $borrowerId = (int)($_POST['student_id'] ?? 0);
            }

            if ($bookId <= 0 || $borrowerId <= 0) {
                throw new InvalidArgumentException('اختر الكتاب والمستعير بشكل صحيح.');
            }
            $db->beginTransaction();
            if ($borrowerType !== 'staff') {
                $portalContext->assertStudentAllowed($borrowerId);
                $studentOperationalGuard->assertWritable($borrowerId);
            }
            $bookStmt = $db->prepare("SELECT copies_available FROM library_books WHERE id = ? FOR UPDATE");
            $bookStmt->execute([$bookId]);
            $available = (int)$bookStmt->fetchColumn();
            if ($available <= 0) {
                throw new RuntimeException('لا توجد نسخ متاحة من هذا الكتاب.');
            }
            $stmt = $db->prepare("INSERT INTO library_loans (book_id, student_id, borrowed_at, due_at, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $bookId,
                $borrowerId,
                $_POST['borrowed_at'] ?: date('Y-m-d'),
                $_POST['due_at'] ?: null,
                trim((string)($_POST['notes'] ?? '')),
                $_SESSION['user_id'] ?? null,
            ]);
            $db->prepare("UPDATE library_books SET copies_available = copies_available - 1 WHERE id = ?")->execute([$bookId]);
            $db->commit();
            ActivityLog::logCreate('library_loan', (int)$db->lastInsertId(), 'استعارة كتاب', ['book_id' => $bookId, 'student_id' => $borrowerId]);
            $_SESSION['library_success'] = 'تم تسجيل الاستعارة بنجاح.';
            $activeTab = 'loans';
        } elseif ($action === 'return_book') {
            $loanId = (int)($_POST['loan_id'] ?? 0);
            if ($loanId <= 0) {
                throw new InvalidArgumentException('اختر عملية الاستعارة.');
            }
            $db->beginTransaction();
            $loan = $assertLoanAllowed($loanId, true);
            $bookId = (int)$loan['book_id'];
            $db->prepare("UPDATE library_loans SET returned_at = ?, status = 'returned' WHERE id = ?")->execute([$_POST['returned_at'] ?: date('Y-m-d'), $loanId]);
            $db->prepare("UPDATE library_books SET copies_available = copies_available + 1 WHERE id = ?")->execute([$bookId]);
            $db->commit();
            ActivityLog::logUpdate('library_loan', $loanId, 'إرجاع كتاب', ['book_id' => $bookId]);
            $_SESSION['library_success'] = 'تم تسجيل الإرجاع بنجاح.';
            $activeTab = 'returns';
        } elseif ($action === 'add_fine') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            if ($studentId <= 0 || $amount <= 0) {
                throw new InvalidArgumentException('اختر الطالب وأدخل قيمة الغرامة.');
            }
            $portalContext->assertStudentAllowed($studentId);
            $studentOperationalGuard->assertWritable($studentId);
            $fineLoanId = !empty($_POST['loan_id']) ? (int)$_POST['loan_id'] : 0;
            if ($fineLoanId > 0) {
                $loan = $assertLoanAllowed($fineLoanId);
                if ((int)$loan['student_id'] !== $studentId) {
                    throw new InvalidArgumentException('الاستعارة المحددة لا تخص الطالب المختار.');
                }
            }
            $stmt = $db->prepare("INSERT INTO library_fines (loan_id, student_id, amount, reason, notes)
                VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $fineLoanId > 0 ? $fineLoanId : null,
                $studentId,
                $amount,
                trim((string)($_POST['reason'] ?? '')),
                trim((string)($_POST['notes'] ?? '')),
            ]);
            ActivityLog::logCreate('library_fine', (int)$db->lastInsertId(), 'غرامة مكتبة', ['student_id' => $studentId, 'amount' => $amount]);
            $_SESSION['library_success'] = 'تم تسجيل الغرامة بنجاح.';
            $activeTab = 'fines';
        } elseif ($action === 'pay_fine') {
            $fineId = (int)($_POST['fine_id'] ?? 0);
            if ($fineId <= 0) {
                throw new InvalidArgumentException('معرف الغرامة غير صالح.');
            }
            $assertFineAllowed($fineId);
            $db->prepare("UPDATE library_fines SET paid = 1, paid_at = CURDATE() WHERE id = ?")->execute([$fineId]);
            ActivityLog::logUpdate('library_fine', $fineId, 'سداد غرامة مكتبة');
            $_SESSION['library_success'] = 'تم تسجيل سداد الغرامة.';
            $activeTab = 'fines';
        } elseif ($action === 'edit_book') {
            $bookId = (int)($_POST['book_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $copiesTotal = max(1, (int)($_POST['copies_total'] ?? 1));

            if ($bookId <= 0 || $title === '') {
                throw new InvalidArgumentException('اسم الكتاب مطلوب.');
            }

            $db->beginTransaction();
            $bookStmt = $db->prepare("SELECT copies_total, copies_available FROM library_books WHERE id = ? FOR UPDATE");
            $bookStmt->execute([$bookId]);
            $oldBook = $bookStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldBook) {
                throw new RuntimeException('الكتاب غير موجود.');
            }

            $diff = $copiesTotal - (int)$oldBook['copies_total'];
            $newAvailable = max(0, (int)$oldBook['copies_available'] + $diff);

            $stmt = $db->prepare("UPDATE library_books SET title = ?, author = ?, category = ?, isbn = ?, copies_total = ?, copies_available = ?, location = ?, notes = ? WHERE id = ?");
            $stmt->execute([
                $title,
                trim((string)($_POST['author'] ?? '')),
                trim((string)($_POST['category'] ?? '')),
                trim((string)($_POST['isbn'] ?? '')),
                $copiesTotal,
                $newAvailable,
                trim((string)($_POST['location'] ?? '')),
                trim((string)($_POST['notes'] ?? '')),
                $bookId
            ]);
            $db->commit();

            ActivityLog::logUpdate('library_book', $bookId, $title);
            $_SESSION['library_success'] = 'تم تحديث بيانات الكتاب بنجاح.';
            $activeTab = 'books';
        } elseif ($action === 'delete_book') {
            $bookId = (int)($_POST['book_id'] ?? 0);
            if ($bookId <= 0) {
                throw new InvalidArgumentException('معرف الكتاب غير صالح.');
            }

            $loanStmt = $db->prepare("SELECT COUNT(*) FROM library_loans WHERE book_id = ? AND status <> 'returned'");
            $loanStmt->execute([$bookId]);
            if ((int)$loanStmt->fetchColumn() > 0) {
                throw new RuntimeException('لا يمكن حذف الكتاب لأنه مستعار حالياً.');
            }

            $db->prepare("DELETE FROM library_books WHERE id = ?")->execute([$bookId]);
            ActivityLog::logDelete('library_book', $bookId, 'حذف كتاب');
            $_SESSION['library_success'] = 'تم حذف الكتاب بنجاح.';
            $activeTab = 'books';
        } elseif ($action === 'edit_loan') {
            $loanId = (int)($_POST['loan_id'] ?? 0);
            $bookId = (int)($_POST['book_id'] ?? 0);
            $borrowerType = trim((string)($_POST['borrower_type'] ?? 'student'));
            $borrowerId = $borrowerType === 'staff' ? (int)($_POST['staff_id'] ?? 0) : (int)($_POST['student_id'] ?? 0);
            if ($borrowerId <= 0) {
                $borrowerId = (int)($_POST['student_id'] ?? 0);
            }
            $borrowedAt = trim((string)($_POST['borrowed_at'] ?? ''));
            $dueAt = trim((string)($_POST['due_at'] ?? '')) ?: null;
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($loanId <= 0 || $bookId <= 0 || $borrowerId <= 0 || $borrowedAt === '') {
                throw new InvalidArgumentException('جميع البيانات المطلوبة يجب ملؤها بشكل صحيح.');
            }
            $assertLoanAllowed($loanId);
            if ($borrowerType !== 'staff') {
                $portalContext->assertStudentAllowed($borrowerId);
                $studentOperationalGuard->assertWritable($borrowerId);
            }

            $stmt = $db->prepare("UPDATE library_loans SET book_id = ?, student_id = ?, borrowed_at = ?, due_at = ?, notes = ? WHERE id = ?");
            $stmt->execute([$bookId, $borrowerId, $borrowedAt, $dueAt, $notes, $loanId]);
            ActivityLog::logUpdate('library_loan', $loanId, 'تعديل بيانات استعارة');
            $_SESSION['library_success'] = 'تم تحديث بيانات الاستعارة بنجاح.';
            $activeTab = 'loans';
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['library_error'] = 'حدث خطأ: ' . $e->getMessage();
    }

    header('Location: library.php?tab=' . urlencode($activeTab));
    exit();
}

$students = [];
$books = [];
$activeLoans = [];
$returnedLoans = [];
$fines = [];

$stagesList = $db->query("SELECT id, stage_name FROM stages ORDER BY stage_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$gradesList = $db->query("SELECT id, grade_name, stage_id FROM grades ORDER BY grade_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$classesList = $db->query("SELECT c.id, c.name AS class_name, c.grade_id, COALESCE(g.stage_id, 0) AS stage_id FROM classes c LEFT JOIN grades g ON g.id = c.grade_id WHERE c.status = 'active' ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$allBooksList = $db->query("SELECT id, title, category, author FROM library_books ORDER BY title")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$categoriesList = $db->query("SELECT DISTINCT category FROM library_books WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$staffList = $db->query("SELECT u.id, u.name, u.role FROM users u WHERE u.role <> 'student' AND u.status = 'active' AND u.deleted_at IS NULL ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC) ?: [];


// لا نحمل قوائم تبويبات المكتبة كلها دفعة واحدة: بعض البيانات مطلوبة
// كخيارات داخل مودالات التبويب الحالي فقط، أما بقية التبويبات فلا داعي لجلبها.
if (false && in_array($activeTab, ['loans', 'fines'], true)) {
    $studentsStmt = $db->prepare("SELECT u.id, u.name, sp.student_code, c.name AS class_name
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'
        LEFT JOIN classes c ON c.id = se.class_id
        WHERE u.role = 'student' AND u.status = 'active' AND u.deleted_at IS NULL
        ORDER BY c.name, u.name");
    $studentsStmt->execute([$currentAcademicYearId]);
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
}
if (false && ($activeTab === 'books' || $activeTab === 'loans')) {
    $books = $db->query("SELECT * FROM library_books ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
}
if (false && in_array($activeTab, ['loans', 'returns', 'fines'], true)) {
    $activeLoans = $db->query("SELECT l.*, b.title, u.name AS student_name, sp.student_code
        FROM library_loans l
        JOIN library_books b ON b.id = l.book_id
        JOIN users u ON u.id = l.student_id
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE l.status <> 'returned'
        ORDER BY l.borrowed_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}
// Rows for every tab are loaded only through the scope-aware DataTables endpoint.

// حساب الإحصائيات لكروت الإحصائيات الموحدة
$bookStats = $db->query("SELECT COUNT(*) AS total_books, COALESCE(SUM(copies_total), 0) AS total_copies, COALESCE(SUM(copies_available), 0) AS available_copies FROM library_books")->fetch(PDO::FETCH_ASSOC) ?: [];
$statTotalBooks = (int)($bookStats['total_books'] ?? 0);
$statTotalCopies = (int)($bookStats['total_copies'] ?? 0);
$statAvailableCopies = (int)($bookStats['available_copies'] ?? 0);
$libraryEnrollmentJoin = '';
$libraryYearParams = [];
$libraryClassColumn = 'u.class_id';
if ($currentAcademicYearId > 0) {
    $libraryEnrollmentJoin = " LEFT JOIN student_enrollments se ON se.student_id = u.id AND se.academic_year_id = ? AND se.enrollment_status = 'enrolled'";
    $libraryYearParams = [$currentAcademicYearId];
    $libraryClassColumn = 'se.class_id';
}
[$libraryScopeClause, $libraryScopeParams] = $studentScopeSql($libraryClassColumn);
$loanStatFrom = ' FROM library_loans l JOIN users u ON u.id = l.student_id' . $libraryEnrollmentJoin;
$loanStatParams = array_merge($libraryYearParams, $libraryScopeParams);
$activeLoanStmt = $db->prepare('SELECT COUNT(*)' . $loanStatFrom . " WHERE l.status <> 'returned'" . $libraryScopeClause);
$activeLoanStmt->execute($loanStatParams);
$statActiveLoans = (int)$activeLoanStmt->fetchColumn();
$totalReturnsStmt = $db->prepare('SELECT COUNT(*)' . $loanStatFrom . " WHERE l.status = 'returned'" . $libraryScopeClause);
$totalReturnsStmt->execute($loanStatParams);
$statTotalReturns = (int)$totalReturnsStmt->fetchColumn();
$fineStatFrom = ' FROM library_fines f LEFT JOIN library_loans l ON l.id = f.loan_id LEFT JOIN users u ON u.id = COALESCE(f.student_id, l.student_id)' . $libraryEnrollmentJoin;
$finesStatStmt = $db->prepare('SELECT COUNT(*) AS unpaid_count, COALESCE(SUM(f.amount), 0) AS unpaid_amount' . $fineStatFrom . ' WHERE f.paid = 0' . $libraryScopeClause);
$finesStatStmt->execute($loanStatParams);
$finesStat = $finesStatStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$statUnpaidFinesCount = (int)($finesStat['unpaid_count'] ?? 0);
$statUnpaidFinesAmount = (float)($finesStat['unpaid_amount'] ?? 0);

require_once '../includes/admin_header.php';
?>

<div class="admin-page-heading">
    <h1 class="h2"><i class="fas fa-book-reader me-2 text-primary"></i>المكتبة المدرسية</h1>
    <div class="admin-top-actions no-print">
        <?php if ($activeTab === 'books'): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addBookModal">
                <i class="fas fa-plus-circle me-1"></i>إضافة كتاب جديد
            </button>
        <?php elseif ($activeTab === 'loans'): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addLoanModal">
                <i class="fas fa-plus-circle me-1"></i>تسجيل استعارة جديدة
            </button>
        <?php elseif ($activeTab === 'returns'): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addReturnModal">
                <i class="fas fa-plus-circle me-1"></i>تسجيل إرجاع كتاب
            </button>
        <?php elseif ($activeTab === 'fines'): ?>
            <button type="button" class="btn btn-header-premium btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addFineModal">
                <i class="fas fa-plus-circle me-1"></i>تسجيل غرامة جديدة
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
<?php endif; ?>

<!-- كروت الإحصائيات الموحدة -->
<div class="dashboard-canvas sortable-dashboard">
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4 sortable-dashboard" id="widget-library-stats" aria-label="كروت إحصائيات المكتبة">
        <div class="col" id="stat-total-books">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                <div class="stat-card-icon"><i class="fas fa-book"></i></div>
                <div class="stat-card-badge"><?php echo $statTotalCopies; ?> نسخة</div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $statTotalBooks; ?>">0</div>
                    <div class="stat-card-label">إجمالي الكتب</div>
                    <div class="stat-card-sub"><i class="fas fa-copy me-1"></i> إجمالي النسخ: <?php echo $statTotalCopies; ?></div>
                </div>
            </div>
        </div>
        <div class="col" id="stat-active-loans">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="stat-card-icon"><i class="fas fa-book-reader"></i></div>
                <div class="stat-card-badge">مستعار</div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $statActiveLoans; ?>">0</div>
                    <div class="stat-card-label">الكتب المستعارة</div>
                    <div class="stat-card-sub"><i class="fas fa-info-circle me-1"></i> قيد الاستخدام حالياً</div>
                </div>
            </div>
        </div>
        <div class="col" id="stat-total-returns">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-card-icon"><i class="fas fa-undo-alt"></i></div>
                <div class="stat-card-badge">مرجع</div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $statTotalReturns; ?>">0</div>
                    <div class="stat-card-label">عمليات الإرجاع</div>
                    <div class="stat-card-sub"><i class="fas fa-check-circle me-1"></i> إجمالي الكتب المرجعة</div>
                </div>
            </div>
        </div>
        <div class="col" id="stat-unpaid-fines">
            <div class="stat-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
                <div class="stat-card-icon"><i class="fas fa-exclamation-circle"></i></div>
                <div class="stat-card-badge"><?php echo number_format($statUnpaidFinesAmount, 0); ?> ج.م</div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $statUnpaidFinesCount; ?>">0</div>
                    <div class="stat-card-label">الغرامات غير المسددة</div>
                    <div class="stat-card-sub"><i class="fas fa-money-bill-wave me-1"></i> القيمة: <?php echo number_format($statUnpaidFinesAmount, 2); ?> ج.م</div>
                </div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-tabs mb-3 border-bottom" id="libraryPageTabs" role="tablist">
    <?php
    $tabCounts = [
        'books' => $statTotalBooks,
        'loans' => $statActiveLoans,
        'returns' => $statTotalReturns,
        'fines' => (int)$db->query("SELECT COUNT(*) FROM library_fines")->fetchColumn()
    ];
    $tabIcons = [
        'books' => 'fa-book',
        'loans' => 'fa-book-reader',
        'returns' => 'fa-undo-alt',
        'fines' => 'fa-exclamation-circle'
    ];
    foreach (['books' => 'الكتب', 'loans' => 'الاستعارة', 'returns' => 'الإرجاع', 'fines' => 'الغرامات'] as $tab => $label):
    ?>
        <li class="nav-item" role="presentation">
            <a class="nav-link fw-semibold <?php echo $activeTab === $tab ? 'active' : ''; ?>" href="library.php?tab=<?php echo $tab; ?>">
                <i class="fas <?php echo $tabIcons[$tab]; ?> me-2"></i><?php echo $label; ?>
                <span class="badge rounded-pill bg-primary ms-1"><?php echo $tabCounts[$tab]; ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($activeTab === 'books'): ?>
    <div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable admin-data-table" id="libraryBooksTable">
                <thead>
                    <tr>
                        <th>الكتاب</th>
                        <th>المؤلف</th>
                        <th>التصنيف</th>
                        <th>المتاح</th>
                        <th>المكان</th>
                        <th class="text-center actions-column admin-table-actions" style="width: 100px;">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($books as $book): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($book['title']); ?></td>
                            <td><?php echo htmlspecialchars($book['author'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($book['category'] ?? '-'); ?></td>
                            <td><span class="badge bg-info text-dark"><?php echo (int)$book['copies_available']; ?> / <?php echo (int)$book['copies_total']; ?></span></td>
                            <td><?php echo htmlspecialchars($book['location'] ?? '-'); ?></td>
                            <td class="text-center actions-column admin-table-actions">
                                <button type="button" class="btn btn-action-pills btn-edit has-tooltip me-1 edit-book-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editBookModal"
                                        data-id="<?php echo $book['id']; ?>"
                                        data-title="<?php echo htmlspecialchars($book['title']); ?>"
                                        data-author="<?php echo htmlspecialchars($book['author'] ?? ''); ?>"
                                        data-category="<?php echo htmlspecialchars($book['category'] ?? ''); ?>"
                                        data-isbn="<?php echo htmlspecialchars($book['isbn'] ?? ''); ?>"
                                        data-copies_total="<?php echo $book['copies_total']; ?>"
                                        data-location="<?php echo htmlspecialchars($book['location'] ?? ''); ?>"
                                        data-notes="<?php echo htmlspecialchars($book['notes'] ?? ''); ?>"
                                        title="تعديل">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-action-pills btn-delete has-tooltip delete-book-btn"
                                        data-id="<?php echo $book['id']; ?>"
                                        data-title="<?php echo htmlspecialchars($book['title']); ?>"
                                        title="حذف">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($activeTab === 'loans'): ?>
    <form id="libraryLoansFilterForm" class="admin-filter-bar" novalidate>
        <div class="admin-filter-controls">
            <select id="tableFilterLoanStatus" class="form-select form-select-sm admin-inline-select-sm" aria-label="حالة الاستعارة">
                <option value="">كل الحالات</option>
                <option value="active">قيد الاستعارة</option>
                <option value="overdue">متأخر</option>
                <option value="returned">تم الإرجاع</option>
            </select>
            <select id="tableFilterBook" class="form-select form-select-sm admin-inline-select-sm" aria-label="الكتاب">
                <option value="">جميع الكتب</option>
                <?php foreach ($allBooksList as $b): ?>
                    <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['title']); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="tableFilterStage" class="form-select form-select-sm admin-inline-select-sm" aria-label="المرحلة الدراسية">
                <option value="">كل المراحل</option>
                <?php foreach ($stagesList as $stg): ?>
                    <option value="<?php echo (int)$stg['id']; ?>"><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="tableFilterGrade" class="form-select form-select-sm admin-inline-select-sm" aria-label="الصف الدراسي">
                <option value="">كل الصفوف</option>
                <?php foreach ($gradesList as $grd): ?>
                    <option value="<?php echo (int)$grd['id']; ?>" data-stage-id="<?php echo (int)$grd['stage_id']; ?>"><?php echo htmlspecialchars($grd['grade_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="tableFilterClass" class="form-select form-select-sm admin-inline-select-sm" aria-label="الفصل">
                <option value="">كل الفصول</option>
                <?php foreach ($classesList as $cls): ?>
                    <option value="<?php echo (int)$cls['id']; ?>" data-grade-id="<?php echo (int)$cls['grade_id']; ?>" data-stage-id="<?php echo (int)$cls['stage_id']; ?>"><?php echo htmlspecialchars($cls['class_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="admin-filter-actions">
            <button type="button" class="btn btn-light btn-sm" id="resetTableFilters"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</button>
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#tableSettingsModal" title="إعدادات الجدول">
                <i class="fas fa-cog me-1"></i>إعدادات الجدول
            </button>
        </div>
    </form>
    <div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable admin-data-table" id="libraryLoansTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الاسم</th>
                        <th>المرحلة والصف والفصل</th>
                        <th>الكتاب</th>
                        <th>تاريخ الاستعارة</th>
                        <th>الاستحقاق</th>
                        <th>تاريخ الإرجاع</th>
                        <th>الملاحظات</th>
                        <th>حالة الاستعارة</th>
                        <th class="text-center actions-column admin-table-actions" style="width: 100px;">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $seq = 1; foreach ($activeLoans as $loan): ?>
                        <tr>
                            <td><?php echo $seq++; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($loan['student_name']); ?></strong>
                                <?php if (!empty($loan['student_code'])): ?>
                                    <span class="badge bg-light text-secondary border ms-1"><?php echo htmlspecialchars($loan['student_code']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $sgc = array_filter([$loan['stage_name'] ?? '', $loan['grade_name'] ?? '', $loan['class_name'] ?? '']);
                                echo !empty($sgc) ? htmlspecialchars(implode(' / ', $sgc)) : '-';
                                ?>
                            </td>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($loan['title']); ?></td>
                            <td><?php echo htmlspecialchars($loan['borrowed_at']); ?></td>
                            <td><?php echo htmlspecialchars($loan['due_at'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($loan['returned_at'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($loan['notes'] ?? '-'); ?></td>
                            <td>
                                <?php
                                $isOverdue = !empty($loan['due_at']) && strtotime($loan['due_at']) < strtotime('today');
                                if (($loan['status'] ?? '') === 'returned') {
                                    echo '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-check-circle me-1"></i>تم الإرجاع</span>';
                                } elseif ($isOverdue) {
                                    echo '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fas fa-exclamation-triangle me-1"></i>متأخر</span>';
                                } else {
                                    echo '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="fas fa-book-reader me-1"></i>قيد الاستعارة</span>';
                                }
                                ?>
                            </td>
                            <td class="text-center actions-column admin-table-actions">
                                <button type="button" class="btn btn-action-pills btn-edit edit-loan-btn me-1"
                                        data-id="<?php echo $loan['id']; ?>"
                                        data-book_id="<?php echo $loan['book_id'] ?? 0; ?>"
                                        data-student_id="<?php echo $loan['student_id'] ?? 0; ?>"
                                        data-student_name="<?php echo htmlspecialchars($loan['student_name']); ?>"
                                        data-title="<?php echo htmlspecialchars($loan['title']); ?>"
                                        data-borrowed_at="<?php echo htmlspecialchars($loan['borrowed_at']); ?>"
                                        data-due_at="<?php echo htmlspecialchars($loan['due_at'] ?? ''); ?>"
                                        data-notes="<?php echo htmlspecialchars($loan['notes'] ?? ''); ?>"
                                        data-bs-toggle="tooltip" title="تعديل الاستعارة">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-action-pills btn-activate return-loan-btn"
                                        data-id="<?php echo $loan['id']; ?>"
                                        data-title="<?php echo htmlspecialchars($loan['title']); ?>"
                                        data-student_name="<?php echo htmlspecialchars($loan['student_name']); ?>"
                                        data-bs-toggle="tooltip" title="تسجيل إرجاع الكتاب">
                                    <i class="fas fa-undo"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($activeTab === 'returns'): ?>
    <div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable admin-data-table" id="libraryReturnsTable">
                <thead>
                    <tr>
                        <th>الكتاب</th>
                        <th>الطالب</th>
                        <th>تاريخ الإرجاع</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($returnedLoans as $loan): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($loan['title']); ?></td>
                            <td><?php echo htmlspecialchars($loan['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($loan['returned_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="admin-list-surface">
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable admin-data-table" id="libraryFinesTable">
                <thead>
                    <tr>
                        <th>الطالب</th>
                        <th>الكتاب</th>
                        <th>القيمة</th>
                        <th>السبب</th>
                        <th>الحالة</th>
                        <th class="text-center actions-column admin-table-actions">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fines as $fine): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($fine['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($fine['title'] ?? '-'); ?></td>
                            <td class="fw-bold text-danger"><?php echo number_format((float)$fine['amount'], 2); ?> ج.م</td>
                            <td><?php echo htmlspecialchars($fine['reason'] ?? '-'); ?></td>
                            <td><?php echo $fine['paid'] ? '<span class="badge bg-success">مسددة</span>' : '<span class="badge bg-warning text-dark">غير مسددة</span>'; ?></td>
                            <td class="text-center actions-column admin-table-actions">
                                <?php if (!$fine['paid']): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="pay_fine">
                                        <input type="hidden" name="fine_id" value="<?php echo (int)$fine['id']; ?>">
                                        <button type="submit" class="btn btn-action-pills btn-activate has-tooltip" title="تسديد الغرامة" data-bs-toggle="tooltip">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ================= Modals (مودالات الإضافة) ================= -->

<!-- مودال إضافة كتاب -->
<div class="modal fade" id="addBookModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="add_book">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-book me-2"></i>إضافة كتاب جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">اسم الكتاب <span class="text-danger">*</span></label>
                            <input class="form-control" name="title" required placeholder="أدخل اسم الكتاب...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">المؤلف</label>
                            <input class="form-control" name="author" placeholder="أدخل اسم المؤلف...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">التصنيف</label>
                            <input class="form-control" name="category" placeholder="مثال: علوم، تاريخ...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">عدد النسخ</label>
                            <input type="number" class="form-control" name="copies_total" value="1" min="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">المكان في المكتبة</label>
                            <input class="form-control" name="location" placeholder="مثال: رف أ-3...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">رقم ISBN</label>
                            <input class="form-control" name="isbn" placeholder="أدخل رقم الـ ISBN...">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <input class="form-control" name="notes" placeholder="ملاحظات إضافية عن الكتاب...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus me-1"></i>إضافة الكتاب
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تسجيل استعارة -->
<div class="modal fade" id="addLoanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="borrow_book">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-book-reader me-2"></i>تسجيل عملية استعارة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-bold">تصنيف الكتاب</label>
                            <select class="form-select" id="addLoanBookCategoryFilter">
                                <option value="">كل التصنيفات...</option>
                                <?php foreach ($categoriesList as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-bold">الكتاب المستعار <span class="text-danger">*</span></label>
                            <select class="form-select" name="book_id" id="addLoanBookSelect" data-library-lookup="books" required>
                                <option value="">اختر الكتاب...</option>
                                <?php foreach ($books as $book): if ((int)$book['copies_available'] <= 0) continue; ?>
                                    <option value="<?php echo (int)$book['id']; ?>"><?php echo htmlspecialchars($book['title']); ?> (المتاح: <?php echo (int)$book['copies_available']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">فئة المستعير <span class="text-danger">*</span></label>
                            <select class="form-select borrower-type-select" name="borrower_type" id="addLoanBorrowerType" required>
                                <option value="student" selected>طالب</option>
                                <option value="staff">موظف / معلم</option>
                            </select>
                        </div>

                        <!-- حاوية بيانات طالب -->
                        <div class="col-12 student-borrower-container">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">المرحلة الدراسية</label>
                                    <select class="form-select" id="loanFilterStage">
                                        <option value="">كل المراحل...</option>
                                        <?php foreach ($stagesList as $stg): ?>
                                            <option value="<?php echo (int)$stg['id']; ?>"><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">الصف الدراسي</label>
                                    <select class="form-select" id="loanFilterGrade">
                                        <option value="">كل الصفوف...</option>
                                        <?php foreach ($gradesList as $grd): ?>
                                            <option value="<?php echo (int)$grd['id']; ?>" data-stage-id="<?php echo (int)$grd['stage_id']; ?>">
                                                <?php echo htmlspecialchars($grd['grade_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">الفصل</label>
                                    <select class="form-select" id="loanFilterClass">
                                        <option value="">كل الفصول...</option>
                                        <?php foreach ($classesList as $cls): ?>
                                            <option value="<?php echo (int)$cls['id']; ?>" data-grade-id="<?php echo (int)$cls['grade_id']; ?>" data-stage-id="<?php echo (int)$cls['stage_id']; ?>">
                                                <?php echo htmlspecialchars($cls['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">الطالب المستعير <span class="text-danger">*</span></label>
                                    <select class="form-select student-select" name="student_id" id="loanStudentSelect" data-library-lookup="students" required>
                                        <option value="">اختر الطالب...</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo (int)$student['id']; ?>"><?php echo htmlspecialchars(($student['student_code'] ? $student['student_code'] . ' - ' : '') . $student['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- حاوية بيانات موظف -->
                        <div class="col-12 staff-borrower-container d-none">
                            <label class="form-label fw-bold">الموظف / المعلم المستعير <span class="text-danger">*</span></label>
                            <select class="form-select staff-select" name="staff_id" id="loanStaffSelect" data-library-lookup="staff">
                                <option value="">اختر الموظف أو المعلم...</option>
                                <?php foreach ($staffList as $stf): ?>
                                    <option value="<?php echo (int)$stf['id']; ?>"><?php echo htmlspecialchars($stf['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">تاريخ الاستعارة</label>
                            <input type="text" class="form-control flatpickr-date" name="borrowed_at" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">تاريخ الاستحقاق (الإرجاع المتوقع)</label>
                            <input type="text" class="form-control flatpickr-date" name="due_at" placeholder="اختر التاريخ...">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <input class="form-control" name="notes" placeholder="أدخل أي ملاحظات على الاستعارة...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>حفظ الاستعارة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تسجيل إرجاع -->
<div class="modal fade" id="addReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="return_book">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-undo-alt me-2"></i>تسجيل إرجاع كتاب</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold">الاستعارة النشطة <span class="text-danger">*</span></label>
                            <select class="form-select" name="loan_id" data-library-lookup="loans" required>
                                <option value="">اختر الاستعارة لإرجاعها...</option>
                                <?php foreach ($activeLoans as $loan): ?>
                                    <option value="<?php echo (int)$loan['id']; ?>"><?php echo htmlspecialchars($loan['title'] . ' - ' . $loan['student_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">تاريخ الإرجاع</label>
                            <input type="text" class="form-control flatpickr-date" name="returned_at" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check-double me-1"></i>تسجيل الإرجاع
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تسجيل غرامة -->
<div class="modal fade" id="addFineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="add_fine">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exclamation-circle me-2"></i>تسجيل غرامة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold">الطالب <span class="text-danger">*</span></label>
                            <select class="form-select" name="student_id" data-library-lookup="students" required>
                                <option value="">اختر الطالب...</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo (int)$student['id']; ?>"><?php echo htmlspecialchars(($student['student_code'] ? $student['student_code'] . ' - ' : '') . $student['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الاستعارة المرتبطة (اختياري)</label>
                            <select class="form-select" name="loan_id" data-library-lookup="loans">
                                <option value="">اختر الاستعارة إن وجدت...</option>
                                <?php foreach ($activeLoans as $loan): ?>
                                    <option value="<?php echo (int)$loan['id']; ?>"><?php echo htmlspecialchars($loan['title'] . ' - ' . $loan['student_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">قيمة الغرامة <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" class="form-control" name="amount" required placeholder="0.00">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">السبب</label>
                            <input class="form-control" name="reason" placeholder="أدخل سبب الغرامة (مثال: تأخير، تلف...)">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="ملاحظات إضافية..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>حفظ الغرامة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تعديل كتاب -->
<div class="modal fade" id="editBookModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="edit_book">
                <input type="hidden" name="book_id" id="editBookId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>تعديل بيانات الكتاب</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">اسم الكتاب <span class="text-danger">*</span></label>
                            <input class="form-control" name="title" id="editBookTitle" required placeholder="أدخل اسم الكتاب...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">المؤلف</label>
                            <input class="form-control" name="author" id="editBookAuthor" placeholder="أدخل اسم المؤلف...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">التصنيف</label>
                            <input class="form-control" name="category" id="editBookCategory" placeholder="مثال: علوم، تاريخ...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">إجمالي عدد النسخ</label>
                            <input type="number" class="form-control" name="copies_total" id="editBookCopiesTotal" min="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">المكان في المكتبة</label>
                            <input class="form-control" name="location" id="editBookLocation" placeholder="مثال: رف أ-3...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">رقم ISBN</label>
                            <input class="form-control" name="isbn" id="editBookIsbn" placeholder="أدخل رقم الـ ISBN...">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <input class="form-control" name="notes" id="editBookNotes" placeholder="ملاحظات إضافية عن الكتاب...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال حذف كتاب -->
<div class="modal fade" id="deleteBookModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="delete_book">
                <input type="hidden" name="book_id" id="deleteBookId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>تأكيد حذف الكتاب</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-trash text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <p class="mb-3">هل أنت متأكد من رغبتك في حذف هذا الكتاب بشكل نهائي؟</p>
                    <h5 class="text-primary mb-3" id="deleteBookTitleText"></h5>
                    <div class="alert alert-warning mb-0 text-start">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        تنبيـه: لا يمكن التراجع عن هذه العملية بعد إتمامها.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>تأكيد الحذف
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/js/admin-server-side-table.js"></script>
<script src="../assets/js/admin_table_actions.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof initializeTableColumnSettings === 'function') {
        initializeTableColumnSettings('libraryLoansTable', {
            colStudentName: 1,
            colStageGradeClass: 2,
            colBookTitle: 3,
            colBorrowedAt: 4,
            colDueAt: 5,
            colReturnedAt: 6,
            colNotes: 7,
            colLoanStatus: 8
        }, 'educore_library_loans_columns');
    }
    var libraryTableMap={books:'#libraryBooksTable',loans:'#libraryLoansTable',returns:'#libraryReturnsTable',fines:'#libraryFinesTable'};
    var libraryTab=<?php echo json_encode($activeTab); ?>;
    if(window.AdminServerSideTable && libraryTableMap[libraryTab]) {
        window.AdminServerSideTable.init({
            selector: libraryTableMap[libraryTab],
            url: 'ajax_library_datatable.php',
            requestData: function() {
                return {
                    list: libraryTab,
                    loan_status: document.getElementById('tableFilterLoanStatus') ? document.getElementById('tableFilterLoanStatus').value : '',
                    book_id: document.getElementById('tableFilterBook') ? document.getElementById('tableFilterBook').value : '',
                    stage_id: document.getElementById('tableFilterStage') ? document.getElementById('tableFilterStage').value : '',
                    grade_id: document.getElementById('tableFilterGrade') ? document.getElementById('tableFilterGrade').value : '',
                    class_id: document.getElementById('tableFilterClass') ? document.getElementById('tableFilterClass').value : ''
                };
            }
        });
    }

    ['tableFilterLoanStatus', 'tableFilterBook', 'tableFilterStage', 'tableFilterGrade', 'tableFilterClass'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', function() {
                if (window.jQuery && window.jQuery.fn.DataTable && libraryTableMap[libraryTab]) {
                    window.jQuery(libraryTableMap[libraryTab]).DataTable().ajax.reload();
                }
            });
        }
    });

    var resetBtn = document.getElementById('resetTableFilters');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            ['tableFilterLoanStatus', 'tableFilterBook', 'tableFilterStage', 'tableFilterGrade', 'tableFilterClass'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.value = '';
            });
            if (window.jQuery && window.jQuery.fn.DataTable && libraryTableMap[libraryTab]) {
                window.jQuery(libraryTableMap[libraryTab]).DataTable().ajax.reload();
            }
        });
    }

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    document.querySelectorAll('select[data-library-lookup]').forEach(function(select) {
        var lookupType = select.dataset.libraryLookup;

        if (lookupType === 'books') {
            // Single-input integrated combobox for books (with category badges & available copies)
            var wrapper = document.createElement('div');
            wrapper.className = 'position-relative library-lookup-combobox';
            select.parentNode.insertBefore(wrapper, select);

            select.style.display = 'none';
            wrapper.appendChild(select);

            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control library-combobox-input';
            input.placeholder = 'ابحث باختيار أو كتابة اسم الكتاب أو التصنيف…';
            input.autocomplete = 'off';
            wrapper.appendChild(input);

            var menu = document.createElement('div');
            menu.className = 'dropdown-menu shadow-sm w-100 mt-1 library-combobox-menu';
            menu.style.maxHeight = '260px';
            menu.style.overflowY = 'auto';
            wrapper.appendChild(menu);

            var categoryFilter = select.form ? select.form.querySelector('#addLoanBookCategoryFilter, #editLoanBookCategoryFilter') : null;

            var loadResults = function() {
                var selectedCategory = categoryFilter ? categoryFilter.value : '';
                var params = new URLSearchParams({
                    type: 'books',
                    category: selectedCategory,
                    q: input.value.trim(),
                    csrf_token: csrfMeta ? csrfMeta.content : ''
                });

                fetch('ajax_library_lookup.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: params.toString()
                })
                .then(function(r) { return r.json(); })
                .then(function(payload) {
                    menu.innerHTML = '';
                    var results = payload.results || [];
                    if (results.length === 0) {
                        menu.innerHTML = '<div class="dropdown-item disabled text-muted py-2">لا توجد نتائج مطابقة</div>';
                    } else {
                        results.forEach(function(row) {
                            var item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'dropdown-item py-2 border-bottom border-light text-wrap';

                            var catBadge = row.category ? '<span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-2"><i class="fas fa-bookmark me-1"></i>' + escapeHtml(row.category) + '</span>' : '';
                            var authorText = row.author ? '<small class="text-muted ms-2">(' + escapeHtml(row.author) + ')</small>' : '';
                            var copiesBadge = '<span class="badge bg-success-subtle text-success float-end"><i class="fas fa-copy me-1"></i>المتاح: ' + row.copies_available + '</span>';
                            item.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-1"><div><strong class="text-dark me-1">' + escapeHtml(row.title) + '</strong>' + catBadge + '</div>' + copiesBadge + '</div>' + (authorText ? '<div>' + authorText + '</div>' : '');

                            item.addEventListener('click', function(e) {
                                e.preventDefault();
                                select.innerHTML = '<option value="' + row.id + '" selected>' + escapeHtml(row.label) + '</option>';
                                select.value = row.id;
                                select.dispatchEvent(new Event('change', { bubbles: true }));

                                var displayVal = row.title + (row.category ? ' [' + row.category + ']' : '');
                                input.value = displayVal;
                                menu.classList.remove('show');
                            });

                            menu.appendChild(item);
                        });
                    }
                    menu.classList.add('show');
                })
                .catch(function() {});
            };

            if (categoryFilter) {
                categoryFilter.addEventListener('change', function() {
                    select.value = '';
                    input.value = '';
                    loadResults();
                });
            }

            input.addEventListener('focus', loadResults);
            input.addEventListener('input', loadResults);

            document.addEventListener('click', function(e) {
                if (!wrapper.contains(e.target)) {
                    menu.classList.remove('show');
                }
            });
        } else {
            // Standard visible dropdown <select> for student selection
            var loadOptions = function() {
                var params = new URLSearchParams({
                    type: lookupType,
                    csrf_token: csrfMeta ? csrfMeta.content : ''
                });

                var modal = select.closest('.modal');
                if (modal) {
                    var stageSel = modal.querySelector('#loanFilterStage, #editLoanFilterStage');
                    var gradeSel = modal.querySelector('#loanFilterGrade, #editLoanFilterGrade');
                    var classSel = modal.querySelector('#loanFilterClass, #editLoanFilterClass');
                    if (stageSel && stageSel.value) params.append('stage_id', stageSel.value);
                    if (gradeSel && gradeSel.value) params.append('grade_id', gradeSel.value);
                    if (classSel && classSel.value) params.append('class_id', classSel.value);
                }

                fetch('ajax_library_lookup.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: params.toString()
                })
                .then(function(r) { return r.json(); })
                .then(function(payload) {
                    var currentVal = select.value;
                    select.innerHTML = '<option value="">اختر الطالب...</option>';
                    var results = payload.results || [];
                    if (results.length === 0) {
                        var emptyOpt = document.createElement('option');
                        emptyOpt.value = '';
                        emptyOpt.textContent = 'لا يوجد طلاب ينتمون لهذا الاختيار';
                        emptyOpt.disabled = true;
                        select.appendChild(emptyOpt);
                    } else {
                        results.forEach(function(row) {
                            var option = document.createElement('option');
                            option.value = row.id;
                            option.textContent = row.label;
                            if (String(row.id) === String(currentVal)) option.selected = true;
                            select.appendChild(option);
                        });
                    }
                })
                .catch(function() {});
            };

            select.reloadLookup = loadOptions;
        }
    });

    // Cascading Stage -> Grade -> Class Filters in Add Loan Modal
    var loanStage = document.getElementById('loanFilterStage');
    var loanGrade = document.getElementById('loanFilterGrade');
    var loanClass = document.getElementById('loanFilterClass');
    var studentSelect = document.getElementById('loanStudentSelect');

    if (loanStage && loanGrade && loanClass && studentSelect) {
        var updateStudentOptions = function() {
            if (studentSelect.reloadLookup) {
                studentSelect.reloadLookup();
            }
        };

        loanStage.addEventListener('change', function() {
            var selectedStage = this.value;
            Array.from(loanGrade.options).forEach(function(opt) {
                if (!opt.value) return;
                opt.style.display = (!selectedStage || opt.dataset.stageId === selectedStage) ? '' : 'none';
            });
            loanGrade.value = '';
            loanClass.value = '';
            Array.from(loanClass.options).forEach(function(opt) {
                if (!opt.value) return;
                opt.style.display = (!selectedStage || opt.dataset.stageId === selectedStage) ? '' : 'none';
            });
            updateStudentOptions();
        });

        loanGrade.addEventListener('change', function() {
            var selectedGrade = this.value;
            var selectedStage = loanStage.value;
            Array.from(loanClass.options).forEach(function(opt) {
                if (!opt.value) return;
                var matchGrade = !selectedGrade || opt.dataset.gradeId === selectedGrade;
                var matchStage = !selectedStage || opt.dataset.stageId === selectedStage;
                opt.style.display = (matchGrade && matchStage) ? '' : 'none';
            });
            loanClass.value = '';
            updateStudentOptions();
        });

        loanClass.addEventListener('change', updateStudentOptions);

        var loanModal = document.getElementById('addLoanModal');
        if (loanModal) {
            loanModal.addEventListener('shown.bs.modal', function() {
                updateStudentOptions();
            });
        }
    }

    // Cascading Stage -> Grade -> Class Filters in Edit Loan Modal
    var editLoanStage = document.getElementById('editLoanFilterStage');
    var editLoanGrade = document.getElementById('editLoanFilterGrade');
    var editLoanClass = document.getElementById('editLoanFilterClass');
    var editStudentSelect = document.getElementById('editLoanStudentSelect');

    if (editLoanStage && editLoanGrade && editLoanClass && editStudentSelect) {
        var updateEditStudentOptions = function() {
            if (editStudentSelect.reloadLookup) {
                editStudentSelect.reloadLookup();
            }
        };

        editLoanStage.addEventListener('change', function() {
            var selectedStage = this.value;
            Array.from(editLoanGrade.options).forEach(function(opt) {
                if (!opt.value) return;
                opt.style.display = (!selectedStage || opt.dataset.stageId === selectedStage) ? '' : 'none';
            });
            editLoanGrade.value = '';
            editLoanClass.value = '';
            Array.from(editLoanClass.options).forEach(function(opt) {
                if (!opt.value) return;
                opt.style.display = (!selectedStage || opt.dataset.stageId === selectedStage) ? '' : 'none';
            });
            updateEditStudentOptions();
        });

        editLoanGrade.addEventListener('change', function() {
            var selectedGrade = this.value;
            var selectedStage = editLoanStage.value;
            Array.from(editLoanClass.options).forEach(function(opt) {
                if (!opt.value) return;
                var matchGrade = !selectedGrade || opt.dataset.gradeId === selectedGrade;
                var matchStage = !selectedStage || opt.dataset.stageId === selectedStage;
                opt.style.display = (matchGrade && matchStage) ? '' : 'none';
            });
            editLoanClass.value = '';
            updateEditStudentOptions();
        });

        editLoanClass.addEventListener('change', updateEditStudentOptions);
    }

    // Populate Edit Book Modal
    document.addEventListener('click', function(event) {
        var btn = event.target.closest('.edit-book-btn'); if (!btn) return;
        document.getElementById('editBookId').value = btn.dataset.id || '';
        document.getElementById('editBookTitle').value = btn.dataset.title || '';
        document.getElementById('editBookAuthor').value = btn.dataset.author || '';
        document.getElementById('editBookCategory').value = btn.dataset.category || '';
        document.getElementById('editBookIsbn').value = btn.dataset.isbn || '';
        document.getElementById('editBookCopiesTotal').value = btn.dataset.copies_total || 1;
        document.getElementById('editBookLocation').value = btn.dataset.location || '';
        document.getElementById('editBookNotes').value = btn.dataset.notes || '';
    });

    // Toggle Borrower Type (Student vs Staff) in Add & Edit Loan Modals
    document.querySelectorAll('.borrower-type-select').forEach(function(sel) {
        sel.addEventListener('change', function() {
            var form = this.closest('form');
            if (!form) return;
            var studentCont = form.querySelector('.student-borrower-container');
            var staffCont = form.querySelector('.staff-borrower-container');
            var studentSelect = form.querySelector('.student-select');
            var staffSelect = form.querySelector('.staff-select');

            if (this.value === 'staff') {
                if (studentCont) studentCont.classList.add('d-none');
                if (staffCont) staffCont.classList.remove('d-none');
                if (studentSelect) studentSelect.removeAttribute('required');
                if (staffSelect) staffSelect.setAttribute('required', 'required');
            } else {
                if (staffCont) staffCont.classList.add('d-none');
                if (studentCont) studentCont.classList.remove('d-none');
                if (staffSelect) staffSelect.removeAttribute('required');
                if (studentSelect) studentSelect.setAttribute('required', 'required');
            }
        });
    });

    // Populate Edit Loan Modal (identical to add modal)
    document.addEventListener('click', function(event) {
        var btn = event.target.closest('.edit-loan-btn'); if (!btn) return;
        document.getElementById('editLoanId').value = btn.dataset.id || '';

        var bookSel = document.getElementById('editLoanBookSelect');
        if (bookSel && btn.dataset.book_id) {
            var bookId = btn.dataset.book_id;
            var bookTitle = btn.dataset.title || '';
            bookSel.innerHTML = '<option value="' + escapeHtml(bookId) + '" selected>' + escapeHtml(bookTitle) + '</option>';
            bookSel.value = bookId;
            var comboboxWrapper = bookSel.closest('.library-lookup-combobox');
            if (comboboxWrapper) {
                var comboboxInput = comboboxWrapper.querySelector('.library-combobox-input');
                if (comboboxInput) {
                    comboboxInput.value = bookTitle;
                }
            }
        }

        var userRole = btn.dataset.user_role || 'student';
        var isStaff = userRole !== 'student';
        var borrowerTypeSel = document.getElementById('editLoanBorrowerType');
        if (borrowerTypeSel) {
            borrowerTypeSel.value = isStaff ? 'staff' : 'student';
            borrowerTypeSel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        var targetStudentId = btn.dataset.student_id || '';
        if (isStaff) {
            var staffSel = document.getElementById('editLoanStaffSelect');
            if (staffSel && targetStudentId) {
                staffSel.value = targetStudentId;
                staffSel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        } else {
            if (editLoanStage) editLoanStage.value = btn.dataset.stage_id || '';
            if (editLoanGrade) editLoanGrade.value = btn.dataset.grade_id || '';
            if (editLoanClass) editLoanClass.value = btn.dataset.class_id || '';

            if (editStudentSelect && editStudentSelect.reloadLookup) {
                editStudentSelect.reloadLookup();
                setTimeout(function() {
                    if (targetStudentId) editStudentSelect.value = targetStudentId;
                }, 150);
            }
        }

        document.getElementById('editLoanBorrowedAt').value = btn.dataset.borrowed_at || '';
        document.getElementById('editLoanDueAt').value = btn.dataset.due_at || '';
        document.getElementById('editLoanNotes').value = btn.dataset.notes || '';
        var editModal = new bootstrap.Modal(document.getElementById('editLoanModal'));
        editModal.show();
    });

    // Populate Return Loan Confirmation Modal
    document.addEventListener('click', function(event) {
        var btn = event.target.closest('.return-loan-btn'); if (!btn) return;
        document.getElementById('confirmReturnLoanId').value = btn.dataset.id || '';
        document.getElementById('confirmReturnLoanDetails').textContent = (btn.dataset.title || '') + ' - ' + (btn.dataset.student_name || '');

        var returnDateInput = document.getElementById('confirmReturnDate');
        if (returnDateInput) {
            var today = new Date().toISOString().split('T')[0];
            returnDateInput.value = today;
        }

        var returnModal = new bootstrap.Modal(document.getElementById('confirmReturnLoanModal'));
        returnModal.show();
    });

    // Populate Delete Book Modal
    document.addEventListener('click', function(event) {
        var btn = event.target.closest('.delete-book-btn'); if (!btn) return;
        var bookId = btn.dataset.id;
        var bookTitle = btn.dataset.title;
        document.getElementById('deleteBookId').value = bookId;
        document.getElementById('deleteBookTitleText').textContent = bookTitle;
        var deleteModal = new bootstrap.Modal(document.getElementById('deleteBookModal'));
        deleteModal.show();
    });
});
</script>

<!-- مودال تأكيد إرجاع استعارة -->
<div class="modal fade" id="confirmReturnLoanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="return_book">
                <input type="hidden" name="loan_id" id="confirmReturnLoanId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-undo me-2"></i>تأكيد تسجيل إرجاع الكتاب</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="fas fa-book-reader text-success fa-3x mb-3"></i>
                    <h6 class="fw-bold mb-2">هل أنت متأكد من تسجيل إرجاع هذا الكتاب للمكتبة؟</h6>
                    <p class="text-muted mb-3" id="confirmReturnLoanDetails"></p>
                    <div class="text-start mt-3">
                        <label class="form-label fw-bold">تاريخ الإرجاع <span class="text-danger">*</span></label>
                        <input type="text" class="form-control flatpickr-date" name="returned_at" id="confirmReturnDate" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>تأكيد الإرجاع</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال تعديل استعارة (مطابق لنموذج الإضافة) -->
<div class="modal fade" id="editLoanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="edit_loan">
                <input type="hidden" name="loan_id" id="editLoanId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>تعديل تفاصيل الاستعارة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-bold">تصنيف الكتاب</label>
                            <select class="form-select" id="editLoanBookCategoryFilter">
                                <option value="">كل التصنيفات...</option>
                                <?php foreach ($categoriesList as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-bold">الكتاب المستعار <span class="text-danger">*</span></label>
                            <select class="form-select" name="book_id" id="editLoanBookSelect" data-library-lookup="books" required>
                                <option value="">اختر الكتاب...</option>
                                <?php foreach ($allBooksList as $book): ?>
                                    <option value="<?php echo (int)$book['id']; ?>"><?php echo htmlspecialchars($book['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">فئة المستعير <span class="text-danger">*</span></label>
                            <select class="form-select borrower-type-select" name="borrower_type" id="editLoanBorrowerType" required>
                                <option value="student" selected>طالب</option>
                                <option value="staff">موظف / معلم</option>
                            </select>
                        </div>

                        <!-- حاوية بيانات طالب -->
                        <div class="col-12 student-borrower-container">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">المرحلة الدراسية</label>
                                    <select class="form-select" id="editLoanFilterStage">
                                        <option value="">كل المراحل...</option>
                                        <?php foreach ($stagesList as $stg): ?>
                                            <option value="<?php echo (int)$stg['id']; ?>"><?php echo htmlspecialchars($stg['stage_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">الصف الدراسي</label>
                                    <select class="form-select" id="editLoanFilterGrade">
                                        <option value="">كل الصفوف...</option>
                                        <?php foreach ($gradesList as $grd): ?>
                                            <option value="<?php echo (int)$grd['id']; ?>" data-stage-id="<?php echo (int)$grd['stage_id']; ?>">
                                                <?php echo htmlspecialchars($grd['grade_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">الفصل</label>
                                    <select class="form-select" id="editLoanFilterClass">
                                        <option value="">كل الفصول...</option>
                                        <?php foreach ($classesList as $cls): ?>
                                            <option value="<?php echo (int)$cls['id']; ?>" data-grade-id="<?php echo (int)$cls['grade_id']; ?>" data-stage-id="<?php echo (int)$cls['stage_id']; ?>">
                                                <?php echo htmlspecialchars($cls['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">الطالب المستعير <span class="text-danger">*</span></label>
                                    <select class="form-select student-select" name="student_id" id="editLoanStudentSelect" data-library-lookup="students" required>
                                        <option value="">اختر الطالب...</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo (int)$student['id']; ?>"><?php echo htmlspecialchars(($student['student_code'] ? $student['student_code'] . ' - ' : '') . $student['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- حاوية بيانات موظف -->
                        <div class="col-12 staff-borrower-container d-none">
                            <label class="form-label fw-bold">الموظف / المعلم المستعير <span class="text-danger">*</span></label>
                            <select class="form-select staff-select" name="staff_id" id="editLoanStaffSelect" data-library-lookup="staff">
                                <option value="">اختر الموظف أو المعلم...</option>
                                <?php foreach ($staffList as $stf): ?>
                                    <option value="<?php echo (int)$stf['id']; ?>"><?php echo htmlspecialchars($stf['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">تاريخ الاستعارة <span class="text-danger">*</span></label>
                            <input type="text" class="form-control flatpickr-date" name="borrowed_at" id="editLoanBorrowedAt" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">تاريخ الاستحقاق (الإرجاع المتوقع)</label>
                            <input type="text" class="form-control flatpickr-date" name="due_at" id="editLoanDueAt">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <input type="text" class="form-control" name="notes" id="editLoanNotes" placeholder="أدخل أي ملاحظات على الاستعارة...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
<!-- مودال إعدادات الجدول (التحكم بالأعمدة المعروضة) -->
<div class="modal fade" id="tableSettingsModal" tabindex="-1" aria-labelledby="tableSettingsModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="tableSettingsModalTitle">
                    <i class="fas fa-cog me-2"></i>إعدادات إظهار وإخفاء الأعمدة
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">اختر الأعمدة التي تريد إظهارها في جدول الاستعارات:</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="colStudentName" checked>
                            <label class="form-check-label fw-semibold" for="colStudentName">الاسم (الطالب)</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="colStageGradeClass" checked>
                            <label class="form-check-label fw-semibold" for="colStageGradeClass">المرحلة والصف والفصل</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="colBookTitle" checked>
                            <label class="form-check-label fw-semibold" for="colBookTitle">الكتاب</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="colBorrowedAt" checked>
                            <label class="form-check-label fw-semibold" for="colBorrowedAt">تاريخ الاستعارة</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="colDueAt" checked>
                            <label class="form-check-label fw-semibold" for="colDueAt">تاريخ الاستحقاق</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="colReturnedAt" checked>
                            <label class="form-check-label fw-semibold" for="colReturnedAt">تاريخ الإرجاع</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="colNotes" checked>
                            <label class="form-check-label fw-semibold" for="colNotes">الملاحظات</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="colLoanStatus" checked>
                            <label class="form-check-label fw-semibold" for="colLoanStatus">حالة الاستعارة</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إغلاق
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
