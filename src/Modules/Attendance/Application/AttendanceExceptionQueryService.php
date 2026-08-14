<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Application;

use DateTimeImmutable;
use DateTimeZone;
use EduCore\Modules\Attendance\Contracts\AttendanceExceptionQuery;
use InvalidArgumentException;

/**
 * Normalizes exception-review filters and turns attendance-owned read rows
 * into clear, redacted Arabic DTOs for the legacy admin surface.
 */
final class AttendanceExceptionQueryService
{
    private const MAX_RANGE_DAYS = 366;
    private const MAX_ITEMS = 100;
    private const CATEGORIES = ['all', 'raw', 'day', 'comparison'];

    private DateTimeZone $timezone;

    public function __construct(
        private AttendanceExceptionQuery $repository,
        ?DateTimeZone $timezone = null
    ) {
        $this->timezone = $timezone ?? new DateTimeZone('Africa/Cairo');
    }

    /**
     * @param array<string,mixed> $input
     * @return array{filters:array{date_from:string,date_to:string,staff_user_id:?int,category:string,limit:int},summary:array{raw_events:int,unresolved_days:int,comparison_differences:int,total:int},items:list<array<string,mixed>>,filtered_total:int,limit_reached:bool}
     */
    public function review(array $input = []): array
    {
        $filters = $this->normalizeFilters($input);
        $summaryRow = $this->repository->summary($filters);
        $summary = [
            'raw_events' => max(0, (int) ($summaryRow['raw_events'] ?? 0)),
            'unresolved_days' => max(0, (int) ($summaryRow['unresolved_days'] ?? 0)),
            'comparison_differences' => max(0, (int) ($summaryRow['comparison_differences'] ?? 0)),
        ];
        $summary['total'] = $summary['raw_events']
            + $summary['unresolved_days']
            + $summary['comparison_differences'];

        $items = [];
        foreach ($this->repository->listItems($filters) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = $this->presentItem($row);
            if ($item === null || ($filters['category'] !== 'all' && $item['category'] !== $filters['category'])) {
                continue;
            }
            $items[] = $item;
        }

        $filteredTotal = match ($filters['category']) {
            'raw' => $summary['raw_events'],
            'day' => $summary['unresolved_days'],
            'comparison' => $summary['comparison_differences'],
            default => $summary['total'],
        };

        return [
            'filters' => $filters,
            'summary' => $summary,
            'items' => $items,
            'filtered_total' => $filteredTotal,
            'limit_reached' => $filteredTotal > count($items),
        ];
    }

    /** @return array{date_from:string,date_to:string,staff_user_id:?int,category:string,limit:int} */
    public function defaultFilters(): array
    {
        $today = new DateTimeImmutable('today', $this->timezone);

        return [
            'date_from' => $today->modify('first day of this month')->format('Y-m-d'),
            'date_to' => $today->format('Y-m-d'),
            'staff_user_id' => null,
            'category' => 'all',
            'limit' => self::MAX_ITEMS,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{date_from:string,date_to:string,staff_user_id:?int,category:string,limit:int}
     */
    private function normalizeFilters(array $input): array
    {
        $defaults = $this->defaultFilters();
        $from = $this->parseDate($input['date_from'] ?? null, $defaults['date_from'], 'تاريخ البداية');
        $to = $this->parseDate($input['date_to'] ?? null, $defaults['date_to'], 'تاريخ النهاية');
        if ($to < $from) {
            throw new InvalidArgumentException('تاريخ النهاية لا يمكن أن يسبق تاريخ البداية.');
        }
        if ((int) $from->diff($to)->days > self::MAX_RANGE_DAYS - 1) {
            throw new InvalidArgumentException('نطاق المراجعة لا يمكن أن يتجاوز 366 يومًا.');
        }

        $category = $input['category'] ?? $defaults['category'];
        if (!is_string($category) || !in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('اختر فئة استثناء صالحة.');
        }

        return [
            'date_from' => $from->format('Y-m-d'),
            'date_to' => $to->format('Y-m-d'),
            'staff_user_id' => $this->parseStaffUserId($input['staff_user_id'] ?? null),
            'category' => $category,
            'limit' => self::MAX_ITEMS,
        ];
    }

    private function parseDate(mixed $value, string $fallback, string $label): DateTimeImmutable
    {
        if ($value === null || $value === '') {
            $value = $fallback;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' غير صالح.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label . ' غير صالح.');
        }

        return $date;
    }

    private function parseStaffUserId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9][0-9]{0,9}$/', (string) $value) !== 1) {
            throw new InvalidArgumentException('رقم العامل في الفلتر غير صالح.');
        }

