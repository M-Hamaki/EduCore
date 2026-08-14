<?php

$page_title = 'مراجعة مجموعات القرابة المحتملة';
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/user.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/RelationshipDiscovery.php';
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';

Utilities::validateSession('admin');
$db = (new Database())->getConnection();
$user = new User($db);
$discovery = new RelationshipDiscovery($db);
$data = $discovery->discover();

$siblingsFather = [];
$siblingsMother = [];
foreach ($data['siblings'] as $candidate) {
    if ($candidate['basis'] === 'mother') {
        $siblingsMother[] = $candidate;
    } else {
        $siblingsFather[] = $candidate;
    }
}

function discovery_pair_key(int $firstId, int $secondId, string $basis): string
{
    return min($firstId, $secondId) . ':' . max($firstId, $secondId) . ':' . $basis;
}

function discovery_candidate_map(array $data): array
{
    $map = [];
    foreach (['siblings', 'kinships'] as $kind) {
        foreach ($data[$kind] as $candidate) {
            $firstId = (int)$candidate['members'][0]['id'];
            $secondId = (int)$candidate['members'][1]['id'];
            $map[$kind][discovery_pair_key($firstId, $secondId, $candidate['basis'])] = $candidate;
        }
    }
    return $map;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfPost();
    $kind = $_POST['kind'] ?? '';
    $basis = $_POST['basis'] ?? '';
    $firstId = (int)($_POST['first_id'] ?? 0);
    $secondId = (int)($_POST['second_id'] ?? 0);
    $candidateMap = discovery_candidate_map($data);
    $candidateKey = discovery_pair_key($firstId, $secondId, $basis);
    $candidate = $candidateMap[$kind][$candidateKey] ?? null;

    if (!$candidate || $firstId <= 0 || $secondId <= 0 || $firstId === $secondId) {
        $_SESSION['error_message'] = 'الاقتراح غير صالح أو تغيرت البيانات منذ فتح الصفحة. أعد المراجعة.';
    } elseif ($kind === 'siblings') {
        $relationship = $basis === 'mother' ? 'step_brother' : 'brother';
        try {
            ActivityLog::setDb($db);
            $db->beginTransaction();
            if (!$user->linkSiblings($firstId, $secondId, $relationship)) {
                throw new RuntimeException('Sibling link failed.');
            }
            $names = array_column($candidate['members'], 'name');
            $logged = ActivityLog::logCreate('sibling', $firstId, 'اعتماد اقتراح أشقاء', [
                'students' => $names,
                'basis' => $basis,
                'reason' => $candidate['reason'],
            ]);
            if (!$logged) {
                throw new RuntimeException('Sibling audit failed.');
            }
            $db->commit();
            $_SESSION['success_message'] = 'تم ربط الطالبين كأشقاء بعد المراجعة.';
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Relationship discovery sibling link failed: ' . $error->getMessage());
            $_SESSION['error_message'] = 'تعذر إنشاء رابط الأشقاء. لم يتم حفظ أي جزء من العملية.';
        }
    } elseif ($kind === 'kinships') {
        $firstToSecondType = (int)($_POST['first_to_second_type_id'] ?? 0);
        $secondToFirstType = (int)($_POST['second_to_first_type_id'] ?? 0);
        $typeStmt = $db->prepare(
            "SELECT id, name FROM kinship_types WHERE status = 'active' AND id IN (?, ?)"
        );
        $typeStmt->execute([$firstToSecondType, $secondToFirstType]);
        $validTypes = [];
        foreach ($typeStmt->fetchAll(PDO::FETCH_ASSOC) as $type) {
            $validTypes[(int)$type['id']] = $type['name'];
        }

        if (!isset($validTypes[$firstToSecondType], $validTypes[$secondToFirstType])) {
            $_SESSION['error_message'] = 'اختر نوع صلة صحيحًا لكل اتجاه.';
        } else {
            try {
                $db->beginTransaction();
                $upsert = $db->prepare(
                    "INSERT INTO student_kinships
                        (student_id, relative_id, kinship_type_id, notes)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        kinship_type_id = VALUES(kinship_type_id),
                        notes = VALUES(notes)"
                );
                $note = 'تمت المراجعة والربط من أداة الاكتشاف: ' . $candidate['reason'];
                $upsert->execute([$firstId, $secondId, $firstToSecondType, $note]);
                $firstChanged = $upsert->rowCount();
                $upsert->execute([$secondId, $firstId, $secondToFirstType, $note]);
                $secondChanged = $upsert->rowCount();
                $db->commit();

                $names = array_column($candidate['members'], 'name');
                ActivityLog::logCreate('student_kinship', $firstId, 'اعتماد اقتراح قرابة', [
                    'first_student' => $names[0],
                    'second_student' => $names[1],
                    'first_to_second' => $validTypes[$firstToSecondType],
                    'second_to_first' => $validTypes[$secondToFirstType],
                    'reason' => $candidate['reason'],
                ]);
                $_SESSION['success_message'] = ($firstChanged || $secondChanged)
                    ? 'تم حفظ صلة القرابة في الاتجاهين بنجاح.'
                    : 'صلة القرابة محفوظة بالفعل دون تغييرات.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Relationship discovery link failed: ' . $e->getMessage());
                $_SESSION['error_message'] = 'تعذر حفظ صلة القرابة. لم يتم حفظ أي جزء من العملية.';
            }
        }
    } else {
        $_SESSION['error_message'] = 'نوع العملية غير صالح.';
    }

    $tab = (string)($_POST['active_tab'] ?? (($kind === 'kinships') ? 'kinships' : 'siblings_father'));
    if (!in_array($tab, ['siblings_father', 'siblings_mother', 'kinships'], true)) {
        $tab = 'siblings_father';
    }
    header('Location: relationship_discovery.php?tab=' . $tab);
    exit;
}

