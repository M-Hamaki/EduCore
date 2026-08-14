<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Modules/Staff/bootstrap.php';

use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Staff\Application\Discipline\DisciplineInvestigationService;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAuthorization;
use EduCore\Modules\Staff\Contracts\DisciplineInvestigationRepository;
use EduCore\Modules\Staff\Infrastructure\LocalDisciplineEvidenceStorage;

final class DisciplineInvestigationMemoryRepository implements DisciplineInvestigationRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $cases = [
        1 => [
            'id' => 1,
            'case_no' => 'DISC-TEST-001',
            'status' => 'triage',
            'lock_version' => 2,
            'subject_staff_user_id' => 7,
            'opened_by_user_id' => 2,
            'incident_reported_by_user_id' => 1,
            'confidentiality_level' => 'restricted',
        ],
    ];
    /** @var array<int,array<string,mixed>> */
    public array $investigations = [];
    /** @var array<int,array<string,mixed>> */
    public array $evidence = [];
    /** @var array<int,bool> */
    public array $users = [1 => true, 2 => true, 3 => true, 4 => true, 7 => true];
    public bool $failEvidencePersistence = false;
    private int $nextInvestigationId = 1;
    private int $nextEvidenceId = 1;

    public function transactional(callable $work): mixed
    {
        $cases = $this->cases;
        $investigations = $this->investigations;
        $evidence = $this->evidence;
        $nextInvestigationId = $this->nextInvestigationId;
        $nextEvidenceId = $this->nextEvidenceId;
        try {
            return $work();
        } catch (Throwable $exception) {
            $this->cases = $cases;
            $this->investigations = $investigations;
            $this->evidence = $evidence;
            $this->nextInvestigationId = $nextInvestigationId;
            $this->nextEvidenceId = $nextEvidenceId;
            throw $exception;
        }
    }

    public function lockUser(int $userId): bool
    {
        return isset($this->users[$userId]);
    }

    public function caseForUpdate(int $caseId): ?array
    {
        return $this->cases[$caseId] ?? null;
    }

    public function investigationByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->investigations as $investigation) {
            if (($investigation['idempotency_key'] ?? null) === $idempotencyKey) {
                return $investigation;
            }
        }

        return null;
    }

    public function investigationForUpdate(int $investigationId): ?array
    {
        return $this->investigations[$investigationId] ?? null;
    }

    public function insertInvestigation(array $investigation): int
    {
        $id = $this->nextInvestigationId++;
        $this->investigations[$id] = $investigation + [
            'id' => $id,
            'status' => 'in_progress',
            'lock_version' => 1,
        ];

        return $id;
    }

    public function completeInvestigation(
        int $investigationId,
        int $expectedLockVersion,
        string $findings,
        string $recommendation,
        string $completedAt
    ): bool {
        $investigation = $this->investigations[$investigationId] ?? null;
        if ($investigation === null
            || ($investigation['status'] ?? null) !== 'in_progress'
            || (int) ($investigation['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $investigation['status'] = 'completed';
        $investigation['findings'] = $findings;
        $investigation['recommendation'] = $recommendation;
        $investigation['completed_at'] = $completedAt;
        $investigation['lock_version'] = $expectedLockVersion + 1;
        $this->investigations[$investigationId] = $investigation;

        return true;
    }

    public function transitionCase(
        int $caseId,
        int $expectedLockVersion,
        string $fromStatus,
        string $toStatus
    ): bool {
        $case = $this->cases[$caseId] ?? null;
        if ($case === null
            || ($case['status'] ?? null) !== $fromStatus
            || (int) ($case['lock_version'] ?? 0) !== $expectedLockVersion) {
            return false;
        }
        $case['status'] = $toStatus;
        $case['lock_version'] = $expectedLockVersion + 1;
        $this->cases[$caseId] = $case;

        return true;
    }

    public function evidenceByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        foreach ($this->evidence as $evidence) {
            if (($evidence['idempotency_key'] ?? null) === $idempotencyKey) {
                return $evidence;
            }
        }

        return null;
    }

    public function evidenceForUpdate(int $evidenceId): ?array
    {
        return $this->evidence[$evidenceId] ?? null;
    }

    public function insertEvidence(array $evidence): int
    {
        if ($this->failEvidencePersistence) {
            throw new DomainException('DISCIPLINE_EVIDENCE_DATABASE_FAILURE');
        }
        $id = $this->nextEvidenceId++;
        $this->evidence[$id] = $evidence + ['id' => $id, 'status' => 'collected'];

        return $id;
    }
}

final class DisciplineInvestigationTestAuthorization implements DisciplineCaseAuthorization
{
    /** @var list<string> */
    public array $actions = [];

    public bool $denyUploadsForActorFour = true;

    public function assertCanAct(
        int $actorId,
        string $action,
        ?array $case,
        DateTimeImmutable $atInstant
    ): void {
        $this->actions[] = $action;
        if ($this->denyUploadsForActorFour && $actorId === 4) {
            throw new DomainException('DISCIPLINE_EVIDENCE_ACCESS_DENIED');
        }
    }
}

final class DisciplineInvestigationTestAudit implements AuditEventWriter
{
    public bool $fail = false;
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
        if ($this->fail) {
            throw new DomainException('DISCIPLINE_EVIDENCE_AUDIT_FAILURE');
        }
        $this->events[] = compact('action', 'entityType', 'recordId', 'name', 'details', 'context');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertThrows = static function (callable $work, string $expectedMessage, string $message) use (&$assertions): void {
    ++$assertions;
    try {
        $work();
    } catch (Throwable $exception) {
        if ($exception->getMessage() === $expectedMessage) {
            return;
        }
        throw new RuntimeException($message . ': expected ' . $expectedMessage . ', got ' . $exception->getMessage());
    }
    throw new RuntimeException($message . ': no exception');
};

$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'educore_discipline_evidence_' . bin2hex(random_bytes(8));
if (!mkdir($sandbox, 0750, true) && !is_dir($sandbox)) {
    throw new RuntimeException('Unable to create discipline evidence test sandbox.');
}
$temporaryFiles = [];
$makeUpload = static function (string $name, string $content, ?int $reportedSize = null, int $error = UPLOAD_ERR_OK) use (&$temporaryFiles): array {
    $path = tempnam(sys_get_temp_dir(), 'educore_discipline_upload_');
    if ($path === false) {
        throw new RuntimeException('Unable to create discipline evidence fixture.');
    }
    file_put_contents($path, $content);
    $temporaryFiles[] = $path;

    return [
        'name' => $name,
        'tmp_name' => $path,
        'size' => $reportedSize ?? (int) filesize($path),
        'error' => $error,
    ];
};
$storedFiles = static function () use ($sandbox): array {
    $paths = glob($sandbox . '/storage/private/discipline_evidence/*');

    return $paths === false ? [] : $paths;
};

$repository = new DisciplineInvestigationMemoryRepository();
$authorization = new DisciplineInvestigationTestAuthorization();
$audit = new DisciplineInvestigationTestAudit();
$storage = new LocalDisciplineEvidenceStorage(
    $sandbox,
    static fn (string $source, string $destination): bool => copy($source, $destination)
);
$service = new DisciplineInvestigationService($repository, $storage, $authorization, $audit);
$pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";

$assertThrows(
    static fn (): array => $service->startInvestigation([
        'actor_id' => 2,
        'case_id' => 1,
        'expected_case_lock_version' => 2,
        'investigator_user_id' => 1,
        'idempotency_key' => 'discipline-investigation-reporter-conflict',
    ]),
    'DISCIPLINE_INVESTIGATOR_RECORDER_CONFLICT',
    'the incident recorder cannot investigate the same case'
);
$assert($repository->investigations === [] && $repository->cases[1]['status'] === 'triage', 'role conflict leaves the case unchanged');

$investigationCommand = [
    'actor_id' => 2,
    'case_id' => 1,
    'expected_case_lock_version' => 2,
    'investigator_user_id' => 3,
    'allegation' => 'تأخر متكرر يحتاج تحقيقًا',
    'idempotency_key' => 'discipline-investigation-1',
];
$investigation = $service->startInvestigation($investigationCommand);
$assert(
    $investigation['investigation_id'] === 1
        && $investigation['status'] === 'in_progress'
        && $repository->cases[1]['status'] === 'under_investigation'
        && $repository->cases[1]['lock_version'] === 3,
    'independent investigator assignment opens the protected investigation state'
);
$assert(
    $service->startInvestigation($investigationCommand)['replayed'] === true
        && count($repository->investigations) === 1,
    'investigation idempotency survives the case state transition'
);

$referenceCommand = [
    'actor_id' => 3,
    'case_id' => 1,
    'investigation_id' => 1,
    'evidence_kind' => 'attendance_reference',
    'source_resource_type' => 'attendance_raw_event',
    'source_resource_id' => 41,
    'evidence_summary' => 'مطابقة سجل البصمة مع الواقعة',
    'idempotency_key' => 'discipline-reference-evidence-1',
];
$reference = $service->recordReferenceEvidence($referenceCommand);
$assert(
    $reference['evidence_id'] === 1
        && $repository->evidence[1]['source_resource_type'] === 'attendance_raw_event'
        && $repository->evidence[1]['storage_ref'] === null,
    'reference evidence records only the immutable source identifier without a source write'
);
$assert(
    $service->recordReferenceEvidence($referenceCommand)['replayed'] === true
        && count($repository->evidence) === 1,
    'reference evidence replay keeps one custody-chain record'
);
$assertThrows(
    static fn (): array => $service->recordReferenceEvidence(array_replace($referenceCommand, [
        'idempotency_key' => 'discipline-reference-source-missing',
        'source_resource_id' => null,
    ])),
    'DISCIPLINE_EVIDENCE_SOURCE_REQUIRED',
    'linked attendance evidence requires both source type and source ID'
);

$privateCommand = [
    'actor_id' => 3,
    'case_id' => 1,
    'investigation_id' => 1,
    'prior_evidence_id' => 1,
    'evidence_summary' => 'محضر مرفق خاص',
    'idempotency_key' => 'discipline-private-evidence-1',
    'file' => $makeUpload('evidence.pdf', $pdf),
];
$privateEvidence = $service->uploadPrivateEvidence($privateCommand);
$privateRef = $repository->evidence[$privateEvidence['evidence_id']]['storage_ref'];
$assert(
    $privateEvidence['evidence_kind'] === 'private_attachment'
        && !array_key_exists('storage_ref', $privateEvidence)
        && is_string($privateRef)
        && str_starts_with($privateRef, 'private:discipline_evidence/'),
    'private evidence stores only a normalized private reference and does not expose it in the service receipt'
);
$assert(is_file($storage->absolutePath((string) $privateRef)), 'validated discipline evidence is stored outside the web root');
$filesBeforeReplay = count($storedFiles());
$assert(
    $service->uploadPrivateEvidence(array_replace($privateCommand, [
        'file' => $makeUpload('replayed.pdf', $pdf),
    ]))['replayed'] === true
        && count($storedFiles()) === $filesBeforeReplay,
    'private upload idempotency returns the original evidence without moving another file'
);

$filesBeforeUnauthorized = count($storedFiles());
$assertThrows(
    fn (): array => $service->uploadPrivateEvidence([
        'actor_id' => 4,
        'case_id' => 1,
        'investigation_id' => 1,
        'idempotency_key' => 'discipline-private-unauthorized',
        'file' => $makeUpload('unauthorized.pdf', $pdf),
    ]),
    'DISCIPLINE_EVIDENCE_ACCESS_DENIED',
    'authorization is evaluated before private upload validation or storage'
);
$assert(count($storedFiles()) === $filesBeforeUnauthorized, 'denied uploads leave no private file behind');

$assertThrows(
    fn (): array => $service->uploadPrivateEvidence([
        'actor_id' => 3,
        'case_id' => 1,
        'investigation_id' => 1,
        'idempotency_key' => 'discipline-private-dangerous-name',
        'file' => $makeUpload('evidence.php.pdf', $pdf),
    ]),
    'اسم الملف يحتوي على امتداد مزدوج خطر.',
    'dangerous double extension is rejected by FileUploadGuard'
);
$assertThrows(
    fn (): array => $service->uploadPrivateEvidence([
        'actor_id' => 3,
        'case_id' => 1,
        'investigation_id' => 1,
        'idempotency_key' => 'discipline-private-spoofed-mime',
        'file' => $makeUpload('evidence.pdf', '<?php echo "not a PDF";'),
    ]),
    'محتوى الملف لا يطابق نوعه المسموح.',
    'spoofed MIME content is rejected before private persistence'
);
$assertThrows(
    fn (): array => $service->uploadPrivateEvidence([
        'actor_id' => 3,
        'case_id' => 1,
        'investigation_id' => 1,
        'idempotency_key' => 'discipline-private-too-large',
        'file' => $makeUpload('evidence.pdf', $pdf, 10485761),
    ]),
    'حجم الملف يتجاوز الحد الأقصى المسموح به.',
    'oversized evidence is rejected before storage'
);
$assertThrows(
    fn (): array => $service->uploadPrivateEvidence([
        'actor_id' => 3,
        'case_id' => 1,
        'investigation_id' => 1,
        'idempotency_key' => 'discipline-private-partial',
        'file' => $makeUpload('evidence.pdf', $pdf, null, UPLOAD_ERR_PARTIAL),
    ]),
    'لم يكتمل رفع الملف. يرجى إعادة المحاولة.',
    'partial upload failures are rejected before persistence'
);

$repository->failEvidencePersistence = true;
$filesBeforeDatabaseFailure = count($storedFiles());
$assertThrows(
    fn (): array => $service->uploadPrivateEvidence([
        'actor_id' => 3,
        'case_id' => 1,
        'investigation_id' => 1,
        'idempotency_key' => 'discipline-private-database-failure',
        'file' => $makeUpload('database-failure.pdf', $pdf),
    ]),
    'DISCIPLINE_EVIDENCE_DATABASE_FAILURE',
    'database persistence failure aborts the private evidence write'
);
$repository->failEvidencePersistence = false;
$assert(count($storedFiles()) === $filesBeforeDatabaseFailure, 'database rollback removes a newly moved private evidence file');

$audit->fail = true;
$filesBeforeAuditFailure = count($storedFiles());
$evidenceBeforeAuditFailure = count($repository->evidence);
$assertThrows(
    fn (): array => $service->uploadPrivateEvidence([
        'actor_id' => 3,
        'case_id' => 1,
        'investigation_id' => 1,
        'idempotency_key' => 'discipline-private-audit-failure',
        'file' => $makeUpload('audit-failure.pdf', $pdf),
    ]),
    'DISCIPLINE_EVIDENCE_AUDIT_FAILURE',
    'mandatory audit failure aborts the evidence transaction'
);
$audit->fail = false;
$assert(
    count($storedFiles()) === $filesBeforeAuditFailure
        && count($repository->evidence) === $evidenceBeforeAuditFailure,
    'audit failure leaves neither a new private file nor a database evidence row'
);

$secondPrivate = $service->uploadPrivateEvidence([
    'actor_id' => 3,
    'case_id' => 1,
    'investigation_id' => 1,
    'prior_evidence_id' => $privateEvidence['evidence_id'],
    'idempotency_key' => 'discipline-private-evidence-2',
    'file' => $makeUpload('evidence.pdf', $pdf),
]);
$secondRef = $repository->evidence[$secondPrivate['evidence_id']]['storage_ref'];
$assert(
    $secondRef !== $privateRef && count($storedFiles()) === 2,
    'equal original names receive collision-resistant independent private references'
);

$assertThrows(
    fn (): array => $service->completeInvestigation([
        'actor_id' => 2,
        'investigation_id' => 1,
        'expected_lock_version' => 1,
        'findings' => 'نتيجة التحقيق',
        'recommendation' => 'توصية إدارية',
    ]),
    'DISCIPLINE_INVESTIGATION_OWNER_ONLY',
    'the case opener cannot complete the assigned investigator work'
);
$completed = $service->completeInvestigation([
    'actor_id' => 3,
    'investigation_id' => 1,
    'expected_lock_version' => 1,
    'findings' => 'نتيجة التحقيق',
    'recommendation' => 'توصية إدارية',
]);
$assert(
    $completed['status'] === 'completed'
        && $completed['lock_version'] === 2
        && count($repository->evidence) === 3,
    'investigator completion preserves existing evidence while recording findings and recommendation'
);
$assert(
    in_array('start_investigation', $authorization->actions, true)
        && in_array('record_evidence', $authorization->actions, true)
        && in_array('upload_evidence', $authorization->actions, true)
        && in_array('complete_investigation', $authorization->actions, true),
    'every investigation/evidence write crosses the authorization boundary'
);

foreach ($temporaryFiles as $temporaryFile) {
    @unlink($temporaryFile);
}
foreach ($storedFiles() as $storedFile) {
    @unlink($storedFile);
}
@rmdir($sandbox . '/storage/private/discipline_evidence');
@rmdir($sandbox . '/storage/private');
@rmdir($sandbox . '/storage');
@rmdir($sandbox);

echo 'staff_hr_discipline_investigation_service_test: PASS (' . $assertions . ' assertions)' . PHP_EOL;
