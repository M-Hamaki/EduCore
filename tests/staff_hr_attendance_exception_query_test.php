<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Attendance\Application\AttendanceExceptionQueryService;
use EduCore\Modules\Attendance\Contracts\AttendanceExceptionQuery;

final class AttendanceExceptionQueryTestRepository implements AttendanceExceptionQuery
{
    /** @var list<array<string,mixed>> */
    public array $summaryFilters = [];

    /** @var list<array<string,mixed>> */
    public array $itemFilters = [];

    public function summary(array $filters): array
    {
        $this->summaryFilters[] = $filters;

        return [
            'raw_events' => 2,
            'unresolved_days' => 1,
            'comparison_differences' => 3,
        ];
    }

    public function listItems(array $filters): array
    {
        $this->itemFilters[] = $filters;

        return [
            [
                'id' => 101,
                'category' => 'raw',
                'staff_user_id' => null,
                'occurred_at' => '2026-08-02 07:31:00.000000',
                'issue_code' => 'RAW_IDENTITY_UNMATCHED',
                'review_status' => 'pending',
                'raw_payload_ref' => 'private/raw/should-not-leak.json',
                'biometric_identity' => '9988123',
            ],
            [
                'id' => 202,
                'category' => 'day',
                'staff_user_id' => 44,
                'occurred_at' => '2026-08-02',
                'issue_code' => 'DAY_UNRESOLVED',
                'is_official' => 0,
                'calculation_mode' => 'shadow',
            ],
            [
                'id' => 303,
                'category' => 'comparison',
                'staff_user_id' => 44,
                'occurred_at' => '2026-08-01',
                'issue_code' => 'LEGACY_RECORD_AMBIGUOUS',
                'is_official' => 0,
                'calculation_mode' => 'shadow',
            ],
        ];
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$expectException = static function (callable $operation, string $message) use ($assert): void {
    $thrown = false;
    try {
        $operation();
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    $assert($thrown, $message);
};

$repository = new AttendanceExceptionQueryTestRepository();
$service = new AttendanceExceptionQueryService($repository, new \DateTimeZone('Africa/Cairo'));
$review = $service->review([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
    'staff_user_id' => '44',
    'category' => 'all',
]);

$assert(($review['filters']['date_from'] ?? null) === '2026-08-01', 'review normalizes the start date');
$assert(($review['filters']['date_to'] ?? null) === '2026-08-03', 'review normalizes the end date');
$assert(($review['filters']['staff_user_id'] ?? null) === 44, 'review normalizes a positive staff filter');
$assert(($review['filters']['limit'] ?? null) === 100, 'review applies a bounded server-side result limit');
$assert(($review['summary']['total'] ?? null) === 6, 'summary total is derived from the three review categories');
$assert(($review['filtered_total'] ?? null) === 6, 'all-category filter reports the complete review total');
$assert(count($review['items'] ?? []) === 3, 'all review categories are presented');
$assert(($review['items'][0]['issue_label'] ?? null) === 'بصمة غير مرتبطة بعامل', 'unmatched biometric evidence has a clear Arabic label');
$assert(($review['items'][0]['detail'] ?? null) === 'لا يمكن احتساب الحدث حتى تُراجع علاقة رقم البصمة بالعامل.', 'unmatched biometric evidence has an actionable explanation');
$assert(($review['items'][1]['state_label'] ?? null) === 'نتيجة تجريبية', 'non-official day exceptions disclose their shadow state');
$assert(($review['items'][2]['issue_label'] ?? null) === 'توجد سجلات حضور سابقة مكررة', 'legacy comparison ambiguity is explained without arbitrary selection');

$presented = json_encode($review['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(!str_contains((string) $presented, 'should-not-leak.json'), 'review DTOs never expose a raw payload reference');
$assert(!str_contains((string) $presented, '9988123'), 'review DTOs never expose a biometric identity');
$assert(($repository->summaryFilters[0]['staff_user_id'] ?? null) === 44, 'summary receives only normalized filter data');
$assert(($repository->itemFilters[0]['category'] ?? null) === 'all', 'item query receives the normalized category');

$rawOnly = $service->review([
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-03',
    'category' => 'raw',
]);
$assert(count($rawOnly['items'] ?? []) === 1, 'service defensively limits presentation to the selected category');
$assert(($rawOnly['filtered_total'] ?? null) === 2, 'raw category total remains independently explainable');
$assert(($rawOnly['limit_reached'] ?? null) === true, 'service identifies when the selected result set is truncated');

$expectException(
    static fn () => $service->review(['date_from' => '2026-08-04', 'date_to' => '2026-08-03']),
    'date range cannot end before it begins'
);
$expectException(
    static fn () => $service->review(['date_from' => '2025-01-01', 'date_to' => '2026-02-01']),
    'date range cannot exceed the documented review bound'
);
$expectException(
    static fn () => $service->review(['category' => 'unsafe']),
    'unknown category is rejected before the repository is called'
);
$expectException(
    static fn () => $service->review(['staff_user_id' => '0']),
    'zero is not a valid staff user filter'
);
$expectException(
    static fn () => $service->review(['staff_user_id' => ['44']]),
    'array input cannot bypass the staff identifier validation'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} attendance exception query test failure(s).\n");
    exit(1);
}

echo "Attendance exception query tests passed.\n";
