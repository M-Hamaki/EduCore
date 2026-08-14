<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Contracts\Repositories;

interface StaffAdvanceRepository
{
    public function create(int $staffId, string $amount, string $issueDate, string $reason, int $createdBy, string $requestId): int;
    public function findById(int $id): ?array;
    public function findByRequestId(string $requestId): ?array;
    public function linkPosting(int $advanceId, int $subledgerTransactionId): void;
    public function remaining(int $advanceId): string;
    public function updateStatus(int $advanceId, string $status): void;
    public function addInstallment(int $advanceId, string $dueDate, string $amount): int;
}
