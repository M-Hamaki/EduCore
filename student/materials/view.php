<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use EduCore\Modules\PublicPortal\Application\GetPublicMaterials;
use EduCore\Modules\PublicPortal\Infrastructure\LegacyMaterialCatalogAdapter;

$gradeParam = trim((string) ($_GET['grade'] ?? ''));
$term = in_array((string) ($_GET['term'] ?? ''), ['term1', 'term2'], true)
    ? (string) $_GET['term']
    : '';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = is_int($page) && $page > 0 ? $page : 1;

if ($gradeParam === '' || $term === '' || strlen($gradeParam) > 64) {
    header('Location: ./', true, 302);
    exit;
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    http_response_code(503);
    exit('تعذر الاتصال بالخدمة حالياً. يرجى المحاولة لاحقاً.');
}

$cleanParam = strtolower(str_replace([' ', '-'], '', $gradeParam));
$gradeStmt = $db->prepare(
    "SELECT g.id, g.grade_name, s.stage_name
     FROM grades g
     INNER JOIN stages s ON s.id = g.stage_id AND s.status = 'active'
     WHERE g.status = 'active'
       AND (
            LOWER(REPLACE(REPLACE(g.grade_code, ' ', ''), '-', '')) = ?
            OR g.grade_code = ?
            OR g.id = ?
       )
     LIMIT 1"
);
$gradeStmt->execute([$cleanParam, $gradeParam, (int) $gradeParam]);
$grade = $gradeStmt->fetch(PDO::FETCH_ASSOC);
if (!$grade) {
    header('Location: ./', true, 302);
    exit;
}

$materialsResult = (new GetPublicMaterials(new LegacyMaterialCatalogAdapter($db)))->execute([
    'grade_id' => (int) $grade['id'],
    'term' => $term,
    'page' => $page,
    'per_page' => 60,
]);
$materials = $materialsResult['materials'];
$pagination = $materialsResult['pagination'];
$gradeName = (string) $grade['grade_name'];
$termName = $term === 'term1' ? 'الفصل الدراسي الأول' : 'الفصل الدراسي الثاني';
$pageTitle = $gradeName . ' - ' . $termName;
$activeRole = (string) ($_SESSION['active_role'] ?? $_SESSION['role'] ?? '');
$portalHomeUrl = !empty($_SESSION['user_id']) && $activeRole === 'student'
    ? '../portal.php'
    : '../../index.php?skip_intro=1';
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle) ?> - DMLS</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="materials-portal-style.css?v=2.1">
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
</head>
<body>
    <div id="particles-js"></div>

    <div class="main-container">
        <header class="materials-header">
            <a href="<?= $escape($portalHomeUrl) ?>">
                <img src="../../assets/img/logo.png" alt="DMLS Logo" class="materials-logo" loading="lazy">
            </a>
            <h1 class="materials-title"><?= $escape($gradeName) ?></h1>
            <p class="materials-subtitle"><?= $escape($termName) ?></p>
        </header>

        <div class="materials-card">
            <div class="card-header-custom">
                <h2><i class="fas fa-book-open"></i> المواد الدراسية</h2>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>اسم المادة</th>
                            <th><i class="fas fa-file-arrow-down"></i> تحميل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($materials === []): ?>
                            <tr>
                                <td colspan="2" style="text-align: center; padding: 2rem; color: #64748b;">
                                    <i class="fas fa-folder-open" style="font-size: 2rem; display: block; margin-bottom: 0.5rem;"></i>
                                    لا توجد مواد دراسية متاحة حالياً لهذا الصف.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($materials as $material): ?>
                                <tr>
                                    <td class="material-name"><?= $escape((string) $material['subject_name']) ?></td>
                                    <td>
                                        <?php if ((int) $material['downloadable'] === 1): ?>
                                            <a href="../../material_download.php?id=<?= (int) $material['id'] ?>" class="download-btn">
                                                <i class="fas fa-download"></i>
                                                تحميل
                                            </a>
                                        <?php else: ?>
                                            <span class="coming-soon-badge">
                                                <i class="fa-solid fa-clock"></i>
                                                قريباً
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pagination['total_pages'] > 1): ?>
                <div style="display: flex; gap: 0.75rem; justify-content: center; margin-top: 1.25rem; flex-wrap: wrap;">
                    <?php for ($pageNumber = 1; $pageNumber <= $pagination['total_pages']; $pageNumber++): ?>
                        <a href="view.php?<?= $escape(http_build_query(['grade' => $gradeParam, 'term' => $term, 'page' => $pageNumber])) ?>"
                            class="back-button" <?= $pageNumber === $pagination['page'] ? 'aria-current="page"' : '' ?>>
                            <?= $pageNumber ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <div style="display: flex; gap: 1rem; justify-content: center; margin-top: 2rem; flex-wrap: wrap;">
                <a href="./" class="back-button">
                    <i class="fas fa-arrow-right"></i>
                    العودة لاختيار الفصل الدراسي
                </a>
                <a href="<?= $escape($portalHomeUrl) ?>" class="back-button">
                    <i class="fas fa-home"></i>
                    العودة للبوابة الرئيسية
                </a>
            </div>
        </div>

        <footer class="materials-footer">
            <p>جميع الحقوق محفوظة © <?= date('Y') ?><br>Delta Modern Language Schools<br>Computer Department</p>
            <div class="social-media-footer">
                <a href="https://www.facebook.com/DELTA.MLS" target="_blank" rel="noopener noreferrer" class="social-footer-icon facebook" title="صفحتنا على الفيسبوك"><i class="fab fa-facebook-f"></i></a>
                <a href="https://wa.me/201289999818" target="_blank" rel="noopener noreferrer" class="social-footer-icon whatsapp" title="الدعم الفني - واتساب"><i class="fab fa-whatsapp"></i></a>
                <a href="https://www.instagram.com/delta.mls" target="_blank" rel="noopener noreferrer" class="social-footer-icon instagram" title="حسابنا على انستجرام"><i class="fab fa-instagram"></i></a>
            </div>
        </footer>
    </div>

    <button class="theme-toggle" aria-label="تبديل المظهر"><i class="fas fa-moon"></i></button>
    <script src="materials-portal-theme.js?v=2.1"></script>
</body>
</html>
