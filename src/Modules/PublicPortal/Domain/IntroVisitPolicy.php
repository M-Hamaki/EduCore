<?php

declare(strict_types=1);

namespace EduCore\Modules\PublicPortal\Domain;

final class IntroVisitPolicy
{
    public const COOKIE_NAME = 'educore_intro_seen_at';

    private int $intervalSeconds;

    public function __construct(int $intervalSeconds = 1296000)
    {
        $this->intervalSeconds = max(60, $intervalSeconds);
    }

    public function shouldShow(
        ?string $cookieValue,
        bool $shownThisSession,
        bool $teamsContext,
        bool $skipRequested,
        bool $hasAccessMessage,
        ?int $now = null
    ): bool {
        if ($teamsContext || $skipRequested || $hasAccessMessage || $shownThisSession) {
            return false;
        }

        $now = $now ?? time();
        if ($cookieValue === null || !preg_match('/^\d{1,12}$/', $cookieValue)) {
            return true;
        }

        $seenAt = (int) $cookieValue;
        if ($seenAt <= 0 || $seenAt > ($now + 300)) {
            return true;
        }

        return ($now - $seenAt) >= $this->intervalSeconds;
    }

    public function normalizeDestination(?string $destination): string
    {
        return $destination === 'materials' ? 'materials' : 'portal';
    }

    public function routeForDestination(?string $destination): string
    {
        return $this->normalizeDestination($destination) === 'materials'
            ? 'materials.php?skip_intro=1'
            : 'index.php?skip_intro=1';
    }

    public function intervalSeconds(): int
    {
        return $this->intervalSeconds;
    }
}
