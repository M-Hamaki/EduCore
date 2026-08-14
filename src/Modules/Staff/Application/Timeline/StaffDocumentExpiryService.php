<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Timeline;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Contracts\StaffCredentialRepository;
use EduCore\Modules\Staff\Contracts\StaffNotificationPort;
use InvalidArgumentException;
use Throwable;

/**
 * Owns immutable qualification/training/document registration and the safe
 * expiry alert projection. Expiry is computed at read time; a scheduler never
 * rewrites a credential just because its date has passed.
 */
final class StaffDocumentExpiryService
{
    /** @var list<string> */
    private const CREDENTIAL_KINDS = ['qualification', 'training', 'document'];

    /** @var list<string> */
    private const VERIFICATION_STATUSES = ['unverified', 'verified', 'rejected'];

    private const MAX_ALERT_DAYS = 365;
    private const MAX_ALERTS = 200;

    public function __construct(
        private StaffCredentialRepository $credentials,
        private StaffNotificationPort $notifications,
        private AuditEventWriter $audit
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *     credential_id:int,
     *     staff_user_id:int,
     *     credential_kind:string,
     *     effective_on:string,
     *     expires_on:?string,
     *     verification_status:string,
     *     version:int,
     *     replayed:bool
     * }
     */
    public function registerCredential(int $actorUserId, array $input): array
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('يلزم تحديد مستخدم مخول لتسجيل المؤهل أو التدريب أو الوثيقة.');
        }

        $credential = $this->normalizeCredential($actorUserId, $input);

        return $this->credentials->transactional(function () use ($actorUserId, $credential): array {
            if (!$this->credentials->actorCanManageCredentials($actorUserId)) {
                throw new DomainException('لا تملك صلاحية إدارة مؤهلات ووثائق العاملين.');
            }
            if (!$this->credentials->isStaffUser($credential['staff_user_id'])) {
                throw new DomainException('لا يوجد ملف عامل صالح للسجل المطلوب.');
            }

            $result = $this->credentials->createCredential($credential);
            $record = $result['record'];
            $replayed = (bool) $result['replayed'];
            if (!$replayed) {
                $this->audit->recordEvent(
                    'create',
                    'staff_credential_record',
                    (int) $record['id'],
                    'سجل مؤهل أو تدريب أو وثيقة #' . (int) $record['id'],
                    [
                        'staff_user_id' => (int) $record['staff_user_id'],
                        'credential_kind' => (string) $record['credential_kind'],
                        'effective_on' => (string) $record['effective_on'],
                        'expires_on' => $record['expires_on'] === null ? null : (string) $record['expires_on'],
                        'verification_status' => (string) $record['verification_status'],
                        'version' => (int) $record['version'],
                    ],
                    ['actor_user_id' => $actorUserId]
                );
            }

            return $this->receipt($record, $replayed);
        });
    }

    /**
     * @return list<array{
     *     credential_id:int,
     *     staff_user_id:int,
     *     credential_kind:string,
     *     expires_on:string,
     *     verification_status:string,
     *     version:int,
     *     days_remaining:int,
     *     expiry_state:string
     * }>
     */
    public function expiryAlerts(DateTimeImmutable $asOf, int $leadDays = 30, int $limit = 100): array
    {
        if ($leadDays < 0 || $leadDays > self::MAX_ALERT_DAYS) {
            throw new InvalidArgumentException('فترة التنبيه يجب أن تكون بين صفر و' . self::MAX_ALERT_DAYS . ' يومًا.');
        }
        if ($limit < 1 || $limit > self::MAX_ALERTS) {
            throw new InvalidArgumentException('عدد تنبيهات الوثائق يجب أن يكون بين 1 و' . self::MAX_ALERTS . '.');
        }

        $timezone = new DateTimeZone('Africa/Cairo');
        $asOfDay = new DateTimeImmutable($asOf->format('Y-m-d') . ' 00:00:00', $timezone);
        $through = $asOfDay->modify('+' . $leadDays . ' days');

        $credentials = $this->credentials->expiringCredentials($asOfDay, $through, $limit);
        usort($credentials, static fn (array $left, array $right): int => [
            (string) $left['expires_on'],
            (int) $left['id'],
        ] <=> [
            (string) $right['expires_on'],
            (int) $right['id'],
        ]);

        return array_map(function (array $credential) use ($asOfDay, $timezone): array {
            $expiresOn = new DateTimeImmutable((string) $credential['expires_on'] . ' 00:00:00', $timezone);
            $daysRemaining = (int) (($expiresOn->getTimestamp() - $asOfDay->getTimestamp()) / 86400);

            return [
                'credential_id' => (int) $credential['id'],
                'staff_user_id' => (int) $credential['staff_user_id'],
                'credential_kind' => (string) $credential['credential_kind'],
                'expires_on' => (string) $credential['expires_on'],
                'verification_status' => (string) $credential['verification_status'],
                'version' => (int) $credential['version'],
                'days_remaining' => $daysRemaining,
                'expiry_state' => $daysRemaining < 0
                    ? 'expired'
                    : ($daysRemaining === 0 ? 'expires_today' : 'expires_soon'),
            ];
        }, $credentials);
    }

    /**
     * @return array{notified_credential_ids:list<int>,failed_credential_ids:list<int>}
     */
    public function notifyExpiryAlerts(DateTimeImmutable $asOf, int $leadDays = 30, int $limit = 100): array
    {
        $notified = [];
        $failed = [];

        foreach ($this->expiryAlerts($asOf, $leadDays, $limit) as $alert) {
            $eventKey = implode(':', [
                'staff.credential.expiry',
                (string) $alert['credential_id'],
                $alert['expires_on'],
                $alert['expiry_state'],
            ]);

            try {
                $receipt = $this->notifications->notifyRecipients(
                    $eventKey,
                    [$alert['staff_user_id']],
                    'admin/hr_center.php?tab=credentials&credential_id=' . $alert['credential_id'],
                    'لديك مؤهل أو تدريب أو وثيقة تحتاج إلى مراجعة.',
                    [
                        'credential_id' => $alert['credential_id'],
                        'credential_kind' => $alert['credential_kind'],
                        'expires_on' => $alert['expires_on'],
                        'expiry_state' => $alert['expiry_state'],
                    ],
                    hash('sha256', $eventKey)
                );
                if (($receipt['accepted'] ?? false) === true) {
                    $notified[] = $alert['credential_id'];
                } else {
                    $failed[] = $alert['credential_id'];
                }
            } catch (Throwable) {
                // An individual outbox failure is retried by the next safe run;
                // never expose internal failure detail to the operational surface.
                $failed[] = $alert['credential_id'];
            }
        }

        return [
            'notified_credential_ids' => $notified,
            'failed_credential_ids' => $failed,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeCredential(int $actorUserId, array $input): array
    {
        $staffUserId = filter_var($input['staff_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($staffUserId === false) {
            throw new InvalidArgumentException('يجب اختيار عامل صحيح قبل حفظ السجل.');
        }

        $kind = strtolower(trim((string) ($input['credential_kind'] ?? '')));
        if (!in_array($kind, self::CREDENTIAL_KINDS, true)) {
            throw new InvalidArgumentException('نوع السجل يجب أن يكون مؤهلًا أو تدريبًا أو وثيقة.');
        }

        $credentialKey = strtolower(trim((string) ($input['credential_key'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,99}$/D', $credentialKey) !== 1) {
            throw new InvalidArgumentException('رمز السجل يجب أن يكون رمزًا ثابتًا صالحًا للمراجعة.');
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || strlen($title) > 255) {
            throw new InvalidArgumentException('عنوان السجل مطلوب ويجب ألا يتجاوز 255 حرفًا.');
        }
        $issuer = trim((string) ($input['issuer'] ?? ''));
        if (strlen($issuer) > 255) {
            throw new InvalidArgumentException('جهة الإصدار يجب ألا تتجاوز 255 حرفًا.');
        }

        $effectiveOn = $this->normalizeDate($input['effective_on'] ?? null, 'تاريخ السريان', false);
        $issuedOn = $this->normalizeDate($input['issued_on'] ?? null, 'تاريخ الإصدار', true);
        $expiresOn = $this->normalizeDate($input['expires_on'] ?? null, 'تاريخ الانتهاء', true);
        if (($issuedOn !== null && $issuedOn < $effectiveOn)
            || ($expiresOn !== null && $expiresOn < $effectiveOn)
        ) {
            throw new InvalidArgumentException('لا يمكن أن يسبق تاريخ الإصدار أو الانتهاء تاريخ سريان السجل.');
        }

        $attachmentId = $this->normalizePositiveInteger($input['attachment_id'] ?? null, 'مرجع المرفق');
        $verificationStatus = strtolower(trim((string) ($input['verification_status'] ?? 'unverified')));
        if (!in_array($verificationStatus, self::VERIFICATION_STATUSES, true)) {
            throw new InvalidArgumentException('حالة التحقق من السجل غير صالحة.');
        }

        $idempotencyKey = strtolower(trim((string) ($input['idempotency_key'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('مفتاح الحفظ الآمن للسجل غير صالح. أعد فتح النموذج ثم حاول مرة أخرى.');
        }

        $payload = [
            'staff_user_id' => (int) $staffUserId,
            'credential_kind' => $kind,
            'credential_key' => $credentialKey,
            'title' => $title,
            'issuer' => $issuer === '' ? null : $issuer,
            'effective_on' => $effectiveOn,
            'issued_on' => $issuedOn,
            'expires_on' => $expiresOn,
            'attachment_id' => $attachmentId,
            'verification_status' => $verificationStatus,
            'source' => 'manual',
            'created_by_user_id' => $actorUserId,
        ];
        $payload['payload_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $payload['idempotency_key'] = $idempotencyKey;

        return $payload;
    }

    private function normalizeDate(mixed $value, string $label, bool $nullable): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        if ($value === null || $value === '') {
            if ($nullable) {
                return null;
            }
            throw new InvalidArgumentException($label . ' مطلوب.');
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' غير صالح.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException($label . ' يجب أن يكون بالتنسيق YYYY-MM-DD.');
        }

        return $date->format('Y-m-d');
    }

    private function normalizePositiveInteger(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new InvalidArgumentException($label . ' غير صالح.');
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $record */
    private function receipt(array $record, bool $replayed): array
    {
        return [
            'credential_id' => (int) $record['id'],
            'staff_user_id' => (int) $record['staff_user_id'],
            'credential_kind' => (string) $record['credential_kind'],
            'effective_on' => (string) $record['effective_on'],
            'expires_on' => $record['expires_on'] === null ? null : (string) $record['expires_on'],
            'verification_status' => (string) $record['verification_status'],
            'version' => (int) $record['version'],
            'replayed' => $replayed,
        ];
    }
}