        $staffUserId = (int) $value;
        if ($staffUserId <= 0) {
            throw new InvalidArgumentException('رقم العامل في الفلتر غير صالح.');
        }

        return $staffUserId;
    }

    /** @return array<string,mixed>|null */
    private function presentItem(array $row): ?array
    {
        $category = (string) ($row['category'] ?? '');
        if (!in_array($category, ['raw', 'day', 'comparison'], true)) {
            return null;
        }

        $issueCode = trim((string) ($row['issue_code'] ?? ''));
        if ($issueCode === '') {
            return null;
        }
        $issue = $this->issueDetails($issueCode);
        $staffUserId = isset($row['staff_user_id']) && (int) $row['staff_user_id'] > 0
            ? (int) $row['staff_user_id']
            : null;
        $sourceId = max(0, (int) ($row['id'] ?? 0));
        $occurredAt = trim((string) ($row['occurred_at'] ?? ''));

        return [
            'id' => $sourceId,
            'category' => $category,
            'category_label' => match ($category) {
                'raw' => 'حدث بصمة',
                'day' => 'نتيجة يوم',
                default => 'مقارنة انتقالية',
            },
            'staff_user_id' => $staffUserId,
            'occurred_at' => $occurredAt === '' ? '—' : $occurredAt,
            'issue_code' => $issueCode,
            'issue_label' => $issue['label'],
            'detail' => $issue['detail'],
            'severity' => $issue['severity'],
            'severity_label' => $issue['severity_label'],
            'state_label' => $this->stateLabel($category, $row),
            'source_label' => match ($category) {
                'raw' => 'حدث بصمة #' . $sourceId,
                'day' => 'نتيجة يوم #' . $sourceId,
                default => 'مقارنة يوم #' . $sourceId,
            },
        ];
    }

    /** @return array{label:string,detail:string,severity:string,severity_label:string} */
    private function issueDetails(string $code): array
    {
        return match ($code) {
            'RAW_IDENTITY_UNMATCHED' => $this->issue('بصمة غير مرتبطة بعامل', 'لا يمكن احتساب الحدث حتى تُراجع علاقة رقم البصمة بالعامل.', 'danger', 'يتطلب تدخلاً'),
            'RAW_IDENTITY_AMBIGUOUS' => $this->issue('رقم بصمة مرتبط بأكثر من عامل', 'يلزم تحديد العامل الصحيح قبل احتساب الحدث.', 'danger', 'يتطلب تدخلاً'),
            'RAW_IDENTITY_MAPPING_RETIRED' => $this->issue('رقم بصمة مرتبط بسجل متوقف', 'تحقق من استمرار صلاحية الربط أو أنشئ ربطًا مؤرخًا صحيحًا.', 'warning', 'يحتاج مراجعة'),
            'RAW_MANUAL_REVIEW' => $this->issue('ربط بصمة يحتاج مراجعة يدوية', 'لا يُستخدم الحدث في النتيجة النهائية قبل قرار المراجعة.', 'warning', 'يحتاج مراجعة'),
            'RAW_CLOCK_INVALID' => $this->issue('وقت بصمة غير صالح', 'تحقق من وقت الجهاز أو ملف الاستيراد قبل الاعتماد.', 'danger', 'يتطلب تدخلاً'),
            'RAW_CLOCK_DRIFTED' => $this->issue('انحراف في ساعة جهاز البصمة', 'راجع فرق توقيت الجهاز؛ سيظل الحدث موثقًا دون تعديل الخام.', 'warning', 'يحتاج مراجعة'),
            'RAW_REVIEW_REJECTED' => $this->issue('حدث حضور مرفوض', 'هذا الحدث مستبعد من الاحتساب ويحتاج متابعة سبب الرفض عند اللزوم.', 'danger', 'مرفوض'),
            'RAW_REVIEW_PENDING' => $this->issue('حدث حضور بانتظار المراجعة', 'يلزم اتخاذ قرار المراجعة قبل استخدامه في النتيجة المعتمدة.', 'warning', 'بانتظار القرار'),
            'DAY_UNRESOLVED' => $this->issue('لم تُحسم نتيجة يوم الحضور', 'لم يمكن تحديد دوام صالح أو مصدر كافٍ لإتمام الاحتساب.', 'danger', 'يتطلب تدخلاً'),
            'DAY_EXCEPTION' => $this->issue('استثناء في نتيجة يوم الحضور', 'توجد بصمة ناقصة أو دليل غير قابل للاحتساب الآلي في هذا اليوم.', 'warning', 'يحتاج مراجعة'),
            'LEGACY_RECORD_MISSING' => $this->issue('سجل الحضور السابق غير موجود', 'لم تُعثر مقارنة الانتقال على سجل قديم لهذا اليوم.', 'info', 'فرق انتقال'),
            'LEGACY_RECORD_AMBIGUOUS' => $this->issue('توجد سجلات حضور سابقة مكررة', 'لم يُختر سجل قديم عشوائيًا؛ يلزم مراجعة التكرار.', 'warning', 'فرق انتقال'),
            'LEGACY_STATUS_DIFFERENCE' => $this->issue('حالة الحضور تختلف عن السجل السابق', 'نتيجة الاحتساب الجديدة لا تطابق حالة السجل السابق.', 'info', 'فرق انتقال'),
            'LEGACY_CHECK_IN_DIFFERENCE' => $this->issue('وقت الدخول يختلف عن السجل السابق', 'راجِع مصدر البصمة أو الإدخال السابق قبل اعتماد الانتقال.', 'info', 'فرق انتقال'),
            'LEGACY_CHECK_OUT_DIFFERENCE' => $this->issue('وقت الانصراف يختلف عن السجل السابق', 'راجِع مصدر البصمة أو الإدخال السابق قبل اعتماد الانتقال.', 'info', 'فرق انتقال'),
            'LEGACY_LATE_MINUTES_DIFFERENCE' => $this->issue('دقائق التأخر تختلف عن السجل السابق', 'الفرق موثق للمراجعة ولا يغيّر السجل السابق تلقائيًا.', 'info', 'فرق انتقال'),
            default => $this->issue('فرق أو استثناء حضور يحتاج مراجعة', 'السجل موثق للمراجعة ولا يغيّر أي بيانات تلقائيًا.', 'warning', 'يحتاج مراجعة'),
        };
    }

    /** @return array{label:string,detail:string,severity:string,severity_label:string} */
    private function issue(string $label, string $detail, string $severity, string $severityLabel): array
    {
        return [
            'label' => $label,
            'detail' => $detail,
            'severity' => $severity,
            'severity_label' => $severityLabel,
        ];
    }

    private function stateLabel(string $category, array $row): string
    {
        if ($category === 'comparison') {
            return 'مقارنة تجريبية';
        }
        if ($category === 'day') {
            if ((int) ($row['is_official'] ?? 0) === 1) {
                return 'نتيجة رسمية';
            }

            return (string) ($row['calculation_mode'] ?? '') === 'shadow'
                ? 'نتيجة تجريبية'
                : 'نتيجة قيد المعالجة';
        }

        return match ((string) ($row['review_status'] ?? '')) {
            'approved' => 'تمت المراجعة',
            'rejected' => 'مرفوض',
            'pending' => 'بانتظار المراجعة',
            default => 'يتطلب المتابعة',
        };
    }
}
