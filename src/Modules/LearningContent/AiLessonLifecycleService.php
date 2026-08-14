<?php

declare(strict_types=1);

namespace EduCore\Modules\LearningContent;

use EduCore\Modules\Operations\Audit\AuditService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__) . '/Operations/Audit/AuditService.php';

final class AiLessonLifecycleService
{
    private const ALLOWED_FIELDS = [
        'status',
        'generation_error',
        'powerpoint_path',
        'powerpoint_theme',
        'powerpoint_status',
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function update(int $lessonId, int $teacherId, array $changes, string $action, array $details = []): void
    {
        if ($lessonId <= 0 || $teacherId <= 0 || $changes === []) {
            throw new InvalidArgumentException('Invalid AI lesson lifecycle update.');
        }

        foreach (array_keys($changes) as $field) {
            if (!in_array($field, self::ALLOWED_FIELDS, true)) {
                throw new InvalidArgumentException('Unsupported AI lesson lifecycle field: ' . $field);
            }
        }

        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $stmt = $this->db->prepare('SELECT * FROM ai_lessons WHERE id = ? AND teacher_id = ? FOR UPDATE');
            $stmt->execute([$lessonId, $teacherId]);
            $beforeRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$beforeRow) throw new RuntimeException('AI lesson not found for lifecycle update.');

            $set = [];
            $values = [];
            $before = [];
            $after = [];
            foreach ($changes as $field => $value) {
                $set[] = "`{$field}` = ?";
                $values[] = $value;
                $before[$field] = $beforeRow[$field] ?? null;
                $after[$field] = $value;
            }
            $set[] = 'updated_at = NOW()';
            $values[] = $lessonId;
            $values[] = $teacherId;

            $update = $this->db->prepare(
                'UPDATE ai_lessons SET ' . implode(', ', $set) . ' WHERE id = ? AND teacher_id = ?'
            );
            $update->execute($values);

            ksort($before);
            ksort($after);
            $safeStatuses = [];
            foreach (['status', 'powerpoint_status', 'powerpoint_theme'] as $field) {
                if (array_key_exists($field, $after)) $safeStatuses[$field] = $after[$field];
            }
            (new AuditService($this->db))->recordEvent(
                $action,
                'ai_lesson',
                $lessonId,
                (string) ($beforeRow['title'] ?? ''),
                array_merge($details, [
                    'changed_fields' => array_keys($after),
                    'before_sha256' => hash('sha256', json_encode($before, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: ''),
                    'after_sha256' => hash('sha256', json_encode($after, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: ''),
                    'statuses' => $safeStatuses,
                    'direct_undo' => false,
                    'reason' => 'generated_content_lifecycle',
                ])
            );
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
