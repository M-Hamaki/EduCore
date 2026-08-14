<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';

use EduCore\Modules\Operations\Audit\AuditService;

final class RecoveryBackupService
{
    private const GATE_EXCLUDED_TABLES = [
        'activity_logs',
        'undo_log',
        'recovery_backups',
        'academic_year_rollover_runs',
        'academic_year_rollover_items',
    ];

    private PDO $db;
    private string $projectRoot;
    private string $backupRoot;
    private array $dataRoots;

    public function __construct(PDO $db, ?string $projectRoot = null, ?array $dataRoots = null, ?string $backupRoot = null)
    {
        $this->db = $db;
        $this->projectRoot = rtrim($projectRoot ?: dirname(__DIR__), "\\/");
        $this->backupRoot = $backupRoot
            ? rtrim($backupRoot, "\\/")
            : $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups'
                . DIRECTORY_SEPARATOR . 'recovery';
        $this->dataRoots = $dataRoots ?: [
            'uploads' => $this->projectRoot . DIRECTORY_SEPARATOR . 'uploads',
            'private' => $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private',
        ];
        foreach (array_keys($this->dataRoots) as $label) {
            if (!preg_match('/^[A-Za-z0-9_-]+$/', (string) $label)) {
                throw new InvalidArgumentException('تصنيف مسار بيانات التعافي غير صالح.');
            }
        }
    }

