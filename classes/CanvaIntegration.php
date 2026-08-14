<?php
require_once __DIR__ . '/SchemaReadinessGuard.php';
require_once __DIR__ . '/../src/Modules/Operations/Audit/AuditService.php';
/**
 * CanvaIntegration — كلاس تكامل Canva Connect API
 *
 * يدير OAuth 2.0 بـ PKCE، التوكنات، جلب القوالب، وتصدير PPTX.
 *
 * @requires PHP 7.4+, cURL, PDO
 */
class CanvaIntegration
{
    const API_BASE     = 'https://api.canva.com/rest/v1';
    const AUTH_URL     = 'https://www.canva.com/api/oauth/authorize';
    const TOKEN_URL    = 'https://api.canva.com/rest/v1/oauth/token';

    // الـ Scopes المطلوبة — يجب تفعيلها في لوحة المطوّر
    const SCOPES       = 'design:content:read design:meta:read brandtemplate:meta:read brandtemplate:content:read design:content:write';

    // مهلة انتظار مهمة التصدير (ثانية)
    const EXPORT_TIMEOUT_SEC  = 90;
    const EXPORT_POLL_DELAY   = 3; // ثواني بين كل استطلاع
    const AUTOFILL_TIMEOUT_SEC = 90;
    const AUTOFILL_POLL_DELAY  = 3;

    /** @var PDO */
    private $db;

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct(PDO $db)
    {
        $this->db           = $db;
        $this->clientId     = env('CANVA_CLIENT_ID', '');
        $this->clientSecret = env('CANVA_CLIENT_SECRET', '');
        $this->redirectUri  = env('CANVA_REDIRECT_URI', '');
        $this->ensureTemplateSchema();
    }

    // =========================================================
    // OAuth PKCE Helpers
    // =========================================================

    /** ينشئ code_verifier عشوائي (43-128 حرف) */
    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** يحوّل code_verifier إلى code_challenge بـ S256 */
    private function generateCodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    // =========================================================
    // Authorization Flow
    // =========================================================

    /**
     * يُنشئ رابط التفويض ويحفظ الـ PKCE state في الجلسة
     * يُعيد URL كامل للتوجيه إلى Canva
     */
    public function getAuthorizationUrl(): string
    {
        $verifier  = $this->generateCodeVerifier();
        $challenge = $this->generateCodeChallenge($verifier);
        $state     = bin2hex(random_bytes(16));

        $_SESSION['canva_code_verifier'] = $verifier;
        $_SESSION['canva_oauth_state']   = $state;

        return self::AUTH_URL . '?' . http_build_query([
            'code_challenge_method' => 'S256',
            'response_type'         => 'code',
            'client_id'             => $this->clientId,
            'redirect_uri'          => $this->redirectUri,
            'scope'                 => self::SCOPES,
            'code_challenge'        => $challenge,
            'state'                 => $state,
        ]);
    }

    /**
     * يعالج callback OAuth — يتحقق من state ويستبدل code بـ tokens
     *
     * @param  string $code  المُرسَل من Canva
     * @param  string $state المُرسَل من Canva
     * @return bool
     */
    public function handleCallback(string $code, string $state): bool
    {
        // التحقق من CSRF state
        if (empty($_SESSION['canva_oauth_state']) ||
            !hash_equals($_SESSION['canva_oauth_state'], $state)) {
            error_log('Canva OAuth: state mismatch');
            return false;
        }

        $verifier = $_SESSION['canva_code_verifier'] ?? '';
        if ($verifier === '') {
            error_log('Canva OAuth: missing code_verifier in session');
            return false;
        }

        // استبدال code بـ tokens
        $response = $this->callTokenEndpoint([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => $this->redirectUri,
        ]);

        if (!$response || empty($response['access_token'])) {
            error_log('Canva OAuth: token exchange failed');
            return false;
        }

        $this->saveTokens($response);

        unset($_SESSION['canva_code_verifier'], $_SESSION['canva_oauth_state']);
        return true;
    }

    // =========================================================
    // Token Management
    // =========================================================

