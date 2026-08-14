<?php

declare(strict_types=1);

namespace EduCore\Modules\Attendance\Domain\Calculation;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule;

/**
 * Converts append-only attendance evidence into deterministic in/out intervals
 * for one scheduled work date. It deliberately never infers a missing punch.
 */
final class PunchWindowMatcher
{
    /** @var list<string> */
    private const REVIEWABLE_STATUSES = ['not_required', 'approved'];

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    public function match(WorkSchedule $schedule, DateTimeImmutable $workDate, array $events): array
    {
        $window = $schedule->workWindow($workDate);
        if ($window === null) {
            return [
                'window' => null,
                'intervals' => [],
                'first_in' => null,
                'last_out' => null,
                'has_complete_pair' => false,
                'missing_entry' => false,
                'missing_exit' => false,
                'unusable_events' => [],
                'matched_events' => [],
            ];
        }

        $timezone = new DateTimeZone($schedule->timezone());
        $usable = [];
        $unusable = [];
        foreach ($events as $inputOrder => $event) {
            if (!is_array($event)) {
                $unusable[] = $this->unusable(null, null, 'UNUSABLE_PUNCH');
                continue;
            }
            $eventId = $this->eventId($event);
            $at = $this->eventAt($event, $timezone);
            if ($at === null) {
                $unusable[] = $this->unusable($eventId, null, 'UNUSABLE_PUNCH');
                continue;
            }
            if (!$this->isLinked($event)) {
                $unusable[] = $this->unusable($eventId, $at, 'UNUSABLE_PUNCH');
                continue;
            }
            if (!$this->isReviewed($event)) {
                $unusable[] = $this->unusable($eventId, $at, 'PUNCH_PENDING_REVIEW');
                continue;
            }
            $semanticType = $this->semanticType((string) ($event['event_type'] ?? 'unknown'));
            if ($semanticType === null) {
                $unusable[] = $this->unusable($eventId, $at, 'UNUSABLE_PUNCH');
                continue;
            }
            if ($at < $window['entry_capture_start'] || $at > $window['exit_capture_end']) {
                $unusable[] = $this->unusable($eventId, $at, 'OUTSIDE_CAPTURE_WINDOW');
                continue;
            }
            $usable[] = [
                'id' => $eventId,
                'at' => $at,
                'semantic_type' => $semanticType,
                'entry_method_type' => trim((string) ($event['entry_method_type'] ?? 'biometric')),
                'input_order' => (int) $inputOrder,
            ];
        }

        usort($usable, static function (array $left, array $right): int {
            $timeOrder = $left['at'] <=> $right['at'];
            if ($timeOrder !== 0) {
                return $timeOrder;
            }
            $idOrder = ($left['id'] ?? 0) <=> ($right['id'] ?? 0);
            return $idOrder !== 0 ? $idOrder : ($left['input_order'] <=> $right['input_order']);
        });

        $intervals = [];
        $matched = [];
        $firstIn = null;
        $lastOut = null;
        $open = null;
        $hasAcceptedEntry = false;
        foreach ($usable as $event) {
            $semanticType = $event['semantic_type'];
            if ($semanticType === 'in') {
                if ($open !== null) {
                    $unusable[] = $this->unusable($event['id'], $event['at'], 'DUPLICATE_ENTRY_PUNCH');
                    continue;
                }
                if (!$hasAcceptedEntry && $event['at'] > $window['entry_capture_end']) {
                    $unusable[] = $this->unusable($event['id'], $event['at'], 'OUTSIDE_ENTRY_CAPTURE_WINDOW');
                    continue;
                }
                $open = $event;
                $hasAcceptedEntry = true;
                $firstIn ??= $event['at'];
                $matched[] = $event;
                continue;
            }

            if ($open === null) {
                $unusable[] = $this->unusable($event['id'], $event['at'], 'UNMATCHED_EXIT_PUNCH');
                continue;
            }
            if ($event['at'] <= $open['at']) {
                $unusable[] = $this->unusable($event['id'], $event['at'], 'UNMATCHED_EXIT_PUNCH');
                continue;
            }
            $intervals[] = [
                'start' => $open['at'],
                'end' => $event['at'],
                'entry_event_id' => $open['id'],
                'exit_event_id' => $event['id'],
                'entry_method_types' => array_values(array_unique([
                    $open['entry_method_type'],
                    $event['entry_method_type'],
                ])),
            ];
            $lastOut = $event['at'];
            $matched[] = $event;
            $open = null;
        }

        return [
            'window' => $window,
            'intervals' => $intervals,
            'first_in' => $firstIn,
            'last_out' => $lastOut,
            'has_complete_pair' => $intervals !== [],
            'missing_entry' => $firstIn === null,
            'missing_exit' => $open !== null || ($firstIn !== null && $lastOut === null),
            'unusable_events' => $unusable,
            'matched_events' => $matched,
        ];
    }

    /** @param array<string,mixed> $event */
    private function eventId(array $event): ?int
    {
        $id = filter_var($event['id'] ?? null, FILTER_VALIDATE_INT);
        return $id === false || $id <= 0 ? null : (int) $id;
    }

    /** @param array<string,mixed> $event */
    private function eventAt(array $event, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $value = $event['event_at_local'] ?? null;
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($timezone);
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($text, $timezone))->setTimezone($timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $event */
    private function isLinked(array $event): bool
    {
        return in_array((string) ($event['link_status'] ?? 'matched'), ['matched', 'alternative_matched'], true);
    }

    /** @param array<string,mixed> $event */
    private function isReviewed(array $event): bool
    {
        return in_array((string) ($event['review_status'] ?? 'not_required'), self::REVIEWABLE_STATUSES, true);
    }

    private function semanticType(string $eventType): ?string
    {
        return match ($eventType) {
            'in', 'break_end' => 'in',
            'out', 'break_start' => 'out',
            default => null,
        };
    }

    /** @return array<string,mixed> */
    private function unusable(?int $eventId, ?DateTimeImmutable $at, string $reasonCode): array
    {
        return [
            'event_id' => $eventId,
            'at' => $at,
            'reason_code' => $reasonCode,
        ];
    }
}
