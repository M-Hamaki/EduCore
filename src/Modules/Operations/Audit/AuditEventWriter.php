<?php

declare(strict_types=1);

namespace EduCore\Modules\Operations\Audit;

/** Small cross-module contract for mandatory, non-restorable event audit. */
interface AuditEventWriter
{
    public function recordEvent(
        string $action,
        ?string $entityType,
        mixed $recordId,
        ?string $name,
        array $details = [],
        array $context = []
    ): void;
}
