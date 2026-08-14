<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Infrastructure\Notification;

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffNotificationPort;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Transactional staff notification inbox/outbox adapter.
 *
 * The event key is the stable identity of one logical event occurrence. The
 * idempotency key identifies the originating request. Replaying the same pair
 * is safe; reusing an event key with different content fails closed.
 */
final class PdoStaffNotificationOutbox implements StaffNotificationPort
{
    private const METADATA_SCHEMA_VERSION = 1;
    private const PAYLOAD_SCHEMA_VERSION = 1;

    public function __construct(
        private PDO $db,
        private AuditEventWriter $audit
    ) {
    }

    /**
     * @param array<int, int|string> $recipientIds
     * @param array<string, mixed> $metadata
     * @return array{accepted:bool,status:string,receipt_id:?string,inbox_count:int,outbox_count:int}
     */
    public function notifyRecipients(
        string $eventKey,
        array $recipientIds,
        string $secureRoute,
        string $neutralText,
        array $metadata,
        string $idempotencyKey
    ): array {
        $eventKey = $this->validatePlainText($eventKey, 'event key', 190);
        $idempotencyKey = $this->validatePlainText($idempotencyKey, 'idempotency key', 190);
        $secureRoute = $this->validateSecureRoute($secureRoute);
        $neutralText = $this->validatePlainText($neutralText, 'neutral text', 500);
        $recipients = $this->normalizeRecipients($recipientIds);
        $secureMetadata = $this->normalizeJsonValue($metadata);

        $idempotencyHash = hash('sha256', $idempotencyKey);
        $requestFingerprint = hash('sha256', $this->canonicalJson([
            'event_key' => $eventKey,
            'recipient_ids' => $recipients,
            'secure_route' => $secureRoute,
            'neutral_text' => $neutralText,
            'secure_metadata' => $secureMetadata,
        ]));
        $receiptId = 'staff-notification:' . substr(
            hash('sha256', $eventKey . "\0" . $idempotencyKey),
            0,
            32
        );

        $metadataJson = $this->canonicalJson([
            '_notification' => [
                'schema_version' => self::METADATA_SCHEMA_VERSION,
                'idempotency_hash' => $idempotencyHash,
                'request_fingerprint' => $requestFingerprint,
                'receipt_id' => $receiptId,
            ],
            'secure_metadata' => $secureMetadata,
        ]);

        $ownsTransaction = !$this->db->inTransaction();
        $savepoint = null;
        $createdInbox = 0;
        $createdOutbox = 0;

        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            } else {
                $savepoint = 'staff_notification_' . bin2hex(random_bytes(6));
                $this->db->exec('SAVEPOINT ' . $savepoint);
            }

            foreach ($recipients as $recipientId) {
                [$inboxId, $inboxCreated] = $this->createOrReuseInbox(
                    $recipientId,
                    $eventKey,
                    $neutralText,
                    $secureRoute,
                    $metadataJson,
                    $idempotencyKey,
                    $idempotencyHash,
                    $requestFingerprint
                );
                $createdInbox += $inboxCreated ? 1 : 0;

                $outboxCreated = $this->createOrReuseOutbox(
                    $inboxId,
                    $recipientId,
                    $eventKey,
                    $idempotencyKey,
                    $neutralText,
                    $secureRoute
                );
                $createdOutbox += $outboxCreated ? 1 : 0;
            }

            $status = $createdInbox === 0 && $createdOutbox === 0
                ? 'idempotent_replay'
                : (($createdInbox === count($recipients) && $createdOutbox === count($recipients))
                    ? 'queued'
                    : 'repaired');

            if ($createdInbox > 0 || $createdOutbox > 0) {
                $this->audit->recordEvent(
                    'staff_notification_enqueue',
                    'staff_notification_batch',
                    $receiptId,
                    null,
                    [
                        'receipt_id' => $receiptId,
                        'event_key' => $eventKey,
                        'idempotency_hash' => $idempotencyHash,
                        'recipient_count' => count($recipients),
                        'inbox_count' => $createdInbox,
                        'outbox_count' => $createdOutbox,
                        'channel' => 'inbox_push',
                        'status' => $status,
                    ]
                );
            }

