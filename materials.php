<?php

declare(strict_types=1);

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/session_config.php';
require_once __DIR__ . '/vendor/autoload.php';

use EduCore\Modules\PublicPortal\Domain\IntroVisitPolicy;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$teamsContext = (string) ($_GET['from_teams'] ?? '') === '1';
$portalConfig = require __DIR__ . '/config/public_portal.php';
$introPolicy = new IntroVisitPolicy((int) ($portalConfig['intro_interval_seconds'] ?? 1296000));
$shouldShowIntro = $introPolicy->shouldShow(
    isset($_COOKIE[IntroVisitPolicy::COOKIE_NAME]) ? (string) $_COOKIE[IntroVisitPolicy::COOKIE_NAME] : null,
    !empty($_SESSION['intro_shown']),
    $teamsContext,
    isset($_GET['skip_intro']),
    false
);

if ($shouldShowIntro) {
    header('Location: intro_youtube.php?destination=materials');
    exit;
}

if (isset($_GET['skip_intro'])) {
    $_SESSION['intro_shown'] = true;
}

$gradeId = filter_input(INPUT_GET, 'grade_id', FILTER_VALIDATE_INT);
$term = in_array((string) ($_GET['term'] ?? ''), ['term1', 'term2'], true)
    ? (string) $_GET['term']
    : '';

$target = 'student/materials/';
if (is_int($gradeId) && $gradeId > 0 && $term !== '') {
    $target .= 'view.php?' . http_build_query([
        'grade' => $gradeId,
        'term' => $term,
    ]);
}

header('Location: ' . $target, true, 302);
exit;
