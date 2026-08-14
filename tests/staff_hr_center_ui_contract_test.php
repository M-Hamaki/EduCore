<?php

declare(strict_types=1);

/**
 * Read-only proof for the unified HR center, its safe timeline projection,
 * rollout compatibility, and protected admin boundary.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Staff\Application\Timeline\StaffHrTimelineQuery;
use EduCore\Modules\Staff\Contracts\StaffTimelineEventSource;
use EduCore\Modules\Staff\Infrastructure\StaffHrFeatureFlags;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo $message . ':' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        ++$failures;
    }
};

$center = (string) file_get_contents($root . '/admin/hr_center.php');
$auditSurface = (string) file_get_contents($root . '/admin/hr_audit.php');

$authAt = strpos($center, "Utilities::validateSession('admin')");
$connectionAt = strpos($center, '$database = new Database()');
$assert(
    $authAt !== false && $connectionAt !== false && $authAt < $connectionAt,
    'center_authenticates_admin_before_database_initialization'
);
$assert(
    str_contains($center, '$staffFactory->staffTimeline()')
    && str_contains($center, '$staffFactory->documentExpiryService()')
    && str_contains($center, "\$validTabs[] = 'timeline'")
    && str_contains($center, "\$activeTab === 'credentials' ? 'timeline' : \$activeTab"),
    'center_composes_unified_queries_and_keeps_credential_link_compatibility'
);
$assert(
    str_contains($center, "DateTimeImmutable::createFromFormat('!Y-m-d'")
    && str_contains($center, "\$timelineToDate->modify('+1 day')")
    && str_contains($center, 'isset($staffNamesById[$timelineUserId])'),
    'center_validates_half_open_date_window_and_active_staff_selection'
);
$assert(
    str_contains($center, 'يعرض هذا السطح ملخصات تشغيلية فقط')
    && str_contains($center, "\$event['resource_id']")
    && !str_contains($center, "\$event['event_id']")
    && !str_contains($center, "\$alert['title']")
    && !str_contains($center, "\$alert['attachment_id']"),
    'center_renders_summary_only_timeline_and_expiry_fields'
);
$assert(
    str_contains($center, 'hr_organization.php')
    && str_contains($center, 'hr_audit.php')
    && str_contains($center, "require_once '../includes/admin_footer.php';")
    && !str_contains($center, '->prepare('),
    'center_uses_owned_boundaries_shared_footer_and_operational_navigation'
);
$assert(
    str_contains($center, 'ManagerApprovalInbox::renderInbox')
    && str_contains($center, '$staffHrFlags->usesLegacyFallback()')
    && str_contains($center, "\$validTabs[] = 'assigned_approvals'")
    && str_contains($center, "hash_equals(\$_SESSION['csrf_token'] ?? '', \$csrfToken)"),
    'center_preserves_assigned_approval_and_legacy_prg_compatibility'
);
$assert(
    str_contains($auditSurface, "'target_type_prefix' => 'staff_'")
    && !str_contains($auditSurface, "\$row['details']")
    && !str_contains($auditSurface, "\$_SERVER['REQUEST_METHOD'] === 'POST'"),
    'linked_staff_audit_surface_is_scoped_summary_only_and_read_only'
);

$rolloutMatrix = [
    StaffHrFeatureFlags::MODE_OFF => [false, true],
    StaffHrFeatureFlags::MODE_SHADOW => [false, true],
    StaffHrFeatureFlags::MODE_COMPARE => [false, true],
    StaffHrFeatureFlags::MODE_DISPLAY => [true, true],
    StaffHrFeatureFlags::MODE_OFFICIAL => [true, false],
];
foreach ($rolloutMatrix as $mode => [$exposes, $legacyFallback]) {
    $flags = new StaffHrFeatureFlags($mode);
    $assert(
        $flags->exposesNewResults() === $exposes
        && $flags->usesLegacyFallback() === $legacyFallback,
        'rollout_mode_' . $mode . '_keeps_expected_visibility_and_fallback'
    );
}

$timezone = new DateTimeZone('Africa/Cairo');
$safeSource = new class implements StaffTimelineEventSource {
    public function sourceKey(): string
    {
        return 'safe_test';
    }

    public function eventsForStaff(
        int $staffUserId,
        DateTimeImmutable $fromInclusive,
        DateTimeImmutable $toExclusive,
        int $limit
    ): array {
        return [[
            'event_id' => 'event-1',
            'occurred_at' => $fromInclusive->modify('+1 day'),
            'event_type' => 'staff.assignment.effective',
            'resource_type' => 'staff_assignment',
            'resource_id' => 31,
            'status' => 'active',
            'version' => 2,
            'private_detail' => 'must-not-cross-the-boundary',
        ]];
    }
};
$failingSource = new class implements StaffTimelineEventSource {
    public function sourceKey(): string
    {
        return 'failed_test';
    }

    public function eventsForStaff(
        int $staffUserId,
        DateTimeImmutable $fromInclusive,
        DateTimeImmutable $toExclusive,
        int $limit
    ): array {
        throw new RuntimeException('private database detail');
    }
};
$timeline = (new StaffHrTimelineQuery([$safeSource, $failingSource]))->forStaff(
    7,
    new DateTimeImmutable('2026-08-01 00:00:00', $timezone),
    new DateTimeImmutable('2026-09-01 00:00:00', $timezone),
    10
);
$assert(
    count($timeline['events']) === 1
    && ($timeline['events'][0]['resource_id'] ?? null) === 31
    && !array_key_exists('private_detail', $timeline['events'][0])
    && ($timeline['warnings'][0] ?? null) === ['source' => 'failed_test', 'code' => 'source_unavailable'],
    'timeline_normalizes_safe_fields_and_contains_individual_source_failures'
);

exit($failures > 0 ? 1 : 0);