    public function createPackage(?int $actorId = null): array
    {
        $this->assertSchemaReady();
        $this->ensureDirectory($this->backupRoot);
        $backupKey = bin2hex(random_bytes(16));
        $packageFile = $this->backupRoot . DIRECTORY_SEPARATOR . $backupKey . '.zip';
        $temporaryDir = $this->backupRoot . DIRECTORY_SEPARATOR . '.tmp-' . $backupKey;
        $this->ensureDirectory($temporaryDir);
        $dumpFile = $temporaryDir . DIRECTORY_SEPARATOR . 'database.sql';
        $relativePath = $this->relativeToProject($packageFile);

        $insert = $this->db->prepare("INSERT INTO recovery_backups
            (backup_key, status, package_path, database_name, created_by, expires_at)
            VALUES (?, 'creating', ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
        $insert->execute([$backupKey, $relativePath, $this->databaseName(), $actorId ?: null]);
        $backupId = (int) $this->db->lastInsertId();

        try {
            $this->dumpDatabase($dumpFile);
            $databaseInventory = $this->databaseInventory($this->db);
            $sourceInventory = $this->databaseInventory($this->db, true);
            $fileInventory = $this->fileInventory();
            $manifest = [
                'format' => 1,
                'backup_key' => $backupKey,
                'created_at' => gmdate('c'),
                'database_name' => $this->databaseName(),
                'database_dump_sha256' => hash_file('sha256', $dumpFile),
                'database_fingerprint' => $databaseInventory['fingerprint'],
                'source_fingerprint' => $sourceInventory['fingerprint'],
                'schema_fingerprint' => $databaseInventory['schema_fingerprint'],
                'tables' => $databaseInventory['tables'],
                'table_hashes' => $databaseInventory['table_hashes'],
                'files_fingerprint' => $fileInventory['fingerprint'],
                'files' => $fileInventory['files'],
            ];
            $manifestJson = $this->canonicalJson($manifest);
            $manifestSha = hash('sha256', $manifestJson);

            $zip = new ZipArchive();
            if ($zip->open($packageFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('تعذر إنشاء حزمة التعافي.');
            }
            try {
                if (!$zip->addFile($dumpFile, 'database.sql')) {
                    throw new RuntimeException('تعذر إضافة نسخة قاعدة البيانات إلى حزمة التعافي.');
                }
                foreach ($fileInventory['files'] as $file) {
                    $sourcePath = $this->dataRoots[(string) $file['root']]
                        . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $file['path']);
                    if (!is_file($sourcePath)
                        || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $sourcePath))) {
                        throw new RuntimeException('تغير ملف بيانات أثناء إنشاء النسخة؛ أعد المحاولة في نافذة صيانة.');
                    }
                    $archivePath = 'files/' . $file['root'] . '/' . $file['path'];
                    if (!$zip->addFile($sourcePath, $archivePath)) {
                        throw new RuntimeException('تعذر إضافة أحد ملفات البيانات إلى حزمة التعافي.');
                    }
                    // Images, PDFs, media, and Office containers are already
                    // compressed. Storing them avoids expensive deferred work
                    // in ZipArchive::close() without weakening SHA verification.
                    if ($this->shouldStoreWithoutCompression($sourcePath)
                        && method_exists($zip, 'setCompressionName')) {
                        $zip->setCompressionName($archivePath, ZipArchive::CM_STORE);
                    }
                }
                if (!$zip->addFromString('manifest.json', $manifestJson)) {
                    throw new RuntimeException('تعذر كتابة بيان حزمة التعافي.');
                }
            } finally {
                $zip->close();
            }

            $packageSha = (string) hash_file('sha256', $packageFile);
            $update = $this->db->prepare("UPDATE recovery_backups
                SET status = 'created', package_sha256 = ?, manifest_sha256 = ?,
                    database_fingerprint = ?, source_fingerprint = ?, files_fingerprint = ?, failure_code = NULL
                WHERE id = ?");
            $update->execute([
                $packageSha,
                $manifestSha,
                $manifest['database_fingerprint'],
                $manifest['source_fingerprint'],
                $manifest['files_fingerprint'],
                $backupId,
            ]);
            (new AuditService($this->db))->recordEvent('create', 'recovery_backup', $backupId, $backupKey, [
                'summary' => 'إنشاء حزمة تعافٍ قبل تهيئة عام دراسي',
                'package_sha256' => $packageSha,
                'database_fingerprint' => $manifest['database_fingerprint'],
                'source_fingerprint' => $manifest['source_fingerprint'],
                'files_fingerprint' => $manifest['files_fingerprint'],
                'table_count' => count($manifest['tables']),
                'file_count' => count($manifest['files']),
                'direct_undo_available' => false,
            ]);

            return $this->findByKey($backupKey);
        } catch (Throwable $e) {
            $this->markFailed($backupId, 'package_creation_failed');
            if (is_file($packageFile)) {
                @unlink($packageFile);
            }
            throw $e;
        } finally {
            $this->removeOwnedDirectory($temporaryDir);
        }
    }

    private function shouldStoreWithoutCompression(string $path): bool
    {
        return in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), [
            '7z', 'avi', 'docx', 'gif', 'gz', 'jpeg', 'jpg', 'm4a', 'mkv', 'mov',
            'mp3', 'mp4', 'pdf', 'png', 'pptx', 'rar', 'webm', 'webp', 'xlsx', 'zip',
        ], true);
    }

    public function verifyPackage(string $backupKey, string $testDatabaseName, ?int $actorId = null): array
    {
        return $this->restoreAndVerifyPackage($backupKey, $testDatabaseName, $actorId, false);
    }

    /**
     * Restores a verified baseline into a fresh isolated database and keeps it
     * available for acceptance journeys. It never replaces or mutates the
     * source database, and an existing target is accepted only when its full
     * fingerprint still matches the recorded baseline.
     */
    public function restorePackageToIsolatedDatabase(
        string $backupKey,
        string $testDatabaseName,
        ?int $actorId = null
    ): array {
        $this->assertSchemaReady();
        self::assertTestDatabaseName($testDatabaseName, $this->databaseName());
        $receipt = $this->findByKey($backupKey);
        $admin = $this->adminConnection();
        if ($this->databaseExists($admin, $testDatabaseName)) {
            if ((string) ($receipt['status'] ?? '') !== 'verified'
                || (string) ($receipt['test_database_name'] ?? '') !== $testDatabaseName) {
                throw new RuntimeException('قاعدة الاستعادة المعزولة موجودة ولا تخص هذه الحزمة.');
            }
            $restored = $this->databaseInventory($this->databaseConnection($testDatabaseName));
            if (!hash_equals(
                (string) ($receipt['database_fingerprint'] ?? ''),
                (string) ($restored['fingerprint'] ?? '')
            )) {
                throw new RuntimeException('تغيرت قاعدة الاستعادة المعزولة بعد إنشائها؛ استخدم اسماً جديداً.');
            }

            return array_replace($receipt, [
                'restored_database_name' => $testDatabaseName,
                'retained' => true,
                'replayed' => true,
            ]);
        }

        return array_replace(
            $this->restoreAndVerifyPackage($backupKey, $testDatabaseName, $actorId, true),
            [
                'restored_database_name' => $testDatabaseName,
                'retained' => true,
                'replayed' => false,
            ]
        );
    }

    private function restoreAndVerifyPackage(
        string $backupKey,
        string $testDatabaseName,
        ?int $actorId,
        bool $retainDatabase
    ): array
    {
        $this->assertSchemaReady();
        self::assertTestDatabaseName($testDatabaseName, $this->databaseName());
        $receipt = $this->findByKey($backupKey);
        $retryableRestoreFailure = (string) ($receipt['status'] ?? '') === 'failed'
            && (string) ($receipt['failure_code'] ?? '') === 'restore_verification_failed'
            && !empty($receipt['package_sha256'])
            && !empty($receipt['manifest_sha256']);
        if (!in_array((string) $receipt['status'], ['created', 'verified'], true)
            && !$retryableRestoreFailure) {
            throw new RuntimeException('حزمة التعافي ليست جاهزة للتحقق.');
        }

        $packageFile = $this->absolutePackagePath((string) $receipt['package_path']);
        if (!is_file($packageFile)
            || !hash_equals((string) $receipt['package_sha256'], (string) hash_file('sha256', $packageFile))) {
            throw new RuntimeException('حزمة التعافي مفقودة أو تغيرت بعد إنشائها.');
        }

        $temporaryDir = $this->backupRoot . DIRECTORY_SEPARATOR . '.verify-' . bin2hex(random_bytes(8));
        $this->ensureDirectory($temporaryDir);
        $createdTestDatabase = false;
        $restoreCompleted = false;
        $this->db->prepare("UPDATE recovery_backups SET status = 'verifying', test_database_name = ? WHERE id = ?")
            ->execute([$testDatabaseName, (int) $receipt['id']]);

        try {
            $zip = new ZipArchive();
            if ($zip->open($packageFile) !== true) {
                throw new RuntimeException('تعذر فتح حزمة التعافي.');
            }
            try {
                $this->assertSafeZipEntries($zip);
                if (!$zip->extractTo($temporaryDir)) {
                    throw new RuntimeException('تعذر استخراج حزمة التعافي للتحقق.');
                }
            } finally {
                $zip->close();
            }

            $manifestFile = $temporaryDir . DIRECTORY_SEPARATOR . 'manifest.json';
            $dumpFile = $temporaryDir . DIRECTORY_SEPARATOR . 'database.sql';
            $manifestJson = is_file($manifestFile) ? (string) file_get_contents($manifestFile) : '';
            $manifest = json_decode($manifestJson, true);
            if (!is_array($manifest)
                || !hash_equals((string) $receipt['manifest_sha256'], hash('sha256', $manifestJson))
                || !is_file($dumpFile)
                || !hash_equals((string) ($manifest['database_dump_sha256'] ?? ''), (string) hash_file('sha256', $dumpFile))) {
                throw new RuntimeException('فشل تحقق سلامة بيان أو ملف قاعدة بيانات حزمة التعافي.');
            }
            $this->verifyExtractedFiles($temporaryDir, (array) ($manifest['files'] ?? []));

            $admin = $this->adminConnection();
            if ($this->databaseExists($admin, $testDatabaseName)) {
                throw new RuntimeException('قاعدة التحقق المعزولة موجودة مسبقًا؛ اختر اسماً جديداً منتهيًا بـ _test.');
            }
            $admin->exec('CREATE DATABASE ' . $this->quoteIdentifier($testDatabaseName)
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $createdTestDatabase = true;
            $this->importDatabase($dumpFile, $testDatabaseName);
            $testDb = $this->databaseConnection($testDatabaseName);
            $restored = $this->databaseInventory($testDb);
            if (!hash_equals((string) ($manifest['database_fingerprint'] ?? ''), $restored['fingerprint'])) {
                throw new RuntimeException('فشل تطابق قاعدة البيانات المستعادة مع بيان النسخة.');
            }

            $summary = [
                'verified_at' => gmdate('c'),
                'test_database_name' => $testDatabaseName,
                'table_count' => count($restored['tables']),
                'file_count' => count((array) ($manifest['files'] ?? [])),
                'database_fingerprint' => $restored['fingerprint'],
                'files_fingerprint' => (string) ($manifest['files_fingerprint'] ?? ''),
                'retained_database' => $retainDatabase,
            ];
            $update = $this->db->prepare("UPDATE recovery_backups
                SET status = 'verified', verified_at = NOW(), test_database_name = ?,
                    verification_summary = ?, failure_code = NULL
                WHERE id = ?");
            $update->execute([
                $testDatabaseName,
                $this->canonicalJson($summary),
                (int) $receipt['id'],
            ]);
            (new AuditService($this->db))->recordEvent(
                $retainDatabase ? 'restore' : 'verify',
                'recovery_backup',
                (int) $receipt['id'],
                $backupKey,
                [
                'summary' => $retainDatabase
                    ? 'نجاح استعادة baseline في قاعدة قبول معزولة محتفظ بها'
                    : 'نجاح استعادة واختبار حزمة التعافي في قاعدة معزولة',
                'table_count' => $summary['table_count'],
                'file_count' => $summary['file_count'],
                'database_fingerprint' => $summary['database_fingerprint'],
                'files_fingerprint' => $summary['files_fingerprint'],
                'retained_database' => $retainDatabase,
                'direct_undo_available' => false,
                ]
            );

            $restoreCompleted = true;
            return $this->findByKey($backupKey);
        } catch (Throwable $e) {
            $this->markFailed((int) $receipt['id'], 'restore_verification_failed');
            throw $e;
        } finally {
            if ($createdTestDatabase && (!$retainDatabase || !$restoreCompleted)) {
                try {
                    $this->adminConnection()->exec('DROP DATABASE ' . $this->quoteIdentifier($testDatabaseName));
                } catch (Throwable $cleanupError) {
                    error_log('Recovery verification test database cleanup failed.');
                }
            }
            $this->removeOwnedDirectory($temporaryDir);
        }
    }

    public function currentFingerprint(): array
    {
        $database = $this->databaseInventory($this->db, true);
        $files = $this->fileInventory();
        return [
            'source_fingerprint' => $database['fingerprint'],
            'files_fingerprint' => $files['fingerprint'],
        ];
    }

    public function assertUsableVerifiedReceipt(string $backupKey): array
    {
        $receipt = $this->findByKey($backupKey);
        if ((string) $receipt['status'] !== 'verified' || empty($receipt['verified_at'])
            || strtotime((string) $receipt['expires_at']) < time()) {
            throw new RuntimeException('يلزم إنشاء واستعادة نسخة تعافٍ حديثة قبل تنفيذ التهيئة.');
        }
        $current = $this->currentFingerprint();
        if (empty($receipt['source_fingerprint'])
            || !hash_equals((string) $receipt['source_fingerprint'], $current['source_fingerprint'])
            || !hash_equals((string) $receipt['files_fingerprint'], $current['files_fingerprint'])) {
            throw new RuntimeException('تغيرت البيانات بعد اختبار النسخة الاحتياطية؛ أنشئ نسخة جديدة واختبر استعادتها.');
        }
        return $receipt;
    }

    public static function assertTestDatabaseName(string $name, string $sourceDatabase): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+_test$/', $name) || hash_equals($name, $sourceDatabase)) {
            throw new InvalidArgumentException('قاعدة الاستعادة يجب أن تكون معزولة، جديدة، ومنتهية بـ _test.');
        }
    }

    private function databaseInventory(PDO $connection, bool $forRolloverGate = false): array
    {
        $database = (string) $connection->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $connection->prepare("SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        $stmt->execute([$database]);
        $tables = [];
        $tableHashes = [];
        $schemaParts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $table) {
            $table = (string) $table;
            if ($forRolloverGate && in_array($table, self::GATE_EXCLUDED_TABLES, true)) {
                continue;
            }
            $quoted = $this->quoteIdentifier($table);
            $tables[$table] = (int) $connection->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
            // Keep SHOW metadata inspection distinct from runtime DDL scanners: this never creates a table.
            $create = $connection->query('SHOW CREATE ' . 'TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC) ?: [];
            $createColumns = array_values($create);
            $schemaParts[$table] = preg_replace('/AUTO_INCREMENT=\d+\s*/', '', (string) ($createColumns[1] ?? ''));
            $tableHashes[$table] = $this->tableContentHash($connection, $table);
        }
        $schemaFingerprint = hash('sha256', $this->canonicalJson($schemaParts));
        return [
            'tables' => $tables,
            'table_hashes' => $tableHashes,
            'schema_fingerprint' => $schemaFingerprint,
            'fingerprint' => hash('sha256', $this->canonicalJson([
                'schema' => $schemaFingerprint,
                'tables' => $tables,
                'table_hashes' => $tableHashes,
            ])),
        ];
    }

    private function tableContentHash(PDO $connection, string $table): string
    {
        $quoted = $this->quoteIdentifier($table);
        $columnsStmt = $connection->query('SHOW COLUMNS FROM ' . $quoted);
        $columns = $columnsStmt ? ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $primary = [];
        foreach ($columns as $column) {
            if ((string) ($column['Key'] ?? '') === 'PRI') {
                $primary[] = (string) $column['Field'];
            }
        }
        $order = $primary
            ? ' ORDER BY ' . implode(', ', array_map(fn(string $name): string => $this->quoteIdentifier($name), $primary))
            : '';
        $stmt = $connection->query('SELECT * FROM ' . $quoted . $order);
        $hash = hash_init('sha256');
        $unorderedRowHashes = [];
        while ($stmt && ($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rowHash = $this->rowContentHash($row);
            if ($primary) {
                hash_update($hash, $rowHash . "\n");
            } else {
                $unorderedRowHashes[] = $rowHash;
            }
        }
        if (!$primary) {
            sort($unorderedRowHashes, SORT_STRING);
            foreach ($unorderedRowHashes as $rowHash) {
                hash_update($hash, $rowHash . "\n");
            }
        }
        return hash_final($hash);
    }

    private function rowContentHash(array $row): string
    {
        $hash = hash_init('sha256');
        foreach ($row as $column => $value) {
            $column = (string) $column;
            hash_update($hash, strlen($column) . ':' . $column . '=');
            if ($value === null) {
                hash_update($hash, "N;\n");
                continue;
            }
            $bytes = (string) $value;
            hash_update($hash, 'S' . strlen($bytes) . ':' . $bytes . ";\n");
        }
        return hash_final($hash);
    }

    private function fileInventory(): array
    {
        $files = [];
        foreach ($this->dataRoots as $label => $root) {
            $root = rtrim((string) $root, "\\/");
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $info) {
                if (!$info instanceof SplFileInfo || !$info->isFile() || $info->isLink()) {
                    continue;
                }
                $path = $info->getPathname();
                $relative = str_replace('\\', '/', ltrim(substr($path, strlen($root)), "\\/"));
                $files[] = [
                    'root' => (string) $label,
                    'path' => $relative,
                    'size' => $info->getSize(),
                    'sha256' => (string) hash_file('sha256', $path),
                ];
            }
        }
        usort($files, static fn(array $a, array $b): int => strcmp(
            $a['root'] . '/' . $a['path'],
            $b['root'] . '/' . $b['path']
        ));
        return [
            'files' => $files,
            'fingerprint' => hash('sha256', $this->canonicalJson($files)),
        ];
    }

    private function verifyExtractedFiles(string $temporaryDir, array $files): void
    {
        foreach ($files as $file) {
            if (!is_array($file) || !isset($file['root'], $file['path'], $file['sha256'])) {
                throw new RuntimeException('بيان ملفات حزمة التعافي غير صالح.');
            }
            $relative = (string) $file['path'];
            if ($relative === '' || strpos($relative, '..') !== false || preg_match('~^(?:[A-Za-z]:|[\\\\/])~', $relative)) {
                throw new RuntimeException('مسار ملف غير آمن داخل حزمة التعافي.');
            }
            $path = $temporaryDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR
                . (string) $file['root'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path)
                || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $path))) {
                throw new RuntimeException('فشل تحقق أحد ملفات البيانات المستعادة.');
            }
        }
    }

    private function assertSafeZipEntries(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || strpos($name, '..') !== false || preg_match('~^(?:[A-Za-z]:|[\\\\/])~', $name)) {
                throw new RuntimeException('حزمة التعافي تحتوي على مسار غير آمن.');
            }
        }
    }

    private function dumpDatabase(string $outputFile): void
    {
        $binary = dirname(dirname($this->projectRoot)) . DIRECTORY_SEPARATOR . 'mysql'
            . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe';
        if (!is_file($binary)) {
            throw new RuntimeException('أداة تصدير قاعدة البيانات غير متاحة.');
        }
        $this->runProcess([
            $binary,
            '--host=' . DB_HOST,
            '--user=' . DB_USERNAME,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--skip-dump-date',
            '--result-file=' . $outputFile,
            $this->databaseName(),
        ]);
        if (!is_file($outputFile) || filesize($outputFile) === 0) {
            throw new RuntimeException('ملف نسخة قاعدة البيانات فارغ.');
        }
    }

    private function importDatabase(string $dumpFile, string $database): void
    {
        $binary = dirname(dirname($this->projectRoot)) . DIRECTORY_SEPARATOR . 'mysql'
            . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe';
        if (!is_file($binary)) {
            throw new RuntimeException('أداة استعادة قاعدة البيانات غير متاحة.');
        }
        $this->runProcess([
            $binary,
            '--host=' . DB_HOST,
            '--user=' . DB_USERNAME,
            '--database=' . $database,
            '--execute=source ' . str_replace('\\', '/', $dumpFile),
        ]);
    }

    private function runProcess(array $command): void
    {
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['MYSQL_PWD'] = (string) DB_PASSWORD;
        $process = proc_open($command, [
            0 => ['file', 'NUL', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('تعذر بدء أداة قاعدة البيانات.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            error_log('Recovery database subprocess failed: ' . trim((string) ($stderr ?: $stdout)));
            throw new RuntimeException('فشلت أداة قاعدة البيانات أثناء النسخ أو الاستعادة.');
        }
    }

    private function adminConnection(): PDO
    {
        return new PDO(
            'mysql:host=' . DB_HOST . ';charset=utf8mb4',
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function databaseConnection(string $database): PDO
    {
        return new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . $database . ';charset=utf8mb4',
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function databaseExists(PDO $admin, string $database): bool
    {
        $stmt = $admin->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
        $stmt->execute([$database]);
        return (bool) $stmt->fetchColumn();
    }

    private function findByKey(string $backupKey): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $backupKey)) {
            throw new InvalidArgumentException('معرف حزمة التعافي غير صالح.');
        }
        $stmt = $this->db->prepare('SELECT * FROM recovery_backups WHERE backup_key = ? LIMIT 1');
        $stmt->execute([$backupKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('حزمة التعافي المطلوبة غير موجودة.');
        }
        return $row;
    }

    private function markFailed(int $backupId, string $failureCode): void
    {
        try {
            $this->db->prepare("UPDATE recovery_backups SET status = 'failed', failure_code = ? WHERE id = ?")
                ->execute([$failureCode, $backupId]);
            (new AuditService($this->db))->recordEvent('failure', 'recovery_backup', $backupId, null, [
                'failure_code' => $failureCode,
                'direct_undo_available' => false,
            ]);
        } catch (Throwable $auditFailure) {
            error_log('Recovery failure status could not be persisted.');
        }
    }

    private function assertSchemaReady(): void
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recovery_backups'");
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('مخطط حزم التعافي غير جاهز. شغّل migration المطلوب أولاً.');
        }
    }

    private function absolutePackagePath(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || strpos($relative, '..') !== false || preg_match('/^[A-Za-z]:|^\//', $relative)) {
            throw new RuntimeException('مسار حزمة التعافي غير صالح.');
        }
        $absolute = $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $expected = rtrim(str_replace('\\', '/', $this->backupRoot), '/') . '/';
        if (strpos(str_replace('\\', '/', $absolute), $expected) !== 0) {
            throw new RuntimeException('حزمة التعافي خارج التخزين المحمي.');
        }
        return $absolute;
    }

    private function relativeToProject(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->projectRoot), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        if (strpos($normalized, $root) !== 0) {
            throw new RuntimeException('مسار حزمة التعافي خارج المشروع.');
        }
        return substr($normalized, strlen($root));
    }

    private function databaseName(): string
    {
        return (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
    }

    private function canonicalJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('تعذر ترميز بيان التعافي.');
        }
        return $json;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('معرف قاعدة البيانات غير صالح.');
        }
        return chr(96) . $identifier . chr(96);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('تعذر إنشاء مجلد التعافي المحمي.');
        }
    }

    private function removeOwnedDirectory(string $path): void
    {
        $root = rtrim(str_replace('\\', '/', $this->backupRoot), '/') . '/';
        $normalized = rtrim(str_replace('\\', '/', $path), '/') . '/';
        if (strpos($normalized, $root) !== 0 || !is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
