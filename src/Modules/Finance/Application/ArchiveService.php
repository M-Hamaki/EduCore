<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Application;

use EduCore\Modules\Finance\Contracts\FinanceTransactionManager;
use EduCore\Modules\Finance\Contracts\Repositories\ArchiveRepository;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use RuntimeException;

final class ArchiveService
{
    public function __construct(
        private ArchiveRepository $archive,
        private FinanceTransactionManager $transactions,
        private AuditEventWriter $audit
    ) {
    }

    public function archive(string $entityType, int $entityId, string $reason, int $archivedBy): void
    {
        if ($entityId <= 0 || $archivedBy <= 0 || trim($reason) === '') {
            throw new RuntimeException('Archive reason, entity, and actor are required.');
        }
        $this->transactions->transactional(function () use ($entityType, $entityId, $reason, $archivedBy): void {
            $this->archive->archive($entityType, $entityId);
            $this->audit->recordEvent('finance_archive', $entityType, $entityId, null, ['reason' => trim($reason), 'archived_by' => $archivedBy]);
        });
    }

    public function restore(string $entityType, int $entityId, int $restoredBy): void
    {
        $this->transactions->transactional(function () use ($entityType, $entityId, $restoredBy): void {
            if (!$this->archive->canRestore($entityType, $entityId)) {
                throw new RuntimeException('Finance entity cannot be restored.');
            }
            $this->archive->restore($entityType, $entityId);
            $this->audit->recordEvent('finance_restore', $entityType, $entityId, null, ['restored_by' => $restoredBy]);
        });
    }

    public function canRestore(string $entityType, int $entityId): bool
    {
        return $this->archive->canRestore($entityType, $entityId);
    }
}
