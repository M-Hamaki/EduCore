<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

/**
 * Private storage boundary for confidential Ertaq evidence.
 *
 * Implementations validate the real upload before moving it and return only a
 * normalized private reference. An absolute filesystem path or public URL
 * must never reach an Ertaq application service, receipt, or database row.
 */
interface ErtaqAttachmentStorage
{
    /**
     * @param array<string,mixed> $file
     * @return array{storage_ref:string,original_name:string,mime:string,size:int,sha256:string}
     */
    public function storeUploadedFile(array $file): array;

    /** Best-effort cleanup after a failed metadata/audit/notification transaction. */
    public function delete(string $storageRef): bool;
}