    /** يحفظ التوكنات في جدول canva_oauth_tokens (صف واحد دائماً) */
    private function saveTokens(array $data): void
    {
        $expiresAt = isset($data['expires_in'])
            ? time() + (int)$data['expires_in']
            : null;

        $ownsTransaction = !$this->db->inTransaction();
        try {
        if ($ownsTransaction) $this->db->beginTransaction();
        $this->db->exec('DELETE FROM canva_oauth_tokens');
        $stmt = $this->db->prepare('
            INSERT INTO canva_oauth_tokens
                (access_token, refresh_token, expires_at, scope)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['access_token'],
            $data['refresh_token'] ?? null,
            $expiresAt,
            $data['scope'] ?? self::SCOPES,
        ]);
        $scopes = preg_split('/\s+/', trim((string)($data['scope'] ?? self::SCOPES))) ?: [];
        (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
            'credential_update', 'canva_connection', null, 'Canva OAuth', [
                'scope_count' => count(array_filter($scopes)),
                'expires_at' => $expiresAt,
                'has_refresh_token' => !empty($data['refresh_token']),
                'direct_undo_available' => false,
            ]
        );
        if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * يُعيد access_token صالح — يُجدد تلقائياً إذا اقترب الانتهاء
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        $row = $this->db
            ->query('SELECT * FROM canva_oauth_tokens LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        // تجديد قبل 5 دقائق من الانتهاء
        if ($row['expires_at'] && (int)$row['expires_at'] < time() + 300) {
            if (!empty($row['refresh_token'])) {
                return $this->performRefresh($row['refresh_token']);
            }
            return null;
        }

        return $row['access_token'];
    }

    /** تجديد التوكن */
    private function performRefresh(string $refreshToken): ?string
    {
        $response = $this->callTokenEndpoint([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (!$response || empty($response['access_token'])) {
            error_log('Canva OAuth: refresh token failed');
            return null;
        }

        $this->saveTokens($response);
        return $response['access_token'];
    }

    /** هل الحساب متصل وله توكن صالح؟ */
    public function isConnected(): bool
    {
        return $this->getAccessToken() !== null;
    }

    /** الصلاحيات المحفوظة مع التوكن الحالي */
    public function getStoredScopes(): array
    {
        $row = $this->db
            ->query('SELECT scope FROM canva_oauth_tokens LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['scope'])) {
            return [];
        }

        return preg_split('/\s+/', trim($row['scope'])) ?: [];
    }

    /** هل التوكن الحالي يحتوي كل الصلاحيات المطلوبة؟ */
    public function hasRequiredScopes(): bool
    {
        $stored = $this->getStoredScopes();
        if (!$stored) {
            return false;
        }

        $required = preg_split('/\s+/', trim(self::SCOPES)) ?: [];
        return empty(array_diff($required, $stored));
    }

    /** الصلاحيات المطلوبة وغير الموجودة في التوكن الحالي */
    public function getMissingScopes(): array
    {
        $required = preg_split('/\s+/', trim(self::SCOPES)) ?: [];
        $stored = $this->getStoredScopes();

        return array_values(array_diff($required, $stored));
    }

    /** قطع الاتصال وحذف التوكنات */
    public function disconnect(): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $beforeStmt = $this->db->query('SELECT * FROM canva_templates WHERE is_active = 1 ORDER BY id FOR UPDATE');
            $beforeRows = $beforeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $this->db->exec('DELETE FROM canva_oauth_tokens');
            $this->db->exec('UPDATE canva_templates SET is_active = 0');
            $items = [];
            foreach ($beforeRows as $before) {
                $afterStmt = $this->db->prepare('SELECT * FROM canva_templates WHERE id = ?');
                $afterStmt->execute([(int)$before['id']]);
                $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($before != $after) {
                    $items[] = ['table' => 'canva_templates', 'record_id' => $before['id'], 'before' => $before, 'after' => $after, 'description' => 'إلغاء تنشيط قالب عند قطع Canva'];
                }
            }
            $batchId = UndoManager::newBatchId();
            $audit = new \EduCore\Modules\Operations\Audit\AuditService($this->db);
            if ($items) {
                $audit->recordCompositeUpdate('canva_connection', null, 'Canva OAuth', $items, ['summary' => 'قطع اتصال Canva وإلغاء القالب النشط'], $batchId);
            }
            $audit->recordEvent('disconnect', 'canva_connection', null, 'Canva OAuth', [
                'deactivated_template_count' => count($items), 'direct_undo_available' => false,
            ], ['batch_id' => $batchId]);
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // =========================================================
    // Designs API
    // =========================================================

    /**
     * يُعيد قائمة تصميمات المستخدم
     *
     * @param  int         $limit
     * @param  string|null $continuation رمز الصفحة التالية
     * @return array ['designs' => [], 'continuation' => string|null]
     */
    public function listDesigns(int $limit = 24, ?string $continuation = null): array
    {
        $token = $this->getAccessToken();
        if (!$token) return ['designs' => [], 'continuation' => null];

        $url = self::API_BASE . '/designs?limit=' . $limit;
        if ($continuation) {
            $url .= '&continuation=' . urlencode($continuation);
        }

        $data = $this->apiGet($url, $token);
        return [
            'designs'      => $data['items'] ?? [],
            'continuation' => $data['continuation'] ?? null,
        ];
    }

    /**
     * يُعيد قوالب Brand Templates القابلة للتعبئة من Canva.
     *
     * @return array ['templates' => [], 'continuation' => string|null]
     */
    public function listBrandTemplates(int $limit = 24, ?string $continuation = null, string $dataset = 'non_empty'): array
    {
        $token = $this->getAccessToken();
        if (!$token) return ['templates' => [], 'continuation' => null];

        $query = [
            'limit' => max(1, min(100, $limit)),
            'dataset' => $dataset,
        ];
        if ($continuation) {
            $query['continuation'] = $continuation;
        }

        $data = $this->apiGet(self::API_BASE . '/brand-templates?' . http_build_query($query), $token);
        return [
            'templates' => $data['items'] ?? [],
            'continuation' => $data['continuation'] ?? null,
            'raw' => $data,
        ];
    }

    /** جلب Dataset لقالب Brand Template */
    public function getBrandTemplateDataset(string $brandTemplateId): array
    {
        $token = $this->getAccessToken();
        if (!$token) return [];

        $data = $this->apiGet(self::API_BASE . '/brand-templates/' . rawurlencode($brandTemplateId) . '/dataset', $token);
        if (!is_array($data)) {
            return [];
        }

        return $data['dataset'] ?? $data['fields'] ?? $data;
    }

    /**
     * إنشاء تصميم من Brand Template عبر Autofill ثم تصديره كـ PPTX.
     *
     * @return array ['success'=>bool,'path'=>string|null,'design_id'=>string|null,'error'=>string|null]
     */
    public function autofillBrandTemplateAsPptx(string $brandTemplateId, string $title, array $lessonData): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'path' => null, 'design_id' => null, 'error' => 'غير متصل بـ Canva'];
        }

        $dataset = $this->getBrandTemplateDataset($brandTemplateId);
        $autofillData = $this->buildAutofillData($dataset, $lessonData);
        if (empty($autofillData)) {
            return ['success' => false, 'path' => null, 'design_id' => null, 'error' => 'قالب Canva لا يحتوي حقول نصية قابلة للتعبئة'];
        }

        $operationId = $this->auditExternalIntent('autofill', $brandTemplateId);
        $job = $this->apiPost(self::API_BASE . '/autofills', [
            'type' => 'create_from_brand_template',
            'brand_template_id' => $brandTemplateId,
            'title' => mb_substr($title ?: 'Lesson PowerPoint', 0, 255),
            'data' => $autofillData,
        ], $token);

        if (empty($job['job']['id'])) {
            $errMsg = $job['message'] ?? $job['error']['message'] ?? json_encode($job, JSON_UNESCAPED_UNICODE);
            return $this->auditExternalOutcome($operationId, 'autofill', $brandTemplateId, ['success' => false, 'path' => null, 'design_id' => null, 'error' => 'فشل بدء Canva Autofill: ' . $errMsg]);
        }

        $jobId = $job['job']['id'];
        $deadline = time() + self::AUTOFILL_TIMEOUT_SEC;
        while (time() < $deadline) {
            sleep(self::AUTOFILL_POLL_DELAY);

            $status = $this->apiGet(self::API_BASE . '/autofills/' . rawurlencode($jobId), $token);
            $jobStatus = $status['job']['status'] ?? 'unknown';

            if ($jobStatus === 'success') {
                $design = $status['job']['result']['design'] ?? [];
                $designId = $design['id'] ?? null;
                if (!$designId) {
                    return $this->auditExternalOutcome($operationId, 'autofill', $brandTemplateId, ['success' => false, 'path' => null, 'design_id' => null, 'error' => 'Canva أنشأ التصميم لكن لم يُعد معرّف التصميم']);
                }

                $export = $this->exportDesignAsPptx($designId, $design['title'] ?? $title);
                return $this->auditExternalOutcome($operationId, 'autofill', $brandTemplateId, [
                    'success' => (bool)$export['success'],
                    'path' => $export['path'] ?? null,
                    'design_id' => $designId,
                    'error' => $export['error'] ?? null,
                ]);
            }

            if ($jobStatus === 'failed') {
                $errMsg = $status['job']['error']['message'] ?? 'خطأ غير معروف في Canva Autofill';
                return $this->auditExternalOutcome($operationId, 'autofill', $brandTemplateId, ['success' => false, 'path' => null, 'design_id' => null, 'error' => $errMsg]);
            }
        }

        return $this->auditExternalOutcome($operationId, 'autofill', $brandTemplateId, ['success' => false, 'path' => null, 'design_id' => null, 'error' => 'انتهت مهلة Canva Autofill']);
    }

