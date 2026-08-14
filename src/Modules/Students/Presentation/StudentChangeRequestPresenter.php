<?php

declare(strict_types=1);

namespace EduCore\Modules\Students\Presentation;

final class StudentChangeRequestPresenter
{
    /** @param array<string,string> $labels */
    public function __construct(private array $labels)
    {
    }

    /**
     * @param array<string,mixed> $beforePayload
     * @param array<string,mixed> $proposedPayload
     * @param array<string,mixed> $canonicalCurrent
     * @return array<int,array{label:string,before:string,after:string}>
     */
    public function diffRows(
        array $beforePayload,
        array $proposedPayload,
        array $canonicalCurrent = []
    ): array
    {
        $format = (string) ($proposedPayload['__format'] ?? '');
        $request = is_array($proposedPayload['request'] ?? null) ? $proposedPayload['request'] : [];
        if (in_array($format, ['full_profile_v1', 'class_transfer_v1'], true)) {
            $before = is_array($beforePayload['display'] ?? null) ? $beforePayload['display'] : [];
            $proposed = is_array($proposedPayload['display'] ?? null) ? $proposedPayload['display'] : [];
        } else {
            $before = $beforePayload;
            $proposed = $proposedPayload;
        }

        $rows = [];
        foreach ($proposed as $field => $afterValue) {
            $field = (string) $field;
            if ($field === '' || str_starts_with($field, '__')) {
                continue;
            }
            if ($format === 'full_profile_v1' && !$this->wasCompositeGroupSubmitted($field, $request)) {
                continue;
            }

            $beforeValue = $this->canonicalLegacyBeforeValue(
                $field,
                $before[$field] ?? '',
                $afterValue,
                $proposed,
                $request,
                $canonicalCurrent
            );
            if ($field === 'extra_data') {
                array_push($rows, ...$this->extraDataRows($beforeValue, $afterValue));
                continue;
            }
            if ($field === 'external_transfer') {
                array_push($rows, ...$this->externalTransferRows($beforeValue, $afterValue));
                continue;
            }
            if ($this->comparable($beforeValue) === $this->comparable($afterValue)) {
                continue;
            }
            $rows[] = [
                'label' => $this->labels[$field] ?? $this->humanizeKey($field),
                'before' => $this->formatValue($beforeValue, $field),
                'after' => $this->formatValue($afterValue, $field),
            ];
        }

        return $rows;
    }

    /**
     * Older profiles may have an empty profile.grade_id while their annual
     * enrollment and class already identify the same grade rendered by the form.
     * Treat that as hydration, not as a specialist-requested academic change.
     *
     * @param array<string,mixed> $proposedDisplay
     * @param array<string,mixed> $request
     * @param array<string,mixed> $canonicalCurrent
     */
    private function canonicalLegacyBeforeValue(
        string $field,
        mixed $beforeValue,
        mixed $afterValue,
        array $proposedDisplay,
        array $request,
        array $canonicalCurrent
    ): mixed {
        if ($field !== 'grade_id'
            || trim((string) $beforeValue) !== ''
            || array_key_exists('class_id', $proposedDisplay)) {
            return $beforeValue;
        }

        $currentGradeId = trim((string) ($canonicalCurrent['current_grade_id'] ?? ''));
        $currentClassId = trim((string) ($canonicalCurrent['current_class_id'] ?? ''));
        $requestClassId = trim((string) ($request['class_id'] ?? ''));
        if ($currentGradeId !== ''
            && $currentClassId !== ''
            && $requestClassId === $currentClassId
            && $this->comparable($afterValue) === $this->comparable($currentGradeId)) {
            return $currentGradeId;
        }

        return $beforeValue;
    }

    /** @param array<string,mixed> $request */
    private function wasCompositeGroupSubmitted(string $field, array $request): bool
    {
        $groups = [
            'extra_phones' => [
                'student_extra_phones_touched', 'student_extra_phones_present',
                'student_mobile_numbers', 'student_mobile_notes',
                'student_landline_numbers', 'student_landline_notes',
            ],
            'extra_data' => [
                'student_extra_data_touched', 'student_extra_data_present',
                'additional_data_labels', 'additional_data_values',
                'educational_guardianship', 'educational_guardianship_other',
            ],
            'guardians' => ['student_guardians_touched', 'student_guardians_present', 'guardians'],
            'external_transfer' => [
                'student_external_transfer_touched', 'student_external_transfer_present',
                'transfer_destination', 'external_transfer_date',
                'external_transfer_reason', 'external_transfer_notes',
            ],
        ];
        if (!isset($groups[$field])) {
            return true;
        }

        $touchedField = $groups[$field][0];
        return trim((string) ($request[$touchedField] ?? '')) === '1';
    }

    /** @return array<int,array{label:string,before:string,after:string}> */
    private function extraDataRows(mixed $before, mixed $after): array
    {
        $beforeMap = $this->labelValueMap($before);
        $afterMap = $this->labelValueMap($after);
        $keys = array_values(array_unique(array_merge(array_keys($beforeMap), array_keys($afterMap))));
        $rows = [];
        foreach ($keys as $key) {
            $oldValue = $beforeMap[$key] ?? '';
            $newValue = $afterMap[$key] ?? '';
            if ($this->comparable($oldValue) === $this->comparable($newValue)) {
                continue;
            }
            $rows[] = [
                'label' => ($this->labels['extra_data'] ?? 'البيانات الإضافية') . ' — ' . $this->humanizeKey($key),
                'before' => $this->formatValue($oldValue),
                'after' => $this->formatValue($newValue),
            ];
        }
        return $rows;
    }

