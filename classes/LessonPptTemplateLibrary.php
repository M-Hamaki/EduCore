<?php
require_once __DIR__ . '/SchemaReadinessGuard.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';

class LessonPptTemplateLibrary
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureSchema();
    }

    public function all(): array
    {
        return $this->db
            ->query("SELECT * FROM lesson_ppt_templates ORDER BY is_active DESC, updated_at DESC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function active(): array
    {
        return $this->db
            ->query("SELECT * FROM lesson_ppt_templates WHERE is_active = 1 ORDER BY updated_at DESC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM lesson_ppt_templates WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $params = [
            trim((string)($data['name'] ?? '')),
            trim((string)($data['subject'] ?? '')),
            trim((string)($data['stage'] ?? '')),
            trim((string)($data['lesson_type'] ?? '')),
            trim((string)($data['language'] ?? '')),
            (int)($data['min_slides'] ?? 0),
            (int)($data['max_slides'] ?? 0),
            trim((string)($data['theme_hint'] ?? '')),
            trim((string)($data['keywords'] ?? '')),
            trim((string)($data['file_path'] ?? '')),
            trim((string)($data['thumbnail_path'] ?? '')),
            !empty($data['is_active']) ? 1 : 0,
        ];

        $ownsTransaction = !$this->db->inTransaction();
        try {
        if ($ownsTransaction) $this->db->beginTransaction();
        $before = null;
        if ($id > 0) {
            $beforeStmt = $this->db->prepare('SELECT * FROM lesson_ppt_templates WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([$id]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $sql = "UPDATE lesson_ppt_templates
                    SET name=?, subject=?, stage=?, lesson_type=?, language=?, min_slides=?, max_slides=?,
                        theme_hint=?, keywords=?, file_path=COALESCE(NULLIF(?, ''), file_path),
                        thumbnail_path=COALESCE(NULLIF(?, ''), thumbnail_path), is_active=?, updated_at=NOW()
                    WHERE id=?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge($params, [$id]));
        } else {
        $sql = "INSERT INTO lesson_ppt_templates
                    (name, subject, stage, lesson_type, language, min_slides, max_slides, theme_hint, keywords, file_path, thumbnail_path, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $id = (int)$this->db->lastInsertId();
        }
        $afterStmt = $this->db->prepare('SELECT * FROM lesson_ppt_templates WHERE id = ?');
        $afterStmt->execute([$id]);
        $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
        if (!$after) throw new RuntimeException('PowerPoint template could not be reloaded after save.');
        $audit = new \EduCore\Modules\Operations\Audit\AuditService($this->db);
        if ($before === null) {
            $audit->recordInsert('lesson_ppt_template', 'lesson_ppt_templates', $id, (string)$after['name'], $after, 'إضافة قالب PowerPoint');
        } elseif ($before != $after) {
            $audit->recordUpdate('lesson_ppt_template', 'lesson_ppt_templates', $id, (string)$after['name'], $before, $after, 'تعديل قالب PowerPoint');
        }
        if ($ownsTransaction) $this->db->commit();
        return $id;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $rowStmt = $this->db->prepare('SELECT * FROM lesson_ppt_templates WHERE id = ? FOR UPDATE');
            $rowStmt->execute([$id]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                $this->db->prepare("DELETE FROM lesson_ppt_templates WHERE id = ?")->execute([$id]);
                (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordDelete(
                    'lesson_ppt_template', 'lesson_ppt_templates', $id, (string)$row['name'], $row, 'حذف قالب PowerPoint وملفاته'
                );
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        if ($row) {
            foreach (['file_path', 'thumbnail_path'] as $field) {
                if (!empty($row[$field])) {
                    $full = dirname(__DIR__) . '/' . ltrim($row[$field], '/\\');
                    if (is_file($full)) {
                        @unlink($full);
                    }
                }
            }
        }

    }

    public function chooseBestTemplate(array $lessonData): ?array
    {
        $templates = $this->active();
        if (!$templates) {
            return null;
        }

        $lessonText = mb_strtolower(trim(implode(' ', [
            (string)($lessonData['title'] ?? ''),
            (string)($lessonData['subject'] ?? ''),
            (string)($lessonData['stage'] ?? ''),
            (string)($lessonData['lesson_type'] ?? ''),
            (string)($lessonData['language'] ?? ''),
            (string)($lessonData['summary'] ?? ''),
        ])));
        $slidesCount = count((array)($lessonData['slides'] ?? []));

        $best = null;
        $bestScore = -1;
        foreach ($templates as $template) {
            $score = 0;

            foreach (['subject' => 8, 'stage' => 6, 'lesson_type' => 5, 'language' => 4, 'theme_hint' => 2] as $field => $weight) {
                $value = mb_strtolower(trim((string)($template[$field] ?? '')));
                if ($value !== '' && mb_strpos($lessonText, $value) !== false) {
                    $score += $weight;
                }
            }

            $minSlides = (int)($template['min_slides'] ?? 0);
            $maxSlides = (int)($template['max_slides'] ?? 0);
            if ($slidesCount > 0) {
                if (($minSlides === 0 || $slidesCount >= $minSlides) && ($maxSlides === 0 || $slidesCount <= $maxSlides)) {
                    $score += 5;
                } elseif ($maxSlides > 0) {
                    $score -= min(5, abs($slidesCount - $maxSlides));
                }
            }

            $keywords = preg_split('/[,،\s]+/u', mb_strtolower((string)($template['keywords'] ?? ''))) ?: [];
            foreach ($keywords as $keyword) {
                $keyword = trim($keyword);
                if (mb_strlen($keyword) >= 3 && mb_strpos($lessonText, $keyword) !== false) {
                    $score += 3;
                }
            }

            if (!empty($template['file_path']) && is_file(dirname(__DIR__) . '/' . ltrim($template['file_path'], '/\\'))) {
                $score += 2;
            } else {
                $score -= 100;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $template;
            }
        }

        return $bestScore >= -20 ? $best : null;
    }

    private function ensureSchema(): void
    {
        (new SchemaReadinessGuard($this->db))->assertTable('lesson_ppt_templates');
    }
}
