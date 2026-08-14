<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Approval\ApprovedCoveragePublisher;
use EduCore\Modules\Staff\Contracts\AttendanceCoverageChangeGateway;

final class ApprovedCoveragePublisherGatewayFixture implements AttendanceCoverageChangeGateway
{
    /** @var array<string,array<string,mixed>> */
    private array $receiptsByKey = [];

    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function publish(array $event): array
    {
        $key = (string) ($event['idempotency_key'] ?? '');
        if (isset($this->receiptsByKey[$key])) {
            return $this->receiptsByKey[$key] + ['replayed' => true];
        }
        $this->events[] = $event;
        $receipt = [
            'change_request_id' => count($this->events),
            'status' => 'ready',
            'next_action' => 'recalculate_now',
            'replayed' => false,
        ];
        $this->receiptsByKey[$key] = $receipt;

        return $receipt;
    }
}

final class ApprovedCoveragePublisherAuditFixture implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void {
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$throws = static function (callable $operation, string $expected, string $message) use (&$failures): void {
    try {
        $operation();
        fwrite(STDERR, "FAIL: {$message} (no exception)\n");
        ++$failures;
    } catch (Throwable $exception) {
        if ($exception->getMessage() !== $expected) {
            fwrite(STDERR, "FAIL: {$message} (got {$exception->getMessage()})\n");
            ++$failures;
        }
    }
};

$gateway = new ApprovedCoveragePublisherGatewayFixture();
$audit = new ApprovedCoveragePublisherAuditFixture();
$publisher = new ApprovedCoveragePublisher($gateway, $audit);
$request = [
    'id' => 301,
    'staff_user_id' => 701,
    'from_at' => '2026-01-05 07:30:00',
    'to_at' => '2026-01-06 09:00:00',
    'timezone' => 'Africa/Cairo',
    'request_hash' => str_repeat('a', 64),
];
$snapshot = [
    'policy' => ['timezone' => 'Africa/Cairo'],
    'type' => ['coverage_behavior' => 'late_arrival'],
];
$occurredAt = new DateTimeImmutable('2026-01-04 12:00:00', new DateTimeZone('UTC'));

$approved = $publisher->publishApproved($request, $snapshot, 801, 900, $occurredAt);
$assert(($approved['published_count'] ?? 0) === 2 && count($gateway->events) === 2, 'approved coverage is split into one dated attendance fact per local day');
$assert(
    array_column($gateway->events, 'work_date') === ['2026-01-05', '2026-01-06'],
    'cross-midnight coverage preserves both local work dates'
);
$assert(
    count(array_filter($gateway->events, static fn (array $event): bool => ($event['event_type'] ?? null) === 'coverage_approved')) === 2,
    'approved permission publishes only approved-coverage event types'
);
$assert(
    count(array_filter($gateway->events, static fn (array $event): bool => isset($event['reason']) || isset($event['custom_label']) || isset($event['attachment_ref']))) === 0,
    'publisher never sends private permission text or attachments across the module boundary'
);
$approvedReplay = $publisher->publishApproved($request, $snapshot, 801, 900, $occurredAt);
$assert(($approvedReplay['published_count'] ?? 0) === 2 && count($gateway->events) === 2, 'same approval publication uses stable day idempotency keys without duplicate attendance facts');
$assert(count($audit->events) === 2, 'each durable publisher invocation is auditable while delivery remains idempotent');

$reversed = $publisher->publishReversed($request, $snapshot, 802, 901, $occurredAt);
$assert(($reversed['published_count'] ?? 0) === 2 && count($gateway->events) === 4, 'approved-coverage reversal emits separate dated correction facts');
$assert(
    count(array_filter($gateway->events, static fn (array $event): bool => ($event['event_type'] ?? null) === 'coverage_reversed')) === 2,
    'reversal never overwrites the original approval event'
);
$assert(
    $gateway->events[0]['source_fingerprint'] !== $gateway->events[2]['source_fingerprint'],
    'reversal source evidence has a distinct immutable fingerprint'
);
$assert(
    count(array_filter($audit->events, static fn (array $event): bool => array_key_exists('source_fingerprint', (array) ($event['details'] ?? [])))) === 0,
    'publisher audit detail remains redacted from source fingerprints'
);

$none = $publisher->publishApproved(
    $request,
    ['policy' => ['timezone' => 'Africa/Cairo'], 'type' => ['coverage_behavior' => 'none']],
    803,
    900,
    $occurredAt
);
$assert(($none['published_count'] ?? -1) === 0 && count($gateway->events) === 4, 'non-coverage permission types do not create attendance facts');
$throws(
    static fn () => $publisher->publishApproved($request, ['policy' => []], 804, 900, $occurredAt),
    'PERMISSION_COVERAGE_SNAPSHOT_INVALID',
    'missing frozen permission type fails closed instead of guessing coverage behavior'
);
$badRequest = $request;
$badRequest['timezone'] = 'Invalid/Zone';
$throws(
    static fn () => $publisher->publishApproved($badRequest, $snapshot, 805, 900, $occurredAt),
    'PERMISSION_COVERAGE_WINDOW_INVALID',
    'invalid historical timezone fails closed'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} approved coverage publisher failure(s).\n");
    exit(1);
}

echo "Staff-HR approved coverage publisher tests passed.\n";
