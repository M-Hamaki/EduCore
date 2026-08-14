<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Ertaq\ErtaqAttachmentNotificationService;
use EduCore\Modules\Staff\Contracts\ErtaqAttachmentNotificationAuthorization;
use EduCore\Modules\Staff\Contracts\ErtaqAttachmentNotificationRepository;
use EduCore\Modules\Staff\Contracts\ErtaqAttachmentStorage;
use EduCore\Modules\Staff\Contracts\StaffNotificationPort;

final class ErtaqAttachmentMemoryRepository implements ErtaqAttachmentNotificationRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $tickets = [
        1 => [
            'id' => 1,
            'ticket_no' => 'ERT-ATTACH-001',
            'status' => 'in_progress',
            'confidentiality_level' => 'restricted',
            'requester_user_id' => 7,
        ],
    ];
    /** @var array<int,array<string,mixed>> */
    public array $messages = [
        1 => ['id' => 1, 'ticket_id' => 1, 'visibility' => 'restricted'],
        2 => ['id' => 2, 'ticket_id' => 99, 'visibility' => 'restricted'],
    ];
    /** @var array<int,array<string,mixed>> */
    public array $attachments = [];
    private int $sequence = 0;

    public function transactional(callable $work): mixed
    {
        $attachments = $this->attachments;
        $sequence = $this->sequence;
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->attachments = $attachments;
            $this->sequence = $sequence;
            throw $exception;
        }
    }

    public function lockUser(int $userId): bool
    {
        return in_array($userId, [7, 8], true);
    }

    public function ticketForUpdate(int $ticketId): ?array
    {
        return $this->tickets[$ticketId] ?? null;
    }

    public function messageForUpdate(int $messageId): ?array
    {
        return $this->messages[$messageId] ?? null;
    }

    public function attachmentByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->attachments as $attachment) {
            if (($attachment['idempotency_key'] ?? null) === $idempotencyKey) {
                return $attachment;
            }
        }

        return null;
    }

    public function insertAttachment(array $attachment): int
    {
        $id = ++$this->sequence;
        $this->attachments[$id] = $attachment + ['id' => $id, 'status' => 'active', 'lock_version' => 1];

        return $id;
    }
}

final class ErtaqAttachmentTestStorage implements ErtaqAttachmentStorage
{
    /** @var list<array<string,mixed>> */
    public array $storedFiles = [];
    /** @var list<string> */
    public array $deleted = [];
    public bool $fail = false;

    public function storeUploadedFile(array $file): array
    {
        if ($this->fail) {
            throw new RuntimeException('ERTAQ_ATTACHMENT_STORAGE_FAILED');
        }
        $number = count($this->storedFiles) + 1;
        $stored = [
            'storage_ref' => 'private:ertaq_attachments/ertaq_attachment_' . $number . '.pdf',
            'original_name' => 'ملف-خاص.pdf',
            'mime' => 'application/pdf',
            'size' => 128,
            'sha256' => str_repeat(dechex($number), 64),
        ];
        $this->storedFiles[] = $stored;

        return $stored;
    }

    public function delete(string $storageRef): bool
    {
        $this->deleted[] = $storageRef;

        return true;
    }
}

final class ErtaqAttachmentTestAuthorization implements ErtaqAttachmentNotificationAuthorization
{
    public bool $allow = true;
    public string $visibility = 'restricted';
    /** @var list<int> */
    public array $recipients = [8];
    public string $route = '/staff/ertaq.php?ticket_id=1';

    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $ticket,
        DateTimeImmutable $atInstant
    ): void {
        if (!$this->allow) {
            throw new DomainException('ERTAQ_ATTACHMENT_ACCESS_DENIED');
        }
    }

    public function resolveAttachmentVisibility(
        int $actorId,
        array $ticket,
        ?array $message,
        DateTimeImmutable $atInstant
    ): string {
        return $this->visibility;
    }

    public function resolveNeutralNotification(
        int $actorId,
        array $ticket,
        array $attachmentContext,
        DateTimeImmutable $atInstant
    ): array {
        return [
            'recipient_user_ids' => $this->recipients,
            'secure_route' => $this->route,
        ];
    }
}