    /** @return array<int,array{label:string,before:string,after:string}> */
    private function externalTransferRows(mixed $before, mixed $after): array
    {
        $beforeMap = $this->associativeMap($before);
        $afterMap = $this->associativeMap($after);
        $fieldLabels = [
            'transfer_destination' => 'جهة النقل',
            'external_transfer_date' => 'تاريخ النقل الخارجي',
            'external_transfer_reason' => 'سبب النقل الخارجي',
            'external_transfer_notes' => 'ملاحظات النقل الخارجي',
        ];
        $rows = [];
        foreach ($fieldLabels as $key => $label) {
            $oldValue = $beforeMap[$key] ?? '';
            $newValue = $afterMap[$key] ?? '';
            if ($this->comparable($oldValue) === $this->comparable($newValue)) {
                continue;
            }
            $rows[] = [
                'label' => $label,
                'before' => $this->formatValue($oldValue),
                'after' => $this->formatValue($newValue),
            ];
        }
        return $rows;
    }

    private function formatValue(mixed $value, string $field = ''): string
    {
        $value = $this->decodeJsonValue($value);
        if (!is_array($value)) {
            $text = trim((string) $value);
            return $text !== '' ? $text : 'لا توجد';
        }
        if ($value === []) {
            return 'لا توجد';
        }
        if ($field === 'extra_phones') {
            $phones = [];
            foreach ($value as $item) {
                if (!is_array($item)) {
                    $number = trim((string) $item);
                    if ($number !== '') $phones[] = $number;
                    continue;
                }
                $number = trim((string) ($item['number'] ?? $item['phone'] ?? ''));
                if ($number === '') continue;
                $type = (string) ($item['type'] ?? '');
                $typeLabel = $type === 'landline' ? 'أرضي' : ($type === 'mobile' ? 'موبايل' : 'هاتف');
                $note = trim((string) ($item['note'] ?? ''));
                $phones[] = $typeLabel . ': ' . $number . ($note !== '' ? ' (' . $note . ')' : '');
            }
            return $phones ? implode('، ', $phones) : 'لا توجد';
        }
        if ($field === 'guardians') {
            $guardians = [];
            foreach ($value as $item) {
                if (!is_array($item)) continue;
                $name = trim((string) ($item['guardian_name'] ?? ''));
                $relationship = $this->relationshipLabel((string) ($item['relationship'] ?? ''));
                $phone = trim((string) ($item['phone_primary'] ?? ''));
                $summary = trim($name . ($relationship !== '' ? ' (' . $relationship . ')' : ''));
                if ($phone !== '') $summary .= ' — ' . $phone;
                if ($summary !== '') $guardians[] = $summary;
            }
            return $guardians ? implode('، ', $guardians) : 'لا توجد';
        }

        $parts = [];
        foreach ($value as $key => $item) {
            $formatted = $this->formatValue($item);
            if ($formatted === 'لا توجد') continue;
            $parts[] = is_string($key) ? $this->humanizeKey($key) . ': ' . $formatted : $formatted;
        }
        return $parts ? implode('، ', $parts) : 'لا توجد';
    }

    /** @return array<string,mixed> */
    private function labelValueMap(mixed $value): array
    {
        $value = $this->decodeJsonValue($value);
        if (!is_array($value)) return [];
        $map = [];
        foreach ($value as $key => $item) {
            if (is_array($item) && array_key_exists('label', $item)) {
                $label = trim((string) ($item['label'] ?? ''));
                if ($label !== '') $map[$label] = $item['value'] ?? '';
                continue;
            }
            if (is_string($key)) {
                $map[$key] = $item;
            }
        }
        return $map;
    }

    /** @return array<string,mixed> */
    private function associativeMap(mixed $value): array
    {
        $value = $this->decodeJsonValue($value);
        return is_array($value) ? $value : [];
    }

    private function comparable(mixed $value): string
    {
        $normalized = $this->normalize($this->decodeJsonValue($value));
        return is_array($normalized)
            ? (json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
            : (string) $normalized;
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return trim((string) $value);
        }
        $result = [];
        foreach ($value as $key => $item) {
            $normalized = $this->normalize($item);
            if ($normalized === '' || $normalized === []) continue;
            $result[$key] = $normalized;
        }
        if (!$this->isList($result)) ksort($result);
        return $result;
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        $trimmed = trim($value);
        if ($trimmed === '' || !in_array($trimmed[0], ['[', '{'], true)) return $value;
        $decoded = json_decode($trimmed, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function isList(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $index++) return false;
        }
        return true;
    }

    private function humanizeKey(string $key): string
    {
        $labels = [
            '__educational_guardianship' => 'الوصاية التعليمية',
            'social_media_contact' => 'وسيلة التواصل الاجتماعي',
            'social_media_work_address' => 'عنوان العمل أو الحساب',
        ];
        return $labels[$key] ?? trim(str_replace('_', ' ', $key));
    }

    private function relationshipLabel(string $relationship): string
    {
        return [
            'father' => 'الأب', 'mother' => 'الأم', 'grandfather' => 'الجد',
            'grandmother' => 'الجدة', 'brother' => 'الأخ', 'sister' => 'الأخت',
            'legal_guardian' => 'وصي قانوني', 'other' => 'أخرى',
        ][$relationship] ?? trim($relationship);
    }
}
