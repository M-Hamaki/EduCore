<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Application\Timeline;

use DateTimeImmutable;
use EduCore\Modules\Staff\Contracts\StaffTimelineEventSource;
use InvalidArgumentException;
use Throwable;

/**
 * Merges bounded, summary-safe timeline streams owned by their respective HR
 * resources. It is intentionally not an authorization boundary: callers must
 * establish viewer scope before requesting another worker's timeline.
 */
final class StaffHrTimelineQuery
{
    private const MAX_LIMIT = 200;

    /** @var array<string,StaffTimelineEventSource> */
    private array $sources = [];

    /** @param iterable<StaffTimelineEventSource> $sources */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            if (!$source instanceof StaffTimelineEventSource) {
                throw new InvalidArgumentException('A staff timeline source must implement the owned event-source contract.');
            }

            $sourceKey = $this->normalizedSourceKey($source->sourceKey());
            if (isset($this->sources[$sourceKey])) {
                throw new InvalidArgumentException('A staff timeline source key may be registered only once.');
            }
            $this->sources[$sourceKey] = $source;
        }
    }

    /**
     * @return array{
     *     events:list<array{
     *         source:string,
     *         event_id:string,
     *         occurred_at:DateTimeImmutable,
     *         event_type:string,
     *         resource_type:string,
     *         resource_id:int,
     *         status:string,
     *         version:?int
     *     }>,
     *     warnings:list<array{source:string,code:string}>,
     *     has_more:bool
     * }
     */
    public function forStaff(
        int $staffUserId,
        DateTimeImmutable $fromInclusive,
        DateTimeImmutable $toExclusive,
        int $limit = 100
    ): array {
        if ($staffUserId <= 0) {
            throw new InvalidArgumentException('A staff timeline requires a positive staff user id.');
        }
        if ($toExclusive <= $fromInclusive) {
            throw new InvalidArgumentException('A staff timeline end must be later than its start.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('A staff timeline limit must be between 1 and ' . self::MAX_LIMIT . '.');
        }

        $sourceLimit = min(self::MAX_LIMIT + 1, $limit + 1);
        $eventsByKey = [];
        $warnings = [];

        foreach ($this->sources as $sourceKey => $source) {
            try {
                $sourceEvents = $source->eventsForStaff($staffUserId, $fromInclusive, $toExclusive, $sourceLimit);
            } catch (Throwable) {
                $warnings[] = ['source' => $sourceKey, 'code' => 'source_unavailable'];
                continue;
            }

            if (!is_array($sourceEvents)) {
                $warnings[] = ['source' => $sourceKey, 'code' => 'invalid_source_response'];
                continue;
            }
            if (count($sourceEvents) > $sourceLimit) {
                $warnings[] = ['source' => $sourceKey, 'code' => 'source_limit_exceeded'];
                $sourceEvents = array_slice($sourceEvents, 0, $sourceLimit);
            }

            foreach ($sourceEvents as $sourceEvent) {
                $event = $this->normalizeEvent($sourceKey, $sourceEvent);
                if ($event === null) {
                    $warnings[] = ['source' => $sourceKey, 'code' => 'invalid_event'];
                    continue;
                }
                if ($event['occurred_at'] < $fromInclusive || $event['occurred_at'] >= $toExclusive) {
                    $warnings[] = ['source' => $sourceKey, 'code' => 'event_outside_window'];
                    continue;
                }

                $eventKey = $sourceKey . ':' . $event['event_id'];
                if (isset($eventsByKey[$eventKey])) {
                    $warnings[] = ['source' => $sourceKey, 'code' => 'duplicate_event'];
                    continue;
                }
                $eventsByKey[$eventKey] = $event;
            }
        }

        $events = array_values($eventsByKey);
        usort($events, static function (array $left, array $right): int {
            $leftTimestamp = $left['occurred_at']->format('U.u');
            $rightTimestamp = $right['occurred_at']->format('U.u');
            if ($leftTimestamp !== $rightTimestamp) {
                return strcmp($rightTimestamp, $leftTimestamp);
            }

            return [$left['source'], $left['event_id']] <=> [$right['source'], $right['event_id']];
        });

        usort($warnings, static function (array $left, array $right): int {
            return [$left['source'], $left['code']] <=> [$right['source'], $right['code']];
        });

        $hasMore = count($events) > $limit;

        return [
            'events' => array_slice($events, 0, $limit),
            'warnings' => $warnings,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param mixed $sourceEvent
     * @return array{source:string,event_id:string,occurred_at:DateTimeImmutable,event_type:string,resource_type:string,resource_id:int,status:string,version:?int}|null
     */
    private function normalizeEvent(string $sourceKey, mixed $sourceEvent): ?array
    {
        if (!is_array($sourceEvent)
            || !isset($sourceEvent['event_id'], $sourceEvent['occurred_at'], $sourceEvent['event_type'], $sourceEvent['resource_type'], $sourceEvent['resource_id'])
            || !$sourceEvent['occurred_at'] instanceof DateTimeImmutable
        ) {
            return null;
        }

        $eventId = trim((string) $sourceEvent['event_id']);
        $eventType = trim((string) $sourceEvent['event_type']);
        $resourceType = trim((string) $sourceEvent['resource_type']);
        $resourceId = filter_var($sourceEvent['resource_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = isset($sourceEvent['status']) ? trim((string) $sourceEvent['status']) : 'recorded';
        $version = $sourceEvent['version'] ?? null;

        if (!$this->isEventToken($eventId)
            || !$this->isEventToken($eventType)
            || !$this->isEventToken($resourceType)
            || $resourceId === false
            || !$this->isEventToken($status)
        ) {
            return null;
        }
        if ($version !== null && (!is_int($version) || $version < 1)) {
            return null;
        }

        return [
            'source' => $sourceKey,
            'event_id' => $eventId,
            'occurred_at' => $sourceEvent['occurred_at'],
            'event_type' => $eventType,
            'resource_type' => $resourceType,
            'resource_id' => (int) $resourceId,
            'status' => $status,
            'version' => $version,
        ];
    }

    private function normalizedSourceKey(string $sourceKey): string
    {
        $sourceKey = strtolower(trim($sourceKey));
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $sourceKey) !== 1) {
            throw new InvalidArgumentException('A staff timeline source key must be a stable lowercase token.');
        }

        return $sourceKey;
    }

    private function isEventToken(string $value): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_.:-]{0,190}$/D', strtolower($value)) === 1;
    }
}