final class ErtaqAttachmentTestNotifications implements StaffNotificationPort
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];
    public bool $accepted = true;
    public bool $throw = false;

    public function notifyRecipients(
        string $eventKey,
        array $recipientIds,
        string $secureRoute,
        string $neutralText,
        array $metadata,
        string $idempotencyKey
    ): array {
        if ($this->throw) {
            throw new RuntimeException('ERTAQ_ATTACHMENT_NOTIFICATION_FAILED');
        }
        $this->calls[] = compact(
            'eventKey',
            'recipientIds',
            'secureRoute',
            'neutralText',
            'metadata',
            'idempotencyKey'
        );

        return [
            'accepted' => $this->accepted,
            'status' => $this->accepted ? 'queued' : 'rejected',
            'receipt_id' => $this->accepted ? 'notification-1' : null,
            'inbox_count' => $this->accepted ? count($recipientIds) : 0,
            'outbox_count' => $this->accepted ? count($recipientIds) : 0,
        ];
    }
}

final class ErtaqAttachmentTestAudit implements AuditEventWriter
{
    /** @var list<array<string,mixed>> */
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
            throw new RuntimeException('ERTAQ_ATTACHMENT_AUDIT_FAILED');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

$failures = 0;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};
$assertThrows = static function (callable $work, string $expectedMessage, string $message) use (&$failures, &$assertions): void {
    ++$assertions;
    try {
        $work();
        fwrite(STDERR, "FAIL: {$message} (no exception)\n");
        ++$failures;
    } catch (Throwable $exception) {
        if ($exception->getMessage() !== $expectedMessage) {
            fwrite(STDERR, "FAIL: {$message} (got {$exception->getMessage()})\n");
            ++$failures;
        }
    }
};
$newFixture = static function (): array {
    $repository = new ErtaqAttachmentMemoryRepository();
    $storage = new ErtaqAttachmentTestStorage();
    $authorization = new ErtaqAttachmentTestAuthorization();
    $notifications = new ErtaqAttachmentTestNotifications();
    $audit = new ErtaqAttachmentTestAudit();

    return [
        $repository,
        $storage,
        $authorization,
        $notifications,
        $audit,
        new ErtaqAttachmentNotificationService(
            $repository,
            $storage,
            $authorization,
            $notifications,
            $audit
        ),
    ];
};
$command = static function (string $idempotencyKey = 'ertaq-attachment-1'): array {
    return [
        'actor_id' => 7,
        'ticket_id' => 1,
        'idempotency_key' => $idempotencyKey,
        'file' => ['fake' => true],
    ];
};

[$repository, $storage, $authorization, $notifications, $audit, $service] = $newFixture();
$uploaded = $service->uploadPrivateAttachment($command());
$assert(
    $uploaded['attachment_id'] === 1
        && $uploaded['resource_type'] === 'ertaq_ticket'
        && $uploaded['visibility_scope'] === 'restricted'
        && $uploaded['notification_status'] === 'queued'
        && $uploaded['notification_recipient_count'] === 1
        && count($storage->storedFiles) === 1
        && count($repository->attachments) === 1,
    'authorized ticket attachment stores private metadata and queues a neutral update'
);
$notificationJson = json_encode($notifications->calls[0], JSON_THROW_ON_ERROR);
$receiptJson = json_encode($uploaded, JSON_THROW_ON_ERROR);
$auditJson = json_encode($audit->events[0], JSON_THROW_ON_ERROR);
$assert(
    $notifications->calls[0]['neutralText'] === 'لديك تحديث جديد في منصة ارتق.'
        && $notifications->calls[0]['recipientIds'] === [8]
        && !str_contains($notificationJson, 'ملف-خاص.pdf')
        && !str_contains($notificationJson, 'private:')
        && !str_contains($receiptJson, 'storage_ref')
        && !str_contains($receiptJson, 'original_name')
        && !str_contains($auditJson, 'storage_ref')
        && !str_contains($auditJson, 'original_name'),
    'receipt, audit, and neutral notification omit filename and private storage reference'
);
$replayed = $service->uploadPrivateAttachment($command());
$assert(
    $replayed['replayed'] === true
        && count($storage->storedFiles) === 1
        && count($repository->attachments) === 1
        && count($notifications->calls) === 1,
    'same attachment idempotency key replays without moving a second file or repeating notification'
);