    /**
     * يُصدِّر تصميماً كـ PPTX وينزّله محلياً
     *
     * @param  string $designId
     * @param  string $designName اسم التصميم (للحفظ)
     * @return array ['success'=>bool, 'path'=>string|null, 'error'=>string|null]
     */
    public function exportDesignAsPptx(string $designId, string $designName = ''): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'path' => null, 'error' => 'غير متصل بـ Canva'];
        }

        $operationId = $this->auditExternalIntent('export_pptx', $designId);
        // بدء مهمة التصدير
        $job = $this->apiPost(self::API_BASE . '/exports', [
            'design_id' => $designId,
            'format'    => ['type' => 'pptx'],
        ], $token);

        if (empty($job['job']['id'])) {
            $errMsg = $job['message'] ?? json_encode($job);
            error_log("Canva export job failed for $designId: $errMsg");
            return $this->auditExternalOutcome($operationId, 'export_pptx', $designId, ['success' => false, 'path' => null, 'error' => 'فشل بدء التصدير: ' . $errMsg]);
        }

        $jobId    = $job['job']['id'];
        $deadline = time() + self::EXPORT_TIMEOUT_SEC;

        // الاستطلاع حتى الانتهاء
        while (time() < $deadline) {
            sleep(self::EXPORT_POLL_DELAY);

            $status = $this->apiGet(self::API_BASE . '/exports/' . $jobId, $token);
            $jobStatus = $status['job']['status'] ?? 'unknown';

            if ($jobStatus === 'success') {
                $downloadUrl = $status['job']['urls'][0] ?? null;
                if (!$downloadUrl) {
                    return $this->auditExternalOutcome($operationId, 'export_pptx', $designId, ['success' => false, 'path' => null, 'error' => 'لا يوجد رابط تنزيل']);
                }
                $localPath = $this->downloadPptx($downloadUrl, $designId);
                if ($localPath) {
                    return $this->auditExternalOutcome($operationId, 'export_pptx', $designId, ['success' => true, 'path' => $localPath, 'error' => null]);
                }
                return $this->auditExternalOutcome($operationId, 'export_pptx', $designId, ['success' => false, 'path' => null, 'error' => 'فشل تنزيل الملف']);
            }

            if ($jobStatus === 'failed') {
                $errMsg = $status['job']['error']['message'] ?? 'خطأ غير معروف';
                error_log("Canva export failed for $designId: $errMsg");
                return $this->auditExternalOutcome($operationId, 'export_pptx', $designId, ['success' => false, 'path' => null, 'error' => 'فشل التصدير: ' . $errMsg]);
            }
        }

        return $this->auditExternalOutcome($operationId, 'export_pptx', $designId, ['success' => false, 'path' => null, 'error' => 'انتهت مهلة التصدير']);
    }

    /**
     * يُنزّل ملف PPTX إلى storage/canva_templates/
     *
     * @return string|null المسار النسبي بعد الجذر أو null عند الفشل
     */
    private function downloadPptx(string $url, string $designId): ?string
    {
        $dir = dirname(__DIR__) . '/storage/canva_templates/';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            error_log('Canva: cannot create storage dir ' . $dir);
            return null;
        }

        $filename = 'canva_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $designId) . '.pptx';
        $fullPath  = $dir . $filename;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $data     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($data === false || $httpCode !== 200) {
            error_log("Canva PPTX download failed ($httpCode): $curlErr");
            return null;
        }

        file_put_contents($fullPath, $data);
        return 'storage/canva_templates/' . $filename;
    }

    // =========================================================
    // Database Helpers (canva_templates)
    // =========================================================

    /** يحفظ/يُحدّث معلومات قالب في قاعدة البيانات */
    public function saveTemplate(string $designId, string $name, string $thumbnailUrl, ?string $localPath): void
    {
        $this->mutateTemplate($designId, function () use ($designId, $name, $thumbnailUrl, $localPath): void {
        $stmt = $this->db->prepare('
            INSERT INTO canva_templates (design_id, template_type, name, thumbnail_url, pptx_local_path)
            VALUES (?, "design", ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                template_type    = "design",
                name            = VALUES(name),
                thumbnail_url   = VALUES(thumbnail_url),
                pptx_local_path = COALESCE(VALUES(pptx_local_path), pptx_local_path),
                updated_at      = NOW()
        ');
        $stmt->execute([$designId, $name, $thumbnailUrl, $localPath]);
        }, 'حفظ قالب تصميم Canva');
    }

    /** يحفظ Brand Template من Canva لاستخدامه في Autofill */
    public function saveBrandTemplate(string $brandTemplateId, string $name, string $thumbnailUrl = '', array $dataset = []): void
    {
        $this->mutateTemplate($brandTemplateId, function () use ($brandTemplateId, $name, $thumbnailUrl, $dataset): void {
        $stmt = $this->db->prepare('
            INSERT INTO canva_templates (design_id, template_type, name, thumbnail_url, dataset_json)
            VALUES (?, "brand_template", ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                template_type  = "brand_template",
                name           = VALUES(name),
                thumbnail_url  = VALUES(thumbnail_url),
                dataset_json   = VALUES(dataset_json),
                updated_at     = NOW()
        ');
        $stmt->execute([
            $brandTemplateId,
            $name,
            $thumbnailUrl,
            json_encode($dataset, JSON_UNESCAPED_UNICODE),
        ]);
        }, 'حفظ قالب علامة تجارية من Canva');
    }

    /** يُعيّن قالباً كنشط (يلغي تنشيط الباقي) */
    public function setActiveTemplate(int $id): void
    {
        $this->setTemplateActivation($id);
    }

    /** يُلغي تنشيط القالب الحالي */
    public function clearActiveTemplate(): void
    {
        $this->setTemplateActivation(null);
    }

    /** يُعيد القالب النشط أو null */
    public function getActiveTemplate(): ?array
    {
        $row = $this->db
            ->query('SELECT * FROM canva_templates WHERE is_active = 1 LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** يُعيد جميع القوالب المحفوظة */
    public function getAllTemplates(): array
    {
        return $this->db
            ->query('SELECT * FROM canva_templates ORDER BY updated_at DESC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** يحذف قالباً ومحتواه المحلي */
    public function deleteTemplate(int $id): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $stmt = $this->db->prepare('SELECT * FROM canva_templates WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                $this->db->prepare('DELETE FROM canva_templates WHERE id = ?')->execute([$id]);
                (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordDelete(
                    'canva_template', 'canva_templates', $id, (string)$row['name'], $row, 'حذف قالب Canva وملفه المحلي'
                );
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        if ($row && $row['template_type'] === 'design' && $row['pptx_local_path']) {
            $fullPath = dirname(__DIR__) . '/' . $row['pptx_local_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

    }

    private function mutateTemplate(string $designId, callable $mutation, string $description): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $stmt = $this->db->prepare('SELECT * FROM canva_templates WHERE design_id = ? FOR UPDATE');
            $stmt->execute([$designId]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $mutation();
            $stmt = $this->db->prepare('SELECT * FROM canva_templates WHERE design_id = ?');
            $stmt->execute([$designId]);
            $after = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$after) throw new RuntimeException('Canva template could not be reloaded after save.');
            $audit = new \EduCore\Modules\Operations\Audit\AuditService($this->db);
            if ($before === null) {
                $audit->recordInsert('canva_template', 'canva_templates', (int)$after['id'], (string)$after['name'], $after, $description);
            } elseif ($before != $after) {
                $audit->recordUpdate('canva_template', 'canva_templates', (int)$after['id'], (string)$after['name'], $before, $after, $description);
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function setTemplateActivation(?int $activeId): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $beforeRows = $this->db->query('SELECT * FROM canva_templates ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $this->db->exec('UPDATE canva_templates SET is_active = 0');
            if ($activeId !== null) {
                $stmt = $this->db->prepare('UPDATE canva_templates SET is_active = 1 WHERE id = ?');
                $stmt->execute([$activeId]);
                if ($stmt->rowCount() === 0) throw new InvalidArgumentException('قالب Canva المحدد غير موجود.');
            }
            $afterRows = $this->db->query('SELECT * FROM canva_templates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $afterById = [];
            foreach ($afterRows as $row) $afterById[(int)$row['id']] = $row;
            $items = [];
            foreach ($beforeRows as $before) {
                $after = $afterById[(int)$before['id']] ?? [];
                if ($before != $after) {
                    $items[] = ['table' => 'canva_templates', 'record_id' => $before['id'], 'before' => $before, 'after' => $after, 'description' => 'تغيير قالب Canva النشط'];
                }
            }
            if ($items) {
                (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordCompositeUpdate(
                    'canva_template_activation', $activeId, 'قالب Canva النشط', $items,
                    ['summary' => $activeId === null ? 'إلغاء القالب النشط' : 'تعيين قالب نشط', 'affected_count' => count($items)]
                );
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** التحقق من جاهزية مخطط Canva دون تعديله أثناء الطلب. */
    private function ensureTemplateSchema(): void
    {
        (new SchemaReadinessGuard($this->db))->assertColumns(
            'canva_templates',
            ['template_type', 'dataset_json', 'last_error']
        );
    }

    private function buildAutofillData(array $dataset, array $lessonData): array
    {
        $fields = $this->normalizeDatasetFields($dataset);
        if (!$fields) {
            return [];
        }

        $values = $this->buildLessonTextValues($lessonData);
        $result = [];
        $fallbackIndex = 0;

        foreach ($fields as $fieldName => $fieldType) {
            if ($fieldType !== 'text') {
                continue;
            }

            $text = $this->resolveAutofillTextForField($fieldName, $values, $fallbackIndex);
            if ($text === '') {
                continue;
            }

            $result[$fieldName] = [
                'type' => 'text',
                'text' => mb_substr($text, 0, 1800),
            ];
            $fallbackIndex++;
        }

        return $result;
    }

    private function normalizeDatasetFields(array $dataset): array
    {
        $fields = [];
        foreach ($dataset as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $type = $definition['type'] ?? $definition['data_type'] ?? $definition['kind'] ?? null;
            if (!$type && isset($definition['schema']['type'])) {
                $type = $definition['schema']['type'];
            }
            if ($type) {
                $fields[(string)$key] = (string)$type;
            }
        }
        return $fields;
    }

    private function buildLessonTextValues(array $lessonData): array
    {
        $slides = $lessonData['slides'] ?? [];
        $slideLines = [];
        foreach ($slides as $index => $slide) {
            $title = trim((string)($slide['title'] ?? ''));
            $points = $slide['points'] ?? $slide['bullets'] ?? [];
            if (!is_array($points)) {
                $points = preg_split('/\r\n|\r|\n/', (string)$points) ?: [];
            }
            $points = array_values(array_filter(array_map('trim', $points)));
            $line = $title;
            if ($points) {
                $line .= "\n- " . implode("\n- ", array_slice($points, 0, 5));
            }
            if (trim($line) !== '') {
                $slideLines[] = trim($line);
            }
        }

        $summary = trim((string)($lessonData['summary'] ?? ''));
        if ($summary === '' && $slideLines) {
            $summary = implode("\n\n", array_slice($slideLines, 0, 4));
        }

        return [
            'title' => trim((string)($lessonData['title'] ?? '')),
            'subject' => trim((string)($lessonData['subject'] ?? '')),
            'grade' => trim((string)($lessonData['grade'] ?? '')),
            'language' => trim((string)($lessonData['language'] ?? 'ar')),
            'summary' => $summary,
            'slides' => $slideLines,
        ];
    }

    private function resolveAutofillTextForField(string $fieldName, array $values, int $fallbackIndex): string
    {
        $name = mb_strtolower($fieldName);

        if (preg_match('/slide[_\-\s]*(\d+)[_\-\s]*(title|heading)?/u', $name, $m)) {
            $idx = max(0, (int)$m[1] - 1);
            $slide = $values['slides'][$idx] ?? '';
            return trim(strtok($slide, "\n") ?: $slide);
        }

        if (preg_match('/slide[_\-\s]*(\d+)|content[_\-\s]*(\d+)|body[_\-\s]*(\d+)/u', $name, $m)) {
            $matches = array_values(array_filter(array_slice($m, 1), 'strlen'));
            $idx = max(0, (int)($matches[0] ?? 1) - 1);
            return $values['slides'][$idx] ?? '';
        }

        if ($this->containsAny($name, ['title', 'lesson', 'topic', 'عنوان'])) {
            return $values['title'];
        }
        if ($this->containsAny($name, ['subject', 'مادة'])) {
            return $values['subject'] ?: $values['title'];
        }
        if ($this->containsAny($name, ['grade', 'class', 'صف'])) {
            return $values['grade'];
        }
        if ($this->containsAny($name, ['summary', 'overview', 'ملخص'])) {
            return $values['summary'];
        }

        if ($fallbackIndex === 0 && $values['title'] !== '') {
            return $values['title'];
        }

        $slideIndex = max(0, $fallbackIndex - 1);
        return $values['slides'][$slideIndex] ?? $values['summary'] ?? $values['title'];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function auditExternalIntent(string $operation, string $externalId): string
    {
        $operationId = bin2hex(random_bytes(16));
        (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
            'external_intent', 'canva_operation', null, $operation, [
                'operation_id' => $operationId,
                'external_id_hash' => hash('sha256', $externalId),
                'direct_undo_available' => false,
            ],
            ['result' => 'started']
        );
        return $operationId;
    }

    private function auditExternalOutcome(string $operationId, string $operation, string $externalId, array $result): array
    {
        try {
            $error = (string)($result['error'] ?? '');
            (new \EduCore\Modules\Operations\Audit\AuditService($this->db))->recordEvent(
                'external_outcome', 'canva_operation', null, $operation, [
                    'operation_id' => $operationId,
                    'external_id_hash' => hash('sha256', $externalId),
                    'success' => !empty($result['success']),
                    'has_local_file' => !empty($result['path']),
                    'error_hash' => $error !== '' ? hash('sha256', $error) : null,
                    'direct_undo_available' => false,
                ],
                ['result' => !empty($result['success']) ? 'success' : 'failed']
            );
        } catch (Throwable $auditError) {
            error_log('Canva external outcome audit failed for operation ' . $operationId);
        }
        return $result;
    }

    // =========================================================
    // HTTP Helpers
    // =========================================================

    /** GET مصادق عليه بـ Bearer Token */
    private function apiGet(string $url, string $token): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            error_log("Canva GET failed [$url]: $err");
            return null;
        }
        return json_decode($body, true);
    }

    /** POST مصادق عليه بـ Bearer Token */
    private function apiPost(string $url, array $payload, string $token): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            error_log("Canva POST failed [$url]: $err");
            return null;
        }
        return json_decode($body, true);
    }

    /**
     * طلب إلى نقطة نهاية التوكن بـ HTTP Basic Auth
     * (client_id:client_secret) + application/x-www-form-urlencoded
     */
    private function callTokenEndpoint(array $params): ?array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => $this->clientId . ':' . $this->clientSecret,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body    = curl_exec($ch);
        $err     = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            error_log("Canva token endpoint cURL error: $err");
            return null;
        }

        $data = json_decode($body, true);

        if ($httpCode >= 400) {
            error_log("Canva token endpoint HTTP $httpCode: $body");
            return null;
        }

        return $data;
    }
}