$types = $db->query(
    "SELECT id, name AS name_ar FROM kinship_types WHERE status = 'active' ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);
$success = $_SESSION['success_message'] ?? null;
$error = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

$activeTab = $_GET['tab'] ?? 'siblings_father';
if (!in_array($activeTab, ['siblings_father', 'siblings_mother', 'kinships'])) {
    $activeTab = 'siblings_father';
}

include '../includes/admin_header.php';
?>

<!-- عنوان الصفحة -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-search-plus me-2"></i>مراجعة علاقات القرابة المحتملة</h1>
    <div class="btn-toolbar admin-top-actions mb-2 mb-md-0 gap-2 no-print">
        <a href="siblings.php" class="btn btn-header-premium btn-secondary shadow-sm"><i class="fas fa-arrow-right"></i>رجوع</a>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3 border-bottom admin-tabs" id="discoveryTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?php echo $activeTab === 'siblings_father' ? 'active' : ''; ?>" id="siblings-father-tab" data-bs-toggle="tab" data-bs-target="#pane-siblings-father" type="button" role="tab" aria-controls="pane-siblings-father" aria-selected="<?php echo $activeTab === 'siblings_father' ? 'true' : 'false'; ?>">
            <i class="fas fa-male me-2 text-primary"></i>اقتراحات الأشقاء من الأب
            <span class="badge ms-1" style="background-color: rgba(37, 99, 235, 0.1) !important; color: #2563eb !important;"><?= count($siblingsFather) ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?php echo $activeTab === 'siblings_mother' ? 'active' : ''; ?>" id="siblings-mother-tab" data-bs-toggle="tab" data-bs-target="#pane-siblings-mother" type="button" role="tab" aria-controls="pane-siblings-mother" aria-selected="<?php echo $activeTab === 'siblings_mother' ? 'true' : 'false'; ?>">
            <i class="fas fa-female me-2 text-danger"></i>اقتراحات الأشقاء من الأم
            <span class="badge ms-1" style="background-color: rgba(236, 72, 153, 0.1) !important; color: #db2777 !important;"><?= count($siblingsMother) ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?php echo $activeTab === 'kinships' ? 'active' : ''; ?>" id="kinships-tab" data-bs-toggle="tab" data-bs-target="#pane-kinships" type="button" role="tab" aria-controls="pane-kinships" aria-selected="<?php echo $activeTab === 'kinships' ? 'true' : 'false'; ?>">
            <i class="fas fa-link me-2 text-purple"></i>اقتراحات القرابة الأخرى
            <span class="badge ms-1" style="background-color: rgba(139, 92, 246, 0.15) !important; color: #8b5cf6 !important;"><?= count($data['kinships']) ?></span>
        </button>
    </li>
</ul>

<div class="tab-content" id="discoveryTabsContent">
    <!-- ====== تبويب اقتراحات الأشقاء من الأب ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'siblings_father' ? 'show active' : ''; ?>" id="pane-siblings-father" role="tabpanel" aria-labelledby="siblings-father-tab">
        <div class="admin-list-surface">
            <?php if (empty($siblingsFather)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-user-friends fa-3x mb-3 opacity-50 text-primary"></i>
                    <p class="mb-0 fw-semibold">لا توجد اقتراحات أشقاء من الأب جديدة تحتاج إلى مراجعة.</p>
                </div>
            <?php else: ?>
                <?php foreach ($siblingsFather as $fi => $candidate):
                    $first = $candidate['members'][0];
                    $second = $candidate['members'][1];
                ?>
                    <form method="post" class="mb-4 pb-4 <?php echo ($fi < count($siblingsFather) - 1) ? 'border-bottom' : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="kind" value="siblings">
                        <input type="hidden" name="basis" value="<?= htmlspecialchars($candidate['basis']) ?>">
                        <input type="hidden" name="first_id" value="<?= (int)$first['id'] ?>">
                        <input type="hidden" name="second_id" value="<?= (int)$second['id'] ?>">
                        <input type="hidden" name="active_tab" value="siblings_father">

                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="fw-bold text-dark me-2">اقتراح #<?= $fi + 1 ?>:</span>
                                <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(13, 202, 240, 0.08); color: #0284c7; font-size: 0.9rem;">
                                    <i class="fas fa-info-circle me-1"></i><?= htmlspecialchars($candidate['reason']) ?>
                                </span>
                                <?php if ($candidate['confidence'] === 'candidate'): ?>
                                    <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(255, 193, 7, 0.08); color: #d97706; font-size: 0.9rem;">
                                        <i class="fas fa-user-check me-1"></i>يحتاج تأكيدًا بشريًا
                                    </span>
                                <?php else: ?>
                                    <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(108, 117, 125, 0.08); color: #64748b; font-size: 0.9rem;">
                                        <i class="fas fa-shield-alt me-1"></i>ثقة: <?= $candidate['confidence'] === 'high' ? 'مرتفعة' : 'متوسطة' ?>
                                    </span>
                                <?php endif; ?>
                                <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(99, 102, 241, 0.08); color: #4f46e5; font-size: 0.9rem;">
                                    <i class="fas fa-tag me-1"></i>المقارنة: الأب
                                </span>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-success shadow px-4 py-2">
                                    <i class="fas fa-check-circle me-2"></i>اعتماد وربط كأشقاء
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive admin-table-wrap mb-0">
                            <table class="table table-hover table-striped align-middle admin-data-table mb-0">
                                <thead>
                                    <tr>
                                        <th>الطالب</th>
                                        <th>الكود</th>
                                        <th>الفصل</th>
                                        <th>اسم الأب</th>
                                        <th>اسم الجد</th>
                                        <th>العائلة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ([$first, $second] as $member): ?>
                                    <tr>
                                        <td class="fw-bold">
                                            <a href="students.php?action=edit&id=<?= (int)$member['id'] ?>" class="text-decoration-none">
                                                <i class="fas fa-user-graduation me-2 text-primary"></i><?= htmlspecialchars($member['name']) ?>
                                            </a>
                                        </td>
                                        <td><span class="badge bg-light text-dark fw-semibold"><?= htmlspecialchars($member['student_code'] ?? '-') ?></span></td>
                                        <td><?= htmlspecialchars($member['class_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($member['second_name_ar'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($member['third_name_ar'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($member['family_name_ar'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====== تبويب اقتراحات الأشقاء من الأم ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'siblings_mother' ? 'show active' : ''; ?>" id="pane-siblings-mother" role="tabpanel" aria-labelledby="siblings-mother-tab">
        <div class="admin-list-surface">
            <?php if (empty($siblingsMother)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-user-friends fa-3x mb-3 opacity-50 text-danger" style="color: #ec4899 !important;"></i>
                    <p class="mb-0 fw-semibold">لا توجد اقتراحات أشقاء من الأم جديدة تحتاج إلى مراجعة.</p>
                </div>
            <?php else: ?>
                <?php foreach ($siblingsMother as $mi => $candidate):
                    $first = $candidate['members'][0];
                    $second = $candidate['members'][1];
                ?>
                    <form method="post" class="mb-4 pb-4 <?php echo ($mi < count($siblingsMother) - 1) ? 'border-bottom' : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="kind" value="siblings">
                        <input type="hidden" name="basis" value="<?= htmlspecialchars($candidate['basis']) ?>">
                        <input type="hidden" name="first_id" value="<?= (int)$first['id'] ?>">
                        <input type="hidden" name="second_id" value="<?= (int)$second['id'] ?>">
                        <input type="hidden" name="active_tab" value="siblings_mother">

                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="fw-bold text-dark me-2">اقتراح #<?= $mi + 1 ?>:</span>
                                <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(13, 202, 240, 0.08); color: #0284c7; font-size: 0.9rem;">
                                    <i class="fas fa-info-circle me-1"></i><?= htmlspecialchars($candidate['reason']) ?>
                                </span>
                                <?php if ($candidate['confidence'] === 'candidate'): ?>
                                    <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(255, 193, 7, 0.08); color: #d97706; font-size: 0.9rem;">
                                        <i class="fas fa-user-check me-1"></i>يحتاج تأكيدًا بشريًا
                                    </span>
                                <?php else: ?>
                                    <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(108, 117, 125, 0.08); color: #64748b; font-size: 0.9rem;">
                                        <i class="fas fa-shield-alt me-1"></i>ثقة: <?= $candidate['confidence'] === 'high' ? 'مرتفعة' : 'متوسطة' ?>
                                    </span>
                                <?php endif; ?>
                                <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(99, 102, 241, 0.08); color: #4f46e5; font-size: 0.9rem;">
                                    <i class="fas fa-tag me-1"></i>المقارنة: الأم
                                </span>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-success shadow px-4 py-2">
                                    <i class="fas fa-check-circle me-2"></i>اعتماد وربط كأشقاء
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive admin-table-wrap mb-0">
                            <table class="table table-hover table-striped align-middle admin-data-table mb-0">
                                <thead>
                                    <tr>
                                        <th>الطالب</th>
                                        <th>الكود</th>
                                        <th>الفصل</th>
                                        <th>بيانات الأم (تطابق الاقتراح)</th>
                                        <th>اسم الأب</th>
                                        <th>اسم الجد</th>
                                        <th>العائلة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ([$first, $second] as $member): ?>
                                    <tr>
                                        <td class="fw-bold">
                                            <a href="students.php?action=edit&id=<?= (int)$member['id'] ?>" class="text-decoration-none">
                                                <i class="fas fa-user-graduation me-2 text-primary"></i><?= htmlspecialchars($member['name']) ?>
                                            </a>
                                        </td>
                                        <td><span class="badge bg-light text-dark fw-semibold"><?= htmlspecialchars($member['student_code'] ?? '-') ?></span></td>
                                        <td><?= htmlspecialchars($member['class_name'] ?? '-') ?></td>
                                        <td class="fw-bold text-success">
                                            <i class="fas fa-female me-1 text-danger"></i><?= htmlspecialchars($member['mother_name'] ?? '-') ?>
                                            <?php if (!empty($member['mother_national_id'])): ?>
                                                <br><small class="text-muted fw-normal"><i class="fas fa-id-card me-1"></i><?= htmlspecialchars($member['mother_national_id']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($member['second_name_ar'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($member['third_name_ar'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($member['family_name_ar'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====== تبويب اقتراحات القرابة ====== -->
    <div class="tab-pane fade <?php echo $activeTab === 'kinships' ? 'show active' : ''; ?>" id="pane-kinships" role="tabpanel" aria-labelledby="kinships-tab">
        <div class="admin-list-surface">
            <?php if (empty($data['kinships'])): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-link fa-3x mb-3 opacity-50 text-purple" style="color: #8b5cf6 !important;"></i>
                    <p class="mb-0 fw-semibold">لا توجد اقتراحات قرابة جديدة تحتاج إلى مراجعة.</p>
                </div>
            <?php else: ?>
                <?php foreach ($data['kinships'] as $ki => $candidate):
                    $first = $candidate['members'][0];
                    $second = $candidate['members'][1];
                ?>
                    <form method="post" class="mb-4 pb-4 <?php echo ($ki < count($data['kinships']) - 1) ? 'border-bottom' : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="kind" value="kinships">
                        <input type="hidden" name="basis" value="<?= htmlspecialchars($candidate['basis']) ?>">
                        <input type="hidden" name="first_id" value="<?= (int)$first['id'] ?>">
                        <input type="hidden" name="second_id" value="<?= (int)$second['id'] ?>">
                        <input type="hidden" name="active_tab" value="kinships">

                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="fw-bold text-dark me-2">اقتراح #<?= $ki + 1 ?>:</span>
                                <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(139, 92, 246, 0.08); color: #8b5cf6; font-size: 0.9rem;">
                                    <i class="fas fa-info-circle me-1"></i><?= htmlspecialchars($candidate['reason']) ?>
                                </span>
                                <?php if ($candidate['confidence'] === 'candidate'): ?>
                                    <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(255, 193, 7, 0.08); color: #d97706; font-size: 0.9rem;">
                                        <i class="fas fa-user-check me-1"></i>يحتاج تأكيدًا بشريًا
                                    </span>
                                <?php else: ?>
                                    <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(108, 117, 125, 0.08); color: #64748b; font-size: 0.9rem;">
                                        <i class="fas fa-shield-alt me-1"></i>ثقة: <?= $candidate['confidence'] === 'high' ? 'مرتفعة' : 'متوسطة' ?>
                                    </span>
                                <?php endif; ?>
                                <span class="badge px-3 py-2 fw-bold rounded-pill" style="background-color: rgba(99, 102, 241, 0.08); color: #4f46e5; font-size: 0.9rem;">
                                    <i class="fas fa-tag me-1"></i>المقارنة: <?= htmlspecialchars($candidate['basis'] === 'mother' ? 'الأم' : 'الأب') ?>
                                </span>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-success shadow px-4 py-2">
                                    <i class="fas fa-check-circle me-2"></i>اعتماد وربط صلة القرابة
                                </button>
                            </div>
                        </div>

                        <div class="row g-3 mb-3 border-bottom pb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark"><i class="fas fa-exchange-alt me-1 text-primary"></i>صلة <?= htmlspecialchars($second['name']) ?> بالنسبة إلى <?= htmlspecialchars($first['name']) ?></label>
                                <select class="form-select" name="first_to_second_type_id" required>
                                    <option value="">اختر الصلة...</option>
                                    <?php foreach ($types as $type): ?>
                                        <option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['name_ar']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark"><i class="fas fa-exchange-alt me-1 text-primary"></i>صلة <?= htmlspecialchars($first['name']) ?> بالنسبة إلى <?= htmlspecialchars($second['name']) ?></label>
                                <select class="form-select" name="second_to_first_type_id" required>
                                    <option value="">اختر الصلة...</option>
                                    <?php foreach ($types as $type): ?>
                                        <option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['name_ar']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="table-responsive admin-table-wrap mb-0">
                            <table class="table table-hover table-striped align-middle admin-data-table mb-0">
                                <thead>
                                    <tr>
                                        <th>الطالب</th>
                                        <th>الكود</th>
                                        <th>الفصل</th>
                                        <th>اسم الأب</th>
                                        <th>اسم الجد</th>
                                        <th>العائلة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ([$first, $second] as $member): ?>
                                    <tr>
                                        <td class="fw-bold">
                                            <a href="students.php?action=edit&id=<?= (int)$member['id'] ?>" class="text-decoration-none">
                                                <i class="fas fa-user-graduation me-2 text-primary"></i><?= htmlspecialchars($member['name']) ?>
                                            </a>
                                        </td>
                                        <td><span class="badge bg-light text-dark fw-semibold"><?= htmlspecialchars($member['student_code'] ?? '-') ?></span></td>
                                        <td><?= htmlspecialchars($member['class_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($member['second_name_ar'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($member['third_name_ar'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($member['family_name_ar'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
