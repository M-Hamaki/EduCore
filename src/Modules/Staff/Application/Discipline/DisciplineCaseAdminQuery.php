<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Discipline;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Staff\Contracts\DisciplineCaseAdminReadRepository;
use InvalidArgumentException;

/**
 * Presentation-safe index for the compatible administrative discipline route.
 *
 * This query intentionally returns operational metadata only. It is not a
 * case-detail permission owner and cannot accidentally expose a reason,
 * evidence chain, decision text, or attachment path through a list view.
 */
final class DisciplineCaseAdminQuery
{
    /** @var list<string> */
    private const CASE_STATUSES = [
        'reported',
        'triage',
        'under_investigation',
        'pending_decision',
        'decided',
        'appeal_pending',
        'upheld',
        'amended',
        'revoked',
        'closed',
        'reopened',
        'cancelled',
    ];

    /** @var list<string> */
    private const CONFIDENTIALITY_LEVELS = ['normal'];

    public function __construct(private DisciplineCaseAdminReadRepository $repository)
    {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,filters:array{status?:string,confidentiality_level?:string,date_from?:string,date_to?:string}}
     */
    public function paginated(array $filters, int $limit, int $offset): array
    {
        if ($limit <= 0 || $limit > 200 || $offset < 0) {
            throw new InvalidArgumentException('DISCIPLINE_CASE_LIST_PAGINATION_INVALID');
        }
        $filters = $this->normalizedFilters($filters);

        return [
            'items' => $this->repository->listSummaries($filters, $limit, $offset),
            'total' => $this->repository->countSummaries($filters),
            'filters' => $filters,
        ];
    }

    /** @return array<string,mixed>|null */
    public function summary(int $caseId): ?array
    {
        if ($caseId <= 0) {
            throw new InvalidArgumentException('DISCIPLINE_CASE_ID_INVALID');
        }

        return $this->repository->summaryById($caseId);
    }

    /** @param array<string,mixed> $filters @return array{status?:string,confidentiality_level?:string,date_from?:string,date_to?:string} */
    private function normalizedFilters(array $filters): array
    {
        $normalized = [];
        $status = $this->nullableEnum($filters['status'] ?? null, self::CASE_STATUSES);
        if ($status !== null) {
            $normalized['status'] = $status;
        }
        $confidentiality = $this->nullableEnum(
            $filters['confidentiality_level'] ?? null,
            self::CONFIDENTIALITY_LEVELS
        );
        if ($confidentiality !== null) {
            $normalized['confidentiality_level'] = $confidentiality;
        }
        $dateFrom = $this->nullableDate($filters['date_from'] ?? null);
        if ($dateFrom !== null) {
            $normalized['date_from'] = $dateFrom;
        }
        $dateTo = $this->nullableDate($filters['date_to'] ?? null);
        if ($dateTo !== null) {
            $normalized['date_to'] = $dateTo;
        }
        if ($dateFrom !== null && $dateTo !== null && $dateTo < $dateFrom) {
            throw new InvalidArgumentException('DISCIPLINE_CASE_LIST_DATE_RANGE_INVALID');
        }

        return $normalized;
    }

    /** @param list<string> $allowed */
    private function nullableEnum(mixed $value, array $allowed): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('DISCIPLINE_CASE_LIST_FILTER_INVALID');
        }

        return $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('DISCIPLINE_CASE_LIST_FILTER_INVALID');
        }
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('DISCIPLINE_CASE_LIST_FILTER_INVALID');
        }

        return $value;
    }
}
