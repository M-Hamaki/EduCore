<?php

declare(strict_types=1);

final class StaffHrAcceptanceReport
{
    private const DATASET_ID = 'staff_hr_acceptance_v1';
    private const VERSION = '2026.08.11-1';
    private const STATUSES = ['passed', 'failed', 'blocked'];

    /** @return array{index:array<string,mixed>,markdown:string} */
    public function generate(string $inputDirectory, string $outputDirectory, ?string $generatedAt = null): array
    {
        $input = realpath($inputDirectory);
        if ($input === false || !is_dir($input)) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_REPORT_INPUT_INVALID');
        }
        $files = glob($input . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files, SORT_STRING);
        $results = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($this->extractResults($decoded) as $result) {
                $validated = $this->validateResult($result);
                $results[$validated['scenario_id']] = $validated;
            }
        }
        ksort($results, SORT_STRING);
        $expected = array_map(static fn (int $number): string => sprintf('Q%02d', $number), range(1, 33));
        $missing = array_values(array_diff($expected, array_keys($results)));
        $counts = ['passed' => 0, 'failed' => 0, 'blocked' => 0, 'missing' => count($missing)];
        foreach ($results as $result) {
            ++$counts[$result['status']];
        }
        $index = $this->sanitize([
            'dataset_id' => self::DATASET_ID,
            'dataset_version' => self::VERSION,
            'generated_at' => $generatedAt ?: gmdate('c'),
            'counts' => $counts,
            'missing_scenarios' => $missing,
            'results' => array_values($results),
        ]);
        $markdown = $this->markdown($index);
        $this->assertOutputDirectory($outputDirectory);
        $this->atomicWrite($outputDirectory . DIRECTORY_SEPARATOR . 'evidence-index.json', json_encode(
            $index,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ) . PHP_EOL);
        $this->atomicWrite($outputDirectory . DIRECTORY_SEPARATOR . 'result-report.md', $markdown);
        return ['index' => $index, 'markdown' => $markdown];
    }

    /** @return list<array<string,mixed>> */
    private function extractResults(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_REPORT_JSON_INVALID');
        }
        if (isset($decoded['results']) && is_array($decoded['results'])) {
            return array_values(array_filter($decoded['results'], 'is_array'));
        }
        return isset($decoded['scenario_id']) ? [$decoded] : [];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function validateResult(array $result): array
    {
        $scenario = (string) ($result['scenario_id'] ?? '');
        $status = (string) ($result['status'] ?? '');
        if (($result['dataset_id'] ?? null) !== self::DATASET_ID
            || ($result['dataset_version'] ?? null) !== self::VERSION
            || preg_match('/^Q(?:0[1-9]|[12][0-9]|3[0-3])$/D', $scenario) !== 1
            || !in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_REPORT_RESULT_INVALID');
        }
        $clean = $this->sanitize($result);
        $references = (array) ($clean['evidence']['references'] ?? []);
        foreach ($references as $reference) {
            $this->assertSafeReference((string) $reference);
        }
        return $clean;
    }

    private function sanitize(mixed $value, string $key = ''): mixed
    {
        if (preg_match('/password|secret|token|cookie|session|authorization/i', $key) === 1) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $itemKey => $item) {
                $clean[$itemKey] = $this->sanitize($item, (string) $itemKey);
            }
            return $clean;
        }
        if (!is_string($value)) {
            return $value;
        }
        if (preg_match('/PHPSESSID=|Bearer\s+|STAFF_HR_ACCEPTANCE_PASSWORD|SQLSTATE\[|stack\s*trace/i', $value) === 1) {
            return '[REDACTED]';
        }
        return $value;
    }

    private function assertSafeReference(string $reference): void
    {
        $normalized = str_replace('\\', '/', trim($reference));
        if ($normalized === ''
            || str_contains($normalized, '..')
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//D', $normalized) === 1
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $normalized) === 1) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_EVIDENCE_REFERENCE_INVALID');
        }
    }

    /** @param array<string,mixed> $index */
    private function markdown(array $index): string
    {
        $counts = (array) $index['counts'];
        $lines = [
            '# تقرير قبول منظومة شؤون العاملين',
            '',
            '- معرف الحزمة: `' . self::DATASET_ID . '`',
            '- الإصدار: `' . self::VERSION . '`',
            '- وقت الإنشاء: `' . (string) $index['generated_at'] . '`',
            '- ناجح: **' . (int) $counts['passed'] . '** — فاشل: **' . (int) $counts['failed']
                . '** — متوقف: **' . (int) $counts['blocked'] . '** — غير منفذ: **' . (int) $counts['missing'] . '**',
            '',
            '| السيناريو | الشخصية | الحالة | مرجع الدليل |',
            '|---|---|---|---|',
        ];
        foreach ((array) $index['results'] as $result) {
            $references = (array) ($result['evidence']['references'] ?? []);
            $lines[] = '| ' . $this->cell((string) $result['scenario_id'])
                . ' | ' . $this->cell((string) ($result['persona'] ?? '—'))
                . ' | ' . $this->cell((string) $result['status'])
                . ' | ' . $this->cell(implode('، ', array_map('strval', $references)) ?: '—') . ' |';
        }
        if ($index['missing_scenarios'] !== []) {
            $lines[] = '';
            $lines[] = '## سيناريوهات غير منفذة';
            $lines[] = '';
            $lines[] = implode('، ', (array) $index['missing_scenarios']);
        }
        $lines[] = '';
        $lines[] = '> التقرير منقح تلقائيًا ولا يجوز إرفاق كلمات مرور أو Cookies أو tokens أو تفاصيل سرية.';
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function cell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }

    private function assertOutputDirectory(string $outputDirectory): void
    {
        $root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'staff-hr-acceptance';
        $normalizedRoot = strtolower(str_replace('\\', '/', rtrim($root, '/\\')));
        $normalizedOutput = strtolower(str_replace('\\', '/', rtrim($outputDirectory, '/\\')));
        if (str_contains('/' . $normalizedOutput . '/', '/../')
            || !str_starts_with($normalizedOutput . '/', $normalizedRoot . '/')) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_REPORT_OUTPUT_REFUSED');
        }
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_REPORT_ROOT_CREATE_FAILED');
        }
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0770, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_REPORT_OUTPUT_CREATE_FAILED');
        }
        $resolvedRoot = realpath($root);
        $resolvedOutput = realpath($outputDirectory);
        if ($resolvedRoot === false || $resolvedOutput === false
            || !str_starts_with($resolvedOutput . DIRECTORY_SEPARATOR, $resolvedRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_REPORT_OUTPUT_REFUSED');
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), '.acceptance-report-');
        if ($temporary === false || file_put_contents($temporary, $contents, LOCK_EX) === false) {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_REPORT_WRITE_FAILED');
        }
        if (!@rename($temporary, $path)) {
            if (!is_file($path) || !unlink($path) || !rename($temporary, $path)) {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
                throw new RuntimeException('STAFF_HR_ACCEPTANCE_REPORT_WRITE_FAILED');
            }
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = getopt('', ['input-dir:', 'output-dir::', 'generated-at::']);
        $inputDirectory = (string) ($options['input-dir'] ?? '');
        $outputDirectory = (string) ($options['output-dir'] ?? dirname(__DIR__)
            . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'staff-hr-acceptance' . DIRECTORY_SEPARATOR . 'report');
        if ($inputDirectory === '') {
            throw new RuntimeException('STAFF_HR_ACCEPTANCE_REPORT_INPUT_REQUIRED');
        }
        $report = (new StaffHrAcceptanceReport())->generate(
            $inputDirectory,
            $outputDirectory,
            isset($options['generated-at']) ? (string) $options['generated-at'] : null
        );
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'counts' => $report['index']['counts'],
            'output_directory' => $outputDirectory,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
