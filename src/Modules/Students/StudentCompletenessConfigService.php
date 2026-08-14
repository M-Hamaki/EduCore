<?php

declare(strict_types=1);

namespace EduCore\Modules\Students;

use EduCore\Modules\Operations\Audit\AuditService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__) . '/Operations/Audit/AuditService.php';

final class StudentCompletenessConfigService
{
    private const SETTING_KEY = 'student_completeness_fields_v2';
    private const VALID_PRIORITIES = ['required', 'important', 'optional', 'ignored'];
    private const VALID_TABLES = [
        'student_profiles',
        'student_guardians_father',
        'student_guardians_mother',
        'student_attachments',
    ];

    private PDO $db;
    private string $defaultConfigPath;

    public function __construct(PDO $db, ?string $defaultConfigPath = null)
    {
        $this->db = $db;
        $this->defaultConfigPath = $defaultConfigPath
            ?? dirname(__DIR__, 3) . '/config/student_fields_config.json';
    }

    /** @return array{fields:array<int,array<string,mixed>>} */
    public function load(): array
    {
        $defaults = $this->loadDefaults();
        $stored = $this->loadStoredOverrides();

        foreach ($defaults['fields'] as &$field) {
            $key = (string) $field['key'];
            if (!isset($stored[$key]) || !is_array($stored[$key])) {
                continue;
            }

            $priority = (string) ($stored[$key]['priority'] ?? '');
            if (in_array($priority, self::VALID_PRIORITIES, true)) {
                $field['priority'] = $priority;
            }
            if (isset($stored[$key]['weight'])) {
                $field['weight'] = max(0, min(20, (int) $stored[$key]['weight']));
            }
        }
        unset($field);

        return $defaults;
    }

    /**
     * @param array<int,array<string,mixed>> $updates
     * @return array{config:array{fields:array<int,array<string,mixed>>},undo_id:?int}
     */
    public function save(array $updates): array
    {
        $defaults = $this->loadDefaults();
        $knownKeys = [];
        foreach ($defaults['fields'] as $field) {
            $knownKeys[(string) $field['key']] = true;
        }

        $overrides = [];
        foreach ($updates as $update) {
            if (!is_array($update)) {
                throw new InvalidArgumentException('صيغة إعدادات الحقول غير صالحة.');
            }
            $key = trim((string) ($update['key'] ?? ''));
            $priority = trim((string) ($update['priority'] ?? ''));
            if ($key === '' || !isset($knownKeys[$key])) {
                throw new InvalidArgumentException('يتضمن الطلب حقلاً غير معتمد.');
            }
            if (!in_array($priority, self::VALID_PRIORITIES, true)) {
                throw new InvalidArgumentException('أولوية أحد الحقول غير صالحة.');
            }
            $weight = filter_var($update['weight'] ?? null, FILTER_VALIDATE_INT);
            if ($weight === false || $weight < 0 || $weight > 20) {
                throw new InvalidArgumentException('وزن الحقل يجب أن يكون بين 0 و20.');
            }
            $overrides[$key] = ['priority' => $priority, 'weight' => $weight];
        }

        foreach ($defaults['fields'] as $field) {
            $key = (string) $field['key'];
            if (!isset($overrides[$key])) {
                $overrides[$key] = [
                    'priority' => (string) $field['priority'],
                    'weight' => (int) $field['weight'],
                ];
            }
        }

        $hasScoredField = false;
        foreach ($overrides as $override) {
            if ($override['priority'] !== 'ignored' && $override['weight'] > 0) {
                $hasScoredField = true;
                break;
            }
        }
        if (!$hasScoredField) {
            throw new InvalidArgumentException('يجب إبقاء حقل واحد على الأقل بوزن أكبر من صفر.');
        }

        ksort($overrides);
        $encoded = json_encode(['fields' => $overrides], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $ownsTransaction = !$this->db->inTransaction();
        $undoId = null;

        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

            $stmt = $this->db->prepare('SELECT * FROM settings WHERE setting_key = ? FOR UPDATE');
            $stmt->execute([self::SETTING_KEY]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $this->db->prepare(
                'INSERT INTO settings (setting_key, setting_value, description)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)'
            )->execute([
                self::SETTING_KEY,
                $encoded,
                'أولويات وأوزان احتساب اكتمال ملف الطالب',
            ]);

            $stmt = $this->db->prepare('SELECT * FROM settings WHERE setting_key = ?');
            $stmt->execute([self::SETTING_KEY]);
            $after = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) {
                throw new RuntimeException('تعذر إعادة تحميل إعدادات اكتمال البيانات.');
            }

            $audit = new AuditService($this->db);
            $recordId = $after['id'] ?? self::SETTING_KEY;
            if ($before === null) {
                $undoId = $audit->recordInsert(
                    'setting',
                    'settings',
                    $recordId,
                    self::SETTING_KEY,
                    $after,
                    'إضافة إعدادات اكتمال بيانات الطلاب'
                );
            } elseif ($before != $after) {
                $undoId = $audit->recordUpdate(
                    'setting',
                    'settings',
                    $recordId,
                    self::SETTING_KEY,
                    $before,
                    $after,
                    'تعديل إعدادات اكتمال بيانات الطلاب'
                );
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return ['config' => $this->load(), 'undo_id' => $undoId];
    }

    /** @return array{fields:array<int,array<string,mixed>>} */
    private function loadDefaults(): array
    {
        $json = @file_get_contents($this->defaultConfigPath);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($decoded) || !isset($decoded['fields']) || !is_array($decoded['fields'])) {
            throw new RuntimeException('تعذر قراءة إعدادات حقول اكتمال بيانات الطلاب.');
        }

        $fields = [];
        $keys = [];
        foreach ($decoded['fields'] as $field) {
            if (!is_array($field)) {
                throw new RuntimeException('تعريف أحد حقول الاكتمال غير صالح.');
            }
            $key = trim((string) ($field['key'] ?? ''));
            $table = trim((string) ($field['db_table'] ?? ''));
            $column = trim((string) ($field['db_column'] ?? ''));
            $priority = trim((string) ($field['priority'] ?? ''));
            if ($key === '' || isset($keys[$key]) || !preg_match('/^[a-z0-9_]+$/', $key)) {
                throw new RuntimeException('مفتاح أحد حقول الاكتمال غير صالح أو مكرر.');
            }
            if (!in_array($table, self::VALID_TABLES, true) || !preg_match('/^[a-z0-9_]+$/', $column)) {
                throw new RuntimeException('مصدر أحد حقول الاكتمال غير معتمد.');
            }
            if (!in_array($priority, self::VALID_PRIORITIES, true)) {
                throw new RuntimeException('أولوية أحد حقول الاكتمال غير صالحة.');
            }
            $field['weight'] = max(0, min(20, (int) ($field['weight'] ?? 0)));
            $fields[] = $field;
            $keys[$key] = true;
        }

        return ['fields' => $fields];
    }

    /** @return array<string,array{priority?:string,weight?:int}> */
    private function loadStoredOverrides(): array
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([self::SETTING_KEY]);
        $value = $stmt->fetchColumn();
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) && isset($decoded['fields']) && is_array($decoded['fields'])
            ? $decoded['fields']
            : [];
    }
}
