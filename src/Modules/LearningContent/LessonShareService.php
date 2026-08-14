<?php

declare(strict_types=1);

namespace EduCore\Modules\LearningContent;

use EduCore\Modules\Operations\Audit\AuditService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__) . '/Operations/Audit/AuditService.php';

final class LessonShareService
{
    private const TOKEN_BYTES = 32;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function isValidToken(string $token): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
    }

    public static function buildPublicUrl(string $token): string
    {
        if (!self::isValidToken($token)) {
            throw new InvalidArgumentException('Invalid public lesson token.');
        }

        $baseUrl = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        if ($baseUrl === '') {
            throw new RuntimeException('APP_URL is required for public lesson links.');
        }

        return $baseUrl . '/shared_lesson.php?token=' . rawurlencode($token);
    }

    public function getOwnerState(int $lessonId, int $teacherId): array
    {
        $lesson = $this->findOwnedLesson($lessonId, $teacherId, false);
        $token = (string) ($lesson['public_share_token'] ?? '');
        $enabled = self::isValidToken($token)
            && !empty($lesson['public_share_enabled_at'])
            && empty($lesson['public_share_revoked_at']);

        return [
            'enabled' => $enabled,
            'share_url' => $enabled ? self::buildPublicUrl($token) : null,
            'enabled_at' => $enabled ? $lesson['public_share_enabled_at'] : null,
        ];
    }

    public function enable(int $lessonId, int $teacherId): array
    {
        $ownsTransaction = !$this->db->inTransaction();

        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

            $lesson = $this->findOwnedLesson($lessonId, $teacherId, true);
            if (($lesson['status'] ?? '') !== 'completed') {
                throw new RuntimeException('Only completed lessons can be shared.');
            }

            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
            $update = $this->db->prepare(
                'UPDATE ai_lessons
                 SET public_share_token = ?,
                     public_share_enabled_at = NOW(),
                     public_share_revoked_at = NULL,
                     updated_at = NOW()
                 WHERE id = ? AND teacher_id = ?'
            );
            $update->execute([$token, $lessonId, $teacherId]);

            (new AuditService($this->db))->recordEvent(
                'ai_lesson_public_share_enabled',
                'ai_lesson',
                $lessonId,
                (string) ($lesson['title'] ?? ''),
                [
                    'was_enabled' => !empty($lesson['public_share_token'])
                        && !empty($lesson['public_share_enabled_at'])
                        && empty($lesson['public_share_revoked_at']),
                    'token_rotated' => true,
                    'direct_undo' => false,
                    'reason' => 'public_bearer_link_requires_explicit_revocation',
                ]
            );

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'enabled' => true,
                'share_url' => self::buildPublicUrl($token),
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function revoke(int $lessonId, int $teacherId): array
    {
        $ownsTransaction = !$this->db->inTransaction();

        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

            $lesson = $this->findOwnedLesson($lessonId, $teacherId, true);
            $wasEnabled = !empty($lesson['public_share_token'])
                && !empty($lesson['public_share_enabled_at'])
                && empty($lesson['public_share_revoked_at']);

            $update = $this->db->prepare(
                'UPDATE ai_lessons
                 SET public_share_token = NULL,
                     public_share_revoked_at = NOW(),
                     updated_at = NOW()
                 WHERE id = ? AND teacher_id = ?'
            );
            $update->execute([$lessonId, $teacherId]);

            (new AuditService($this->db))->recordEvent(
                'ai_lesson_public_share_revoked',
                'ai_lesson',
                $lessonId,
                (string) ($lesson['title'] ?? ''),
                [
                    'was_enabled' => $wasEnabled,
                    'direct_undo' => false,
                    'reason' => 'revocation_invalidates_public_bearer_link',
                ]
            );

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return ['enabled' => false, 'share_url' => null];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function findPublicLesson(string $token): ?array
    {
        if (!self::isValidToken($token)) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT *
             FROM ai_lessons
             WHERE public_share_token = ?
               AND public_share_enabled_at IS NOT NULL
               AND public_share_revoked_at IS NULL
               AND status = 'completed'
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

        return $lesson ?: null;
    }

    private function findOwnedLesson(int $lessonId, int $teacherId, bool $forUpdate): array
    {
        if ($lessonId <= 0 || $teacherId <= 0) {
            throw new InvalidArgumentException('Invalid lesson ownership lookup.');
        }

        $sql = 'SELECT id, teacher_id, title, status, public_share_token,
                       public_share_enabled_at, public_share_revoked_at
                FROM ai_lessons
                WHERE id = ? AND teacher_id = ?';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$lessonId, $teacherId]);
        $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lesson) {
            throw new RuntimeException('Lesson not found or not owned by the teacher.');
        }

        return $lesson;
    }
}
