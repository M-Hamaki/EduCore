<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Private storage boundary for discipline evidence.
 *
 * Implementations validate the real file before moving it and return a
 * normalized private reference only; no absolute path or public URL crosses
 * into the application or database layer.
 */
interface DisciplineEvidenceStorage
{
    /**
     * @param array<string,mixed> $file
     * @return array{storage_ref:string,original_name:string,mime:string,size:int,sha256:string}
     */
    public function storeUploadedFile(array $file): array;

    /** Best-effort cleanup after a failed database/audit transaction. */
    public function delete(string $storageRef): bool;
}