            if ($ownsTransaction) {
                $this->db->commit();
            } elseif ($savepoint !== null) {
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return [
                'accepted' => true,
                'status' => $status,
                'receipt_id' => $receiptId,
                'inbox_count' => count($recipients),
                'outbox_count' => count($recipients),
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            } elseif ($savepoint !== null && $this->db->inTransaction()) {
                try {
                    $this->db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (Throwable) {
                    // Preserve the original failure; the owning transaction must fail closed.
                }
            }

            throw $exception;
        }
    }

    /** @return array{0:int,1:bool} */
    private function createOrReuseInbox(
        int $recipientId,
        string $eventKey,
        string $neutralText,
        string $secureRoute,
        string $metadataJson,
        string $idempotencyKey,
        string $idempotencyHash,
        string $requestFingerprint
    ): array {
        $existing = $this->lockInbox($recipientId, $eventKey, $idempotencyKey);
        if ($existing !== null) {
            $this->assertInboxMatches(
                $existing,
                $eventKey,
                $idempotencyKey,
                $neutralText,
                $secureRoute,
                $idempotencyHash,
                $requestFingerprint
            );
            return [(int) $existing['id'], false];
        }

        $insert = $this->db->prepare(
            'INSERT INTO user_notification_inbox
                (recipient_user_id, event_key, idempotency_key, neutral_text, secure_route, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $insert->execute([$recipientId, $eventKey, $idempotencyKey, $neutralText, $secureRoute, $metadataJson]);

        $row = $this->lockInbox($recipientId, $eventKey, $idempotencyKey);
        if ($row === null) {
            throw new RuntimeException('The notification inbox row could not be persisted.');
        }
        $this->assertInboxMatches(
            $row,
            $eventKey,
            $idempotencyKey,
            $neutralText,
            $secureRoute,
            $idempotencyHash,
            $requestFingerprint
        );

        return [(int) $row['id'], $insert->rowCount() === 1];
    }

    /** @return array<string, mixed>|null */
    private function lockInbox(int $recipientId, string $eventKey, string $idempotencyKey): ?array
    {
        $select = $this->db->prepare(
            'SELECT id, event_key, idempotency_key, neutral_text, secure_route, metadata_json
             FROM user_notification_inbox
             WHERE recipient_user_id = ? AND (event_key = ? OR idempotency_key = ?)
             FOR UPDATE'
        );
        $select->execute([$recipientId, $eventKey, $idempotencyKey]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new RuntimeException('The notification identity conflicts with existing requests.');
        }

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $row */
    private function assertInboxMatches(
        array $row,
        string $eventKey,
        string $idempotencyKey,
        string $neutralText,
        string $secureRoute,
        string $idempotencyHash,
        string $requestFingerprint
    ): void {
        if (
            (string) $row['event_key'] !== $eventKey
            || (string) $row['idempotency_key'] !== $idempotencyKey
            || (string) $row['neutral_text'] !== $neutralText
            || (string) $row['secure_route'] !== $secureRoute
        ) {
            throw new RuntimeException('The notification event key is already associated with different content.');
        }

        try {
            $metadata = json_decode((string) $row['metadata_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The existing notification metadata is invalid.', 0, $exception);
        }

        $notification = is_array($metadata) && isset($metadata['_notification'])
            && is_array($metadata['_notification'])
            ? $metadata['_notification']
            : null;
        $storedIdempotency = is_array($notification)
            ? (string) ($notification['idempotency_hash'] ?? '')
            : '';
        $storedFingerprint = is_array($notification)
            ? (string) ($notification['request_fingerprint'] ?? '')
            : '';

        if (
            $storedIdempotency === ''
            || $storedFingerprint === ''
            || !hash_equals($storedIdempotency, $idempotencyHash)
            || !hash_equals($storedFingerprint, $requestFingerprint)
        ) {
            throw new RuntimeException('The notification event key is already associated with another request.');
        }
    }

    private function createOrReuseOutbox(
        int $inboxId,
        int $recipientId,
        string $eventKey,
        string $idempotencyKey,
        string $neutralText,
        string $secureRoute
    ): bool {
        $payload = $this->canonicalJson([
            'schema_version' => self::PAYLOAD_SCHEMA_VERSION,
            'neutral_text' => $neutralText,
            'secure_route' => $secureRoute,
        ]);

        $existing = $this->lockOutbox($recipientId, $eventKey, $idempotencyKey);
        if ($existing !== null) {
            $this->assertOutboxMatches($existing, $inboxId, $eventKey, $idempotencyKey, $payload);
            return false;
        }

        $insert = $this->db->prepare(
            'INSERT INTO notification_outbox
                (inbox_id, event_key, recipient_user_id, idempotency_key, payload, attempts, next_attempt_at, status)
             VALUES (?, ?, ?, ?, ?, 0, NULL, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $insert->execute([$inboxId, $eventKey, $recipientId, $idempotencyKey, $payload, 'pending']);

        $row = $this->lockOutbox($recipientId, $eventKey, $idempotencyKey);
        if ($row === null) {
            throw new RuntimeException('The notification outbox row could not be persisted.');
        }
        $this->assertOutboxMatches($row, $inboxId, $eventKey, $idempotencyKey, $payload);

        return $insert->rowCount() === 1;
    }

    /** @return array<string, mixed>|null */
    private function lockOutbox(int $recipientId, string $eventKey, string $idempotencyKey): ?array
    {
        $select = $this->db->prepare(
            'SELECT id, inbox_id, event_key, idempotency_key, payload
             FROM notification_outbox
             WHERE recipient_user_id = ? AND (event_key = ? OR idempotency_key = ?)
             FOR UPDATE'
        );
        $select->execute([$recipientId, $eventKey, $idempotencyKey]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new RuntimeException('The notification outbox identity conflicts with existing requests.');
        }

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $row */
    private function assertOutboxMatches(
        array $row,
        int $inboxId,
        string $eventKey,
        string $idempotencyKey,
        string $expectedPayload
    ): void
    {
        try {
            $actual = json_decode((string) $row['payload'], true, 64, JSON_THROW_ON_ERROR);
            $expected = json_decode($expectedPayload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The existing notification payload is invalid.', 0, $exception);
        }

        if (
            (int) $row['inbox_id'] !== $inboxId
            || (string) $row['event_key'] !== $eventKey
            || (string) $row['idempotency_key'] !== $idempotencyKey
            || $this->canonicalJson($actual) !== $this->canonicalJson($expected)
        ) {
            throw new RuntimeException('The notification event key is already associated with another payload.');
        }
    }

    /** @param array<int, int|string> $recipientIds @return list<int> */
    private function normalizeRecipients(array $recipientIds): array
    {
        $normalized = [];
        foreach ($recipientIds as $recipientId) {
            if (is_int($recipientId)) {
                $id = $recipientId;
            } elseif (is_string($recipientId) && ctype_digit($recipientId)) {
                $id = (int) $recipientId;
            } else {
                throw new InvalidArgumentException('Every notification recipient must be a positive integer.');
            }

            if ($id <= 0) {
                throw new InvalidArgumentException('Every notification recipient must be a positive integer.');
            }
            $normalized[$id] = $id;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('At least one notification recipient is required.');
        }

        ksort($normalized, SORT_NUMERIC);
        return array_values($normalized);
    }

    private function validatePlainText(string $value, string $label, int $maxLength): string
    {
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($value === '' || $length > $maxLength || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new InvalidArgumentException('Invalid notification ' . $label . '.');
        }

        return $value;
    }

    private function validateSecureRoute(string $route): string
    {
        $route = $this->validatePlainText($route, 'secure route', 500);
        if (
            str_starts_with($route, '//')
            || str_contains($route, '\\')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $route)
        ) {
            throw new InvalidArgumentException('The notification route must be an internal application route.');
        }

        $path = parse_url($route, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException('The notification route must contain an internal path.');
        }
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException('The notification route must not traverse directories.');
            }
        }

        return $route;
    }

    private function canonicalJson(mixed $value): string
    {
        try {
            return json_encode(
                $this->normalizeJsonValue($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Notification metadata must be valid JSON data.', 0, $exception);
        }
    }

    private function normalizeJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($this->isList($value)) {
                return array_map(fn (mixed $item): mixed => $this->normalizeJsonValue($item), $value);
            }

            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeJsonValue($item);
            }
            return $value;
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Notification metadata cannot contain non-finite numbers.');
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Notification metadata must contain JSON-compatible values only.');
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            ++$expected;
        }

        return true;
    }
}
