<?php

declare(strict_types=1);

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Infrastructure\Notification\PdoStaffNotificationOutbox;

require_once __DIR__ . '/bootstrap_staff_hr.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditEventWriter.php';
require_once __DIR__ . '/../src/Modules/Staff/Contracts/StaffNotificationPort.php';
require_once __DIR__ . '/../src/Modules/Staff/Infrastructure/Notification/PdoStaffNotificationOutbox.php';

$options = getopt('', ['database:']);
$databaseName = trim((string) ($options['database'] ?? getenv('EDUCORE_TEST_DB_NAME') ?: ''));
if ($databaseName !== '') {
    putenv('EDUCORE_TEST_DB_NAME=' . $databaseName);
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        echo "FAIL: {$message}\n";
        ++$failures;
    }
};

$db = null;
$createdUserId = null;
$eventKeys = [];

try {
    $db = staffHrTestDatabase();

    $migrationPath = __DIR__ . '/../database/migrations/20260730_staff_hr_workflow_operations_foundation.php';
    if (!is_file($migrationPath)) {
        throw new RuntimeException('The staff-HR workflow operations migration is missing.');
    }
    $migration = require $migrationPath;
    if (!is_callable($migration)) {
        throw new RuntimeException('The staff-HR workflow operations migration must return a callable.');
    }
    $migration($db);

    $recipientId = (int) $db->query(
        "SELECT id FROM users WHERE status = 'active' ORDER BY id ASC LIMIT 1"
    )->fetchColumn();
    if ($recipientId <= 0) {
        $userToken = bin2hex(random_bytes(8));
        $db->prepare(
            'INSERT INTO users (name, username, password, role, status, class_id)
             VALUES (?, ?, ?, ?, ?, NULL)'
        )->execute([
            'Staff HR notification test',
            'staff_hr_notification_' . $userToken,
            password_hash($userToken, PASSWORD_DEFAULT),
            'admin',
            'active',
        ]);
        $recipientId = (int) $db->lastInsertId();
        $createdUserId = $recipientId;
    }

    $audit = new class implements AuditEventWriter {
        /** @var list<array<string, mixed>> */
        public array $events = [];
        public bool $fail = false;

        public function recordEvent(
            string $action,
            ?string $entityType,
            mixed $recordId,
            ?string $name,
            array $details = [],
            array $context = []
        ): void {
            if ($this->fail) {
                throw new RuntimeException('Deliberate audit failure.');
            }
            $this->events[] = [
                'action' => $action,
                'entity_type' => $entityType,
                'record_id' => $recordId,
                'name' => $name,
                'details' => $details,
                'context' => $context,
            ];
        }
    };
    $adapter = new PdoStaffNotificationOutbox($db, $audit);

    $token = bin2hex(random_bytes(8));
    $eventKey = 'staff.ertaq.assigned.test.' . $token;
    $failedAuditEventKey = 'staff.ertaq.reply.test.' . $token;
    $eventKeys = [$eventKey, $failedAuditEventKey];
    $idempotencyKey = 'notification-request-' . $token;
    $secureRoute = '/admin/hr_ertaq.php?ticket=' . $token;
    $neutralText = 'لديك تحديث جديد يتطلب مراجعتك.';
    $sensitiveText = 'تفاصيل شكوى صحية سرية للاختبار فقط ' . $token;
    $fakeSensitiveIdentifier = 'TEST-NATIONAL-ID-' . $token;
    $metadata = [
        'resource_type' => 'staff_ertaq_ticket',
        'resource_id' => $token,
        'confidential_case_subject' => $sensitiveText,
        'national_id' => $fakeSensitiveIdentifier,
    ];

    $first = $adapter->notifyRecipients(
        $eventKey,
        [$recipientId, $recipientId],
        $secureRoute,
        $neutralText,
        $metadata,
        $idempotencyKey
    );

    $assert($first['accepted'] === true, 'the notification batch is accepted');
    $assert($first['status'] === 'queued', 'the first delivery is queued');
    $assert(is_string($first['receipt_id']) && $first['receipt_id'] !== '', 'a stable receipt id is returned');
    $assert($first['inbox_count'] === 1, 'duplicate recipient ids produce one inbox item');
    $assert($first['outbox_count'] === 1, 'duplicate recipient ids produce one outbox item');

    $reorderedMetadata = [
        'national_id' => $fakeSensitiveIdentifier,
        'confidential_case_subject' => $sensitiveText,
        'resource_id' => $token,
        'resource_type' => 'staff_ertaq_ticket',
    ];
    $replay = $adapter->notifyRecipients(
        $eventKey,
        [$recipientId],
        $secureRoute,
        $neutralText,
        $reorderedMetadata,
        $idempotencyKey
    );

    $assert($replay['status'] === 'idempotent_replay', 'the same request is an idempotent replay');
    $assert($replay['receipt_id'] === $first['receipt_id'], 'the replay returns the same receipt id');
    $assert(count($audit->events) === 1, 'an idempotent replay does not duplicate the audit event');

    $inboxStmt = $db->prepare(
        'SELECT id, idempotency_key, neutral_text, secure_route, metadata_json
         FROM user_notification_inbox
         WHERE event_key = ? AND recipient_user_id = ?'
    );
    $inboxStmt->execute([$eventKey, $recipientId]);
    $inboxRows = $inboxStmt->fetchAll(PDO::FETCH_ASSOC);

    $outboxStmt = $db->prepare(
        'SELECT id, inbox_id, idempotency_key, payload, status
         FROM notification_outbox
         WHERE event_key = ? AND recipient_user_id = ?'
    );
    $outboxStmt->execute([$eventKey, $recipientId]);
    $outboxRows = $outboxStmt->fetchAll(PDO::FETCH_ASSOC);

    $assert(count($inboxRows) === 1, 'one inbox row exists after replay');
    $assert(count($outboxRows) === 1, 'one outbox row exists after replay');
    $assert((string) ($inboxRows[0]['neutral_text'] ?? '') === $neutralText, 'the inbox title is the supplied neutral text');
    $assert((string) ($inboxRows[0]['idempotency_key'] ?? '') === $idempotencyKey, 'the inbox stores the originating idempotency key');
    $assert(strpos((string) ($inboxRows[0]['neutral_text'] ?? ''), $sensitiveText) === false, 'the inbox title does not reveal sensitive metadata');
    $assert((string) ($inboxRows[0]['secure_route'] ?? '') === $secureRoute, 'the inbox stores only the authorized route to details');
    $assert(strpos((string) ($inboxRows[0]['metadata_json'] ?? ''), $sensitiveText) !== false, 'sensitive metadata remains inside the secured inbox record');
    $assert((int) ($outboxRows[0]['inbox_id'] ?? 0) === (int) ($inboxRows[0]['id'] ?? 0), 'the push outbox points to the inbox item');
    $assert((string) ($outboxRows[0]['idempotency_key'] ?? '') === $idempotencyKey, 'the outbox carries the same idempotency key');
    $assert((string) ($outboxRows[0]['status'] ?? '') === 'pending', 'the outbox row starts pending');

    $outboxPayload = (string) ($outboxRows[0]['payload'] ?? '');
    $assert(strpos($outboxPayload, $neutralText) !== false, 'the push payload includes neutral text');
    $assert(strpos($outboxPayload, $secureRoute) !== false, 'the push payload includes only the secure route to details');
    $assert(strpos($outboxPayload, $sensitiveText) === false, 'the push payload excludes confidential content');
    $assert(strpos($outboxPayload, $fakeSensitiveIdentifier) === false, 'the push payload excludes sensitive identifiers');
    $assert(strpos($outboxPayload, 'secure_metadata') === false, 'the push payload excludes the metadata envelope');

    $auditJson = json_encode($audit->events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assert(is_string($auditJson) && strpos($auditJson, $sensitiveText) === false, 'the audit event excludes confidential content');
    $assert(is_string($auditJson) && strpos($auditJson, $secureRoute) === false, 'the audit event excludes the secure route');
    $auditDetails = $audit->events[0]['details'] ?? [];
    $assert(is_array($auditDetails) && !array_key_exists('recipient_ids', $auditDetails), 'the audit event excludes recipient identifiers');

    $conflictThrown = false;
    try {
        $adapter->notifyRecipients(
            $eventKey,
            [$recipientId],
            $secureRoute,
            'نص محايد مختلف.',
            $metadata,
            $idempotencyKey
        );
    } catch (RuntimeException) {
        $conflictThrown = true;
    }
    $assert($conflictThrown, 'reusing an event occurrence with different content fails closed');

    $audit->fail = true;
    $auditFailureThrown = false;
    try {
        $adapter->notifyRecipients(
            $failedAuditEventKey,
            [$recipientId],
            $secureRoute,
            $neutralText,
            $metadata,
            'notification-audit-failure-' . $token
        );
    } catch (RuntimeException $exception) {
        $auditFailureThrown = $exception->getMessage() === 'Deliberate audit failure.';
    }
    $assert($auditFailureThrown, 'audit failure is propagated to the caller');

    $rolledBackInbox = $db->prepare('SELECT COUNT(*) FROM user_notification_inbox WHERE event_key = ?');
    $rolledBackInbox->execute([$failedAuditEventKey]);
    $rolledBackOutbox = $db->prepare('SELECT COUNT(*) FROM notification_outbox WHERE event_key = ?');
    $rolledBackOutbox->execute([$failedAuditEventKey]);
    $assert((int) $rolledBackInbox->fetchColumn() === 0, 'audit failure rolls back the inbox write');
    $assert((int) $rolledBackOutbox->fetchColumn() === 0, 'audit failure rolls back the outbox write');
} catch (Throwable $exception) {
    echo 'ERROR: ' . $exception->getMessage() . "\n";
    ++$failures;
} finally {
    if ($db instanceof PDO) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($eventKeys !== []) {
            $placeholders = implode(',', array_fill(0, count($eventKeys), '?'));
            try {
                $db->prepare('DELETE FROM notification_outbox WHERE event_key IN (' . $placeholders . ')')
                    ->execute($eventKeys);
                $db->prepare('DELETE FROM user_notification_inbox WHERE event_key IN (' . $placeholders . ')')
                    ->execute($eventKeys);
            } catch (Throwable $cleanupException) {
                echo 'FAIL: cleanup failed: ' . $cleanupException->getMessage() . "\n";
                ++$failures;
            }
        }
        if (is_int($createdUserId) && $createdUserId > 0) {
            try {
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$createdUserId]);
            } catch (Throwable $cleanupException) {
                echo 'FAIL: test user cleanup failed: ' . $cleanupException->getMessage() . "\n";
                ++$failures;
            }
        }
    }
}

if ($failures > 0) {
    echo "\n{$failures} FAILURE(S)\n";
    exit(1);
}

echo "All staff-HR notification outbox integration tests passed.\n";
exit(0);