$messageAttachment = $service->uploadPrivateAttachment(array_replace($command('ertaq-attachment-message-2'), [
    'message_id' => 1,
]));
$assert(
    $messageAttachment['resource_type'] === 'ertaq_message'
        && $messageAttachment['message_id'] === 1
        && $repository->attachments[2]['resource_id'] === 1
        && count($notifications->calls) === 2,
    'message attachment is bound to its own ticket message and retains a separate private resource identity'
);

$storedBeforeMismatch = count($storage->storedFiles);
$assertThrows(
    static fn (): array => $service->uploadPrivateAttachment(array_replace($command('ertaq-attachment-mismatch'), [
        'message_id' => 2,
    ])),
    'ERTAQ_ATTACHMENT_MESSAGE_TICKET_MISMATCH',
    'a message from another ticket cannot receive an attachment through this ticket'
);
$assert(count($storage->storedFiles) === $storedBeforeMismatch, 'message-ticket mismatch is rejected before file storage');

[$deniedRepository, $deniedStorage, $deniedAuthorization, , , $deniedService] = $newFixture();
$deniedAuthorization->allow = false;
$assertThrows(
    static fn (): array => $deniedService->uploadPrivateAttachment($command('ertaq-attachment-denied')),
    'ERTAQ_ATTACHMENT_ACCESS_DENIED',
    'authorization is checked before reading or moving a private file'
);
$assert($deniedRepository->attachments === [] && $deniedStorage->storedFiles === [], 'denied upload leaves no file metadata or file move');

[$auditRepository, $auditStorage, , $auditNotifications, $failingAudit, $auditService] = $newFixture();
$failingAudit->fail = true;
$assertThrows(
    static fn (): array => $auditService->uploadPrivateAttachment($command('ertaq-attachment-audit-fail')),
    'ERTAQ_ATTACHMENT_AUDIT_FAILED',
    'mandatory audit failure aborts private attachment persistence'
);
$assert(
    $auditRepository->attachments === []
        && count($auditStorage->deleted) === 1
        && $auditNotifications->calls === [],
    'audit failure rolls back metadata, cleans the new private file, and precedes notification enqueue'
);

[$notificationRepository, $notificationStorage, , $failingNotifications, , $notificationService] = $newFixture();
$failingNotifications->accepted = false;
$assertThrows(
    static fn (): array => $notificationService->uploadPrivateAttachment($command('ertaq-attachment-notification-fail')),
    'ERTAQ_ATTACHMENT_NOTIFICATION_NOT_ACCEPTED',
    'a rejected notification intent aborts the attachment operation rather than leaving an unannounced secret update'
);
$assert(
    $notificationRepository->attachments === []
        && count($notificationStorage->deleted) === 1
        && count($failingNotifications->calls) === 1,
    'notification failure rolls back attachment metadata and removes the newly stored file'
);

[$quietRepository, $quietStorage, $quietAuthorization, $quietNotifications, , $quietService] = $newFixture();
$quietAuthorization->recipients = [];
$quiet = $quietService->uploadPrivateAttachment($command('ertaq-attachment-quiet'));
$assert(
    $quiet['notification_status'] === 'not_required'
        && $quiet['notification_recipient_count'] === 0
        && count($quietRepository->attachments) === 1
        && count($quietStorage->storedFiles) === 1
        && $quietNotifications->calls === [],
    'no-recipient policy preserves private attachment evidence without inventing a recipient or route'
);

[$routeRepository, $routeStorage, $routeAuthorization, $routeNotifications, , $routeService] = $newFixture();
$routeAuthorization->route = 'https://unsafe.example/ertaq';
$assertThrows(
    static fn (): array => $routeService->uploadPrivateAttachment($command('ertaq-attachment-route-invalid')),
    'ERTAQ_ATTACHMENT_NOTIFICATION_ROUTE_INVALID',
    'recipient policy cannot turn a confidential notification into an external route'
);
$assert(
    $routeRepository->attachments === []
        && count($routeStorage->deleted) === 1
        && $routeNotifications->calls === [],
    'external route rejection cleans private bytes before any notification enqueue'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Ertaq attachment/notification test failure(s).\n");
    exit(1);
}

echo 'staff_hr_ertaq_attachment_notification_service_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
